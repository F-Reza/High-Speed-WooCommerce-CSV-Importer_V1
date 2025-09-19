Ah! I see exactly what you’re doing—you have a lightweight, SSH-run PHP importer that handles WooCommerce products, fixed gallery images, and avoids thumbnails. ✅

I can help you “fix and improve it” to make it more robust, especially for large CSVs (hundreds of thousands of rows) while keeping your workflow intact.


If you want, I can rewrite your full script using this structure:
Supports very large CSVs (300k rows)
Keeps your SSH workflow
Keeps fixed gallery images
Writes progress logs
Optimizes memory and image caching
This will make your import stable and more maintainable without changing your workflow.


I can rewrite your full PHP WooCommerce importer optimized for large CSVs, fixed gallery images, SSH execution, and memory efficiency. This will keep your current workflow, with logging and batch processing.

Here’s the complete script: importer.php

You can run it exactly like before:

nohup /opt/plesk/php/8.2/bin/php 
/var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/var_shopflixtestbest.php > /var/www/vhosts/omarket.gr/httpdocs/xmldeedsbestprice/var_shopflixtestbest.log &



✅ Features of this version:

Memory & time optimized for huge CSVs.

Uses SplFileObject for memory-efficient reading.

Batch processing with progress logs.

Keeps static gallery images and avoids creating thumbnails.

Logs every batch to a file (plus echo for SSH monitoring).

SKU map avoids duplicate imports.

Flexible: change CSV path, batch size, or gallery IDs at the top.