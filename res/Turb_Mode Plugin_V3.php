<?php
/**
 * Plugin Name: WooCommerce Turbo CSV Importer Pro
 * Plugin URI: https://www.facebook.com/NextDigitOfficial/
 * Description: Ultra-fast WooCommerce CSV Importer with images, gallery, categories & brands (CLI/SSH ready)
 * Version: 1.2
 * Author: Next Digit - Reza
 * Author URI: https://www.facebook.com/NextDigitOfficial/
 */

if (!defined('ABSPATH')) exit;

class WC_Turbo_Importer_Pro {
    private $batch_size = 2000;
    private $csv_path;
    private $log_file;
    private $errors = [];
    private $total_processed = 0;
    private $turbo_mode = true;
    private $image_cache = [];

    public function __construct() {
        $this->init_settings();
        $this->init_hooks();
    }

    private function init_settings() {
        $defaults = [
            'csv_path' => ABSPATH . '../var/import/products.csv',
            'batch_size' => $this->batch_size,
            'enable_images' => true,
            'enable_gallery' => true,
            'enable_debug_log' => false,
        ];
        $this->settings = get_option('wc_turbo_importer_pro_settings', $defaults);
        $this->csv_path = $this->settings['csv_path'];
        $this->batch_size = $this->settings['batch_size'];
        $this->log_file = WP_CONTENT_DIR . '/uploads/wc-turbo-import-pro.log';
    }

    private function init_hooks() {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('wc-turbo-import-pro', [$this, 'cli_import']);
        }
    }

    public function cli_import($args = [], $assoc_args = []) {
        $batch_size = isset($assoc_args['batch-size']) ? (int)$assoc_args['batch-size'] : $this->batch_size;
        $this->batch_size = $batch_size;

        if (!file_exists($this->csv_path)) {
            WP_CLI::error("CSV file not found: {$this->csv_path}");
            return;
        }

        WP_CLI::log("Starting Turbo Pro Import with Gallery from: {$this->csv_path}");
        WP_CLI::log("Batch size: {$this->batch_size}");

        $result = $this->import_csv($this->csv_path);

        if ($result) {
            WP_CLI::success("Turbo Pro Import completed! Total products processed: {$this->total_processed}");
        } else {
            WP_CLI::error("Import finished with errors: " . count($this->errors));
        }
    }

    public function import_csv($file) {
        global $wpdb;
        set_time_limit(0);
        ini_set('memory_limit','8G');

        if (!file_exists($file)) return false;

        $handle = fopen($file, 'r');
        if (!$handle) return false;

        $header = fgetcsv($handle, 0, ',', '"');
        if (!$header) return false;

        // Preload existing SKUs for speed
        $sku_map = $wpdb->get_results("SELECT post_id, meta_value as sku FROM {$wpdb->postmeta} WHERE meta_key='_sku'", OBJECT_K);

        while (!feof($handle)) {
            $batch_data = [];
            $count = 0;
            while ($count < $this->batch_size && ($row = fgetcsv($handle, 0, ',', '"')) !== FALSE) {
                if (count($row) === count($header)) {
                    $batch_data[] = array_combine($header, $row);
                    $count++;
                }
            }

            if (!empty($batch_data)) {
                $this->process_batch($batch_data, $sku_map, $wpdb);
                unset($batch_data);
                gc_collect_cycles();
            }
        }

        fclose($handle);
        return true;
    }

    private function process_batch($batch, &$sku_map, $wpdb) {
        foreach ($batch as $data) {
            $sku = sanitize_text_field($data['sku']);
            if (!$sku) continue;

            $name = sanitize_text_field($data['name']);
            $price = floatval($data['price']);
            $stock = intval($data['stock_quantity']);
            $status = $stock > 0 ? 'instock' : 'outofstock';

            // Categories & Brands
            $category = !empty($data['category']) ? sanitize_text_field($data['category']) : '';
            $brand = !empty($data['brand']) ? sanitize_text_field($data['brand']) : '';

            // Featured + Gallery Images
            $image_urls = !empty($data['images']) ? explode(',', $data['images']) : []; // multiple URLs comma-separated
            $featured_image = array_shift($image_urls); // first is featured
            $gallery_images = $image_urls; // rest are gallery

            if (isset($sku_map[$sku])) {
                $post_id = $sku_map[$sku]->post_id;
                if ($this->turbo_mode) {
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->posts,
                        ['post_title' => $name, 'post_modified' => current_time('mysql')],
                        ['ID' => $post_id]
                    );
                    update_post_meta($post_id, '_price', $price);
                    update_post_meta($post_id, '_regular_price', $price);
                    update_post_meta($post_id, '_stock', $stock);
                    update_post_meta($post_id, '_stock_status', $status);
                }

                $this->assign_terms($post_id, $category, $brand);
                if ($featured_image) $this->set_featured_image($post_id, $featured_image);
                if ($gallery_images) $this->set_gallery_images($post_id, $gallery_images);

            } else {
                $post_id = wp_insert_post([
                    'post_title' => $name,
                    'post_status' => 'publish',
                    'post_type' => 'product',
                ]);
                update_post_meta($post_id, '_sku', $sku);
                update_post_meta($post_id, '_price', $price);
                update_post_meta($post_id, '_regular_price', $price);
                update_post_meta($post_id, '_stock', $stock);
                update_post_meta($post_id, '_stock_status', $status);

                $this->assign_terms($post_id, $category, $brand);
                if ($featured_image) $this->set_featured_image($post_id, $featured_image);
                if ($gallery_images) $this->set_gallery_images($post_id, $gallery_images);

                $sku_map[$sku] = (object)['post_id' => $post_id];
            }

            $this->total_processed++;
        }
    }

    private function assign_terms($post_id, $category, $brand) {
        if ($category) {
            $term = term_exists($category, 'product_cat');
            if (!$term) $term = wp_insert_term($category, 'product_cat');
            if (!is_wp_error($term)) wp_set_post_terms($post_id, [$term['term_id']], 'product_cat');
        }

        if ($brand) {
            $brand_tax = 'product_brand';
            if (!taxonomy_exists($brand_tax)) register_taxonomy($brand_tax, 'product', ['hierarchical' => false, 'label' => 'Brands']);
            $term = term_exists($brand, $brand_tax);
            if (!$term) $term = wp_insert_term($brand, $brand_tax);
            if (!is_wp_error($term)) wp_set_object_terms($post_id, [$term['term_id']], $brand_tax);
        }
    }

    private function set_featured_image($post_id, $url) {
        $id = $this->attach_image($url, $post_id);
        if ($id) set_post_thumbnail($post_id, $id);
    }

    private function set_gallery_images($post_id, $urls) {
        $ids = [];
        foreach ($urls as $url) {
            $id = $this->attach_image(trim($url), $post_id);
            if ($id) $ids[] = $id;
        }
        if (!empty($ids)) update_post_meta($post_id, '_product_image_gallery', implode(',', $ids));
    }

    private function attach_image($url, $post_id) {
        if (isset($this->image_cache[$url])) return $this->image_cache[$url];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url);
        if (is_wp_error($tmp)) return 0;

        $file_array = [
            'name' => basename($url),
            'tmp_name' => $tmp
        ];

        $id = media_handle_sideload($file_array, $post_id);
        if (!is_wp_error($id)) $this->image_cache[$url] = $id;
        return is_wp_error($id) ? 0 : $id;
    }
}

new WC_Turbo_Importer_Pro();
