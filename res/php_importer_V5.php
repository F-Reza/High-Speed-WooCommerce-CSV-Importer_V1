<?php
require_once '/var/www/vhosts/omarket.gr/httpdocs/wp-load.php';

global $wpdb;

$csv_path = '/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/var/import/products.csv';
$log_file = '/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/sql_import.log';
$batch_size = 1000;

function log_msg($msg) {
    global $log_file;
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

if (!file_exists($csv_path)) {
    log_msg("CSV file not found: $csv_path");
    exit;
}

$handle = fopen($csv_path, 'r');
$header = fgetcsv($handle, 0, ',', '"');
if (!$header) { log_msg("CSV header not readable."); exit; }

$total = 0;
$batch = [];

while (($row = fgetcsv($handle, 0, ',', '"')) !== false) {
    if (count($row) !== count($header)) continue;
    $data = array_combine($header, $row);

    $sku   = $wpdb->esc_like($data['sku']);
    $name  = $wpdb->esc_like($data['name']);
    $price = floatval($data['price']);
    $qty   = intval($data['stock_quantity']);
    $status = $qty > 0 ? 'instock' : 'outofstock';

    // Prepare SQL rows
    $batch[] = [
        'sku' => $sku,
        'name' => $name,
        'price' => $price,
        'qty' => $qty,
        'status' => $status,
    ];

    if (count($batch) >= $batch_size) {
        insert_batch($batch, $wpdb);
        $total += count($batch);
        $batch = [];
        log_msg("Imported $total so far...");
    }
}

if (!empty($batch)) {
    insert_batch($batch, $wpdb);
    $total += count($batch);
}

fclose($handle);
log_msg("✅ Import finished. Total products inserted: $total.");

function insert_batch($batch, $wpdb) {
    foreach ($batch as $row) {
        // Insert product as "post"
        $wpdb->insert("{$wpdb->posts}", [
            'post_author' => 1,
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', 1),
            'post_content' => '',
            'post_title' => $row['name'],
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_type' => 'product'
        ]);
        $post_id = $wpdb->insert_id;

        // Meta: price, sku, stock
        $wpdb->insert("{$wpdb->postmeta}", ['post_id'=>$post_id,'meta_key'=>'_sku','meta_value'=>$row['sku']]);
        $wpdb->insert("{$wpdb->postmeta}", ['post_id'=>$post_id,'meta_key'=>'_price','meta_value'=>$row['price']]);
        $wpdb->insert("{$wpdb->postmeta}", ['post_id'=>$post_id,'meta_key'=>'_regular_price','meta_value'=>$row['price']]);
        $wpdb->insert("{$wpdb->postmeta}", ['post_id'=>$post_id,'meta_key'=>'_manage_stock','meta_value'=>'yes']);
        $wpdb->insert("{$wpdb->postmeta}", ['post_id'=>$post_id,'meta_key'=>'_stock','meta_value'=>$row['qty']]);
        $wpdb->insert("{$wpdb->postmeta}", ['post_id'=>$post_id,'meta_key'=>'_stock_status','meta_value'=>$row['status']]);
    }
}
