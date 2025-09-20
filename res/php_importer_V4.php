<?php
// sql_direct_importer.php
// Direct SQL bulk importer for large WooCommerce catalogs (300k+ rows)
// Run from CLI: php sql_direct_importer.php

// --- CONFIG ---
require_once '/var/www/vhosts/omarket.gr/httpdocs/wp-load.php'; // adjust if needed
global $wpdb;

ini_set('memory_limit', '6G');
set_time_limit(0);

$csv_path     = '/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/var/import/products.csv';
$log_file     = '/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/sql_direct_importer.log';
$batch_size   = 2000;   // tune: how many CSV rows per batch. 2k is a good start for 300k.
$author_id    = 1;      // post_author for created products
$start_from_row = 1;    // if you want to resume: skip header + N rows (set to 0 to start at top)
$limit_batches = 0;     // set >0 to limit batches for testing, 0 = unlimited
// --- END CONFIG ---

function log_msg($msg) {
    global $log_file;
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

if (!file_exists($csv_path)) {
    echo "CSV not found: $csv_path\n";
    log_msg("CSV not found: $csv_path");
    exit(1);
}

if (!isset($wpdb) || !is_object($wpdb)) {
    echo "This script must be run in a WordPress environment (wp-load.php failed).\n";
    exit(1);
}

$handle = fopen($csv_path, 'r');
if (!$handle) {
    log_msg("Could not open CSV file.");
    exit(1);
}

// Read header
$header = fgetcsv($handle, 0, ',', '"');
if (!$header) {
    log_msg("CSV header not readable.");
    exit(1);
}

// Map header to positions (lowercase trim)
$map = array_map(function($h){ return trim(strtolower($h)); }, $header);
$colIndex = array_flip($map);

// Required columns: sku, name, price, stock_quantity
foreach (['sku','name','price','stock_quantity'] as $col) {
    if (!isset($colIndex[$col])) {
        log_msg("Missing required CSV column: $col");
        echo "Missing required CSV column: $col\n";
        exit(1);
    }
}

$total_processed = 0;
$batch_count = 0;
$line_no = 1; // header already read

if ($start_from_row > 0) {
    // skip start_from_row rows (useful to resume)
    $skipped = 0;
    while ($skipped < $start_from_row && ($r = fgetcsv($handle, 0, ',', '"')) !== FALSE) {
        $skipped++;
        $line_no++;
    }
    log_msg("Skipped $skipped rows (resuming).");
}

log_msg("Starting direct SQL import from: $csv_path");

while (!feof($handle)) {
    if ($limit_batches > 0 && $batch_count >= $limit_batches) break;

    $batch = [];
    $rows = 0;
    while ($rows < $batch_size && ($row = fgetcsv($handle, 0, ',', '"')) !== FALSE) {
        $line_no++;
        // Skip empty lines
        if (count($row) === 1 && trim($row[0]) === '') continue;
        // ensure same column count
        if (count($row) < count($header)) {
            // skip malformed row, but log it
            log_msg("Skipping malformed CSV line $line_no");
            continue;
        }
        // build associative row
        $assoc = [];
        foreach ($colIndex as $col => $idx) {
            $assoc[$col] = isset($row[$idx]) ? $row[$idx] : '';
        }
        $batch[] = $assoc;
        $rows++;
    }

    if (empty($batch)) break;

    $batch_count++;
    $skus = [];
    foreach ($batch as $r) {
        $sku = trim($r['sku']);
        if ($sku !== '') $skus[] = $sku;
    }
    if (empty($skus)) {
        log_msg("Batch $batch_count: no SKUs found, skipping.");
        continue;
    }

    // Prepare placeholders for IN()
    $placeholders = implode(',', array_fill(0, count($skus), '%s'));
    $sql = $wpdb->prepare(
        "SELECT post_id, meta_value AS sku FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value IN ($placeholders)",
        $skus
    );
    $existing_rows = $wpdb->get_results($sql, ARRAY_A);

    // Map sku => post_id
    $sku_to_id = [];
    foreach ($existing_rows as $er) {
        $sku_to_id[$er['sku']] = intval($er['post_id']);
    }

    // Partition batch into $to_update (sku exists) and $to_insert (sku new)
    $to_update = []; $to_insert = [];
    foreach ($batch as $r) {
        $sku = trim($r['sku']);
        if ($sku === '') continue;
        if (isset($sku_to_id[$sku])) {
            $r['post_id'] = $sku_to_id[$sku];
            $to_update[] = $r;
        } else {
            $to_insert[] = $r;
        }
    }

    // Begin transaction for the batch
    $wpdb->query('START TRANSACTION');

    // 1) UPDATE existing products: update post_title and prepare meta rows to replace
    if (!empty($to_update)) {
        // Bulk update posts' post_title using CASE ... WHEN
        $cases = [];
        $ids = [];
        foreach ($to_update as $u) {
            $id = intval($u['post_id']);
            $title = $wpdb->escape($u['name']); // escaping content for SQL
            $cases[] = "WHEN ID = {$id} THEN '" . esc_sql($u['name']) . "'";
            $ids[] = $id;
        }
        if (!empty($cases)) {
            $case_sql = implode(' ', $cases);
            $id_list  = implode(',', $ids);
            $sql_up_posts = "UPDATE {$wpdb->posts} SET post_title = CASE {$case_sql} END WHERE ID IN ({$id_list})";
            $wpdb->query($sql_up_posts);
        }

        // Delete meta keys for these product ids for keys we will re-insert (clean slate)
        $meta_keys = ['_price','_regular_price','_stock','_manage_stock','_stock_status','_sku','_visibility'];
        $id_list = implode(',', $ids);
        $meta_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        // build safe delete: delete only meta keys for these posts
        $in_keys = "('" . implode("','", array_map('esc_sql', $meta_keys)) . "')";
        $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm WHERE pm.post_id IN ({$id_list}) AND pm.meta_key IN {$in_keys}");
        // We'll re-insert below via bulk insert
    }

    // 2) INSERT new products into wp_posts in bulk
    $new_post_ids = []; // will map temporary idx -> inserted ID
    if (!empty($to_insert)) {
        $values = [];
        foreach ($to_insert as $ins) {
            $name = sanitize_text_field($ins['name']);
            $slug = sanitize_title($name);
            $now  = current_time('mysql');
            // guid: do not rely on pretty permalink; set to site url + ?post_type=product&name=slug to be safe
            $guid = esc_sql(home_url('/?post_type=product&name=' . $slug));
            $post_content = ''; // you can add description if present in CSV
            $post_status  = 'publish';
            $post_type    = 'product';
            // escape values
            $values[] = "('{$author_id}','{$now}','{$now}','{$post_content}','" . esc_sql($name) . "','{$post_status}','closed','closed','{$slug}','{$post_type}','{$guid}')";
        }

        if (!empty($values)) {
            // The column order must match
            // post_author, post_date, post_date_gmt, post_content, post_title, post_status, comment_status, ping_status, post_name, post_type, guid
            $sql_insert_posts = "INSERT INTO {$wpdb->posts} 
                (post_author, post_date, post_date_gmt, post_content, post_title, post_status, comment_status, ping_status, post_name, post_type, guid)
                VALUES " . implode(',', $values);
            $wpdb->query($sql_insert_posts);
            $first_insert_id = $wpdb->insert_id; // id of first row inserted in this batch (MySQL behavior)
            $num_inserted = count($to_insert);
            // estimate IDs assigned (consecutive)
            for ($i = 0; $i < $num_inserted; $i++) {
                $new_post_ids[$i] = $first_insert_id + $i;
            }
        }
    }

    // 3) Build bulk postmeta inserts for both new and updated products
    // We'll create a single multi-row INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (...)
    $meta_rows = [];
    // Helper to add meta
    $add_meta = function($post_id, $key, $value) use (&$meta_rows) {
        $post_id = intval($post_id);
        $key_s = esc_sql($key);
        // store as string; WooCommerce expects string values for many meta fields
        $value_s = esc_sql((string)$value);
        $meta_rows[] = "({$post_id},'{$key_s}','{$value_s}')";
    };

    // For updated products
    if (!empty($to_update)) {
        foreach ($to_update as $u) {
            $pid = intval($u['post_id']);
            $sku = sanitize_text_field($u['sku']);
            $price = wc_format_decimal($u['price']);
            $stock = intval($u['stock_quantity']);
            $manage_stock = ($u['stock_quantity'] !== '' && $u['stock_quantity'] !== null) ? 'yes' : 'no';
            $stock_status = ($stock > 0) ? 'instock' : 'outofstock';
            $visibility = 'visible';

            $add_meta($pid, '_sku', $sku);
            $add_meta($pid, '_price', $price);
            $add_meta($pid, '_regular_price', $price);
            $add_meta($pid, '_stock', $stock);
            $add_meta($pid, '_manage_stock', $manage_stock);
            $add_meta($pid, '_stock_status', $stock_status);
            $add_meta($pid, '_visibility', $visibility);
        }
    }

    // For newly inserted products
    if (!empty($to_insert) && !empty($new_post_ids)) {
        $i = 0;
        foreach ($to_insert as $ins) {
            if (!isset($new_post_ids[$i])) { $i++; continue; }
            $pid = intval($new_post_ids[$i]);
            $sku = sanitize_text_field($ins['sku']);
            $price = wc_format_decimal($ins['price']);
            $stock = intval($ins['stock_quantity']);
            $manage_stock = ($ins['stock_quantity'] !== '' && $ins['stock_quantity'] !== null) ? 'yes' : 'no';
            $stock_status = ($stock > 0) ? 'instock' : 'outofstock';
            $visibility = 'visible';

            $add_meta($pid, '_sku', $sku);
            $add_meta($pid, '_price', $price);
            $add_meta($pid, '_regular_price', $price);
            $add_meta($pid, '_stock', $stock);
            $add_meta($pid, '_manage_stock', $manage_stock);
            $add_meta($pid, '_stock_status', $stock_status);
            $add_meta($pid, '_visibility', $visibility);

            $i++;
        }
    }

    if (!empty($meta_rows)) {
        // Because wp_postmeta.meta_id is auto-increment primary key and there is no unique constraint on (post_id,meta_key),
        // we deleted keys for updated products earlier, so bulk-inserting is safe; for new ones there is no conflict.
        $sql_insert_meta = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode(',', $meta_rows);
        $wpdb->query($sql_insert_meta);
    }

    // 4) Update/insert into wc_product_meta_lookup for both updated and new
    // Build rows for lookup table
    $lookup_rows = [];
    $lookup_pairs = []; // product_id => [price,stock,stock_status]
    if (!empty($to_update)) {
        foreach ($to_update as $u) {
            $pid = intval($u['post_id']);
            $price = (float) wc_format_decimal($u['price']);
            $stock = intval($u['stock_quantity']);
            $stock_status = ($stock > 0) ? 'instock' : 'outofstock';
            $lookup_pairs[$pid] = [$price, $stock, $stock_status];
        }
    }
    if (!empty($to_insert) && !empty($new_post_ids)) {
        $i = 0;
        foreach ($to_insert as $ins) {
            if (!isset($new_post_ids[$i])) { $i++; continue; }
            $pid = intval($new_post_ids[$i]);
            $price = (float) wc_format_decimal($ins['price']);
            $stock = intval($ins['stock_quantity']);
            $stock_status = ($stock > 0) ? 'instock' : 'outofstock';
            $lookup_pairs[$pid] = [$price, $stock, $stock_status];
            $i++;
        }
    }
    if (!empty($lookup_pairs)) {
        foreach ($lookup_pairs as $pid => $vals) {
            $min_price = (float)$vals[0];
            $max_price = (float)$vals[0];
            $stock_q = intval($vals[1]);
            $stock_s = esc_sql($vals[2]);
            $lookup_rows[] = "({$pid},{$min_price},{$max_price},{$stock_q},'{$stock_s}')";
        }
        if (!empty($lookup_rows)) {
            $lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
            // product_id is primary key on this table — use INSERT ... ON DUPLICATE KEY UPDATE
            $sql_lookup = "INSERT INTO {$lookup_table} (product_id,min_price,max_price,stock_quantity,stock_status) VALUES " . implode(',', $lookup_rows)
                      . " ON DUPLICATE KEY UPDATE min_price = VALUES(min_price), max_price = VALUES(max_price), stock_quantity = VALUES(stock_quantity), stock_status = VALUES(stock_status)";
            $wpdb->query($sql_lookup);
        }
    }

    // Commit transaction
    $wpdb->query('COMMIT');

    $processed_this_batch = count($to_insert) + count($to_update);
    $total_processed += $processed_this_batch;

    log_msg("Batch {$batch_count} processed: inserts=" . count($to_insert) . " updates=" . count($to_update) . " csv_rows={$rows}");
    echo "Batch {$batch_count}: inserted=" . count($to_insert) . " updated=" . count($to_update) . " total_processed={$total_processed}\n";

    // free batch memory
    unset($batch, $to_insert, $to_update, $meta_rows, $lookup_rows, $lookup_pairs, $sku_to_id, $new_post_ids);

    // small sleep optionally to reduce DB load (uncomment if needed)
    // usleep(10000); // 10ms
}

fclose($handle);

log_msg("Import finished. Total products processed: $total_processed. Batches: $batch_count.");
echo "Finished. Total products processed: $total_processed. Batches: $batch_count\n";
