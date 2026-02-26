<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Cache;

class DailyImportCommand extends Command
{
    protected $signature = 'import:daily {--force-days=} {--force : Force update all products regardless of timestamps}';
    protected $description = 'Run daily product import - Rug API as source of truth';

    private $logFilePath;
    private $cronTrackingKey = 'daily_import_last_successful_run';

    public function handle()
    {
        $this->log("╔══════════════════════════════════════════════════════════════╗");
        $this->log("║              DAILY PRODUCT IMPORT STARTED                    ║");
        $this->log("╚══════════════════════════════════════════════════════════════╝");
        $this->log("Started at: " . now()->format('Y-m-d H:i:s'));

        Log::info('Cron executed at: ' . now());

        try {
            $shops = Setting::all();

            if ($shops->isEmpty()) {
                return $this->fail("❌ No shops found in settings table.");
            }

            foreach ($shops as $shop) {
                $this->initLog($shop->shopify_store_url);

                if ($shop->shopify_store_url == 'rugs-simple.myshopify.com') {
                    $this->log("⏭️  Skipping shop: {$shop->shopify_store_url}");
                    continue;
                }

                if (!$shop->shopify_store_url || !$shop->api_key || !$shop->shopify_token) {
                    $this->log("⚠️ Skipping shop (missing credentials): {$shop->shopify_store_url}");
                    continue;
                }

                try {
                    $this->log("════════════════════════════════════════════════════════════════");
                    $this->log("🏪 SHOP: {$shop->shopify_store_url}");
                    $this->log("════════════════════════════════════════════════════════════════");

                    $this->processShop($shop);

                    $this->log("✅ Cron completed successfully for {$shop->shopify_store_url}");
                } catch (\Exception $innerEx) {
                    $this->log("❌ Error processing {$shop->shopify_store_url}: " . $innerEx->getMessage());
                    $this->log("   Stack trace: " . $innerEx->getTraceAsString());
                    Log::error("Shop processing error", [
                        'shop' => $shop->shopify_store_url,
                        'error' => $innerEx->getMessage(),
                        'trace' => $innerEx->getTraceAsString()
                    ]);
                }

                $this->log("-------------------------------------------------------------");
            }

            $this->markCronSuccess();

            $this->log("
╔══════════════════════════════════════════════════════════════╗");
            $this->log("║          🎉 ALL SHOP IMPORTS COMPLETED SUCCESSFULLY          ║");
            $this->log("╚══════════════════════════════════════════════════════════════╝");
            $this->log("Completed at: " . now()->format('Y-m-d H:i:s'));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->exceptionFail($e);
        }
    }

    /**
     * Process shop - Fetch from Rug API, then sync to Shopify
     */
    private function processShop($shop)
    {
        $settingsController = new SettingsController();

        $stats = [
            'total_rug_products' => 0,
            'recently_updated' => 0,
            'found_in_shopify' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unpublished' => 0,
            'errors' => 0
        ];

        // Get days to look back
        $daysToLookBack = $this->getDaysToLookBack($shop);
        $this->log("📅 Looking back {$daysToLookBack} days for updated products");

        $cutoffDate = Carbon::now()->subDays($daysToLookBack);
        $this->log("📅 Cutoff Date: {$cutoffDate->toIso8601String()}");

        // Get Rug API token
        $token = $this->getRugApiToken($shop);
        if (!$token) {
            throw new \Exception("Failed to get Rug API token");
        }

        // Fetch all products from Rug API
        $allRugProducts = $this->fetchAllRugProducts($token);
        $stats['total_rug_products'] = count($allRugProducts);
        $this->log("📦 Total products from Rug API: {$stats['total_rug_products']}");

        if (empty($allRugProducts)) {
            $this->log("⚠️ No products fetched from Rug API");
            return;
        }

        // Filter by date
        $recentlyUpdatedProducts = $this->filterRugProductsByDate($allRugProducts, $cutoffDate);
        $stats['recently_updated'] = count($recentlyUpdatedProducts);
        $this->log("🔍 Products updated in last {$daysToLookBack} days: {$stats['recently_updated']}");

        // Process products
        $recentlyUpdatedProducts = $settingsController->process_product_data($recentlyUpdatedProducts);

        if (empty($recentlyUpdatedProducts)) {
            $this->log("✓ No recently updated products to process");
            return;
        }

        // Process in batches
        $batchSize = 50;
        $batches = array_chunk($recentlyUpdatedProducts, $batchSize);
        $this->log("📋 Total SKUs to process: {$stats['recently_updated']} (in " . count($batches) . " batches)");

        foreach ($batches as $batchNumber => $batch) {
            $this->log("🔄 Processing batch " . ($batchNumber + 1) . "/" . count($batches) . " (" . count($batch) . " products)");

            foreach ($batch as $rugProduct) {
                $sku = $rugProduct['ID'] ?? '';

                if (empty($sku)) {
                    $this->log("⚠️ Skipping product with no SKU");
                    $stats['errors']++;
                    continue;
                }

                try {
                    $this->log("📦 Processing SKU: {$sku}");

                    // Check if product exists in Shopify
                    $shopifyProduct = $this->getShopifyProductBySKU($shop, $sku);

                    if (!$shopifyProduct) {
                        // Product not found - insert new
                        $this->log("⊘ Not found in Shopify - inserting new product");

                        $inserted = $this->insertNewProduct($rugProduct, $shop->shopify_store_url);

                        if ($inserted) {
                            $this->log("   ✅ Successfully inserted");
                            $stats['inserted']++;
                        } else {
                            $this->log("   ❌ Failed to insert");
                            $stats['errors']++;
                        }

                        continue;
                    }

                    // Product found - update it
                    $stats['found_in_shopify']++;
                    $this->log("   ✅ Found in Shopify - Product ID: {$shopifyProduct['id']}");

                    // Fetch metafields
                    $metafields = $this->getShopifyProductMetafields($shop, $shopifyProduct['id']);
                    $shopifyProduct['metafields'] = $metafields;

                    // Find matching variant
                    $variant = null;
                    foreach ($shopifyProduct['variants'] as $v) {
                        if ($v['sku'] === $sku) {
                            $variant = $v;
                            break;
                        }
                    }

                    if (!$variant) {
                        $this->log("   ⚠️ No variant found matching SKU {$sku}");
                        $stats['errors']++;
                        continue;
                    }

                    // Prepare shopify data
                    $shopifyData = [
                        'sku' => $sku,
                        'product_id' => $shopifyProduct['id'],
                        'variant_id' => $variant['id'],
                        'title' => $shopifyProduct['title'],
                        'metafields' => $metafields,
                        'full_product' => $shopifyProduct,
                    ];

                    // Check if we should update (force mode or timestamp comparison)
                    $forceUpdate = $this->option('force');
                    $shouldUpdate = $forceUpdate;

                    if (!$forceUpdate) {
                        $metafieldUpdatedAt = null;
                        foreach ($metafields as $metafield) {
                            if ($metafield['namespace'] === 'custom' && $metafield['key'] === 'updated_at') {
                                $metafieldUpdatedAt = $metafield['value'];
                                break;
                            }
                        }

                        if (empty($metafieldUpdatedAt)) {
                            $shouldUpdate = true;
                            $this->log("   ℹ️ No custom.updated_at - will update");
                        } else {
                            $rugTimestamp = strtotime($rugProduct['updated_at']);
                            $shopifyTimestamp = strtotime($metafieldUpdatedAt);

                            if ($rugTimestamp >= $shopifyTimestamp) {
                                $shouldUpdate = true;
                                $diff = $rugTimestamp - $shopifyTimestamp;
                                $this->log("   🔄 Rug API is newer by {$diff}s - will update");
                            } else {
                                $this->log("   ⏭️ Shopify is newer - skipping");
                            }
                        }
                    } else {
                        $this->log("   🔨 FORCE MODE - updating");
                    }

                    if ($shouldUpdate) {
                        $updated = $this->updateShopifyProduct($rugProduct, $shopifyData, $shop);

                        if ($updated) {
                            $stats['updated']++;

                            // Handle publish/unpublish status
                            $status = $rugProduct['status'] ?? '';
                            $inventoryQty = $rugProduct['inventory']['quantityLevel'][0]['available'] ?? null;

                            // Check inventory first - if 0, always unpublish
                            if ($inventoryQty !== null && $inventoryQty <= 0) {
                                $this->unpublishProduct($shop, $shopifyProduct['id']);
                                $stats['unpublished']++;
                                $this->log("   📴 Product unpublished (inventory: 0)");
                            }
                            // If inventory > 0, then check status field
                            else if ($status === 'available') {
                                $this->publishProduct($shop, $shopifyProduct['id']);
                            } else {
                                $this->unpublishProduct($shop, $shopifyProduct['id']);
                                $stats['unpublished']++;
                            }
                        } else {
                            $stats['errors']++;
                        }
                    }
                } catch (\Exception $e) {
                    $this->log("   ❌ Error: " . $e->getMessage());
                    $stats['errors']++;
                }

                usleep(500000); // Rate limiting
            }
        }

        // Final summary
        $this->log("
╔══════════════════════════════════════════════════════════════╗");
        $this->log("║                  PROCESSING SUMMARY                          ║");
        $this->log("╚══════════════════════════════════════════════════════════════╝");
        $this->log("📊 Total Rug API products: {$stats['total_rug_products']}");
        $this->log("📊 Recently updated: {$stats['recently_updated']}");
        $this->log("✅ Found in Shopify: {$stats['found_in_shopify']}");
        $this->log("➕ Inserted (new): {$stats['inserted']}");
        $this->log("🔄 Updated: {$stats['updated']}");
        $this->log("📴 Unpublished: {$stats['unpublished']}");
        $this->log("❌ Errors: {$stats['errors']}");
        $this->log("════════════════════════════════════════════════════════════════");
    }

    /**
     * Publish product in Shopify
     */
    private function publishProduct($shop, $productId)
    {
        try {
            $response = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                ->withHeaders([
                    'X-Shopify-Access-Token' => $shop->shopify_token,
                    'Content-Type' => 'application/json',
                ])->put("https://{$shop->shopify_store_url}/admin/api/2025-07/products/{$productId}.json", [
                    'product' => [
                        'status' => 'active'
                    ]
                ]);

            if ($response->successful()) {
                $this->log("   ✅ Product published (status: active)");
                return true;
            } else {
                $this->log("   ⚠️ Failed to publish product - Status: " . $response->status());
                return false;
            }
        } catch (\Exception $e) {
            $this->log("   ⚠️ Error publishing product: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unpublish product in Shopify (set to draft)
     */
    private function unpublishProduct($shop, $productId)
    {
        try {
            $response = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                ->withHeaders([
                    'X-Shopify-Access-Token' => $shop->shopify_token,
                    'Content-Type' => 'application/json',
                ])->put("https://{$shop->shopify_store_url}/admin/api/2025-07/products/{$productId}.json", [
                    'product' => [
                        'status' => 'draft'
                    ]
                ]);

            if ($response->successful()) {
                $this->log("   📴 Product unpublished (status: draft)");
                return true;
            } else {
                $this->log("   ⚠️ Failed to unpublish product - Status: " . $response->status());
                return false;
            }
        } catch (\Exception $e) {
            $this->log("   ⚠️ Error unpublishing product: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert new product - SIMPLIFIED with better error handling
     */

    /**
     * insertNewProduct — used by the cron job to create a new Shopify product.
     *
     * Fully consistent with importProducts() and updateShopifyProduct():
     *  - Same tag logic
     *  - Same single variant structure (isSingleItem=true)
     *  - Same metafield list (dimensions, shipping dims, 30+ text fields, rental price, source flag)
     *  - Inventory set via inventory_levels/set AFTER creation (not via inventory_quantity on variant)
     *  - Status: active if available qty > 0 AND status === 'available', else draft
     */
    private function insertNewProduct(array $product, string $shopUrl): bool
    {
        try {
            // ============================================================
            // SETUP
            // ============================================================
            $settings = Setting::where('shopify_store_url', $shopUrl)->first();
            $settingsController = new SettingsController();
            if (!$settings) {
                $this->log("❌ Settings not found for shop: {$shopUrl}");
                return false;
            }

            $shopifyDomain = rtrim($settings->shopify_store_url, '/');
            $sku           = $product['ID'] ?? null;

            // ============================================================
            // VALIDATION
            // ============================================================
            if (!$sku) {
                $this->log("❌ Missing SKU — skipping product");
                return false;
            }

            if (empty($product['title'])) {
                $this->log("❌ [{$sku}] Missing title");
                return false;
            }

            if (empty($product['regularPrice'])) {
                $this->log("❌ [{$sku}] Missing regular price");
                return false;
            }

            if (empty($product['product_category'])) {
                $this->log("❌ [{$sku}] Missing product_category (rental/sale/both)");
                return false;
            }

            if (empty($product['images'])) {
                $this->log("❌ [{$sku}] Missing images");
                return false;
            }

            // ============================================================
            // CHECK IF ALREADY EXISTS BY SKU
            // ============================================================
            // $searchResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
            //     ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
            //     ->get("https://{$shopifyDomain}/admin/api/2025-07/variants.json", ['sku' => $sku]);

            // if ($searchResponse->successful() && !empty($searchResponse->json('variants'))) {
            //     $this->log("⏭️ [{$sku}] Already exists in Shopify — skipping insert");
            //     return false;
            // }

            // usleep(400000);

            // ============================================================
            // PREPARE SHARED DATA
            // ============================================================
            $size      = $product['size'] ?? '';
            $shapeTags = [];
            if (!empty($product['shapeCategoryTags'])) {
                $shapeTags = array_map('ucfirst', array_map('trim', explode(',', $product['shapeCategoryTags'])));
            }

            $nominalSize = $settingsController->convertSizeToNominal($size);
            if (!empty($shapeTags)) {
                $nominalSize .= ' ' . implode(' ', $shapeTags);
            }

            $regularPrice   = $product['regularPrice'] ?? '0.00';
            $sellingPrice   = $product['sellingPrice'] ?? null;
            $currentPrice   = !empty($sellingPrice) ? $sellingPrice : $regularPrice;
            $compareAtPrice = (!empty($sellingPrice) && !empty($regularPrice) && $sellingPrice < $regularPrice)
                ? $regularPrice : null;

            // Inventory: single quantityLevel[0].available — no per-sku breakdown in your API
            $newQty      = (int)($product['inventory']['quantityLevel'][0]['available'] ?? 0);
            $manageStock = ($product['inventory']['manageStock'] ?? false) === true;

            // Status: draft if out of stock, active only if available AND status=available
            $status = ($newQty <= 0) ? 'draft' : (($product['status'] ?? '') === 'available' ? 'active' : 'draft');

            // Title
            $updatedTitle = $product['title'] . ' #' . $sku;
            if (!empty($size)) {
                $updatedTitle = $size . ' ' . $updatedTitle;
            }

            // ============================================================
            // BUILD TAGS (identical to importProducts + updateShopifyProduct)
            // ============================================================
            $tags = [];

            if (!empty($product['product_category'])) {
                if ($product['product_category'] === 'both') {
                    $tags[] = 'Rugs for Rent';
                    $tags[] = 'Rugs for Sale';
                } elseif ($product['product_category'] === 'rental') {
                    $tags[] = 'Rugs for Rent';
                } elseif ($product['product_category'] === 'sale') {
                    $tags[] = 'Rugs for Sale';
                }
            }

            foreach (['constructionType', 'country', 'primaryMaterial', 'design', 'palette', 'pattern', 'styleTags', 'otherTags', 'foundation', 'region', 'rugType', 'productType'] as $field) {
                if (!empty($product[$field])) {
                    if (is_string($product[$field]) && strpos($product[$field], ',') !== false) {
                        foreach (array_map('trim', explode(',', $product[$field])) as $v) {
                            $tags[] = $v;
                        }
                    } else {
                        $tags[] = $product[$field];
                    }
                }
            }

            foreach (['sizeCategoryTags', 'shapeCategoryTags', 'colourTags'] as $field) {
                if (!empty($product[$field])) {
                    foreach (array_map('trim', explode(',', $product[$field])) as $t) {
                        $tags[] = $t;
                    }
                }
            }

            if (!empty($product['collectionDocs'])) {
                foreach ($product['collectionDocs'] as $col) {
                    if (!empty($col['name'])) {
                        $tags[] = trim($col['name']);
                    }
                }
            }

            if (!empty($product['category'])) {
                $tags[] = $product['category'];
            }
            if (!empty($product['subCategory'])) {
                $tags[] = $product['subCategory'];
            }

            // ============================================================
            // BUILD SINGLE VARIANT
            // isSingleItem=true: one variant per product with Size + Nominal Size options.
            // Do NOT set inventory_quantity here — we use inventory_levels/set after creation.
            // inventory_management='shopify' is required so the set call works.
            // ============================================================
            $variant = [
                'sku'                  => $sku,
                'price'                => $currentPrice,
                'option1'              => $size ?: 'Default',
                'option2'              => $nominalSize ?: 'Default',
                'inventory_management' => $manageStock ? 'shopify' : null,
                'requires_shipping'    => true,
                'taxable'              => true,
                'fulfillment_service'  => 'manual',
                'grams'                => $product['weight_grams'] ?? 0,
            ];

            if ($compareAtPrice) {
                $variant['compare_at_price'] = $compareAtPrice;
            }

            // ============================================================
            // BUILD PRODUCT PAYLOAD
            // ============================================================
            $shopifyProduct = [
                'product' => [
                    'title'        => $updatedTitle,
                    'body_html'    => '<p>' . ($product['description'] ?? '') . '</p>',
                    'vendor'       => $product['vendor'] ?? 'Oriental Rug Mart',
                    'product_type' => !empty($product['constructionType']) ? ucfirst($product['constructionType']) : '',
                    'tags'         => implode(',', array_unique(array_filter($tags))),
                    'status'       => $status,
                    'options'      => [
                        ['name' => 'Size',         'values' => [$size ?: 'Default']],
                        ['name' => 'Nominal Size', 'values' => [$nominalSize ?: 'Default']],
                    ],
                    'variants' => [$variant],
                    'images'   => array_map(fn($img, $i) => [
                        'src'      => str_replace(' ', '%20', $img),
                        'position' => $i + 1,
                    ], $product['images'], array_keys($product['images'])),
                ],
            ];

            // ============================================================
            // STEP 1: CREATE PRODUCT
            // ============================================================
            $response = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                ->withHeaders([
                    'X-Shopify-Access-Token' => $settings->shopify_token,
                    'Content-Type'           => 'application/json',
                ])->post("https://{$shopifyDomain}/admin/api/2025-07/products.json", $shopifyProduct);

            if (!$response->successful()) {
                $this->log("❌ [{$sku}] Failed to create product (HTTP {$response->status()}): " . $response->body());
                return false;
            }

            $productData = $response->json('product');
            $productId   = $productData['id'] ?? null;

            if (!$productId) {
                $this->log("❌ [{$sku}] Product created but no ID returned");
                return false;
            }

            $this->log("   ✓ [{$sku}] Product created — Shopify ID: {$productId}");
            usleep(500000);

            // ============================================================
            // STEP 2: SET INVENTORY via inventory_levels/set
            // Must be done AFTER product creation (inventory_item_id only exists after creation).
            // ============================================================
            if ($manageStock && $newQty > 0) {
                try {
                    // Get the created variant's inventory_item_id
                    $createdVariant  = $productData['variants'][0] ?? null;
                    $inventoryItemId = $createdVariant['inventory_item_id'] ?? null;

                    if ($inventoryItemId) {
                        // Get location
                        $locResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                            ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                            ->get("https://{$shopifyDomain}/admin/api/2025-07/locations.json");

                        if ($locResponse->successful() && !empty($locResponse->json('locations'))) {
                            $locationId = $locResponse->json('locations')[0]['id'];

                            usleep(300000);

                            $invResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                                ->withHeaders([
                                    'X-Shopify-Access-Token' => $settings->shopify_token,
                                    'Content-Type'           => 'application/json',
                                ])->post("https://{$shopifyDomain}/admin/api/2025-07/inventory_levels/set.json", [
                                    'location_id'       => $locationId,
                                    'inventory_item_id' => $inventoryItemId,
                                    'available'         => $newQty,
                                ]);

                            if ($invResponse->successful()) {
                                $this->log("   ✓ [{$sku}] Inventory set to {$newQty}");
                            } else {
                                $this->log("   ⚠️ [{$sku}] Inventory set failed: " . $invResponse->body());
                            }
                        } else {
                            $this->log("   ⚠️ [{$sku}] No locations found — inventory skipped");
                        }
                    } else {
                        $this->log("   ⚠️ [{$sku}] No inventory_item_id on created variant");
                    }
                } catch (\Exception $e) {
                    $this->log("   ⚠️ [{$sku}] Inventory exception: " . $e->getMessage());
                }

                usleep(400000);
            } else {
                $this->log("   ℹ️ [{$sku}] Inventory skipped (manageStock=" . ($manageStock ? 'true' : 'false') . ", qty={$newQty})");
            }

            // ============================================================
            // STEP 3: ADD ALL METAFIELDS
            // Metafields cannot be reliably included in the product creation payload,
            // so we POST each one separately after creation — same as importProducts.
            // ============================================================
            $metafields = [];

            // Product dimensions
            foreach (['length', 'width', 'height'] as $dim) {
                if (isset($product['dimension'][$dim]) && $product['dimension'][$dim] !== '') {
                    $metafields[] = [
                        'namespace' => 'custom',
                        'key'       => $dim,
                        'type'      => 'dimension',
                        'value'     => json_encode(['value' => (float)$product['dimension'][$dim], 'unit' => 'INCHES']),
                    ];
                }
            }

            // Shipping / package dimensions
            foreach (['height' => 'packageheight', 'length' => 'packagelength', 'width' => 'packagewidth'] as $field => $key) {
                if (isset($product['shipping'][$field]) && $product['shipping'][$field] !== '') {
                    $metafields[] = [
                        'namespace' => 'custom',
                        'key'       => $key,
                        'type'      => 'dimension',
                        'value'     => json_encode(['value' => (float)$product['shipping'][$field], 'unit' => 'INCHES']),
                    ];
                }
            }

            // All text metafields (same map as updateShopifyProduct)
            $textMetaMap = [
                'sizeCategoryTags'    => 'size_category_tags',
                'costType'            => 'cost_type',
                'cost'                => 'cost',
                'condition'           => 'condition',
                'productType'         => 'product_type',
                'rugType'             => 'rug_type',
                'constructionType'    => 'construction_type',
                'country'             => 'country',
                'production'          => 'production',
                'primaryMaterial'     => 'primary_material',
                'design'              => 'design',
                'palette'             => 'palette',
                'pattern'             => 'pattern',
                'pile'                => 'pile',
                'period'              => 'period',
                'styleTags'           => 'style_tags',
                'otherTags'           => 'other_tags',
                'colourTags'          => 'color_tags',
                'foundation'          => 'foundation',
                'age'                 => 'age',
                'quality'             => 'quality',
                'conditionNotes'      => 'condition_notes',
                'region'              => 'region',
                'density'             => 'density',
                'knots'               => 'knots',
                'rugID'               => 'rug_id',
                'size'                => 'size',
                //'isTaxable'           => 'is_taxable',
                'subCategory'         => 'subcategory',
                'created_at'          => 'created_at',
                'updated_at'          => 'updated_at',
                //'consignmentisActive' => 'consignment_active',
                'consignorRef'        => 'consignor_ref',
                'parentId'            => 'parent_id',
                'agreedLowPrice'      => 'agreed_low_price',
                'agreedHighPrice'     => 'agreed_high_price',
                'payoutPercentage'    => 'payout_percentage',
            ];

            foreach ($textMetaMap as $field => $key) {
                if (array_key_exists($field, $product) && $product[$field] !== null && $product[$field] !== '') {
                    $metafields[] = [
                        'namespace' => 'custom',
                        'key'       => $key,
                        'type'      => 'single_line_text_field',
                        'value'     => (string)$product[$field],
                    ];
                }
            }

            // Boolean metafields — must use type='boolean' with value 'true'/'false' (not 1/0/"").
            // Shopify returns 422 "can't be blank" if you send these as single_line_text_field.
            // Only add if the field exists and is not null (skip missing fields entirely).
            $booleanMetaMap = [
                'consignmentisActive' => 'consignment_active',
                'isTaxable'           => 'is_taxable',
            ];

            foreach ($booleanMetaMap as $field => $key) {
                if (array_key_exists($field, $product) && $product[$field] !== null) {
                    // Normalise to 'true' or 'false' string — Shopify boolean metafields require this
                    $boolVal = filter_var($product[$field], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
                    $metafields[] = [
                        'namespace' => 'custom',
                        'key'       => $key,
                        'type'      => 'boolean',
                        'value'     => $boolVal,
                    ];
                }
            }

            // Rental price metafield
            $rentalPrice = '';
            $rpData = $product['rental_price_value'] ?? null;
            if (!empty($rpData)) {
                if (isset($rpData['key']) && $rpData['key'] === 'general_price') {
                    $rentalPrice = $rpData['value'];
                } elseif (!empty($rpData['redq_day_ranges_cost'])) {
                    foreach ($rpData['redq_day_ranges_cost'] as $range) {
                        if (!empty($range['range_cost'])) {
                            $rentalPrice = $range['range_cost'];
                        }
                    }
                }
            }
            if (!empty($rentalPrice)) {
                $metafields[] = [
                    'namespace' => 'custom',
                    'key'       => 'rental_price',
                    'type'      => 'single_line_text_field',
                    'value'     => (string)$rentalPrice,
                ];
            }

            // Source flag — marks this product as imported by the app
            $metafields[] = [
                'namespace' => 'custom',
                'key'       => 'source',
                'type'      => 'boolean',
                'value'     => 'true',
            ];

            // POST each metafield individually (most reliable approach)
            $metaCount = 0;
            foreach ($metafields as $metafield) {
                try {
                    $metaResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                        ->withHeaders([
                            'X-Shopify-Access-Token' => $settings->shopify_token,
                            'Content-Type'           => 'application/json',
                        ])->post("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/metafields.json", [
                            'metafield' => $metafield,
                        ]);

                    if ($metaResponse->successful()) {
                        $metaCount++;
                    } else {
                        $this->log("   ⚠️ [{$sku}] Metafield '{$metafield['key']}' failed: " . $metaResponse->body());
                    }
                } catch (\Exception $e) {
                    $this->log("   ⚠️ [{$sku}] Metafield '{$metafield['key']}' exception: " . $e->getMessage());
                }

                usleep(150000); // Rate limit between metafield POSTs
            }

            $this->log("   ✓ [{$sku}] {$metaCount}/" . count($metafields) . " metafields added");

            // ============================================================
            // DONE
            // ============================================================
            $this->log("✅ [{$sku}] Product inserted successfully — Shopify ID: {$productId}, Status: {$status}, Qty: {$newQty}");
            return true;
        } catch (\Exception $e) {
            $this->log("❌ [{$sku}] insertNewProduct exception: " . $e->getMessage());
            return false;
        }
    }
    // private function insertNewProduct($product, $shopUrl)
    // {
    //     try {
    //         $settings = Setting::where('shopify_store_url', $shopUrl)->first();
    //         if (!$settings) {
    //             $this->log("❌ Settings not found for shop");
    //             return false;
    //         }

    //         $settingsController = new SettingsController();
    //         $sku = $product['ID'] ?? null;

    //         // Validation
    //         if (!$sku) {
    //             $this->log("❌ Missing SKU");
    //             return false;
    //         }

    //         if (empty($product['title'])) {
    //             $this->log("❌ Missing title");
    //             return false;
    //         }

    //         if (empty($product['regularPrice'])) {
    //             $this->log("❌ Missing regular price");
    //             return false;
    //         }

    //         if (empty($product['images'])) {
    //             $this->log("❌ Missing images");
    //             return false;
    //         }

    //         $shopifyDomain = rtrim($settings->shopify_store_url, '/');

    //         // Check if already exists by SKU
    //         $existingSkuProduct = $settingsController->checkProductBySkuOrTitle($settings, $shopifyDomain, $sku, 'sku');
    //         if ($existingSkuProduct) {
    //             $this->log("⏭️ Already exists by SKU");
    //             return false;
    //         }

    //         // Check if already exists by title
    //         // $existingTitleProduct = $settingsController->checkProductBySkuOrTitle($settings, $shopifyDomain, $product['title'], 'title');
    //         // if ($existingTitleProduct) {
    //         //     $this->log("⏭️ Already exists by title");
    //         //     return false;
    //         // }

    //         // Build tags
    //         $tags = $this->buildProductTags($product);

    //         // Get prices
    //         $regularPrice = $product['regularPrice'] ?? '0.00';
    //         $sellingPrice = $product['sellingPrice'] ?? null;
    //         $currentPrice = !empty($sellingPrice) ? $sellingPrice : $regularPrice;

    //         // Get size data
    //         $size = $product['size'] ?? '';
    //         $shapeTags = [];
    //         if (!empty($product['shapeCategoryTags'])) {
    //             $shapeTags = array_map('trim', explode(',', $product['shapeCategoryTags']));
    //             $shapeTags = array_map('ucfirst', $shapeTags);
    //         }

    //         $nominalSize = $settingsController->convertSizeToNominal($size);
    //         if (!empty($shapeTags)) {
    //             $nominalSize .= ' ' . implode(' ', $shapeTags);
    //         }

    //         // Get colors
    //         $colors = [];
    //         if (!empty($product['colourTags'])) {
    //             $colors = array_map('trim', explode(',', $product['colourTags']));
    //         }

    //         // Build variants
    //         $variants = $this->buildVariants($product, $size, $nominalSize, $colors, $currentPrice, $regularPrice, $sellingPrice);

    //         // Build title
    //         $updatedTitle = $product['title'] . ' #' . $sku;
    //         if (!empty($size)) {
    //             $updatedTitle = $size . ' ' . $updatedTitle;
    //         }

    //         // Build product payload
    //         $shopifyProduct = [
    //             "product" => [
    //                 'title' => $updatedTitle,
    //                 'body_html' => '<p>' . ($product['description'] ?? '') . '</p>',
    //                 'vendor' => $product['vendor'] ?? 'Oriental Rug Mart',
    //                 'product_type' => isset($product['constructionType']) ? ucfirst($product['constructionType']) : '',
    //                 "options" => [
    //                     ["name" => "Size", "values" => [$size]],
    //                     ["name" => "Nominal Size", "values" => [$nominalSize]],
    //                 ],
    //                 'images' => array_map(fn($imgUrl, $i) => [
    //                     'src' => str_replace(' ', '%20', $imgUrl),
    //                     'position' => $i + 1
    //                 ], $product['images'], array_keys($product['images'])),
    //                 'tags' => implode(', ', array_unique($tags)),
    //                 "variants" => $variants,
    //                 'status' => ($product['status'] ?? '') === 'available' ? 'active' : 'draft', // Set status based on Rug API
    //             ]
    //         ];

    //         // Create product
    //         $response = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
    //             ->withHeaders([
    //                 'X-Shopify-Access-Token' => $settings->shopify_token,
    //                 'Content-Type' => 'application/json',
    //             ])->post("https://{$shopifyDomain}/admin/api/2025-07/products.json", $shopifyProduct);

    //         if (!$response->successful()) {
    //             $this->log("   ❌ Failed to create product - Status: " . $response->status());
    //             $this->log("   Response: " . $response->body());
    //             return false;
    //         }

    //         $productData = $response->json();
    //         $productId = $productData['product']['id'] ?? null;

    //         if (!$productId) {
    //             $this->log("   ❌ Product created but no ID returned");
    //             return false;
    //         }

    //         // Add metafields
    //         $metafields = $this->buildMetafields($product);
    //         foreach ($metafields as $metafield) {
    //             Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
    //                 ->withHeaders([
    //                     'X-Shopify-Access-Token' => $settings->shopify_token,
    //                     'Content-Type' => 'application/json',
    //                 ])->post("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/metafields.json", [
    //                     'metafield' => $metafield
    //                 ]);

    //             usleep(200000); // Rate limit
    //         }

    //         $this->log("✅ Product created successfully - ID: {$productId}");
    //         return true;
    //     } catch (\Exception $e) {
    //         $this->log("   ❌ Exception: " . $e->getMessage());
    //         //$this->log("   Trace: " . $e->getTraceAsString());
    //         return false;
    //     }
    // }

    /**
     * Build product tags from Rug API data
     */
    private function buildProductTags($product)
    {
        $tags = [];

        // Product category tags
        if (!empty($product['product_category'])) {
            if ($product['product_category'] === 'both') {
                $tags[] = 'Rugs for Rent';
                $tags[] = 'Rugs for Sale';
            } elseif ($product['product_category'] === 'rental') {
                $tags[] = 'Rugs for Rent';
            } elseif ($product['product_category'] === 'sale') {
                $tags[] = 'Rugs for Sale';
            }
        }

        // Other tag fields
        $tagFields = [
            'constructionType',
            'country',
            'primaryMaterial',
            'design',
            'palette',
            'pattern',
            'styleTags',
            'foundation',
            'region',
            'rugType',
            'productType',
            'otherTags'
        ];

        foreach ($tagFields as $field) {
            if (!empty($product[$field])) {
                if (is_string($product[$field]) && strpos($product[$field], ',') !== false) {
                    $values = array_map('trim', explode(',', $product[$field]));
                    $tags = array_merge($tags, $values);
                } else {
                    $tags[] = $product[$field];
                }
            }
        }

        // Size category tags
        if (!empty($product['sizeCategoryTags'])) {
            $sizeTags = array_map('trim', explode(',', $product['sizeCategoryTags']));
            $tags = array_merge($tags, $sizeTags);
        }

        // Shape tags
        if (!empty($product['shapeCategoryTags'])) {
            $shapeTags = array_map('trim', explode(',', $product['shapeCategoryTags']));
            $tags = array_merge($tags, $shapeTags);
        }

        // Color tags
        if (!empty($product['colourTags'])) {
            $colorTags = array_map('trim', explode(',', $product['colourTags']));
            $tags = array_merge($tags, $colorTags);
        }

        // Collections
        if (!empty($product['collectionDocs'])) {
            foreach ($product['collectionDocs'] as $collection) {
                if (!empty($collection['name'])) {
                    $tags[] = trim($collection['name']);
                }
            }
        }

        // Category and subcategory
        if (!empty($product['category'])) $tags[] = $product['category'];
        if (!empty($product['subCategory'])) $tags[] = $product['subCategory'];

        return array_filter($tags);
    }

    /**
     * Build variants array
     */
    private function buildVariants($product, $size, $nominalSize, $currentPrice, $regularPrice, $sellingPrice)
    {
        $variantData = [
            "option1"              => $size ?: 'Default',
            "option2"              => $nominalSize ?: 'Default',
            "price"                => $currentPrice,
            'inventory_management' => ($product['inventory']['manageStock'] ?? false) ? 'shopify' : null,
            'inventory_quantity'   => $product['inventory']['quantityLevel'][0]['available'] ?? 0,
            'sku'                  => $product['ID'] ?? '',
            "requires_shipping"    => true,
            "taxable"              => true,
            "fulfillment_service"  => "manual",
            "grams"                => $product['weight_grams'] ?? 0,
        ];

        if (!empty($sellingPrice) && !empty($regularPrice) && $sellingPrice < $regularPrice) {
            $variantData['compare_at_price'] = $regularPrice;
        }

        return [$variantData];
    }

    /**
     * Build metafields array
     */
    private function buildMetafields($product)
    {
        $metafields = [
            ['namespace' => 'custom', 'key' => 'height', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['dimension']['height'] ?? 0), 'unit' => 'INCHES'])],
            ['namespace' => 'custom', 'key' => 'length', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['dimension']['length'] ?? 0), 'unit' => 'INCHES'])],
            ['namespace' => 'custom', 'key' => 'width', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['dimension']['width'] ?? 0), 'unit' => 'INCHES'])],
        ];

        // Other metafields
        $metaFieldMap = [
            'sizeCategoryTags' => 'size_category_tags',
            'costType' => 'cost_type',
            'cost' => 'cost',
            'condition' => 'condition',
            'productType' => 'product_type',
            'rugType' => 'rug_type',
            'constructionType' => 'construction_type',
            'country' => 'country',
            'production' => 'production',
            'primaryMaterial' => 'primary_material',
            'design' => 'design',
            'palette' => 'palette',
            'pattern' => 'pattern',
            'pile' => 'pile',
            'period' => 'period',
            'styleTags' => 'style_tags',
            'otherTags' => 'other_tags',
            'colourTags' => 'color_tags',
            'foundation' => 'foundation',
            'age' => 'age',
            'quality' => 'quality',
            'conditionNotes' => 'condition_notes',
            'region' => 'region',
            'density' => 'density',
            'knots' => 'knots',
            'rugID' => 'rug_id',
            'size' => 'size',
            'isTaxable' => 'is_taxable',
            'subCategory' => 'subcategory',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];

        foreach ($metaFieldMap as $field => $key) {
            if (!empty($product[$field])) {
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => $key,
                    'type' => 'single_line_text_field',
                    'value' => (string)$product[$field]
                ];
            }
        }

        // Rental price
        if (!empty($product['rental_price_value'])) {
            $rental = $product['rental_price_value'];
            $rentalPrice = '';

            if (isset($rental['key']) && $rental['key'] === 'general_price') {
                $rentalPrice = $rental['value'];
            } elseif (!empty($rental['redq_day_ranges_cost'])) {
                foreach ($rental['redq_day_ranges_cost'] as $range) {
                    if (!empty($range['range_cost'])) {
                        $rentalPrice = $range['range_cost'];
                        break;
                    }
                }
            }

            if (!empty($rentalPrice)) {
                $metafields[] = [
                    'namespace' => 'custom',
                    'key' => 'rental_price',
                    'value' => $rentalPrice,
                    'type' => 'single_line_text_field'
                ];
            }
        }

        // Source marker
        $metafields[] = [
            'namespace' => 'custom',
            'key' => 'source',
            'type' => 'boolean',
            'value' => true
        ];

        return $metafields;
    }

    /**
     * Update Shopify product - ROBUST version with proper option handling
     */
    private function updateShopifyProduct(array $rug, array $shopifyData, $settings)
    {
        $this->log("🔧 Updating product...");

        try {
            $productId = $shopifyData['product_id'];
            $variantId = $shopifyData['variant_id'];
            $shopifyDomain = rtrim($settings->shopify_store_url, '/');
            $settingsController = new SettingsController();
            $fullProduct = $shopifyData['full_product'];
            $updatedFields = [];

            // ===== PREPARE DATA =====
            $size = $rug['size'] ?? '';
            $shapeTags = [];
            if (!empty($rug['shapeCategoryTags'])) {
                $shapeTags = array_map('ucfirst', array_map('trim', explode(',', $rug['shapeCategoryTags'])));
            }

            $nominalSize = $settingsController->convertSizeToNominal($size);
            if (!empty($shapeTags)) {
                $nominalSize .= ' ' . implode(' ', $shapeTags);
            }

            $regularPrice = $rug['regularPrice'] ?? null;
            $sellingPrice = $rug['sellingPrice'] ?? null;
            $currentPrice = !empty($sellingPrice) ? $sellingPrice : $regularPrice;

            $colors = [];
            if (!empty($rug['colourTags'])) {
                $colors = array_map('trim', explode(',', $rug['colourTags']));
            }

            $updatedTitle = $rug['title'] . ' #' . $rug['ID'];
            if (!empty($size)) {
                $updatedTitle = $size . ' ' . $updatedTitle;
            }

            // ===== STEP 1: UPDATE PRODUCT LEVEL (without variants/options) =====
            $productPayload = [
                'title' => $updatedTitle,
                'body_html' => '<p>' . ($rug['description'] ?? '') . '</p>',
            ];

            if (!empty($rug['vendor'])) {
                $productPayload['vendor'] = $rug['vendor'];
            }

            if (!empty($rug['constructionType'])) {
                $productPayload['product_type'] = ucfirst($rug['constructionType']);
            }

            // Tags
            $tags = $this->buildProductTags($rug);
            if (!empty($tags)) {
                $productPayload['tags'] = implode(',', array_unique($tags));
            }

            // Update product (without images and options first)
            $response = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                ->withHeaders([
                    'X-Shopify-Access-Token' => $settings->shopify_token,
                    'Content-Type' => 'application/json',
                ])->put("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json", [
                    'product' => $productPayload
                ]);

            if ($response->successful()) {
                $updatedFields[] = 'product_basic';
                $this->log("      ✓ Product basic info updated");
            } else {
                $this->log("      ⚠️ Product basic update failed - Status: " . $response->status());
                $this->log("      Response: " . $response->body());
            }

            usleep(500000);

            // ===== STEP 2: ENSURE PRODUCT OPTIONS EXIST =====
            $this->log("      🔧 Ensuring product options...");

            $currentOptions = $fullProduct['options'] ?? [];
            $needsOptions = false;

            // Check if we have all 3 options
            $hasSize = false;
            $hasNominal = false;

            foreach ($currentOptions as $option) {
                $optionName = strtolower($option['name'] ?? '');
                if ($optionName === 'size') $hasSize = true;
                if ($optionName === 'nominal size') $hasNominal = true;
            }

            if (!$hasSize || !$hasNominal) {
                $needsOptions = true;
                $this->log("⚠️ Missing product options - will add them");

                // Add missing options
                $optionsPayload = [];
                if (!$hasSize) {
                    $optionsPayload[] = ['name' => 'Size', 'values' => [$size ?: 'Default']];
                }
                if (!$hasNominal) {
                    $optionsPayload[] = ['name' => 'Nominal Size', 'values' => [$nominalSize ?: 'Default']];
                }

                // Update product with options
                $optionsResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                    ->withHeaders([
                        'X-Shopify-Access-Token' => $settings->shopify_token,
                        'Content-Type' => 'application/json',
                    ])->put("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json", [
                        'product' => [
                            'options' => array_merge($currentOptions, $optionsPayload)
                        ]
                    ]);

                if ($optionsResponse->successful()) {
                    $this->log("✓ Product options added");
                    $updatedFields[] = 'options_added';

                    // Refresh product data
                    $refreshResponse = Http::timeout(60)->connectTimeout(30)
                        ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                        ->get("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json");

                    if ($refreshResponse->successful()) {
                        $fullProduct = $refreshResponse->json('product');
                    }
                } else {
                    $this->log("⚠️ Failed to add options - Status: " . $optionsResponse->status());
                }

                usleep(500000);
            }

            // ===== STEP 3: UPDATE VARIANT =====
            $this->log("🔧 Updating variant...");

            $currentVariant = collect($fullProduct['variants'])->firstWhere('id', $variantId);
            if (!$currentVariant) {
                $this->log("⚠️ Variant not found after refresh");
                // Try to find by SKU
                foreach ($fullProduct['variants'] as $v) {
                    if ($v['sku'] === $rug['ID']) {
                        $currentVariant = $v;
                        $variantId = $v['id'];
                        break;
                    }
                }

                if (!$currentVariant) {
                    $this->log("❌ Cannot find variant - skipping variant update");
                    return false;
                }
            }

            $variantPayload = [
                'sku' => $rug['ID'],
                'price' => $currentPrice,
            ];

            // Only update options if they're defined
            if ($hasSize || $needsOptions) {
                $variantPayload['option1'] = $size ?: 'Default';
            }
            if ($hasNominal || $needsOptions) {
                $variantPayload['option2'] = $nominalSize ?: 'Default';
            }

            if (!empty($sellingPrice) && !empty($regularPrice) && $sellingPrice < $regularPrice) {
                $variantPayload['compare_at_price'] = $regularPrice;
            } else {
                $variantPayload['compare_at_price'] = null;
            }

            if (isset($rug['inventory']['manageStock'])) {
                $variantPayload['inventory_management'] = $rug['inventory']['manageStock'] ? 'shopify' : null;
            }

            if (isset($rug['weight_grams'])) {
                $variantPayload['grams'] = $rug['weight_grams'];
            }

            $variantResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                ->withHeaders([
                    'X-Shopify-Access-Token' => $settings->shopify_token,
                    'Content-Type' => 'application/json',
                ])->put("https://{$shopifyDomain}/admin/api/2025-07/variants/{$variantId}.json", [
                    'variant' => $variantPayload
                ]);

            if ($variantResponse->successful()) {
                $updatedFields[] = 'variant';
                $this->log("      ✓ Variant updated");
            } else {
                $this->log("      ⚠️ Variant update failed - Status: " . $variantResponse->status());
                $this->log("      Response: " . $variantResponse->body());
                // Don't return false - continue with other updates
            }

            usleep(500000);

            // ===== STEP 4: UPDATE INVENTORY =====
            if (isset($rug['inventory']['quantityLevel'][0]['available'])) {
                $this->log("      🔧 Updating inventory...");

                $newQuantity = $rug['inventory']['quantityLevel'][0]['available'];
                $inventoryItemId = $currentVariant['inventory_item_id'] ?? null;

                if ($inventoryItemId && ($currentVariant['inventory_management'] ?? null) === 'shopify') {
                    $locationsResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                        ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                        ->get("https://{$shopifyDomain}/admin/api/2025-07/locations.json");

                    if ($locationsResponse->successful()) {
                        $locations = $locationsResponse->json('locations');
                        if (!empty($locations)) {
                            $locationId = $locations[0]['id'];

                            $invResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                                ->withHeaders([
                                    'X-Shopify-Access-Token' => $settings->shopify_token,
                                    'Content-Type' => 'application/json',
                                ])->post("https://{$shopifyDomain}/admin/api/2025-07/inventory_levels/set.json", [
                                    'location_id' => $locationId,
                                    'inventory_item_id' => $inventoryItemId,
                                    'available' => $newQuantity
                                ]);

                            if ($invResponse->successful()) {
                                $updatedFields[] = 'inventory';
                                $this->log("      ✓ Inventory updated to {$newQuantity} units");
                            } else {
                                $this->log("      ⚠️ Inventory update failed");
                            }

                            usleep(500000);
                        }
                    }
                }
            }

            // ===== STEP 5: UPDATE IMAGES =====
            if (!empty($rug['images'])) {
                $this->log("🔧 Updating images...");

                $imagePayload = array_map(fn($img) => [
                    'src' => str_replace(' ', '%20', $img)  // <-- Encode spaces
                ], $rug['images']);

                $imageResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                    ->withHeaders([
                        'X-Shopify-Access-Token' => $settings->shopify_token,
                        'Content-Type' => 'application/json',
                    ])->put("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json", [
                        'product' => ['images' => $imagePayload]
                    ]);

                if ($imageResponse->successful()) {
                    $updatedFields[] = 'images';
                    $this->log("      ✓ Images updated (" . count($rug['images']) . " images)");
                } else {
                    $this->log("      ⚠️ Images update failed");
                }

                usleep(500000);
            }

            // ===== STEP 6: UPDATE METAFIELDS =====
            $this->log("🔧 Updating metafields...");

            $existingMetafields = [];
            foreach ($shopifyData['metafields'] as $meta) {
                $key = $meta['namespace'] . '.' . $meta['key'];
                $existingMetafields[$key] = $meta;
            }

            $metaUpdates = 0;

            // Dimension metafields
            if (isset($rug['dimension'])) {
                foreach (['length', 'width', 'height'] as $dim) {
                    if (isset($rug['dimension'][$dim])) {
                        $value = json_encode(['value' => (float)$rug['dimension'][$dim], 'unit' => 'INCHES']);
                        $metaKey = 'custom.' . $dim;
                        $metaId = $existingMetafields[$metaKey]['id'] ?? null;

                        if ($metaId) {
                            $metaResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                                ->withHeaders([
                                    'X-Shopify-Access-Token' => $settings->shopify_token,
                                    'Content-Type' => 'application/json',
                                ])->put("https://{$shopifyDomain}/admin/api/2025-07/metafields/{$metaId}.json", [
                                    'metafield' => ['value' => $value, 'type' => 'dimension']
                                ]);

                            if ($metaResponse->successful()) {
                                $metaUpdates++;
                            }

                            usleep(200000);
                        }
                    }
                }
            }

            // Other metafields
            $metaFieldMap = [
                'sizeCategoryTags' => 'size_category_tags',
                'cost' => 'cost',
                'condition' => 'condition',
                'constructionType' => 'construction_type',
                'country' => 'country',
                'primaryMaterial' => 'primary_material',
                'design' => 'design',
                'palette' => 'palette',
                'pattern' => 'pattern',
                'styleTags' => 'style_tags',
                'colourTags' => 'color_tags',
                'region' => 'region',
                'rugType' => 'rug_type',
                'size' => 'size',
                'updated_at' => 'updated_at',
            ];

            foreach ($metaFieldMap as $field => $key) {
                if (array_key_exists($field, $rug)) {
                    $value = (string)$rug[$field];
                    $metaKey = 'custom.' . $key;
                    $metaId = $existingMetafields[$metaKey]['id'] ?? null;

                    if ($metaId) {
                        $metaResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                            ->withHeaders([
                                'X-Shopify-Access-Token' => $settings->shopify_token,
                                'Content-Type' => 'application/json',
                            ])->put("https://{$shopifyDomain}/admin/api/2025-07/metafields/{$metaId}.json", [
                                'metafield' => ['value' => $value, 'type' => 'single_line_text_field']
                            ]);

                        if ($metaResponse->successful()) {
                            $metaUpdates++;
                        }

                        usleep(200000);
                    }
                }
            }

            if ($metaUpdates > 0) {
                $updatedFields[] = "{$metaUpdates}_metafields";
                $this->log("      ✓ {$metaUpdates} metafields updated");
            } else {
                $this->log("      ℹ️ No metafields to update");
            }

            // ===== FINAL RESULT =====
            if (!empty($updatedFields)) {
                $this->log("   ✅ Update completed: " . implode(', ', $updatedFields));
                return true;
            } else {
                $this->log("   ⚠️ No fields were updated");
                return false;
            }
        } catch (\Exception $e) {
            $this->log("   ❌ Update exception: " . $e->getMessage());
            //$this->log("   Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    // ===== HELPER METHODS (Fetching, Filtering, etc.) =====

    private function fetchAllRugProducts($token)
    {
        $allProducts = [];
        $limit = 200;
        $skip = 0;
        $pageNumber = 1;

        $this->log("
🔄 Fetching all products from Rug API...");

        while (true) {
            try {
                $response = Http::timeout(120)->connectTimeout(30)->retry(3, 5000)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ])->get("https://plugin-api.rugsimple.com/api/rug", [
                        'limit' => $limit,
                        'skip' => $skip
                    ]);

                if (!$response->successful()) {
                    $this->log("   ❌ Rug API failed - Status: " . $response->status());
                    break;
                }

                $data = $response->json();
                $products = $data['data'] ?? [];
                $pagination = $data['pagination'] ?? null;

                if (empty($products)) {
                    break;
                }

                $allProducts = array_merge($allProducts, $products);
                $this->log("   📄 Page {$pageNumber}: " . count($products) . " products (Total: " . count($allProducts) . ")");

                if ($pagination && isset($pagination['next']) && $pagination['next'] !== null) {
                    $skip += $limit;
                    $pageNumber++;
                    usleep(200000);
                } else {
                    break;
                }
            } catch (\Exception $e) {
                $this->log("   ❌ Error on page {$pageNumber}: " . $e->getMessage());
                break;
            }
        }

        return $allProducts;
    }

    private function filterRugProductsByDate($products, $cutoffDate)
    {
        $filtered = [];

        foreach ($products as $product) {
            $updatedAt = $product['updated_at'] ?? null;
            if (empty($updatedAt)) continue;

            try {
                $productDate = Carbon::parse($updatedAt);
                if ($productDate->isAfter($cutoffDate)) {
                    $filtered[] = $product;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $filtered;
    }

    private function getShopifyProductBySKU($shop, $sku)
    {
        try {
            $limit = 250;
            $pageInfo = null;

            do {
                $url = "https://{$shop->shopify_store_url}/admin/api/2025-07/products.json?limit={$limit}&fields=id,title,variants,tags,vendor,product_type,body_html,images,updated_at";
                if ($pageInfo) $url .= "&page_info={$pageInfo}";

                $response = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                    ->withHeaders([
                        'X-Shopify-Access-Token' => $shop->shopify_token,
                        'Content-Type' => 'application/json',
                    ])->get($url);

                if (!$response->successful()) return null;

                $products = $response->json('products', []);

                foreach ($products as $product) {
                    foreach ($product['variants'] ?? [] as $variant) {
                        if (($variant['sku'] ?? '') === $sku) {
                            return $product;
                        }
                    }
                }

                $linkHeader = $response->header('Link');
                if ($linkHeader && preg_match('/page_info=([^&>]+)/', $linkHeader, $matches)) {
                    $pageInfo = $matches[1];
                } else {
                    $pageInfo = null;
                }
            } while ($pageInfo);

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getShopifyProductMetafields($shop, $productId)
    {
        try {
            $response = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                ->withHeaders([
                    'X-Shopify-Access-Token' => $shop->shopify_token,
                    'Content-Type' => 'application/json',
                ])->get("https://{$shop->shopify_store_url}/admin/api/2025-07/products/{$productId}/metafields.json");

            return $response->successful() ? $response->json('metafields', []) : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getDaysToLookBack($shop)
    {
        if ($this->option('force-days')) {
            return (int) $this->option('force-days');
        }

        $cacheKey = $this->cronTrackingKey . '_' . $shop->id;
        $lastSuccessfulRun = Cache::get($cacheKey);

        if (!$lastSuccessfulRun) {
            return 7;
        }

        $daysSinceLastRun = Carbon::parse($lastSuccessfulRun)->diffInDays(Carbon::now());
        return $daysSinceLastRun > 1 ? $daysSinceLastRun + 1 : 2;
    }

    private function markCronSuccess()
    {
        $shops = Setting::all();
        foreach ($shops as $shop) {
            Cache::put($this->cronTrackingKey . '_' . $shop->id, Carbon::now()->toIso8601String(), now()->addDays(30));
        }
        Cache::put($this->cronTrackingKey, Carbon::now()->toIso8601String(), now()->addDays(30));
    }

    private function getRugApiToken($settings)
    {
        $tokenExpiry = $settings->token_expiry ? Carbon::parse($settings->token_expiry) : null;

        if (!$settings->token || !$tokenExpiry || $tokenExpiry->isPast()) {
            try {
                $tokenResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 1000)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'x-api-key' => $settings->api_key,
                    ])->post('https://plugin-api.rugsimple.com/api/token');

                if (!$tokenResponse->successful() || !isset($tokenResponse['token'])) {
                    return null;
                }

                $settings->token = $tokenResponse['token'];
                $settings->token_expiry = Carbon::now()->addHours(3);
                $settings->save();

                return $tokenResponse['token'];
            } catch (\Exception $e) {
                return null;
            }
        }

        return $settings->token;
    }

    private function initLog($shopUrl = null)
    {
        if (!empty($shopUrl)) {
            $shopSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', parse_url($shopUrl, PHP_URL_HOST) ?? $shopUrl);
            $logDir = storage_path('logs/imports/' . $shopSlug);
        } else {
            $logDir = storage_path('logs/imports/unknown');
        }

        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $this->logFilePath = $logDir . '/import_log_' . now()->format('Y-m-d_H-i-s') . '.log';
    }

    private function log($message)
    {
        if (empty($this->logFilePath)) {
            $this->initLog(null);
        }
        file_put_contents($this->logFilePath, $message . PHP_EOL, FILE_APPEND);
        $this->info($message);
    }

    private function fail($message)
    {
        $this->log($message);
        $this->error($message);
        return Command::FAILURE;
    }

    private function exceptionFail($e)
    {
        $errorMessage = '❌ CRON FAILED: ' . $e->getMessage();

        if (!empty($this->logFilePath)) {
            $this->log($errorMessage);
            $this->log("Stack trace: " . $e->getTraceAsString());
        }

        Log::error('Daily import failed', [
            'exception' => $e,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        return Command::FAILURE;
    }
}
