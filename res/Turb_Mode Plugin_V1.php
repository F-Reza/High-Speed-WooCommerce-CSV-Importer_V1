<?php
/**
 * Plugin Name: WooCommerce Turbo CSV Importer
 * Plugin URI: https://www.facebook.com/NextDigitOfficial/
 * Description: Ultra-fast WooCommerce CSV Importer for large catalogs (CLI/SSH ready, Turbo Mode)
 * Version: 1.0
 * Author: Next Digit - Reza
 * Author URI: https://www.facebook.com/NextDigitOfficial/
 */

if (!defined('ABSPATH')) exit;

class WC_Turbo_Importer {
    private $batch_size = 2000; // Default batch size
    private $csv_path;
    private $log_file;
    private $errors = [];
    private $turbo_mode = true; // Direct DB insert for speed
    private $total_processed = 0;

    public function __construct() {
        $this->init_settings();
        $this->init_hooks();
    }

    private function init_settings() {
        $defaults = [
            'csv_path' => ABSPATH . '../var/import/products.csv',
            'batch_size' => $this->batch_size,
            'enable_image_import' => false,
            'log_enabled' => true,
        ];
        $this->settings = get_option('wc_turbo_importer_settings', $defaults);
        $this->csv_path = $this->settings['csv_path'];
        $this->batch_size = $this->settings['batch_size'];
        $this->log_file = WP_CONTENT_DIR . '/uploads/wc-turbo-import.log';
    }

    private function init_hooks() {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('wc-turbo-import', [$this, 'cli_import']);
        }
    }

    public function cli_import($args = [], $assoc_args = []) {
        $batch_size = isset($assoc_args['batch-size']) ? (int)$assoc_args['batch-size'] : $this->batch_size;
        $this->batch_size = $batch_size;

        if (!file_exists($this->csv_path)) {
            WP_CLI::error("CSV file not found: {$this->csv_path}");
            return;
        }

        WP_CLI::log("Starting Turbo Import from: {$this->csv_path}");
        WP_CLI::log("Batch size: {$this->batch_size}");

        $result = $this->import_csv($this->csv_path);

        if ($result) {
            WP_CLI::success("Turbo Import completed! Total products processed: {$this->total_processed}");
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

        $sku_map = $wpdb->get_results("SELECT post_id, meta_value as sku FROM {$wpdb->postmeta} WHERE meta_key='_sku'", OBJECT_K);
        $this->total_processed = 0;

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

            if (isset($sku_map[$sku])) {
                // Update existing product directly in DB for speed
                if ($this->turbo_mode) {
                    $wpdb->update(
                        $wpdb->posts,
                        ['post_title' => $name, 'post_modified' => current_time('mysql')],
                        ['ID' => $sku_map[$sku]->post_id]
                    );
                    update_post_meta($sku_map[$sku]->post_id, '_price', $price);
                    update_post_meta($sku_map[$sku]->post_id, '_regular_price', $price);
                    update_post_meta($sku_map[$sku]->post_id, '_stock', $stock);
                    update_post_meta($sku_map[$sku]->post_id, '_stock_status', $status);
                } else {
                    // Full WC_Product update (slower)
                    $product = wc_get_product($sku_map[$sku]->post_id);
                    $product->set_name($name);
                    $product->set_price($price);
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($stock);
                    $product->set_stock_status($status);
                    $product->save();
                }
            } else {
                // Insert new product
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

                $sku_map[$sku] = (object)['post_id' => $post_id];
            }
            $this->total_processed++;
        }
    }
}

new WC_Turbo_Importer();
