<?php
/**
 * Plugin Name: High-Speed WooCommerce CSV Importer V2
 * Description: Custom high-performance importer for WooCommerce (100K+ SKUs) using WP-CLI + Action Scheduler.
 * Author: Next Didit
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

class HighSpeed_WC_Importer {

    private static $batch_size = 100; // SKUs per batch

    public function __construct() {
        // Register WP-CLI command
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('wc-import', [$this, 'cli_import']);
        }
    }

    /**
     * WP-CLI Import Command
     * Usage: wp wc-import run /path/to/file.csv
     */
    public function cli_import($args, $assoc_args) {
        $file = $args[0] ?? '';
        if (!file_exists($file)) {
            \WP_CLI::error("CSV file not found: $file");
        }

        \WP_CLI::log("Starting import from $file ...");

        $handle = fopen($file, 'r');
        if (!$handle) {
            \WP_CLI::error("Unable to open file.");
        }

        $header = fgetcsv($handle, 0, ',');
        if (!$header) {
            \WP_CLI::error("Invalid CSV format: No header row found.");
        }

        $rows = [];
        $count = 0;
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $rows[] = array_combine($header, $data);
            $count++;

            if ($count % self::$batch_size === 0) {
                $this->queue_batch($rows);
                $rows = [];
            }
        }

        // Queue last partial batch
        if (!empty($rows)) {
            $this->queue_batch($rows);
        }

        fclose($handle);
        \WP_CLI::success("Import queued successfully. Use Action Scheduler to monitor progress.");
    }

    /**
     * Queue batch processing
     */
    private function queue_batch($rows) {
        as_enqueue_async_action('wc_import_process_batch', [$rows]);
    }

    /**
     * Process a batch of rows
     */
    public static function process_batch($rows) {
        foreach ($rows as $row) {
            try {
                self::import_product($row);
            } catch (\Exception $e) {
                error_log("Import Error: " . $e->getMessage());
                wp_mail(get_option('admin_email'), "WC Import Error", $e->getMessage());
            }
        }
    }

    /**
     * Import or update product by SKU
     */
    private static function import_product($row) {
        if (empty($row['SKU'])) return;

        $product_id = wc_get_product_id_by_sku($row['SKU']);
        if ($product_id) {
            $product = new WC_Product($product_id);
        } else {
            $product = new WC_Product();
        }

        if (!empty($row['Title'])) {
            $product->set_name(sanitize_text_field($row['Title']));
        }
        if (!empty($row['Description'])) {
            $product->set_description(wp_kses_post($row['Description']));
        }
        if (!empty($row['Price'])) {
            $product->set_regular_price($row['Price']);
        }
        if (!empty($row['Stock'])) {
            $product->set_stock_quantity((int)$row['Stock']);
        }

        $product->set_sku($row['SKU']);
        $product->save();
    }
}

new HighSpeed_WC_Importer();

// Hook for Action Scheduler
add_action('wc_import_process_batch', ['HighSpeed_WC_Importer', 'process_batch']);
