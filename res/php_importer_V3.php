<?php
require_once '/var/www/vhosts/omarket.gr/httpdocs/wp-load.php';

global $wpdb;

ini_set('memory_limit', '4G');
set_time_limit(0);

$csv_path = '/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/var/import/products.csv';
$batch_size = 1000; 
$log_file  = '/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/sql_import.log';

function log_msg($msg) {
    global $log_file;
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

if (!file_exists($csv_path)) {
    log_msg("CSV file not found: $csv_path");
    exit;
}

log_msg("Starting direct SQL import from: $csv_path");

$handle = fopen($csv_path, 'r');
if (!$handle) { log_msg("Could not open CSV file."); exit; }

$header = fgetcsv($handle, 0, ',', '"');
if (!$header) { log_msg("CSV header not readable."); exit; }

$total = 0;
$batch_count = 0;

while (!feof($handle)) {
    $batch_data = [];
    $count = 0;
    while ($count < $batch_size && ($row = fgetcsv($handle, 0, ',', '"')) !== FALSE) {
        if (count($row) === count($header)) {
            $batch_data[] = array_combine($header, $row);
            $count++;
        }
    }

    foreach ($batch_data as $data) {
        $sku   = sanitize_text_field($data['sku']);
        $name  = sanitize_text_field($data['name']);
        $price = wc_format_decimal($data['price']);
        $stock = intval($data['stock_quantity']);
        $stock_status = ($stock > 0) ? 'instock' : 'outofstock';

        if (empty($sku)) continue;

        // Check if SKU already exists
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1",
            $sku
        ));

        if ($existing_id) {
            // UPDATE product meta directly
            $wpdb->update($wpdb->posts, 
                [ 'post_title' => $name ], 
                [ 'ID' => $existing_id ]
            );

            update_post_meta($existing_id, '_price', $price);
            update_post_meta($existing_id, '_regular_price', $price);
            update_post_meta($existing_id, '_stock', $stock);
            update_post_meta($existing_id, '_manage_stock', 'yes');
            update_post_meta($existing_id, '_stock_status', $stock_status);

            // Lookup table update
            $wpdb->update("{$wpdb->prefix}wc_product_meta_lookup", [
                'min_price' => $price,
                'max_price' => $price,
                'stock_quantity' => $stock,
                'stock_status' => $stock_status
            ], ['product_id' => $existing_id]);

            log_msg("Updated SKU: $sku (ID: $existing_id)");
        } else {
            // INSERT new product into posts
            $wpdb->insert($wpdb->posts, [
                'post_author' => 1,
                'post_date' => current_time('mysql'),
                'post_date_gmt' => current_time('mysql', 1),
                'post_content' => '',
                'post_title' => $name,
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_name' => sanitize_title($name),
                'post_type' => 'product',
                'guid' => home_url('/?post_type=product&name=' . sanitize_title($name))
            ]);
            $product_id = $wpdb->insert_id;

            // Insert product meta
            add_post_meta($product_id, '_sku', $sku, true);
            add_post_meta($product_id, '_price', $price);
            add_post_meta($product_id, '_regular_price', $price);
            add_post_meta($product_id, '_stock', $stock);
            add_post_meta($product_id, '_manage_stock', 'yes');
            add_post_meta($product_id, '_stock_status', $stock_status);
            add_post_meta($product_id, '_visibility', 'visible');

            // Insert lookup table row
            $wpdb->insert("{$wpdb->prefix}wc_product_meta_lookup", [
                'product_id' => $product_id,
                'min_price' => $price,
                'max_price' => $price,
                'stock_quantity' => $stock,
                'stock_status' => $stock_status
            ]);

            log_msg("Created SKU: $sku (ID: $product_id)");
        }

        $total++;
    }

    $batch_count++;
    unset($batch_data);
    gc_collect_cycles();
}

fclose($handle);
log_msg("Import finished. Total products processed: $total. Batches: $batch_count.");
