<?php
require_once '/var/www/vhosts/omarket.gr/httpdocs/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

if (!class_exists('WooCommerce')) {
die("WooCommerce is not active.");
}

global $wpdb;

ini_set('memory_limit', '4G');
set_time_limit(0);

$csv_path = '/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/var/import/products.csv';
$batch_size = 500;
$log_file = '/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/var_shopflixtestbest.log';

function log_msg($msg) {
global $log_file;
file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

if (!file_exists($csv_path)) {
log_msg("CSV file not found: $csv_path");
exit;
}

log_msg("Starting import from: $csv_path");

$handle = fopen($csv_path, 'r');
if (!$handle) {
log_msg("Could not open CSV file.");
exit;
}

$header = fgetcsv($handle, 0, ',', '"');
if (!$header) {
log_msg("CSV header not readable.");
exit;
}

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
$sku = sanitize_text_field($data['sku']);
if (empty($sku)) continue;

$product_data = [
'name' => sanitize_text_field($data['name']),
'sku' => $sku,
'price' => wc_format_decimal($data['price']),
'stock_quantity' => intval($data['stock_quantity']),
'manage_stock' => isset($data['stock_quantity']) && $data['stock_quantity'] !== '',
'stock_status' => (!empty($data['stock_quantity']) && intval($data['stock_quantity']) > 0) ? 'instock' : 'outofstock',
];

// Check if product exists
$existing_id = $wpdb->get_var($wpdb->prepare(
"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value=%s LIMIT 1",
$sku
));

if ($existing_id) {
$product = wc_get_product($existing_id);
if ($product) {
$product->set_name($product_data['name']);
$product->set_regular_price($product_data['price']);
$product->set_manage_stock($product_data['manage_stock']);
if ($product_data['manage_stock']) $product->set_stock_quantity($product_data['stock_quantity']);
$product->set_stock_status($product_data['stock_status']);
$product->save();
log_msg("Updated product SKU: $sku (ID: $existing_id)");
}
} else {
$product = new WC_Product();
$product->set_name($product_data['name']);
$product->set_sku($product_data['sku']);
$product->set_regular_price($product_data['price']);
$product->set_manage_stock($product_data['manage_stock']);
if ($product_data['manage_stock']) $product->set_stock_quantity($product_data['stock_quantity']);
$product->set_stock_status($product_data['stock_status']);
$product->save();
log_msg("Created new product SKU: $sku (ID: {$product->get_id()})");
}

$total++;
}

$batch_count++;
unset($batch_data);
gc_collect_cycles();
}

fclose($handle);
log_msg("Import finished. Total products processed: $total. Batches: $batch_count.");