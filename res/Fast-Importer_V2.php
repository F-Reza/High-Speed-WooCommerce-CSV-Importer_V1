<?php
/**
 * Plugin Name: High-Speed WooCommerce CSV Importer
 * Plugin URI: https://www.facebook.com/NextDigitOfficial/
 * Description: A high-performance product importer for large WooCommerce catalogs
 * Version: 1.2
 * Author: Next Digit - Reza
 * Author URI: https://www.facebook.com/NextDigitOfficial/
 */

if (!defined('ABSPATH')) exit;

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
            'csv_path' => ABSPATH . '../var/import/products.csv', // default path
            'batch_size' => 500,
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
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Cron setup
        if ($this->settings['enable_cron'] && !wp_next_scheduled('hs_wc_import_cron')) {
            wp_schedule_event(time(), $this->settings['cron_interval'], 'hs_wc_import_cron');
        }
    }

    public function add_cron_schedules($schedules) {
        $schedules['every_15_minutes'] = ['interval' => 900, 'display' => __('Every 15 Minutes')];
        $schedules['every_5_minutes'] = ['interval' => 300, 'display' => __('Every 5 Minutes')];
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
            function() { echo '<p>Configure the high-speed importer settings</p>'; },
            'hs-wc-importer'
        );

        add_settings_field('csv_path', 'CSV Path', [$this, 'csv_path_callback'], 'hs-wc-importer', 'hs_wc_importer_main');
        add_settings_field('batch_size', 'Batch Size', [$this, 'batch_size_callback'], 'hs-wc-importer', 'hs_wc_importer_main');
        add_settings_field('csv_delimiter', 'CSV Delimiter', [$this, 'csv_delimiter_callback'], 'hs-wc-importer', 'hs_wc_importer_main');
        add_settings_field('enable_cron', 'Enable Cron Import', [$this, 'enable_cron_callback'], 'hs-wc-importer', 'hs_wc_importer_main');
        add_settings_field('cron_interval', 'Cron Interval', [$this, 'cron_interval_callback'], 'hs-wc-importer', 'hs_wc_importer_main');
        add_settings_field('notification_email', 'Notification Email', [$this, 'notification_email_callback'], 'hs-wc-importer', 'hs_wc_importer_main');
    }

    public function csv_path_callback() {
        $value = isset($this->settings['csv_path']) ? $this->settings['csv_path'] : ABSPATH . '../var/import/products.csv';
        echo '<input type="text" name="hs_wc_importer_settings[csv_path]" value="' . esc_attr($value) . '" size="60" />';
        echo '<p class="description">Full server path to CSV file. Example: /httpdocs/var/import/products.csv</p>';
    }

    public function batch_size_callback() {
        $value = isset($this->settings['batch_size']) ? $this->settings['batch_size'] : 200;
        echo '<input type="number" name="hs_wc_importer_settings[batch_size]" value="' . esc_attr($value) . '" min="50" max="1000" />';
    }

    public function csv_delimiter_callback() {
        $value = isset($this->settings['csv_delimiter']) ? $this->settings['csv_delimiter'] : ',';
        echo '<input type="text" name="hs_wc_importer_settings[csv_delimiter]" value="' . esc_attr($value) . '" size="1" />';
    }

    public function enable_cron_callback() {
        $value = isset($this->settings['enable_cron']) ? $this->settings['enable_cron'] : true;
        echo '<input type="checkbox" name="hs_wc_importer_settings[enable_cron]" value="1" ' . checked(1, $value, false) . ' />';
    }

    public function cron_interval_callback() {
        $value = isset($this->settings['cron_interval']) ? $this->settings['cron_interval'] : 'hourly';
        $schedules = wp_get_schedules();
        echo '<select name="hs_wc_importer_settings[cron_interval]">';
        foreach ($schedules as $key => $schedule) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($schedule['display']) . '</option>';
        }
        echo '</select>';
    }

    public function notification_email_callback() {
        $value = isset($this->settings['notification_email']) ? $this->settings['notification_email'] : get_option('admin_email');
        echo '<input type="email" name="hs_wc_importer_settings[notification_email]" value="' . esc_attr($value) . '" />';
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>High-Speed WooCommerce Importer</h1>

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
                <h2>Run Import</h2>
                <form method="post">
                    <?php wp_nonce_field('hs_wc_import', 'hs_wc_import_nonce'); ?>
                    <p>Import products from CSV path:</p>
                    <p><strong><?php echo esc_html($this->settings['csv_path']); ?></strong></p>
                    <p><input type="submit" name="start_import" class="button button-primary" value="Start Import Now" /></p>
                </form>
            </div>
        </div>
        <?php

        if (isset($_POST['start_import']) && check_admin_referer('hs_wc_import', 'hs_wc_import_nonce')) {
            $csv_path = $this->settings['csv_path'];
            if ($csv_path && file_exists($csv_path)) {
                $result = $this->import_from_csv($csv_path);
                if ($result) {
                    echo '<div class="updated"><p>Import completed successfully! Processed ' . $this->total_processed . ' products.</p></div>';
                } else {
                    echo '<div class="error"><p>Import failed. Check logs for details.</p></div>';
                }
            } else {
                echo '<div class="error"><p>CSV file not found at configured path: ' . esc_html($csv_path) . '</p></div>';
            }
        }
    }

    public function cron_import_handler() {
        $csv_path = isset($this->settings['csv_path']) ? $this->settings['csv_path'] : '';
        if (!$csv_path || !file_exists($csv_path)) {
            $this->log_error('Scheduled import failed: CSV file not found at ' . $csv_path);
            $this->send_notification('Import Failed', 'CSV file not found at scheduled path: ' . $csv_path);
            return false;
        }
        return $this->import_from_csv($csv_path);
    }

    // ----- The rest of your import logic remains the same -----
    // process_batch, process_product, map_product_data, create_product, update_product,
    // process_categories, process_images, upload_image_from_url, set_product_images,
    // process_attributes, process_meta_data, ensure_log_directory, log_info, log_error,
    // finalize_import, cleanup_old_logs, send_notification
}

new HighSpeed_WC_Importer();
