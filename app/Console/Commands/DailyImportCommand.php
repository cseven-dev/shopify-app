<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Http\Controllers\SettingsController;

class DailyImportCommand extends Command
{
    protected $signature = 'import:daily';
    protected $description = 'Run daily product import at midnight - FULL IMPORT';

    private $logFilePath;

    public function handle()
    {

        try {
            // Fetch all shop settings (assuming each shop has its own record)
            $shops = Setting::all();

            if ($shops->isEmpty()) {
                return $this->fail("❌ No shops found in settings table.");
            }

            foreach ($shops as $shop) {

                // Initialize a shop-specific log file for this shop
                $this->initLog($shop->shopify_store_url);

                // if ($shop->shopify_store_url == 'rugs-simple.myshopify.com') {
                //     $this->log("Stop shop for processing: {$shop->shopify_store_url}");
                //     continue;
                // }

                $this->log("🛍 Processing shop: {$shop->shopify_store_url}");

                if (!$shop->shopify_store_url || !$shop->api_key || !$shop->shopify_token) {
                    $this->log("⚠️ Skipping shop (missing credentials): {$shop->shopify_store_url}");
                    continue;
                }

                try {
                    // 1. Fetch Shopify products
                    $allProducts = $this->fetchAllShopifyProducts($shop);
                    if (empty($allProducts)) {
                        $this->log("❌ No products fetched from Shopify for {$shop->shopify_store_url}");
                        continue;
                    }

                    // 2. Build SKU list & product map
                    [$shopifyComparedProducts, $sku_list] = $this->buildSkuList($allProducts);
                    if (empty($sku_list)) {
                        $this->log("❌ No SKUs found for {$shop->shopify_store_url}");
                        continue;
                    }

                    // 3. Get Rug API token
                    $token = $this->getRugApiToken($shop);
                    if (!$token) {
                        $this->log("❌ Failed to get Rug API token for {$shop->shopify_store_url}");
                        continue;
                    }

                    // 4. Fetch Rug products
                    $shopifyFetchedProducts = $this->fetchRugProducts($token, $sku_list);
                    if (empty($shopifyFetchedProducts)) {
                        $this->log("❌ No product data received from Rug API for {$shop->shopify_store_url}");
                        continue;
                    }

                    // 5. Process Rug data
                    $settingsController = new SettingsController();
                    $processedProducts = $settingsController->process_product_data($shopifyFetchedProducts);

                    // 6. Compare & update
                    $this->compareAndUpdateProducts($processedProducts, $shopifyComparedProducts, $shop);

                    $this->log("✅ Cron completed successfully for {$shop->shopify_store_url}");
                } catch (\Exception $innerEx) {
                    $this->log("❌ Error processing {$shop->shopify_store_url}: " . $innerEx->getMessage());
                }

                $this->log("-------------------------------------------------------------");
            }

            $this->log("🎉 All shop imports completed successfully.");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->exceptionFail($e);
        }
    }

    /**
     * Initialize log file
     */
    private function initLog($shopUrl = null)
    {
        // If a shop URL is provided, create a shop-specific folder
        if (!empty($shopUrl)) {
            $shopSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', parse_url($shopUrl, PHP_URL_HOST) ?? $shopUrl);
            $logDir = storage_path('logs/imports/' . $shopSlug);
        } else {
            // fallback into an 'unknown' directory (not a global master file)
            $logDir = storage_path('logs/imports/unknown');
        }

        if (!file_exists($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFileName = 'import_log_' . now()->format('Y-m-d_H-i-s') . '.log';
        $this->logFilePath = $logDir . '/' . $logFileName;

        // write a header line to the shop log
        $this->log("🟢 CRON STARTED at: " . now()->format('Y-m-d H:i:s'));
    }

    /**
     * Fetch all Shopify products with pagination
     */
    private function fetchAllShopifyProducts($settings)
    {
        $allProducts = [];
        $nextPageInfo = null;
        $limit = 250;
        $pageCount = 1;

        do {
            $url = "https://{$settings->shopify_store_url}/admin/api/2025-07/products.json?limit={$limit}";
            if ($nextPageInfo) {
                $url .= "&page_info={$nextPageInfo}";
            }

            $this->log("📡 Fetching Shopify products page {$pageCount}...");
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $settings->shopify_token,
                'Content-Type' => 'application/json',
            ])->timeout(3600)->get($url);

            if (!$response->successful()) {
                $this->log("❌ Shopify API failed with status " . $response->status());
                break;
            }

            $products = $response->json('products');
            if (empty($products)) {
                $this->log("⚠️ No products found on page {$pageCount}.");
                break;
            }

            $this->log("✅ Retrieved " . count($products) . " products from page {$pageCount}");

            // Fetch metafields for each product
            foreach ($products as &$product) {
                $metafieldsUrl = "https://{$settings->shopify_store_url}/admin/api/2025-07/products/{$product['id']}/metafields.json";

                $metafieldsResponse = Http::withHeaders([
                    'X-Shopify-Access-Token' => $settings->shopify_token,
                    'Content-Type' => 'application/json',
                ])->get($metafieldsUrl);

                if ($metafieldsResponse->successful()) {
                    $metafields = $metafieldsResponse->json('metafields');
                    $product['metafields'] = $metafields ?? [];
                    $this->log("  ✅ Fetched " . count($product['metafields']) . " metafields for product ID: {$product['id']}");
                } else {
                    $product['metafields'] = [];
                    //$this->log("  ⚠️ Failed to fetch metafields for product ID: {$product['id']}");
                    $this->log("⚠️ Failed to fetch metafields for product ID: {$product['id']} | Status: " . $metafieldsResponse->status() . " | Response: " . $metafieldsResponse->body());
                }

                // Respect Shopify API rate limits (2 requests per second for REST Admin API)
                usleep(500000); // 0.5 second delay between metafield requests
            }

            $allProducts = array_merge($allProducts, $products);

            $linkHeader = $response->header('Link');
            if ($linkHeader && preg_match('/page_info=([^&>]+)/', $linkHeader, $matches)) {
                $nextPageInfo = $matches[1];
                $pageCount++;
            } else {
                $nextPageInfo = null;
            }
        } while ($nextPageInfo);

        $this->log("🎉 Total products imported: " . count($allProducts));
        return $allProducts;
    }

    /**
     * Build SKU list and product comparison map
     */
    private function buildSkuList($allProducts)
    {
        $products = [];
        $sku_list = [];

        foreach ($allProducts as $product) {
            if (!isset($product['variants'])) continue;

            // Extract updated_at from metafields
            $metafieldUpdatedAt = null;
            if (isset($product['metafields']) && is_array($product['metafields'])) {
                foreach ($product['metafields'] as $metafield) {
                    if ($metafield['namespace'] === 'custom' && $metafield['key'] === 'updated_at') {
                        $metafieldUpdatedAt = $metafield['value'];
                        break;
                    }
                }
            }

            foreach ($product['variants'] as $variant) {
                $sku = $variant['sku'] ?? null;
                $variantId = $variant['id'] ?? null;
                $variantUpdatedAt = $variant['updated_at'] ?? $product['updated_at'] ?? null;

                if (!empty($sku)) {
                    $sku_list[] = $sku;
                    $products[$sku] = [
                        'id'                    => $variantId,
                        'sku'                   => $sku,
                        'product_id'            => $product['id'],
                        'title'                 => $product['title'],
                        'variant_id'            => $variantId,
                        'variant_name'          => $variant['title'],
                        'last_updated'          => $variantUpdatedAt,
                        'metafield_updated_at'  => $metafieldUpdatedAt,
                        'metafields'            => $product['metafields'] ?? [],
                        'full_product'          => $product, // Keep full product data
                    ];
                }
            }
        }

        $this->log("📦 Total SKUs collected: " . count($sku_list));
        return [$products, $sku_list];
    }

    /**
     * Get Rug API token
     */
    private function getRugApiToken($settings)
    {
        $tokenExpiry = $settings->token_expiry ? Carbon::parse($settings->token_expiry) : null;

        if (!$settings->token || !$tokenExpiry || $tokenExpiry->isPast()) {
            $tokenResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-api-key' => $settings->api_key,
            ])->timeout(3600)->post('https://plugin-api.rugsimple.com/api/token');

            if (!$tokenResponse->successful() || !isset($tokenResponse['token'])) {
                $this->log("❌ Failed to get token from Rug API. Status: " . $tokenResponse->status());
                return null;
            }

            $token = $tokenResponse['token'];
            $settings->token = $token;
            $settings->token_expiry = Carbon::now()->addHours(3);
            $settings->save();

            $this->log("🔑 New Rug API token retrieved.");
            return $token;
        }

        return $settings->token;
    }

    /**
     * Fetch Rug products
     */
    private function fetchRugProducts($token, $sku_list)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ])->timeout(3600)->post('https://plugin-api.rugsimple.com/api/rug', [
            'ids' => $sku_list
        ]);

        if (!$response->successful()) {
            $this->log("❌ Failed to fetch Rug products. Status: " . $response->status());
            return [];
        }

        $data = $response->json();

        $this->log("Fetch Rug products. Status: " . count($data['data'] ?? []));

        return $data['data'] ?? [];
    }

    /**
     * Compare Rug vs Shopify products and update
     */
    private function compareAndUpdateProducts($rugProducts, $shopifyProducts,  $settings)
    {
        foreach ($rugProducts as $rug) {
            $sku = $rug['ID'] ?? '';
            if (empty($sku) || !isset($shopifyProducts[$sku])) continue;

            $shopifyData = $shopifyProducts[$sku];
            $productId = $shopifyData['id'];
            $shopifyUpdated = $shopifyData['metafield_updated_at'] ?? '';
            $rugUpdated = $rug['updated_at'];

            //$this->log("Comparing RUG ($sku) and Shopify ($productId) last updated dates...");
            //$this->log("RUG Last Updated: " . ($rugUpdated ?? 'N/A'));
            //$this->log("Shopify Last Updated: " . ($shopifyUpdated ?? 'N/A'));

            if (empty($shopifyUpdated)) {
                //$this->log("⚠️ No updated_at found for SKU: {$sku}");
                continue;
            }

            // Convert timestamps to comparable format
            $shopifyTimestamp = strtotime($shopifyUpdated);
            $rugTimestamp = strtotime($rugUpdated);

            // Log the actual timestamp values for debugging
            $this->log("RUG Timestamp: {$rugTimestamp} (" . date('Y-m-d H:i:s', $rugTimestamp) . ")");
            $this->log("Shopify Timestamp: {$shopifyTimestamp} (" . date('Y-m-d H:i:s', $shopifyTimestamp) . ")");

            // Calculate the difference in seconds
            $timeDifference = $rugTimestamp - $shopifyTimestamp;
            $this->log("Time Difference: {$timeDifference} seconds");

            // Compare: if rug data is newer (strictly greater, not equal), update Shopify
            if ($timeDifference > 0) {
                $this->log("🔄 RUG data is NEWER by {$timeDifference} seconds for SKU: {$sku}. Updating Shopify...");
                $this->updateShopifyProduct($rug, $shopifyData, $settings);
            } elseif ($timeDifference < 0) {
                $absDiff = abs($timeDifference);
                $this->log("⬅️ Shopify is NEWER by {$absDiff} seconds for SKU: {$sku}. Skipping update.");
            } else {
                $this->log("✅ Shopify is up-to-date for SKU: {$sku} (timestamps are equal)");
            }
        }
    }

    /**
     * Update Shopify product if differences exist
     */

    private function updateShopifyProduct(array $rug, array $shopifyData, $settings)
    {
        try {
            $updatePayload = [];
            $updatedFields = [];
            $productId = $shopifyData['product_id'];
            $variantId = $shopifyData['variant_id'];
            $shopifyDomain = rtrim($settings->shopify_store_url, '/');
            $settingsController = new SettingsController();

            // Use full_product instead of making API call
            $fullProduct = $shopifyData['full_product'];

            // ----------------------
            // 📝 PREPARE DATA FROM RUG
            // ----------------------

            // Get size and shape tags
            $size = $rug['size'] ?? '';
            $shapeTags = [];
            if (!empty($rug['shapeCategoryTags'])) {
                $shapeTags = array_map('trim', explode(',', $rug['shapeCategoryTags']));
            }

            $nominalSize = $settingsController->convertSizeToNominal($size);
            if (!empty($shapeTags)) {
                $shapeTags = array_map('ucfirst', $shapeTags);
                $nominalSize .= ' ' . implode(' ', $shapeTags);
            }

            // Get prices
            $regularPrice = $rug['regularPrice'] ?? null;
            $sellingPrice = $rug['sellingPrice'] ?? null;
            $currentPrice = !empty($sellingPrice) ? $sellingPrice : $regularPrice;

            // Get colors for variations
            $variationColors = [];
            $colors = [];
            if (!empty($rug['colourTags'])) {
                $colorTags = array_map('trim', explode(',', $rug['colourTags']));
                foreach ($colorTags as $colorTag) {
                    $variationColors[] = $colorTag;
                    $colors[] = $colorTag;
                }
            }

            // Build title
            $updatedTitle = $rug['title'] . ' #' . $rug['ID'];
            if (!empty($size)) {
                $updatedTitle = $size . ' ' . $updatedTitle;
            }

            // ----------------------
            // 📝 CORE PRODUCT FIELDS
            // ----------------------
            if (!empty($updatedTitle) && $updatedTitle !== ($fullProduct['title'] ?? '')) {
                $updatePayload['title'] = $updatedTitle;
                $updatedFields[] = 'title';
                $this->log("   🔸 Title changed: '{$fullProduct['title']}' → '{$updatedTitle}'");
            }

            // Helper to clean description
            $cleanText = function ($text) {
                $text = html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = strip_tags($text);
                $text = preg_replace('/[\xC2\xA0|\s]+/u', ' ', $text);
                $text = preg_replace('/[[:cntrl:]]/', '', $text);
                $text = strtolower(trim($text));
                return $text;
            };

            // Only check description if it exists in rug data
            if (isset($rug['description']) && !empty($rug['description'])) {
                $rugDescription = '<p>' . $rug['description'] . '</p>';
                $rugDescriptionClean = $cleanText($rugDescription);
                $shopifyDescriptionClean = $cleanText($fullProduct['body_html'] ?? '');

                if ($rugDescriptionClean !== $shopifyDescriptionClean) {
                    $updatePayload['body_html'] = $rugDescription;
                    $updatedFields[] = 'description';
                    $this->log("   🔸 Description changed");
                }
            }

            // Only check vendor if provided in rug data
            if (isset($rug['vendor']) && !empty($rug['vendor'])) {
                if ($rug['vendor'] !== ($fullProduct['vendor'] ?? '')) {
                    $updatePayload['vendor'] = $rug['vendor'];
                    $updatedFields[] = 'vendor';
                    $this->log("   🔸 Vendor changed: '{$fullProduct['vendor']}' → '{$rug['vendor']}'");
                }
            }

            // Product type from constructionType - only if provided
            if (isset($rug['constructionType']) && !empty($rug['constructionType'])) {
                $productType = ucfirst($rug['constructionType']);
                if ($productType !== ($fullProduct['product_type'] ?? '')) {
                    $updatePayload['product_type'] = $productType;
                    $updatedFields[] = 'product_type';
                    $this->log("   🔸 Product type changed: '{$fullProduct['product_type']}' → '{$productType}'");
                }
            }

            // Tags - Only update if tag fields are provided in rug data
            $hasTagData = false;
            $tags = [];

            if (isset($rug['sizeCategoryTags']) && !empty($rug['sizeCategoryTags'])) {
                $tags = array_merge($tags, array_map('trim', explode(',', $rug['sizeCategoryTags'])));
                $hasTagData = true;
            }
            if (isset($rug['styleTags']) && !empty($rug['styleTags'])) {
                $tags = array_merge($tags, array_map('trim', explode(',', $rug['styleTags'])));
                $hasTagData = true;
            }
            if (isset($rug['otherTags']) && !empty($rug['otherTags'])) {
                $tags = array_merge($tags, array_map('trim', explode(',', $rug['otherTags'])));
                $hasTagData = true;
            }
            if (isset($rug['colourTags']) && !empty($rug['colourTags'])) {
                $tags = array_merge($tags, array_map('trim', explode(',', $rug['colourTags'])));
                $hasTagData = true;
            }
            if (!empty($shapeTags)) {
                $tags = array_merge($tags, $shapeTags);
                $hasTagData = true;
            }

            if ($hasTagData) {
                $rugTags = implode(',', array_unique(array_filter($tags)));
                $currentTags = $fullProduct['tags'] ?? '';

                //$this->log("   🔸 Tags: " . json_encode(array_map('trim', explode(',', $currentTags))));

                // Normalize tags for comparison (lowercase + trim)
                $rugTagsArray = array_map(fn($t) => strtolower(trim($t)), explode(',', $rugTags));
                $currentTagsArray = array_map(fn($t) => strtolower(trim($t)), explode(',', $currentTags));

                // Remove empty values
                $rugTagsArray = array_filter($rugTagsArray);
                $currentTagsArray = array_filter($currentTagsArray);

                sort($rugTagsArray);
                sort($currentTagsArray);

                if ($rugTagsArray !== $currentTagsArray) {
                    $updatePayload['tags'] = $rugTags;

                    $added = array_diff($rugTagsArray, $currentTagsArray);
                    $removed = array_diff($currentTagsArray, $rugTagsArray);
                    if (!empty($added)) {
                        $this->log("   🔸 Tags added: " . implode(', ', $added));
                        $updatedFields[] = 'tags';
                    }
                    if (!empty($removed)) {
                        $this->log("   🔸 Tags removed: " . implode(', ', $removed));
                        $updatedFields[] = 'tags';
                    }
                }
            }

            // ----------------------
            // 📝 UPDATE PRODUCT (if needed)
            // ----------------------
            if (!empty($updatePayload)) {
                $this->log("📝 Updating Shopify product {$productId} (SKU: {$shopifyData['sku']})");
                $productUrl = "https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json";

                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $settings->shopify_token,
                    'Content-Type' => 'application/json',
                ])->put($productUrl, [
                    'product' => $updatePayload
                ]);

                if ($response->successful()) {
                    $this->log("✅ Product fields updated: " . implode(', ', $updatedFields));
                    // Update fullProduct with new data for subsequent operations
                    $fullProduct = array_merge($fullProduct, $updatePayload);
                } else {
                    $this->log("❌ Failed to update product {$productId} - Status: {$response->status()} - " . $response->body());
                    return;
                }

                // Rate limit
                usleep(500000);
            }

            // ----------------------
            // 📝 VARIANT FIELDS
            // ----------------------

            // Find current variant data
            $currentVariant = collect($fullProduct['variants'])->firstWhere('id', $variantId);

            if (!$currentVariant) {
                $this->log("⚠️ Variant {$variantId} not found in product {$productId}");
                return;
            }

            $variantPayload = [];

            // Update SKU - only if provided
            if (isset($rug['ID']) && !empty($rug['ID']) && $rug['ID'] !== ($currentVariant['sku'] ?? '')) {
                $variantPayload['sku'] = $rug['ID'];
                $updatedFields[] = 'variant:sku';
                //$this->log("   🔸 Variant SKU changed: '{$currentVariant['sku']}' → '{$rug['ID']}'");
            }

            // Update price - only if provided
            if (isset($regularPrice) || isset($sellingPrice)) {
                if (!empty($currentPrice) && $currentPrice != ($currentVariant['price'] ?? null)) {
                    $variantPayload['price'] = $currentPrice;
                    $updatedFields[] = 'variant:price';
                    //$this->log("   🔸 Variant price changed: '{$currentVariant['price']}' → '{$currentPrice}'");
                }

                // Update compare_at_price (for sale items)
                $compareAtPrice = null;
                if (!empty($sellingPrice) && !empty($regularPrice) && $sellingPrice < $regularPrice) {
                    $compareAtPrice = $regularPrice;
                }
                $currentComparePrice = $currentVariant['compare_at_price'] ?? null;
                // Handle string vs number comparison for compare_at_price
                $currentComparePriceStr = $currentComparePrice !== null ? (string)$currentComparePrice : null;
                $compareAtPriceStr = $compareAtPrice !== null ? number_format((float)$compareAtPrice, 2, '.', '') : null;

                if ($currentComparePriceStr !== $compareAtPriceStr) {
                    $variantPayload['compare_at_price'] = $compareAtPrice;
                    $updatedFields[] = 'variant:compare_at_price';
                    //$this->log("   🔸 Compare at price changed: '{$currentComparePrice}' → '{$compareAtPrice}'");
                }
            }

            // Update inventory - only if provided
            if (isset($rug['inventory']['manageStock'])) {
                $inventoryManagement = $rug['inventory']['manageStock'] ? 'shopify' : null;
                if ($inventoryManagement !== ($currentVariant['inventory_management'] ?? null)) {
                    $variantPayload['inventory_management'] = $inventoryManagement;
                    $updatedFields[] = 'variant:inventory_management';
                    //$this->log("   🔸 Inventory management changed: '{$currentVariant['inventory_management']}' → '{$inventoryManagement}'");
                }
            }

            // Update weight - only if provided
            if (isset($rug['weight_grams']) && $rug['weight_grams'] != ($currentVariant['grams'] ?? null)) {
                $variantPayload['grams'] = $rug['weight_grams'];
                $updatedFields[] = 'variant:weight';
                //$this->log("   🔸 Variant weight changed: '{$currentVariant['grams']}' → '{$rug['weight_grams']}'");
            }

            // ----------------------
            // 🚨 CRITICAL: VARIANT OPTIONS UPDATE
            // ----------------------
            $needsOptionUpdate = false;

            // Helper to normalize size strings for comparison (handles spacing differences)
            $normalizeSize = function ($sizeStr) {
                // Remove extra spaces and normalize quotes
                $normalized = preg_replace('/\s+/', ' ', trim($sizeStr));
                $normalized = str_replace(['"', '"', '"'], '"', $normalized); // Normalize smart quotes
                return $normalized;
            };

            // Check option1 (size) - THIS IS THE KEY FIX
            if (array_key_exists('size', $rug)) { // Check if size key exists in rug data
                $currentOption1 = $normalizeSize($currentVariant['option1'] ?? '');
                $newOption1 = $normalizeSize($size);

                //$this->log("   🔍 Checking Option1 (Size): Current='{$currentOption1}' | New='{$newOption1}'");

                if ($newOption1 !== '' && $newOption1 !== $currentOption1) {
                    $variantPayload['option1'] = $size; // Use original size, not normalized
                    $updatedFields[] = 'variant:option1_size';
                    $needsOptionUpdate = true;
                    //$this->log("   🔸 Option1 (Size) changed: '{$currentVariant['option1']}' → '{$size}'");
                }
            }

            // Check option3 (nominal size)
            if (array_key_exists('size', $rug)) {
                $currentOption3 = $normalizeSize($currentVariant['option3'] ?? '');
                $newOption3 = $normalizeSize($nominalSize);

                //$this->log("   🔍 Checking Option3 (Nominal): Current='{$currentOption3}' | New='{$newOption3}'");

                if ($newOption3 !== '' && $newOption3 !== $currentOption3) {
                    $variantPayload['option3'] = $nominalSize;
                    $updatedFields[] = 'variant:option3_nominal';
                    $needsOptionUpdate = true;
                    //$this->log("   🔸 Option3 (Nominal) changed: '{$currentVariant['option3']}' → '{$nominalSize}'");
                }
            }

            // Check option2 (color) - ONLY if colors are provided AND different
            if (array_key_exists('colourTags', $rug) && !empty($colors)) {
                $currentOption2 = $currentVariant['option2'] ?? 'Default';
                $expectedColor = $colors[0];

                //$this->log("   🔍 Checking Option2 (Color): Current='{$currentOption2}' | New='{$expectedColor}'");

                if ($expectedColor !== $currentOption2) {
                    $variantPayload['option2'] = $expectedColor;
                    $updatedFields[] = 'variant:option2_color';
                    $needsOptionUpdate = true;
                    //$this->log("   🔸 Option2 (Color) changed: '{$currentOption2}' → '{$expectedColor}'");
                }
            }

            // IMPORTANT: Check for duplicate variants
            if ($needsOptionUpdate) {
                $proposedOption1 = $variantPayload['option1'] ?? $currentVariant['option1'];
                $proposedOption2 = $variantPayload['option2'] ?? $currentVariant['option2'];
                $proposedOption3 = $variantPayload['option3'] ?? $currentVariant['option3'];

                $duplicateVariant = collect($fullProduct['variants'])->first(function ($variant) use ($proposedOption1, $proposedOption2, $proposedOption3, $variantId) {
                    return $variant['id'] !== $variantId &&
                        $variant['option1'] === $proposedOption1 &&
                        $variant['option2'] === $proposedOption2 &&
                        $variant['option3'] === $proposedOption3;
                });

                if ($duplicateVariant) {
                    $this->log("⚠️ Cannot update variant options - combination '{$proposedOption1} / {$proposedOption2} / {$proposedOption3}' already exists (Variant ID: {$duplicateVariant['id']})");
                    unset($variantPayload['option1']);
                    unset($variantPayload['option2']);
                    unset($variantPayload['option3']);
                    $updatedFields = array_filter($updatedFields, fn($f) => !str_contains($f, 'option'));
                }
            }

            if (!empty($variantPayload)) {
                $this->log("📝 Updating variant {$variantId}");
                $variantUrl = "https://{$shopifyDomain}/admin/api/2025-07/variants/{$variantId}.json";

                $variantResponse = Http::withHeaders([
                    'X-Shopify-Access-Token' => $settings->shopify_token,
                    'Content-Type' => 'application/json',
                ])->put($variantUrl, [
                    'variant' => $variantPayload
                ]);

                if ($variantResponse->successful()) {
                    $variantUpdates = array_filter($updatedFields, fn($f) => str_starts_with($f, 'variant:'));
                    $this->log("✅ Variant updated: " . implode(', ', $variantUpdates));
                } else {
                    $this->log("❌ Failed to update variant {$variantId} - Status: {$variantResponse->status()} - " . $variantResponse->body());
                }

                usleep(500000);
            }

            // ----------------------
            // 📝 INVENTORY QUANTITY UPDATE
            // ----------------------
            if (isset($rug['inventory']['quantityLevel'][0]['available'])) {
                $newQuantity = $rug['inventory']['quantityLevel'][0]['available'];
                $currentQuantity = $currentVariant['inventory_quantity'] ?? 0;

                if ($newQuantity != $currentQuantity && ($currentVariant['inventory_management'] ?? null) === 'shopify') {
                    //$this->log("📝 Updating inventory quantity for variant {$variantId}");
                    //$this->log("   🔸 Inventory changed: {$currentQuantity} → {$newQuantity}");

                    $inventoryItemId = $currentVariant['inventory_item_id'] ?? null;

                    if ($inventoryItemId) {
                        $locationsUrl = "https://{$shopifyDomain}/admin/api/2025-07/locations.json";
                        $locationsResponse = Http::withHeaders([
                            'X-Shopify-Access-Token' => $settings->shopify_token,
                        ])->get($locationsUrl);

                        if ($locationsResponse->successful()) {
                            $locations = $locationsResponse->json('locations');
                            if (!empty($locations)) {
                                $locationId = $locations[0]['id'];

                                $inventoryUrl = "https://{$shopifyDomain}/admin/api/2025-07/inventory_levels/set.json";
                                $inventoryResponse = Http::withHeaders([
                                    'X-Shopify-Access-Token' => $settings->shopify_token,
                                    'Content-Type' => 'application/json',
                                ])->post($inventoryUrl, [
                                    'location_id' => $locationId,
                                    'inventory_item_id' => $inventoryItemId,
                                    'available' => $newQuantity
                                ]);

                                if ($inventoryResponse->successful()) {
                                    //$this->log("✅ Inventory updated");
                                    $updatedFields[] = 'inventory:quantity';
                                } else {
                                    $this->log("❌ Failed to update inventory - Status: {$inventoryResponse->status()}");
                                }

                                usleep(500000);
                            }
                        }
                    }
                }
            }

            // ----------------------
            // 📝 IMAGES - ONLY UPDATE IF ACTUALLY CHANGED (FIXED)
            // ----------------------
            // ----------------------
            // 📝 IMAGES - ONLY UPDATE IF ACTUALLY CHANGED (FIXED)
            // ----------------------
            if (isset($rug['images']) && is_array($rug['images']) && !empty($rug['images'])) {
                $newUrls = array_values($rug['images']);
                $currentImages = $fullProduct['images'] ?? [];
                $currentUrls = array_values(array_map(fn($img) => $img['src'] ?? '', $currentImages));

                // Remove empty values
                $newUrls = array_filter($newUrls);
                $currentUrls = array_filter($currentUrls);

                // Extract filenames from URLs for comparison (ignore domain differences)
                $extractFilename = function ($url) {
                    // Get the filename with extension from URL
                    $parts = parse_url($url);
                    $path = $parts['path'] ?? '';
                    $filename = basename($path);
                    // Remove query parameters from filename comparison
                    return explode('?', $filename)[0];
                };

                $newFilenames = array_map($extractFilename, $newUrls);
                $currentFilenames = array_map($extractFilename, $currentUrls);

                sort($newFilenames);
                sort($currentFilenames);

                //$this->log("   🔍 Comparing images:");
                //$this->log("      Current count: " . count($currentFilenames));
                //$this->log("      New count: " . count($newFilenames));

                // Check if arrays are actually different
                $imagesDifferent = false;

                if (count($newFilenames) !== count($currentFilenames)) {
                    $imagesDifferent = true;
                    //$this->log("      → Different counts");
                } else {
                    // Compare each filename
                    foreach ($newFilenames as $index => $filename) {
                        if (!isset($currentFilenames[$index]) || $currentFilenames[$index] !== $filename) {
                            $imagesDifferent = true;
                            //$this->log("      → Filename mismatch at index {$index}");
                            //$this->log("         Current: {$currentFilenames[$index]}");
                            //$this->log("         New: {$filename}");
                            break;
                        }
                    }
                }

                if ($imagesDifferent) {
                    //$this->log("   📝 Images have changed - updating for product {$productId}");

                    $newImages = array_map(fn($img) => ['src' => $img], $rug['images']);
                    $productUrl = "https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json";

                    $imageResponse = Http::withHeaders([
                        'X-Shopify-Access-Token' => $settings->shopify_token,
                        'Content-Type' => 'application/json',
                    ])->put($productUrl, [
                        'product' => ['images' => $newImages]
                    ]);

                    if ($imageResponse->successful()) {
                        //$this->log("   ✅ Images updated");
                        $updatedFields[] = 'images';
                    } else {
                        $this->log("   ❌ Failed to update images - Status: {$imageResponse->status()}");
                    }

                    usleep(500000);
                } else {
                    //$this->log("   ℹ️ Images unchanged (same filenames) - skipping update");
                }
            }

            // ----------------------
            // 📝 CUSTOM METAFIELDS
            // ----------------------

            $existingMetafields = [];
            foreach ($shopifyData['metafields'] as $meta) {
                $key = $meta['namespace'] . '.' . $meta['key'];
                $existingMetafields[$key] = $meta;
            }

            $customUpdates = [];

            // 1. Dimension fields
            if (isset($rug['dimension']) && !empty($rug['dimension'])) {
                foreach (['length', 'width', 'height'] as $dim) {
                    if (isset($rug['dimension'][$dim]) && !empty($rug['dimension'][$dim])) {
                        $value = $rug['dimension'][$dim];
                        $metaKey = 'custom.' . $dim;
                        $current = $existingMetafields[$metaKey]['value'] ?? null;

                        if ((string)$value !== (string)$current) {
                            $customUpdates[] = [
                                'id' => $existingMetafields[$metaKey]['id'] ?? null,
                                'namespace' => 'custom',
                                'key' => $dim,
                                'value' => (string)$value,
                                'type' => 'single_line_text_field'
                            ];
                            $updatedFields[] = "metafield:$dim";
                            $this->log("   🔸 Metafield '{$dim}' changed: '{$current}' → '{$value}'");
                        }
                    }
                }
            }

            // 2. Other metafields
            $metaFields = [
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
                'consignmentisActive' => 'consignment_active',
                'consignorRef' => 'consignor_ref',
                'parentId' => 'parent_id',
                'agreedLowPrice' => 'agreed_low_price',
                'agreedHighPrice' => 'agreed_high_price',
                'payoutPercentage' => 'payout_percentage'
            ];

            foreach ($metaFields as $field => $key) {
                if (array_key_exists($field, $rug)) {
                    $value = $rug[$field];
                    $metaKey = 'custom.' . $key;
                    $current = $existingMetafields[$metaKey]['value'] ?? null;

                    if ((string)$value !== (string)$current) {
                        $customUpdates[] = [
                            'id' => $existingMetafields[$metaKey]['id'] ?? null,
                            'namespace' => 'custom',
                            'key' => $key,
                            'value' => (string)$value,
                            'type' => 'single_line_text_field'
                        ];
                        $updatedFields[] = "metafield:$key";
                        $this->log("   🔸 Metafield '{$key}' changed: '{$current}' → '{$value}'");
                    }
                }
            }

            // 3. Cost per square
            if (isset($rug['costPerSquare']['foot']) && !empty($rug['costPerSquare']['foot'])) {
                $value = $rug['costPerSquare']['foot'];
                $metaKey = 'custom.cost_per_square_foot';
                $current = $existingMetafields[$metaKey]['value'] ?? null;

                if ((string)$value !== (string)$current) {
                    $customUpdates[] = [
                        'id' => $existingMetafields[$metaKey]['id'] ?? null,
                        'namespace' => 'custom',
                        'key' => 'cost_per_square_foot',
                        'value' => (string)$value,
                        'type' => 'single_line_text_field'
                    ];
                    $updatedFields[] = "metafield:cost_per_square_foot";
                    $this->log("   🔸 Metafield 'cost_per_square_foot' changed: '{$current}' → '{$value}'");
                }
            }

            if (isset($rug['costPerSquare']['meter']) && !empty($rug['costPerSquare']['meter'])) {
                $value = $rug['costPerSquare']['meter'];
                $metaKey = 'custom.cost_per_square_meter';
                $current = $existingMetafields[$metaKey]['value'] ?? null;

                if ((string)$value !== (string)$current) {
                    $customUpdates[] = [
                        'id' => $existingMetafields[$metaKey]['id'] ?? null,
                        'namespace' => 'custom',
                        'key' => 'cost_per_square_meter',
                        'value' => (string)$value,
                        'type' => 'single_line_text_field'
                    ];
                    $updatedFields[] = "metafield:cost_per_square_meter";
                    $this->log("   🔸 Metafield 'cost_per_square_meter' changed: '{$current}' → '{$value}'");
                }
            }

            // 4. Rental Price
            if (isset($rug['rental_price_value']) && !empty($rug['rental_price_value'])) {
                $rentalPrice = '';
                $rental = $rug['rental_price_value'];

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
                    $metaKey = 'custom.rental_price';
                    $current = $existingMetafields[$metaKey]['value'] ?? null;

                    if ((string)$rentalPrice !== (string)$current) {
                        $customUpdates[] = [
                            'id' => $existingMetafields[$metaKey]['id'] ?? null,
                            'namespace' => 'custom',
                            'key' => 'rental_price',
                            'value' => (string)$rentalPrice,
                            'type' => 'single_line_text_field'
                        ];
                        $updatedFields[] = "metafield:rental_price";
                        $this->log("   🔸 Metafield 'rental_price' changed: '{$current}' → '{$rentalPrice}'");
                    }
                }
            }

            // ----------------------
            // 🚀 SEND METAFIELD UPDATES
            // ----------------------
            if (!empty($customUpdates)) {
                $metaUpdateCount = 0;
                foreach ($customUpdates as $field) {
                    if (!empty($field['id'])) {
                        $metaUrl = "https://{$shopifyDomain}/admin/api/2025-07/metafields/{$field['id']}.json";

                        $response = Http::withHeaders([
                            'X-Shopify-Access-Token' => $settings->shopify_token,
                            'Content-Type' => 'application/json',
                        ])->put($metaUrl, [
                            'metafield' => [
                                'value' => $field['value'],
                                'type' => $field['type']
                            ]
                        ]);
                    } else {
                        $metaUrl = "https://{$shopifyDomain}/admin/api/metafields.json";

                        $response = Http::withHeaders([
                            'X-Shopify-Access-Token' => $settings->shopify_token,
                            'Content-Type' => 'application/json',
                        ])->post($metaUrl, [
                            'metafield' => [
                                'namespace' => $field['namespace'],
                                'key' => $field['key'],
                                'value' => $field['value'],
                                'type' => $field['type'],
                                'owner_id' => $productId,
                                'owner_resource' => 'product'
                            ]
                        ]);
                    }

                    if ($response->successful()) {
                        $metaUpdateCount++;
                    } else {
                        $this->log("❌ Failed to update metafield {$field['key']} - Status: {$response->status()}");
                    }

                    usleep(500000);
                }

                if ($metaUpdateCount > 0) {
                    $this->log("✅ Updated {$metaUpdateCount} metafields");
                }
            }

            // ----------------------
            // 📊 FINAL SUMMARY
            // ----------------------
            if (empty($updatePayload) && empty($variantPayload) && empty($customUpdates) && !in_array('images', $updatedFields)) {
                $this->log("⚠️ No changes needed for SKU: {$shopifyData['sku']}");
            } else {
                $this->log("🎉 Successfully updated product {$productId} (SKU: {$shopifyData['sku']})");
                $this->log("   📋 Summary - Updated fields: " . implode(', ', array_unique($updatedFields)));
            }
        } catch (\Exception $e) {
            $this->log("❌ Exception updating product {$shopifyData['product_id']} - " . $e->getMessage());
        }
    }



    /**
     * Helper logging
     */
    private function log($message)
    {
        // If logFilePath not set, fallback to an 'unknown' shop file to avoid failure
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

        // If a shop log is available, use it; otherwise write to an exception file in imports/exception
        if (!empty($this->logFilePath) && dirname($this->logFilePath) !== storage_path('logs/imports')) {
            // write to the last-used shop log
            $this->log($errorMessage);
        } else {
            // ensure exception dir exists
            $exDir = storage_path('logs/imports/exception');
            if (!file_exists($exDir)) {
                mkdir($exDir, 0777, true);
            }
            $exFile = $exDir . '/exception_' . now()->format('Y-m-d_H-i-s') . '.log';
            file_put_contents($exFile, $errorMessage . PHP_EOL, FILE_APPEND);
        }

        Log::error('Daily import failed', [
            'exception' => $e,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        return Command::FAILURE;
    }
}
