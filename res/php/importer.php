<?php
/**
 * WooCommerce CSV Importer (Optimized Hybrid for Large CSVs, Fixed Gallery Images)
 */

require_once('/var/www/vhosts/omarket.gr/httpdocs/wp-load.php');

// ==== TAXONOMY for Brands ====
$brand_tax = 'product_brand';
if (!taxonomy_exists($brand_tax)) {
    register_taxonomy($brand_tax, 'product', [
        'hierarchical' => false,
        'label'        => 'Brands',
        'query_var'    => true,
        'rewrite'      => ['slug' => 'product_brand'],
    ]);
}

// ==== SETTINGS ====
$csvFile        = '/var/www/vhosts/omarket.gr/httpdocs/var/import/kadro5test.csv'; // CSV path
$batch_size     = 50; // adjust as needed
$static_gallery_ids = [1234, 1235, 1236, 1237]; // Media IDs
$logFile        = '/var/www/vhosts/omarket.gr/httpdocs/var/logs/import.log';

// ==== MEMORY & TIME ====
ini_set('memory_limit', '7024M');
set_time_limit(0);

// ==== LOGGING FUNCTION ====
function log_message($msg) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND | LOCK_EX);
    echo "[$timestamp] $msg\n";
}

// ==== DEFER WP COUNTING ====
wp_defer_term_counting(true);
wp_defer_comment_counting(true);

// ==== SKU MAP ====
global $wpdb;
$sku_map = [];
$results = $wpdb->get_results("SELECT p.ID, pm.meta_value as sku 
                               FROM {$wpdb->posts} p 
                               JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                               WHERE pm.meta_key = '_sku'");
foreach ($results as $row) {
    if ($row->sku) $sku_map[$row->sku] = $row->ID;
}

// ==== IMAGE CACHE ====
$image_cache = [];

// ==== CSV CHECK ====
if (!file_exists($csvFile)) {
    exit("❌ CSV file not found: $csvFile\n");
}

log_message("Starting import from: $csvFile");

// ==== OPEN CSV ====
$csv = new SplFileObject($csvFile);
$csv->setFlags(SplFileObject::READ_CSV);
$header = $csv->fgetcsv();

$row_count = 0;
$created   = 0;
$updated   = 0;
$batch     = [];

foreach ($csv as $row) {
    if ($row === [null]) continue;
    $batch[] = $row;
    $row_count++;

    if (count($batch) >= $batch_size) {
        process_batch($batch);
        $batch = [];
        log_message("Processed $row_count rows | Created: $created | Updated: $updated");
    }
}

// Process leftover batch
if (!empty($batch)) {
    process_batch($batch);
}

log_message("🏁 Import finished | Total rows: $row_count | Created: $created | Updated: $updated");

// ==== RE-ENABLE WP COUNTING ====
wp_defer_term_counting(false);
wp_defer_comment_counting(false);

// ==== FUNCTIONS ====

function process_batch($batch) {
    global $sku_map, $wpdb, $static_gallery_ids, $image_cache, $created, $updated, $brand_tax;

    foreach ($batch as $data) {
        $description    = trim($data[0]);
        $name           = trim($data[1]);
        $sku            = trim($data[2]);
        $image_url      = trim($data[3]);
        $price          = floatval(str_replace(',', '.', $data[4]));
        $category_name  = trim($data[5]);
        $stock_status   = 'instock';
        $stock_quantity = intval($data[7]);
        $manufacturer   = trim($data[8]);
        $slug           = sanitize_title($name);

        // ==== CATEGORY ====
        $category_id = 0;
        if ($category_name) {
            $term = term_exists($category_name, 'product_cat');
            if (!$term) $term = wp_insert_term($category_name, 'product_cat');
            if (!is_wp_error($term)) $category_id = (int)$term['term_id'];
        }

        // ==== IMAGES ====
        $featured_id = 0;
        $gallery_ids = $static_gallery_ids;

        if (!empty($image_url)) {
            $urls = array_map('trim', explode(',', $image_url));
            $featured_url = array_shift($urls);

            if (isset($image_cache[$featured_url])) {
                $featured_id = $image_cache[$featured_url];
            } else {
                $featured_id = attach_hybrid_image($featured_url);
                $image_cache[$featured_url] = $featured_id;
            }

            foreach ($urls as $extra_url) {
                if (isset($image_cache[$extra_url])) {
                    $img_id = $image_cache[$extra_url];
                } else {
                    $img_id = attach_hybrid_image($extra_url);
                    $image_cache[$extra_url] = $img_id;
                }
                if ($img_id) $gallery_ids[] = $img_id;
            }
        }

        // ==== CREATE OR UPDATE PRODUCT ====
        if (isset($sku_map[$sku])) {
            $product_id = $sku_map[$sku];

            wp_update_post([
                'ID'           => $product_id,
                'post_title'   => $name,
                'post_content' => $description,
                'post_name'    => $slug,
            ]);

            update_post_meta($product_id, '_sku', $sku);
            update_post_meta($product_id, '_regular_price', $price);
            update_post_meta($product_id, '_price', $price);
            update_post_meta($product_id, '_stock_status', $stock_status);
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_stock', $stock_quantity);

            if ($category_id) wp_set_post_terms($product_id, [$category_id], 'product_cat');
            if ($featured_id) set_post_thumbnail($product_id, $featured_id);
            if (!empty($gallery_ids)) update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
            if ($manufacturer) wp_set_object_terms($product_id, $manufacturer, $brand_tax, false);

            $updated++;
        } else {
            $product_id = wp_insert_post([
                'post_title'   => $name,
                'post_content' => $description,
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'post_name'    => $slug,
            ]);

            update_post_meta($product_id, '_sku', $sku);
            update_post_meta($product_id, '_regular_price', $price);
            update_post_meta($product_id, '_price', $price);
            update_post_meta($product_id, '_stock_status', $stock_status);
            update_post_meta($product_id, '_manage_stock', 'yes');
            update_post_meta($product_id, '_stock', $stock_quantity);

            if ($category_id) wp_set_post_terms($product_id, [$category_id], 'product_cat');
            if ($featured_id) set_post_thumbnail($product_id, $featured_id);
            if (!empty($gallery_ids)) update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
            if ($manufacturer) wp_set_object_terms($product_id, $manufacturer, $brand_tax, false);

            $created++;
            $sku_map[$sku] = $product_id;
        }
    }
}

/**
 * Attach image from URL without creating thumbnails
 */
function attach_hybrid_image($image_url) {
    global $wpdb;
    $upload_dir = wp_upload_dir();

    if (strpos($image_url, $upload_dir['baseurl']) !== false) {
        $relative_path = str_replace($upload_dir['baseurl'] . '/', '', $image_url);
        $file_path = $upload_dir['basedir'] . '/' . $relative_path;

        if (file_exists($file_path)) {
            $attachment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment'",
                $image_url
            ));
            if ($attachment_id) return (int)$attachment_id;

            $filetype = wp_check_filetype(basename($file_path), null);
            $attachment = [
                'guid'           => $image_url,
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name(basename($file_path)),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ];
            return wp_insert_attachment($attachment, $file_path);
        }
    }

    $filename = sanitize_file_name(basename($image_url));
    $file_basename = pathinfo($filename, PATHINFO_FILENAME);

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_title=%s LIMIT 1",
        $file_basename
    ));
    if ($existing) return (int)$existing;

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $tmp = download_url($image_url);
    if (is_wp_error($tmp)) return 0;

    $file = [
        'name'     => $filename,
        'tmp_name' => $tmp,
    ];

    $attachment_id = media_handle_sideload($file, 0);
    if (is_wp_error($attachment_id)) return 0;

    return $attachment_id; // No thumbnails
}
