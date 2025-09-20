<?php
// Bootstrap WP + Woo
require_once dirname(__FILE__, 4) . '/wp-load.php';

// Get CSV path from settings
$csv_path = get_option('hs_importer_csv_path', '/var/www/vhosts/yourdomain.com/httpdocs/var/import/products.csv');

if (!file_exists($csv_path)) {
echo "CSV not found: $csv_path\n";
exit(1);
}

echo "Starting import from: $csv_path\n";

// --- Batch Settings ---
$batch_size = 5000; // rows per batch
$offset_file = plugin_dir_path(__FILE__) . 'import_offset.txt';

// Load offset (resume position)
$start_row = file_exists($offset_file) ? (int) file_get_contents($offset_file) : 0;

$handle = fopen($csv_path, 'r');
if (!$handle) {
echo "Error opening CSV\n";
exit(1);
}

$row = -1;
$processed = 0;

while (($data = fgetcsv($handle, 1000, ",")) !== false) {
$row++;
if ($row < $start_row) continue; // skip until offset
if ($row == 0) continue; // skip header

// Example: [sku, name, price]
list($sku, $name, $price) = $data;

if (empty($sku)) continue;

$product_id = wc_get_product_id_by_sku($sku);

if ($product_id) {
// Update existing
$product = wc_get_product($product_id);
$product->set_name($name);
$product->set_regular_price($price);
$product->save();
echo "Updated: $sku\n";
} else {
// Create new
$product = new WC_Product_Simple();
$product->set_sku($sku);
$product->set_name($name);
$product->set_regular_price($price);
$product->save();
echo "Created: $sku\n";
}

$processed++;

// Stop after batch
if ($processed >= $batch_size) {
file_put_contents($offset_file, $row + 1);
echo "Batch finished! Processed $processed rows. Resume from row " . ($row + 1) . " next run.\n";
fclose($handle);
exit(0);
}
}

fclose($handle);

// Reset offset when done
unlink($offset_file);
echo "✅ Import finished! All rows processed.\n";