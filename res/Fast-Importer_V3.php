<?php
/**
 * Plugin Name: High-Speed WooCommerce CSV Importer
 * Plugin URI: https://www.facebook.com/NextDigitOfficial/
 * Description: A high-performance product importer for large WooCommerce catalogs (CLI/SSH ready)
 * Version: 1.3
 * Author: Next Digit - Reza
 * Author URI: https://www.facebook.com/NextDigitOfficial/
 */

if (!defined('ABSPATH')) exit;

class HighSpeed_WC_Importer {
    private $batch_size = 500;
    private $log_file;
    private $current_batch = 0;
    private $total_processed = 0;
    private $start_time;
    private $errors = [];
    private $settings;

    public function __construct() {
        $this->init_settings();
        $this->init_hooks();
    }

    private function init_settings() {
        $defaults = [
            'csv_path' => ABSPATH . '../var/import/products.csv',
            'batch_size' => 500,
            'csv_delimiter' => ',',
            'csv_enclosure' => '"',
            'enable_image_import' => true,
            'image_timeout' => 10,
            'enable_debug_log' => false,
            'log_retention_days' => 30,
            'notification_email' => get_option('admin_email')
        ];
        $this->settings = get_option('hs_wc_importer_settings', $defaults);
        $this->batch_size = $this->settings['batch_size'];
    }

    private function init_hooks() {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('wc-import', [$this, 'cli_import']);
        }
    }

    public function cli_import($args = [], $assoc_args = []) {
        $csv_path = $this->settings['csv_path'];
        $batch_size = isset($assoc_args['batch-size']) ? (int)$assoc_args['batch-size'] : $this->batch_size;

        if (!file_exists($csv_path)) {
            WP_CLI::error('CSV file not found: ' . $csv_path);
            return;
        }

        $this->batch_size = $batch_size;
        WP_CLI::log('Starting import from: ' . $csv_path);
        WP_CLI::log('Batch size: ' . $this->batch_size);

        $result = $this->import_from_csv($csv_path);

        if ($result) {
            WP_CLI::success('Import completed successfully! Processed ' . $this->total_processed . ' products.');
        } else {
            WP_CLI::error('Import failed with ' . count($this->errors) . ' errors. Check logs.');
        }
    }

    public function import_from_csv($file_path) {
        $this->log_file = WP_CONTENT_DIR . '/uploads/wc-import-logs/import-' . date('Y-m-d-H-i-s') . '.log';
        $this->ensure_log_directory();
        $this->start_time = microtime(true);
        $this->errors = [];
        $this->total_processed = 0;
        $this->current_batch = 0;

        wp_raise_memory_limit('wc_import');
        set_time_limit(0);

        if (!file_exists($file_path)) {
            $this->log_error('CSV file not found: ' . $file_path);
            return false;
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->log_error('Could not open CSV file: ' . $file_path);
            return false;
        }

        $header = fgetcsv($handle, 0, $this->settings['csv_delimiter'], $this->settings['csv_enclosure']);
        if (!$header) {
            $this->log_error('Could not read CSV header');
            fclose($handle);
            return false;
        }

        while (!feof($handle)) {
            $batch_data = [];
            $batch_count = 0;

            while ($batch_count < $this->batch_size && ($row = fgetcsv($handle, 0, $this->settings['csv_delimiter'], $this->settings['csv_enclosure'])) !== FALSE) {
                if (count($row) === count($header)) {
                    $batch_data[] = array_combine($header, $row);
                    $batch_count++;
                } else {
                    $this->log_error('Skipping malformed row: ' . implode(',', $row));
                }
            }

            if (!empty($batch_data)) {
                $this->process_batch($batch_data);
                $this->current_batch++;
                $this->total_processed += count($batch_data);
                unset($batch_data);
                gc_collect_cycles();
            }
        }

        fclose($handle);
        $this->finalize_import();
        return empty($this->errors);
    }

    private function process_batch($batch_data) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            foreach ($batch_data as $row) {
                $this->process_product($row);
            }
            $wpdb->query('COMMIT');
            $this->log_info('Processed batch ' . $this->current_batch . ' with ' . count($batch_data) . ' items');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->log_error('Batch ' . $this->current_batch . ' failed: ' . $e->getMessage());
            $this->errors[] = 'Batch ' . $this->current_batch . ' failed: ' . $e->getMessage();
        }
    }

    private function process_product($data) {
        $sku = sanitize_text_field($data['sku']);
        if (empty($sku)) { $this->log_error('Skipping empty SKU'); return; }

        $product_data = [
            'name' => sanitize_text_field($data['name']),
            'sku' => $sku,
            'price' => wc_format_decimal($data['price']),
            'stock_quantity' => intval($data['stock_quantity']),
            'manage_stock' => !empty($data['stock_quantity']),
            'stock_status' => !empty($data['stock_quantity']) ? 'instock' : 'outofstock',
        ];

        $existing_id = $this->find_product_by_sku($sku);
        if ($existing_id) { $this->update_product($existing_id, $product_data); }
        else { $this->create_product($product_data); }
    }

    private function find_product_by_sku($sku) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1", $sku));
    }

    private function create_product($data) {
        $product = new WC_Product();
        $product->set_name($data['name']);
        $product->set_sku($data['sku']);
        $product->set_price($data['price']);
        $product->set_manage_stock($data['manage_stock']);
        if ($data['manage_stock']) $product->set_stock_quantity($data['stock_quantity']);
        $product->set_stock_status($data['stock_status']);
        $product->save();
        $this->log_info('Created product: ' . $data['sku']);
    }

    private function update_product($product_id, $data) {
        $product = wc_get_product($product_id);
        if (!$product) return;
        $product->set_name($data['name']);
        $product->set_price($data['price']);
        $product->set_manage_stock($data['manage_stock']);
        if ($data['manage_stock']) $product->set_stock_quantity($data['stock_quantity']);
        $product->set_stock_status($data['stock_status']);
        $product->save();
        $this->log_info('Updated product: ' . $data['sku']);
    }

    private function ensure_log_directory() {
        $dir = WP_CONTENT_DIR . '/uploads/wc-import-logs/';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        if (!file_exists($dir . 'index.html')) file_put_contents($dir . 'index.html', '<!-- Silence is golden -->');
    }

    private function log_info($msg) { $this->log('INFO', $msg); }
    private function log_error($msg) { $this->log('ERROR', $msg); $this->errors[] = $msg; }
    private function log($level, $msg) { 
        $ts = date('Y-m-d H:i:s'); 
        file_put_contents($this->log_file, "[$ts][$level] $msg" . PHP_EOL, FILE_APPEND | LOCK_EX); 
        if ($this->settings['enable_debug_log']) error_log("HS_WC_Importer: $msg");
    }

    private function finalize_import() {
        $exec_time = round(microtime(true) - $this->start_time,2);
        $mem = round(memory_get_peak_usage(true)/1024/1024,2);
        $this->log_info("Import finished. Products: {$this->total_processed}, Time: {$exec_time}s, Memory: {$mem}MB");
    }
}

new HighSpeed_WC_Importer();
