
<?php
/**
 * Plugin Name: High-Speed WooCommerce CSV Importer
 Plugin URI: https://www.facebook.com/NextDigitOfficial/
 * Description: A high-performance product importer for large WooCommerce catalogs
 * Version: 1.0
 * Author: Next Digit -Reza
  Author URI: https://www.facebook.com/NextDigitOfficial/
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class HighSpeed_WC_Importer {
    
    private $batch_size = 200;
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
            'batch_size' => 200,
            'csv_delimiter' => ',',
            'csv_enclosure' => '"',
            'enable_cron' => true,
            'cron_interval' => 'hourly',
            'notification_email' => get_option('admin_email'),
            'log_retention_days' => 30,
            'enable_image_import' => true,
            'image_timeout' => 10,
            'enable_debug_log' => false
        ];
        
        $this->settings = get_option('hs_wc_importer_settings', $defaults);
        $this->batch_size = $this->settings['batch_size'];
    }
    
    private function init_hooks() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('hs_wc_import_cron', [$this, 'cron_import_handler']);
        
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('wc-import', [$this, 'cli_import']);
        }
        
        // Register cron schedule if enabled
        if ($this->settings['enable_cron']) {
            if (!wp_next_scheduled('hs_wc_import_cron')) {
                wp_schedule_event(time(), $this->settings['cron_interval'], 'hs_wc_import_cron');
            }
        } else {
            wp_clear_scheduled_hook('hs_wc_import_cron');
        }
        
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
    }
    
    public function add_cron_schedules($schedules) {
        $schedules['every_15_minutes'] = [
            'interval' => 15 * 60,
            'display' => __('Every 15 Minutes')
        ];
        $schedules['every_5_minutes'] = [
            'interval' => 5 * 60,
            'display' => __('Every 5 Minutes')
        ];
        return $schedules;
    }
    
    public function admin_menu() {
        add_menu_page(
            'High-Speed Importer',
            'HS Importer',
            'manage_options',
            'hs-wc-importer',
            [$this, 'admin_page'],
            'dashicons-database-import',
            30
        );
    }
    
    public function register_settings() {
        register_setting('hs_wc_importer_settings', 'hs_wc_importer_settings');
        
        add_settings_section(
            'hs_wc_importer_main',
            'Import Settings',
            [$this, 'settings_section_callback'],
            'hs-wc-importer'
        );
        
        add_settings_field(
            'batch_size',
            'Batch Size',
            [$this, 'batch_size_callback'],
            'hs-wc-importer',
            'hs_wc_importer_main'
        );
        
        add_settings_field(
            'csv_delimiter',
            'CSV Delimiter',
            [$this, 'csv_delimiter_callback'],
            'hs-wc-importer',
            'hs_wc_importer_main'
        );
        
        add_settings_field(
            'enable_cron',
            'Enable Cron Import',
            [$this, 'enable_cron_callback'],
            'hs-wc-importer',
            'hs_wc_importer_main'
        );
        
        add_settings_field(
            'cron_interval',
            'Cron Interval',
            [$this, 'cron_interval_callback'],
            'hs-wc-importer',
            'hs_wc_importer_main'
        );
        
        add_settings_field(
            'notification_email',
            'Notification Email',
            [$this, 'notification_email_callback'],
            'hs-wc-importer',
            'hs_wc_importer_main'
        );
    }
    
    public function settings_section_callback() {
        echo '<p>Configure the high-speed importer settings</p>';
    }
    
    public function batch_size_callback() {
        $value = isset($this->settings['batch_size']) ? $this->settings['batch_size'] : 200;
        echo '<input type="number" name="hs_wc_importer_settings[batch_size]" value="' . $value . '" min="50" max="1000" />';
        echo '<p class="description">Number of products to process in each batch (50-1000)</p>';
    }
    
    public function csv_delimiter_callback() {
        $value = isset($this->settings['csv_delimiter']) ? $this->settings['csv_delimiter'] : ',';
        echo '<input type="text" name="hs_wc_importer_settings[csv_delimiter]" value="' . $value . '" size="1" />';
        echo '<p class="description">CSV delimiter character (usually , or ;)</p>';
    }
    
    public function enable_cron_callback() {
        $value = isset($this->settings['enable_cron']) ? $this->settings['enable_cron'] : true;
        echo '<input type="checkbox" name="hs_wc_importer_settings[enable_cron]" value="1" ' . checked(1, $value, false) . ' />';
        echo '<p class="description">Enable automatic scheduled imports</p>';
    }
    
    public function cron_interval_callback() {
        $value = isset($this->settings['cron_interval']) ? $this->settings['cron_interval'] : 'hourly';
        $schedules = wp_get_schedules();
        
        echo '<select name="hs_wc_importer_settings[cron_interval]">';
        foreach ($schedules as $key => $schedule) {
            echo '<option value="' . $key . '" ' . selected($value, $key, false) . '>' . $schedule['display'] . '</option>';
        }
        echo '</select>';
    }
    
    public function notification_email_callback() {
        $value = isset($this->settings['notification_email']) ? $this->settings['notification_email'] : get_option('admin_email');
        echo '<input type="email" name="hs_wc_importer_settings[notification_email]" value="' . $value . '" />';
        echo '<p class="description">Email address to receive import notifications</p>';
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>High-Speed WooCommerce Importer</h1>
            
            <div class="card">
                <h2>Import Products</h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('hs_wc_import', 'hs_wc_import_nonce'); ?>
                    <p>
                        <label for="csv_file">Select CSV File:</label>
                        <input type="file" name="csv_file" accept=".csv" required />
                    </p>
                    <p>
                        <input type="submit" name="start_import" class="button button-primary" value="Start Import" />
                    </p>
                </form>
            </div>
            
            <div class="card">
                <h2>Settings</h2>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('hs_wc_importer_settings');
                    do_settings_sections('hs-wc-importer');
                    submit_button();
                    ?>
                </form>
            </div>
            
            <div class="card">
                <h2>Import Logs</h2>
                <?php $this->display_logs(); ?>
            </div>
        </div>
        <?php
        
        // Handle form submission
        if (isset($_POST['start_import']) && check_admin_referer('hs_wc_import', 'hs_wc_import_nonce')) {
            if (!empty($_FILES['csv_file']['tmp_name'])) {
                $this->handle_manual_import();
            } else {
                echo '<div class="error"><p>Please select a CSV file to import</p></div>';
            }
        }
    }
    
    private function handle_manual_import() {
        $file = $_FILES['csv_file']['tmp_name'];
        $result = $this->import_from_csv($file);
        
        if ($result) {
            echo '<div class="updated"><p>Import completed successfully! Processed ' . $this->total_processed . ' products.</p></div>';
        } else {
            echo '<div class="error"><p>Import failed. Check logs for details.</p></div>';
        }
    }
    
    private function display_logs() {
        $log_dir = WP_CONTENT_DIR . '/uploads/wc-import-logs/';
        if (!file_exists($log_dir)) {
            echo '<p>No logs available yet.</p>';
            return;
        }
        
        $logs = glob($log_dir . '*.log');
        if (empty($logs)) {
            echo '<p>No logs available yet.</p>';
            return;
        }
        
        rsort($logs);
        echo '<ul>';
        foreach ($logs as $log) {
            $log_name = basename($log);
            $log_date = str_replace(['import-', '.log'], '', $log_name);
            $log_date = str_replace('-', ':', $log_date);
            echo '<li><a href="' . WP_CONTENT_URL . '/uploads/wc-import-logs/' . $log_name . '" target="_blank">' . $log_date . '</a></li>';
        }
        echo '</ul>';
    }
    
    public function cron_import_handler() {
        $csv_path = get_option('hs_wc_importer_csv_path');
        
        if (!$csv_path || !file_exists($csv_path)) {
            $this->log_error('Scheduled import failed: CSV file not found at ' . $csv_path);
            $this->send_notification('Import Failed', 'CSV file not found at scheduled path: ' . $csv_path);
            return false;
        }
        
        $result = $this->import_from_csv($csv_path);
        
        if ($result) {
            $message = 'Scheduled import completed successfully. Processed ' . $this->total_processed . ' products.';
            $this->send_notification('Import Completed', $message);
        } else {
            $message = 'Scheduled import failed. Check logs for details.';
            $this->send_notification('Import Failed', $message);
        }
        
        return $result;
    }
    
    public function cli_import($args, $assoc_args) {
        $file_path = $args[0];
        $batch_size = isset($assoc_args['batch-size']) ? (int)$assoc_args['batch-size'] : $this->batch_size;
        
        if (!file_exists($file_path)) {
            WP_CLI::error('CSV file not found: ' . $file_path);
            return;
        }
        
        $this->batch_size = $batch_size;
        
        WP_CLI::log('Starting import from: ' . $file_path);
        WP_CLI::log('Batch size: ' . $this->batch_size);
        
        $result = $this->import_from_csv($file_path);
        
        if ($result) {
            WP_CLI::success('Import completed successfully! Processed ' . $this->total_processed . ' products.');
        } else {
            WP_CLI::error('Import failed with ' . count($this->errors) . ' errors. Check log for details.');
        }
    }
    
    public function import_from_csv($file_path) {
        $this->log_file = WP_CONTENT_DIR . '/uploads/wc-import-logs/import-' . date('Y-m-d-H-i-s') . '.log';
        $this->ensure_log_directory();
        $this->start_time = microtime(true);
        $this->errors = [];
        $this->total_processed = 0;
        $this->current_batch = 0;
        
        $this->log_info('Starting import from: ' . $file_path);
        $this->log_info('Memory limit: ' . ini_get('memory_limit'));
        $this->log_info('Max execution time: ' . ini_get('max_execution_time'));
        
        if (!file_exists($file_path)) {
            $this->log_error('CSV file not found: ' . $file_path);
            return false;
        }
        
        // Increase limits for large imports
        wp_raise_memory_limit('wc_import');
        set_time_limit(0);
        
        // Process in batches
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->log_error('Could not open CSV file: ' . $file_path);
            return false;
        }
        
        // Read header
        $header = fgetcsv($handle, 0, $this->settings['csv_delimiter'], $this->settings['csv_enclosure']);
        if (!$header) {
            $this->log_error('Could not read CSV header');
            fclose($handle);
            return false;
        }
        
        $this->log_info('CSV header: ' . implode(', ', $header));
        
        // Process batches
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
                
                // Memory cleanup
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
        
        // Begin transaction for data integrity
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
        // Normalize data
        $sku = sanitize_text_field($data['sku']);
        
        if (empty($sku)) {
            $this->log_error('Skipping product with empty SKU: ' . print_r($data, true));
            return;
        }
        
        $product_data = $this->map_product_data($data);
        
        // Check if product exists
        $existing_id = $this->find_product_by_sku($sku);
        
        if ($existing_id) {
            $this->update_product($existing_id, $product_data);
        } else {
            $this->create_product($product_data);
        }
    }
    
    private function map_product_data($data) {
        // Map CSV fields to WooCommerce product data
        return [
            'name' => sanitize_text_field($data['name']),
            'sku' => sanitize_text_field($data['sku']),
            'description' => wp_kses_post($data['description']),
            'short_description' => wp_kses_post($data['short_description']),
            'price' => wc_format_decimal($data['price']),
            'regular_price' => wc_format_decimal($data['regular_price']),
            'sale_price' => wc_format_decimal($data['sale_price']),
            'stock_quantity' => intval($data['stock_quantity']),
            'manage_stock' => !empty($data['stock_quantity']),
            'stock_status' => !empty($data['stock_quantity']) ? 'instock' : 'outofstock',
            'categories' => $this->process_categories($data['categories']),
            'images' => $this->process_images($data['images']),
            'attributes' => $this->process_attributes($data['attributes']),
            'meta_data' => $this->process_meta_data($data)
        ];
    }
    
    private function find_product_by_sku($sku) {
        global $wpdb;
        
        $product_id = $wpdb->get_var(
            $wpdb->prepare("
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_sku' AND meta_value = %s 
                LIMIT 1
            ", $sku)
        );
        
        return $product_id;
    }
    
    private function create_product($product_data) {
        $product = new WC_Product();
        
        try {
            $product->set_name($product_data['name']);
            $product->set_sku($product_data['sku']);
            $product->set_description($product_data['description']);
            $product->set_short_description($product_data['short_description']);
            $product->set_price($product_data['price']);
            $product->set_regular_price($product_data['regular_price']);
            
            if (!empty($product_data['sale_price'])) {
                $product->set_sale_price($product_data['sale_price']);
            }
            
            $product->set_manage_stock($product_data['manage_stock']);
            
            if ($product_data['manage_stock']) {
                $product->set_stock_quantity($product_data['stock_quantity']);
            }
            
            $product->set_stock_status($product_data['stock_status']);
            
            // Set categories
            if (!empty($product_data['categories'])) {
                $product->set_category_ids($product_data['categories']);
            }
            
            // Set attributes
            if (!empty($product_data['attributes'])) {
                $product->set_attributes($product_data['attributes']);
            }
            
            // Set meta data
            if (!empty($product_data['meta_data'])) {
                foreach ($product_data['meta_data'] as $key => $value) {
                    $product->update_meta_data($key, $value);
                }
            }
            
            $product_id = $product->save();
            
            // Set images
            if (!empty($product_data['images'])) {
                $this->set_product_images($product_id, $product_data['images']);
            }
            
            $this->log_info('Created product: ' . $product_data['name'] . ' (SKU: ' . $product_data['sku'] . ')');
            
        } catch (Exception $e) {
            $this->log_error('Failed to create product: ' . $product_data['sku'] . ' - ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function update_product($product_id, $product_data) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            $this->log_error('Product not found for update: ' . $product_id);
            return;
        }
        
        try {
            // Only update if the data has changed
            if ($product->get_name() !== $product_data['name']) {
                $product->set_name($product_data['name']);
            }
            
            if ($product->get_description() !== $product_data['description']) {
                $product->set_description($product_data['description']);
            }
            
            if ($product->get_short_description() !== $product_data['short_description']) {
                $product->set_short_description($product_data['short_description']);
            }
            
            if ($product->get_price() !== $product_data['price']) {
                $product->set_price($product_data['price']);
            }
            
            if ($product->get_regular_price() !== $product_data['regular_price']) {
                $product->set_regular_price($product_data['regular_price']);
            }
            
            if ($product->get_sale_price() !== $product_data['sale_price']) {
                $product->set_sale_price($product_data['sale_price']);
            }
            
            // Update stock if changed
            if ($product->get_manage_stock() !== $product_data['manage_stock']) {
                $product->set_manage_stock($product_data['manage_stock']);
            }
            
            if ($product_data['manage_stock'] && $product->get_stock_quantity() !== $product_data['stock_quantity']) {
                $product->set_stock_quantity($product_data['stock_quantity']);
            }
            
            if ($product->get_stock_status() !== $product_data['stock_status']) {
                $product->set_stock_status($product_data['stock_status']);
            }
            
            // Update categories if changed
            if (!empty($product_data['categories'])) {
                $current_categories = $product->get_category_ids();
                if (array_diff($product_data['categories'], $current_categories) || 
                    array_diff($current_categories, $product_data['categories'])) {
                    $product->set_category_ids($product_data['categories']);
                }
            }
            
            $product->save();
            
            $this->log_info('Updated product: ' . $product_data['name'] . ' (SKU: ' . $product_data['sku'] . ')');
            
        } catch (Exception $e) {
            $this->log_error('Failed to update product: ' . $product_data['sku'] . ' - ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function process_categories($categories_string) {
        if (empty($categories_string)) {
            return [];
        }
        
        $categories = explode('|', $categories_string);
        $category_ids = [];
        
        foreach ($categories as $category) {
            $term = term_exists($category, 'product_cat');
            
            if (!$term) {
                $term = wp_insert_term($category, 'product_cat');
            }
            
            if (!is_wp_error($term)) {
                $category_ids[] = $term['term_id'];
            }
        }
        
        return $category_ids;
    }
    
    private function process_images($images_string) {
        if (empty($images_string) || !$this->settings['enable_image_import']) {
            return [];
        }
        
        $image_urls = explode('|', $images_string);
        $image_ids = [];
        
        foreach ($image_urls as $image_url) {
            $image_id = $this->upload_image_from_url($image_url);
            if ($image_id) {
                $image_ids[] = $image_id;
            }
        }
        
        return $image_ids;
    }
    
    private function upload_image_from_url($image_url) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $timeout = $this->settings['image_timeout'];
        
        // Set timeout for image download
        add_filter('http_request_timeout', function() use ($timeout) {
            return $timeout;
        });
        
        try {
            $media_id = media_sideload_image($image_url, 0, '', 'id');
            
            if (is_wp_error($media_id)) {
                $this->log_error('Failed to upload image: ' . $image_url . ' - ' . $media_id->get_error_message());
                return false;
            }
            
            return $media_id;
        } catch (Exception $e) {
            $this->log_error('Failed to upload image: ' . $image_url . ' - ' . $e->getMessage());
            return false;
        }
    }
    
    private function set_product_images($product_id, $image_ids) {
        if (empty($image_ids)) {
            return;
        }
        
        // Set the first image as featured
        $featured_image = array_shift($image_ids);
        set_post_thumbnail($product_id, $featured_image);
        
        // Set remaining images as gallery
        if (!empty($image_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $image_ids));
        }
    }
    
    private function process_attributes($attributes_string) {
        if (empty($attributes_string)) {
            return [];
        }
        
        $attributes = [];
        $pairs = explode('|', $attributes_string);
        
        foreach ($pairs as $pair) {
            $parts = explode(':', $pair);
            
            if (count($parts) === 2) {
                $attribute_name = sanitize_text_field($parts[0]);
                $attribute_values = array_map('sanitize_text_field', explode(',', $parts[1]));
                
                $attribute = new WC_Product_Attribute();
                $attribute->set_name($attribute_name);
                $attribute->set_options($attribute_values);
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                
                $attributes[] = $attribute;
            }
        }
        
        return $attributes;
    }
    
    private function process_meta_data($data) {
        $meta_data = [];
        
        // Extract meta fields (fields starting with meta:)
        foreach ($data as $key => $value) {
            if (strpos($key, 'meta:') === 0) {
                $meta_key = substr($key, 5); // Remove 'meta:' prefix
                $meta_data[$meta_key] = $value;
            }
        }
        
        return $meta_data;
    }
    
    private function ensure_log_directory() {
        $log_dir = WP_CONTENT_DIR . '/uploads/wc-import-logs/';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Add index.html to prevent directory listing
        $index_file = $log_dir . 'index.html';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<!-- Silence is golden -->');
        }
    }
    
    private function log_info($message) {
        $this->log('INFO', $message);
    }
    
    private function log_error($message) {
        $this->log('ERROR', $message);
        $this->errors[] = $message;
    }
    
    private function log($level, $message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
        
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        if ($this->settings['enable_debug_log']) {
            error_log("HS_WC_Importer: $message");
        }
    }
    
    private function finalize_import() {
        $execution_time = microtime(true) - $this->start_time;
        $memory_used = memory_get_peak_usage(true) / 1024 / 1024;
        
        $this->log_info('Import completed in ' . round($execution_time, 2) . ' seconds');
        $this->log_info('Peak memory usage: ' . round($memory_used, 2) . 'MB');
        $this->log_info('Total products processed: ' . $this->total_processed);
        
        if (!empty($this->errors)) {
            $this->log_info('Errors encountered: ' . count($this->errors));
            foreach ($this->errors as $error) {
                $this->log_error($error);
            }
        }
        
        // Clean up old logs
        $this->cleanup_old_logs();
    }
    
    private function cleanup_old_logs() {
        $log_dir = WP_CONTENT_DIR . '/uploads/wc-import-logs/';
        $retention_days = $this->settings['log_retention_days'];
        
        if (!file_exists($log_dir)) {
            return;
        }
        
        $logs = glob($log_dir . '*.log');
        $now = time();
        
        foreach ($logs as $log) {
            if (filemtime($log) < $now - ($retention_days * 24 * 60 * 60)) {
                unlink($log);
            }
        }
    }
    
    private function send_notification($subject, $message) {
        $email = $this->settings['notification_email'];
        
        if (!empty($email)) {
            wp_mail($email, 'WC Importer: ' . $subject, $message);
        }
    }
}

// Initialize the importer
new HighSpeed_WC_Importer();