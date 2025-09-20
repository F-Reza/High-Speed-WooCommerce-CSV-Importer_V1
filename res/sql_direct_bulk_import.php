<?php
// sql_direct_bulk_import.php
// Bulk SQL importer optimized for very large catalogs (300k+)
// Usage: php sql_direct_bulk_import.php

require_once '/var/www/vhosts/omarket.gr/httpdocs/wp-load.php';
global $wpdb;

// ---------- CONFIG ----------
$csvFile      = '/var/www/vhosts/omarket.gr/httpdocs/var/import/kadro3test.csv';
$batch_size   = 5000;            // tune based on RAM & MySQL (2k-10k typical)
$log_file     = '/var/www/vhosts/omarket.gr/httpdocs/var/import/sql_bulk_import.log';
$author_id    = 1;
$limit_batches= 0;               // set >0 for testing
$start_from_row = 0;             // rows to skip (use when resuming)
// ---------- END CONFIG ----------

ini_set('memory_limit', '8G');
set_time_limit(0);
date_default_timezone_set('UTC');

function log_msg($s){
    global $log_file;
    file_put_contents($log_file, date('[Y-m-d H:i:s] ').$s.PHP_EOL, FILE_APPEND);
}

// quick checks
if (!file_exists($csvFile)) { echo "CSV not found: $csvFile\n"; exit(1); }
if (!isset($wpdb) || !is_object($wpdb)) { echo "Run inside WP environment (wp-load.php not loaded)\n"; exit(1); }

$handle = fopen($csvFile, 'r');
if (!$handle) { echo "Cannot open CSV\n"; exit(1); }

// read header
$header = fgetcsv($handle, 0, ',');
if (!$header) { echo "Header missing\n"; exit(1); }

// normalize header -> lowercase keys without BOM
$map = array_map(function($h){ return strtolower(trim($h)); }, $header);
$colIndex = array_flip($map);

// required columns (adjust as your CSV)
foreach (['name','sku','price','stock_quantity'] as $req) {
    if (!isset($colIndex[$req])) { echo "Missing column: $req\n"; exit(1); }
}

// Preload existing SKUs globally to avoid querying whole table each batch? 
// For 300k it's safe to preload sku->post_id map if memory allows. If you prefer per-batch SELECT, set $preload_skus=false
$preload_skus = true;
$global_sku_map = [];
if ($preload_skus) {
    log_msg("Preloading existing SKUs into memory...");
    $sql = "SELECT pm.post_id, pm.meta_value AS sku FROM {$wpdb->postmeta} pm WHERE pm.meta_key = '_sku'";
    $rows = $wpdb->get_results($sql, ARRAY_A);
    foreach ($rows as $r) {
        $global_sku_map[ $r['sku'] ] = intval($r['post_id']);
    }
    unset($rows);
    log_msg("Preloaded ".count($global_sku_map)." SKUs.");
}

$total_processed = 0;
$batch_count = 0;
$line_no = 1; // header read

if ($start_from_row > 0) {
    log_msg("Skipping $start_from_row rows to resume...");
    $skipped = 0;
    while ($skipped < $start_from_row && ($r = fgetcsv($handle,0,',')) !== FALSE) { $skipped++; $line_no++; }
}

log_msg("Starting import CSV: $csvFile");

while (!feof($handle)) {
    if ($limit_batches > 0 && $batch_count >= $limit_batches) break;

    $rows = [];
    $count = 0;
    while ($count < $batch_size && ($row = fgetcsv($handle,0,',')) !== FALSE) {
        $line_no++;
        // skip empty lines
        if (count($row) === 1 && trim($row[0]) === '') continue;
        // if row shorter than header skip
        if (count($row) < count($header)) { log_msg("Skipping malformed CSV line: $line_no"); continue; }

        // Build associative row keyed by lowercased header
        $assoc = [];
        foreach ($colIndex as $col => $idx) {
            $assoc[$col] = isset($row[$idx]) ? $row[$idx] : '';
        }
        $rows[] = $assoc;
        $count++;
    }

    if (empty($rows)) break;
    $batch_count++;

    // Build SKU list for this batch
    $skus = [];
    foreach ($rows as $r) {
        $sku = trim($r['sku']);
        if ($sku !== '') $skus[] = $sku;
    }
    if (empty($skus)) {
        log_msg("Batch {$batch_count}: no SKUs, skipping.");
        continue;
    }

    // Determine existing SKUs (either from global map or run SELECT)
    $sku_to_id = [];
    if ($preload_skus) {
        foreach ($skus as $s) if (isset($global_sku_map[$s])) $sku_to_id[$s] = $global_sku_map[$s];
    } else {
        // safe prepare placeholders
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $sql = $wpdb->prepare("SELECT post_id, meta_value AS sku FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value IN ($placeholders)", $skus);
        $res = $wpdb->get_results($sql, ARRAY_A);
        foreach ($res as $r) $sku_to_id[$r['sku']] = intval($r['post_id']);
        unset($res);
    }

    // Partition into inserts and updates
    $to_insert = []; $to_update = [];
    foreach ($rows as $r) {
        $sku = trim($r['sku']);
        if ($sku === '') continue;
        if (isset($sku_to_id[$sku])) {
            $r['post_id'] = $sku_to_id[$sku];
            $to_update[] = $r;
        } else {
            $to_insert[] = $r;
        }
    }

    // Begin transaction
    $wpdb->query('START TRANSACTION');

    // -----------------------
    // 1) Bulk insert into wp_posts for new products
    // -----------------------
    $new_post_ids = []; // index -> product_id
    if (!empty($to_insert)) {
        $values = [];
        foreach ($to_insert as $ins) {
            $name = esc_sql(trim($ins['name']));
            $slug = esc_sql(sanitize_title($ins['name']));
            $content = esc_sql(trim($ins['description'] ?? ''));
            $now = current_time('mysql');
            // column order: post_author, post_date, post_date_gmt, post_content, post_title, post_status, comment_status, ping_status, post_name, post_type, guid
            $guid = esc_sql(home_url('/?post_type=product&name='.$slug));
            $values[] = "({$author_id},'{$now}','{$now}','{$content}','{$name}','publish','closed','closed','{$slug}','product','{$guid}')";
        }
        if (!empty($values)) {
            $sql = "INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_status, comment_status, ping_status, post_name, post_type, guid) VALUES ".implode(',', $values);
            $wpdb->query($sql);
            $first_id = $wpdb->insert_id;
            $n = count($to_insert);
            for ($i=0;$i<$n;$i++) $new_post_ids[$i] = $first_id + $i;
        }
    }

    // -----------------------
    // 2) Prepare bulk postmeta rows (for updates: delete keys then re-insert; for new: just insert)
    // -----------------------
    $meta_rows = []; // strings "(post_id,'meta_key','meta_value')"
    $del_update_ids = [];
    if (!empty($to_update)) {
        foreach ($to_update as $u) {
            $del_update_ids[] = intval($u['post_id']);
        }
        // remove old meta keys we will replace for updates (reduce duplicates)
        if (!empty($del_update_ids)) {
            $id_list = implode(',', $del_update_ids);
            // only remove the keys we will re-insert
            $keys = array_map('esc_sql', ['_sku','_price','_regular_price','_stock','_manage_stock','_stock_status','_visibility','_external_image_url','_external_gallery_urls']);
            $in_keys = "('".implode("','",$keys)."')";
            $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm WHERE pm.post_id IN ({$id_list}) AND pm.meta_key IN {$in_keys}");
        }
    }

    // build meta for updates
    if (!empty($to_update)) {
        foreach ($to_update as $u) {
            $pid = intval($u['post_id']);
            $sku = esc_sql(trim($u['sku']));
            $price = esc_sql(wc_format_decimal($u['price']));
            $stock = intval($u['stock_quantity']);
            $manage_stock = ($u['stock_quantity'] !== '' && $u['stock_quantity'] !== null) ? 'yes' : 'no';
            $stock_status = ($stock > 0) ? 'instock' : 'outofstock';
            $visibility = 'visible';
            $meta_rows[] = "({$pid},'_sku','{$sku}')";
            $meta_rows[] = "({$pid},'_price','{$price}')";
            $meta_rows[] = "({$pid},'_regular_price','{$price}')";
            $meta_rows[] = "({$pid},'_stock','".esc_sql((string)$stock)."')";
            $meta_rows[] = "({$pid},'_manage_stock','{$manage_stock}')";
            $meta_rows[] = "({$pid},'_stock_status','{$stock_status}')";
            $meta_rows[] = "({$pid},'_visibility','{$visibility}')";
            // images
            if (!empty($u['image_urls'])) {
                $urls = array_map('trim', explode(',', $u['image_urls']));
                $featured = esc_sql($urls[0] ?? '');
                $gallery = esc_sql(implode(',', array_slice($urls,1)));
                if ($featured !== '') $meta_rows[] = "({$pid},'_external_image_url','{$featured}')";
                if ($gallery !== '')  $meta_rows[] = "({$pid},'_external_gallery_urls','{$gallery}')";
            }
        }
    }

    // build meta for inserts
    if (!empty($to_insert)) {
        $i = 0;
        foreach ($to_insert as $ins) {
            if (!isset($new_post_ids[$i])) { $i++; continue; }
            $pid = intval($new_post_ids[$i]);
            $sku = esc_sql(trim($ins['sku']));
            $price = esc_sql(wc_format_decimal($ins['price']));
            $stock = intval($ins['stock_quantity']);
            $manage_stock = ($ins['stock_quantity'] !== '' && $ins['stock_quantity'] !== null) ? 'yes' : 'no';
            $stock_status = ($stock > 0) ? 'instock' : 'outofstock';
            $visibility = 'visible';
            $meta_rows[] = "({$pid},'_sku','{$sku}')";
            $meta_rows[] = "({$pid},'_price','{$price}')";
            $meta_rows[] = "({$pid},'_regular_price','{$price}')";
            $meta_rows[] = "({$pid},'_stock','".esc_sql((string)$stock)."')";
            $meta_rows[] = "({$pid},'_manage_stock','{$manage_stock}')";
            $meta_rows[] = "({$pid},'_stock_status','{$stock_status}')";
            $meta_rows[] = "({$pid},'_visibility','{$visibility}')";
            if (!empty($ins['image_urls'])) {
                $urls = array_map('trim', explode(',', $ins['image_urls']));
                $featured = esc_sql($urls[0] ?? '');
                $gallery = esc_sql(implode(',', array_slice($urls,1)));
                if ($featured !== '') $meta_rows[] = "({$pid},'_external_image_url','{$featured}')";
                if ($gallery !== '')  $meta_rows[] = "({$pid},'_external_gallery_urls','{$gallery}')";
            }
            $i++;
        }
    }

    // Insert meta in one go
    if (!empty($meta_rows)) {
        // chunk large meta inserts to avoid exceeding max_allowed_packet
        $chunks = array_chunk($meta_rows, 2000);
        foreach ($chunks as $chunk) {
            $sql = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ".implode(',', $chunk);
            $wpdb->query($sql);
        }
    }

    // -----------------------
    // 3) Term relationships (categories & brands) - bulk insert
    // -----------------------
    // Build a map of term name -> term_id for categories (product_cat) and brand taxonomy (product_brand) using direct lookups and creating missing terms
    // We'll gather unique term names in this batch, ensure they exist, then bulk-insert term_relationships.

    $term_names_cat = []; $term_names_brand = [];
    foreach (array_merge($to_insert, $to_update) as $r) {
        if (!empty($r['category'])) $term_names_cat[trim($r['category'])] = true;
        if (!empty($r['manufacturer'])) $term_names_brand[trim($r['manufacturer'])] = true;
    }
    $term_map_cat = []; $term_map_brand = [];

    // helper to ensure terms exist and return id map
    $ensure_terms = function($names, $taxonomy) use ($wpdb, &$term_map_cat, &$term_map_brand) {
        $map = [];
        if (empty($names)) return $map;
        $names = array_keys($names);
        // lookup existing
        $in = "('".implode("','", array_map('esc_sql',$names))."')";
        $sql = "SELECT t.term_id, t.name FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id WHERE tt.taxonomy = '".esc_sql($taxonomy)."' AND t.name IN {$in}";
        $res = $wpdb->get_results($sql, ARRAY_A);
        $found = [];
        foreach ($res as $r) { $map[$r['name']] = intval($r['term_id']); $found[$r['name']] = true; }
        // insert missing terms via wp_insert_term (safe)
        foreach ($names as $n) {
            if (isset($found[$n])) continue;
            $ins = wp_insert_term($n, $taxonomy);
            if (!is_wp_error($ins) && isset($ins['term_id'])) {
                $map[$n] = intval($ins['term_id']);
            } else {
                // try fallback: manual insert into terms & term_taxonomy
                $slug = sanitize_title($n);
                $wpdb->insert($wpdb->terms, ['name' => $n, 'slug' => $slug]);
                $tid = $wpdb->insert_id;
                if ($tid) {
                    $wpdb->insert($wpdb->term_taxonomy, ['term_id' => $tid, 'taxonomy' => $taxonomy, 'count' => 0]);
                    $map[$n] = $tid;
                }
            }
        }
        return $map;
    };

    // ensure category and brand ids
    $term_map_cat = $ensure_terms($term_names_cat, 'product_cat');
    $term_map_brand = $ensure_terms($term_names_brand, 'product_brand');

    $term_rel_rows = []; // "(object_id,term_taxonomy_id,term_order)"
    // for updates: set new relationships (we'll delete existing product_cat/product_brand relationships first)
    $all_product_ids_to_set_terms = [];
    foreach ($to_update as $u) $all_product_ids_to_set_terms[] = intval($u['post_id']);
    foreach ($to_insert as $idx => $ins) {
        if (isset($new_post_ids[$idx])) $all_product_ids_to_set_terms[] = intval($new_post_ids[$idx]);
    }
    $all_product_ids_to_set_terms = array_unique($all_product_ids_to_set_terms);
    if (!empty($all_product_ids_to_set_terms)) {
        $idlist = implode(',', $all_product_ids_to_set_terms);
        // delete old relationships for these products for the taxonomies we will set
        $taxonomies = "('product_cat','product_brand')";
        $sql = "DELETE tr FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id IN ({$idlist}) AND tt.taxonomy IN {$taxonomies}";
        $wpdb->query($sql);
    }

    // build new term relationships
    // updates
    foreach ($to_update as $u) {
        $pid = intval($u['post_id']);
        if (!empty($u['category']) && isset($term_map_cat[trim($u['category'])])) {
            $tid = intval($term_map_cat[trim($u['category'])]);
            // find term_taxonomy_id
            $tt = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_cat' LIMIT 1", $tid));
            if ($tt) $term_rel_rows[] = "({$pid},{$tt},0)";
        }
        if (!empty($u['manufacturer']) && isset($term_map_brand[trim($u['manufacturer'])])) {
            $tid = intval($term_map_brand[trim($u['manufacturer'])]);
            $tt = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_brand' LIMIT 1", $tid));
            if ($tt) $term_rel_rows[] = "({$pid},{$tt},0)";
        }
    }
    // inserts
    foreach ($to_insert as $idx => $ins) {
        if (!isset($new_post_ids[$idx])) continue;
        $pid = intval($new_post_ids[$idx]);
        if (!empty($ins['category']) && isset($term_map_cat[trim($ins['category'])])) {
            $tid = intval($term_map_cat[trim($ins['category'])]);
            $tt = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_cat' LIMIT 1", $tid));
            if ($tt) $term_rel_rows[] = "({$pid},{$tt},0)";
        }
        if (!empty($ins['manufacturer']) && isset($term_map_brand[trim($ins['manufacturer'])])) {
            $tid = intval($term_map_brand[trim($ins['manufacturer'])]);
            $tt = $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_brand' LIMIT 1", $tid));
            if ($tt) $term_rel_rows[] = "({$pid},{$tt},0)";
        }
    }

    if (!empty($term_rel_rows)) {
        $chunks = array_chunk($term_rel_rows, 2000);
        foreach ($chunks as $chunk) {
            $sql = "INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES ".implode(',', $chunk);
            $wpdb->query($sql);
        }
    }

    // After all new term_relationships inserted, update term_taxonomy.count for affected term_taxonomy_ids
    $affected_tt_ids = [];
    if (!empty($term_rel_rows)) {
        // collect unique tt ids from inserted rows
        foreach ($term_rel_rows as $r) {
            // r is "(object_id,tt_id,0)" -> extract tt_id
            if (preg_match('/\([0-9]+,([0-9]+),0\)/', $r, $m)) $affected_tt_ids[] = intval($m[1]);
        }
        $affected_tt_ids = array_unique($affected_tt_ids);
        foreach ($affected_tt_ids as $ttid) {
            // recompute count
            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d", $ttid));
            $wpdb->update($wpdb->term_taxonomy, ['count' => intval($count)], ['term_taxonomy_id' => $ttid]);
        }
    }

    // -----------------------
    // 4) Update wc_product_meta_lookup via INSERT ... ON DUPLICATE KEY UPDATE
    // -----------------------
    $lookup_rows = [];
    // updates
    foreach ($to_update as $u) {
        $pid = intval($u['post_id']);
        $price = (float) wc_format_decimal($u['price']);
        $stock = intval($u['stock_quantity']);
        $stock_status = ($stock>0) ? 'instock' : 'outofstock';
        $lookup_rows[] = "({$pid},{$price},{$price},{$stock},'{$stock_status}')";
    }
    // inserts
    if (!empty($to_insert)) {
        $i=0;
        foreach ($to_insert as $ins) {
            if (!isset($new_post_ids[$i])) { $i++; continue; }
            $pid = intval($new_post_ids[$i]);
            $price = (float) wc_format_decimal($ins['price']);
            $stock = intval($ins['stock_quantity']);
            $stock_status = ($stock>0) ? 'instock' : 'outofstock';
            $lookup_rows[] = "({$pid},{$price},{$price},{$stock},'{$stock_status}')";
            $i++;
        }
    }
    if (!empty($lookup_rows)) {
        $lookup_table = $wpdb->prefix.'wc_product_meta_lookup';
        $chunks = array_chunk($lookup_rows, 1000);
        foreach ($chunks as $chunk) {
            $sql = "INSERT INTO {$lookup_table} (product_id, min_price, max_price, stock_quantity, stock_status) VALUES ".implode(',', $chunk)
                 . " ON DUPLICATE KEY UPDATE min_price=VALUES(min_price), max_price=VALUES(max_price), stock_quantity=VALUES(stock_quantity), stock_status=VALUES(stock_status)";
            $wpdb->query($sql);
        }
    }

    // Commit batch
    $wpdb->query('COMMIT');

    // update global sku map for newly inserted products (so subsequent batches detect them)
    if ($preload_skus && !empty($to_insert) && !empty($new_post_ids)) {
        $i = 0;
        foreach ($to_insert as $ins) {
            if (!isset($new_post_ids[$i])) { $i++; continue; }
            $global_sku_map[ trim($ins['sku']) ] = intval($new_post_ids[$i]);
            $i++;
        }
    }

    $processed = count($to_insert) + count($to_update);
    $total_processed += $processed;
    log_msg("Batch {$batch_count}: processed {$processed} rows (inserted=".count($to_insert).", updated=".count($to_update).") total={$total_processed}");
    echo "Batch {$batch_count}: processed {$processed} rows (ins=".count($to_insert).", upd=".count($to_update).") total={$total_processed}\n";

    // free memory
    unset($rows, $to_insert, $to_update, $meta_rows, $term_rel_rows, $lookup_rows);

} // end while

fclose($handle);

log_msg("Import finished. total_processed={$total_processed}");
echo "Finished. total_processed={$total_processed}\n";
