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
        $this->initLog();

        try {
            $this->log("🚀 Starting daily product import...");

            $settings = Setting::first();
            if (!$settings || !$settings->shopify_store_url || !$settings->shopify_token) {
                return $this->fail("❌ Shopify settings not found or invalid.");
            }

            // 1. Fetch Shopify products
            $allProducts = $this->fetchAllShopifyProducts($settings);
            if (empty($allProducts)) {
                return $this->fail("❌ No products fetched from Shopify.");
            }

            // 2. Build SKU list & product map
            [$shopifyComparedProducts, $sku_list] = $this->buildSkuList($allProducts);
            if (empty($sku_list)) {
                return $this->fail("❌ No SKUs found from Shopify products.");
            }

            // 3. Get Rug API token
            $token = $this->getRugApiToken($settings);
            if (!$token) {
                return $this->fail("❌ Failed to retrieve token from Rug API.");
            }

            // 4. Fetch Rug products
            $shopifyFetchedProducts = $this->fetchRugProducts($token, $sku_list);
            if (empty($shopifyFetchedProducts)) {
                return $this->fail("❌ No product data received from Rug API.");
            }

            // 5. Process Rug data
            $settingsController = new SettingsController();
            $processedProducts = $settingsController->process_product_data($shopifyFetchedProducts);

            // 6. Compare & update
            $this->compareAndUpdateProducts($processedProducts, $shopifyComparedProducts);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            return $this->exceptionFail($e);
        }
    }

    /**
     * Initialize log file
     */
    private function initLog()
    {
        $logFileName = 'import_log_' . now()->format('Y-m-d_H-i-s') . '.log';
        $this->logFilePath = storage_path('logs/imports/' . $logFileName);

        if (!file_exists(dirname($this->logFilePath))) {
            mkdir(dirname($this->logFilePath), 0777, true);
        }

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

            foreach ($product['variants'] as $variant) {
                $sku = $variant['sku'] ?? null;
                $variantId = $variant['id'] ?? null;
                $updatedAt = $variant['updated_at'] ?? $product['updated_at'] ?? null;

                if (!empty($sku)) {
                    $sku_list[] = $sku;
                    $products[$sku] = [
                        'id'           => $variantId,
                        'sku'          => $sku,
                        'product_id'   => $product['id'],
                        'title'        => $product['title'],
                        'variant_id'   => $variantId,
                        'variant_name' => $variant['title'],
                        'last_updated' => $updatedAt,
                        'full_product' => $product,
                    ];
                }
            }
        }

         //$this->info(json_encode($products));

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
        return $data['data'] ?? [];
    }

    /**
     * Compare Rug vs Shopify products and update
     */
    private function compareAndUpdateProducts($rugProducts, $shopifyProducts)
    {
        foreach ($rugProducts as $rug) {
            $sku = $rug['ID'] ?? '';
            if (empty($sku) || !isset($shopifyProducts[$sku])) continue;

            $shopifyData = $shopifyProducts[$sku];
            $productId = $shopifyData['id'];
            $shopifyUpdated = $shopifyData['last_updated'];
            $rugUpdated = isset($rug['updated_at']) ? date('Y-m-d H:i:s', strtotime($rug['updated_at'])) : null;


            if ($rugUpdated && $rugUpdated > $shopifyUpdated) {
                $rug['product_id'] = $productId;
                $this->updateShopifyProduct($rug, $shopifyData);
            }
        }
    }

    /**
     * Update Shopify product if differences exist
     */
    private function updateShopifyProduct(array $rug, array $shopifyData)
    {

        $this->info(json_encode($shopifyData));

        return Command::FAILURE;

        try {

            //$settings = new SettingsController();
            $settings = Setting::first();

            $updatePayload = [];
            $updatedFields = [];

            // ----------------------
            // 📝 CORE PRODUCT FIELDS
            // ----------------------
            //if (!empty($rug['title']) && $rug['title'] !== $shopifyData['title']) {
            $updatePayload['title'] = $rug['title'];
            $updatedFields[] = 'title';
            //}

            // Helper to clean description (strip tags, styles, spaces)
            $cleanText = function ($text) {
                // Decode HTML entities
                $text = html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Remove all HTML tags
                $text = strip_tags($text);

                // Replace multiple whitespaces, newlines, tabs, non-breaking spaces
                $text = preg_replace('/[\xC2\xA0|\s]+/u', ' ', $text);

                // Remove any control or invisible Unicode chars
                $text = preg_replace('/[[:cntrl:]]/', '', $text);

                // Lowercase and trim for consistent comparison
                $text = strtolower(trim($text));

                return $text;
            };

            // Cleaned versions for comparison
            $rugDescription = $cleanText($rug['description'] ?? '');
            $shopifyDescription = $shopifyData['description'] ?? '';

            $this->info("🔸 Shopify Description (clean): {$shopifyData['description']}");

            if (!empty($rugDescription) && $rugDescription !== $shopifyDescription) {
                $updatePayload['body_html'] = $rug['description'];
                $updatedFields[] = 'description';

                // Log both sides for debug
                $this->info("📝 Updating description for Shopify product {$shopifyData['id']} (SKU: {$shopifyData['sku']})");
                $this->info("🔹 RUG Description (clean): {$rugDescription}");
                $this->info("🔸 Shopify Description (clean): {$shopifyDescription}");
            }

            if (!empty($rug['vendor']) && $rug['vendor'] !== ($shopifyData['vendor'] ?? '')) {
                $updatePayload['vendor'] = $rug['vendor'];
                $updatedFields[] = 'vendor';
            }

            if (!empty($rug['product_type']) && $rug['product_type'] !== ($shopifyData['product_type'] ?? '')) {
                $updatePayload['product_type'] = $rug['product_type'];
                $updatedFields[] = 'product_type';
            }

            if (!empty($rug['tags'])) {
                $rugTags = is_array($rug['tags']) ? implode(',', $rug['tags']) : $rug['tags'];
                if ($rugTags !== ($shopifyData['tags'] ?? '')) {
                    $updatePayload['tags'] = $rugTags;
                    $updatedFields[] = 'tags';
                }
            }

            // ----------------------
            // 📝 VARIANT FIELDS
            // ----------------------
            $variantPayload = [];
            if (!empty($rug['ID']) && $rug['ID'] !== $shopifyData['sku']) {
                $variantPayload['sku'] = $rug['ID'];
                $updatedFields[] = 'sku';
            }
            if (!empty($rug['price']) && $rug['price'] != ($shopifyData['price'] ?? null)) {
                $variantPayload['price'] = $rug['price'];
                $updatedFields[] = 'price';
            }

            if (!empty($variantPayload)) {
                $variantPayload['id'] = $shopifyData['variant_id'];
                $updatePayload['variants'][] = $variantPayload;
            }

            // ----------------------
            // 📝 IMAGES
            // ----------------------
            if (!empty($rug['images'])) {
                $newImages = array_map(fn($img) => ['src' => $img], $rug['images']);
                $currentImages = $shopifyData['images'] ?? [];
                $currentUrls = array_map(fn($img) => $img['src'], $currentImages);

                if (array_diff(array_column($newImages, 'src'), $currentUrls)) {
                    $updatePayload['images'] = $newImages;
                    $updatedFields[] = 'images';
                }
            }

            // ----------------------
            // 📝 CUSTOM METAFIELDS
            // ----------------------
            $customUpdates = [];

            // 1. Dimension fields
            if (!empty($rug['dimension'])) {
                foreach (['length', 'width', 'height'] as $dim) {
                    $value = $rug['dimension'][$dim] ?? '';
                    $current = $shopifyData['custom_fields']['custom'][$dim] ?? null;
                    if ($value && $value !== $current) {
                        $customUpdates[] = [
                            'namespace' => 'custom',
                            'key' => $dim,
                            'value' => $value,
                            'type' => 'single_line_text_field'
                        ];
                        $updatedFields[] = "dimension:$dim";
                    }
                }
            }

            // 2. Other mapped metafields
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
                if (!empty($rug[$field])) {
                    $value = $rug[$field];
                    $current = $shopifyData['custom_fields']['custom'][$key] ?? null;
                    if ($value !== $current) {
                        $customUpdates[] = [
                            'namespace' => 'custom',
                            'key' => $key,
                            'value' => $value,
                            'type' => 'single_line_text_field'
                        ];
                        $updatedFields[] = "metafield:$key";
                    }
                }
            }

            // 3. Cost per square
            if (!empty($rug['costPerSquare']['foot'])) {
                $value = $rug['costPerSquare']['foot'];
                $current = $shopifyData['custom_fields']['custom']['cost_per_square_foot'] ?? null;
                if ($value !== $current) {
                    $customUpdates[] = [
                        'namespace' => 'custom',
                        'key' => 'cost_per_square_foot',
                        'value' => $value,
                        'type' => 'single_line_text_field'
                    ];
                    $updatedFields[] = "metafield:cost_per_square_foot";
                }
            }
            if (!empty($rug['costPerSquare']['meter'])) {
                $value = $rug['costPerSquare']['meter'];
                $current = $shopifyData['custom_fields']['custom']['cost_per_square_meter'] ?? null;
                if ($value !== $current) {
                    $customUpdates[] = [
                        'namespace' => 'custom',
                        'key' => 'cost_per_square_meter',
                        'value' => $value,
                        'type' => 'single_line_text_field'
                    ];
                    $updatedFields[] = "metafield:cost_per_square_meter";
                }
            }

            // 4. Rental Price
            if (!empty($rug['rental_price_value'])) {
                $rentalPrice = '';
                $rental = $rug['rental_price_value'];
                if (isset($rental['key']) && $rental['key'] === 'general_price') {
                    $rentalPrice = $rental['value'];
                } elseif (!empty($rental['redq_day_ranges_cost'])) {
                    foreach ($rental['redq_day_ranges_cost'] as $range) {
                        if (!empty($range['range_cost'])) {
                            $rentalPrice = $range['range_cost'];
                        }
                    }
                }

                $current = $shopifyData['custom_fields']['custom']['rental_price'] ?? null;
                if ($rentalPrice && $rentalPrice !== $current) {
                    $customUpdates[] = [
                        'namespace' => 'custom',
                        'key' => 'rental_price',
                        'value' => $rentalPrice,
                        'type' => 'single_line_text_field'
                    ];
                    $updatedFields[] = "metafield:rental_price";
                }
            }

            // ----------------------
            // 🚀 SEND UPDATES
            // ----------------------
            //$shopifyDomain = rtrim($settings->shopify_store_url, '/');
            $this->info($settings->shopify_store_url);
            $this->info(json_encode($updatePayload));
            return Command::FAILURE;


            // if (!empty($updatePayload)) {
            //     $this->log("📝 Updating Shopify product {$shopifyData['id']} (SKU: {$shopifyData['sku']})");
            //     $url = "https://{$shopifyDomain}/admin/api/2025-07/products/{$shopifyData['id']}.json";

            //     $response = Http::withHeaders([
            //         'X-Shopify-Access-Token' => $settings->shopify_token,
            //         'Content-Type' => 'application/json',
            //     ])->put($url, [
            //         'product' => $updatePayload
            //     ]);

            //     if ($response->successful()) {
            //         $this->log("✅ Product fields updated: " . implode(', ', $updatedFields));
            //     } else {
            //         $this->log("❌ Failed to update product {$shopifyData['id']} - Status: {$response->status()}");
            //     }
            // }

            // if (!empty($customUpdates)) {
            //     foreach ($customUpdates as $field) {
            //         $this->log("📝 Updating metafield {$field['namespace']}.{$field['key']} for product {$shopifyData['id']}");
            //         $metaUrl = "https://{$shopifyDomain}/admin/api/2025-07/metafields.json";

            //         Http::withHeaders([
            //             'X-Shopify-Access-Token' => $settings->shopify_token,
            //             'Content-Type' => 'application/json',
            //         ])->post($metaUrl, [
            //             'metafield' => array_merge($field, [
            //                 'owner_id'   => $shopifyData['id'],
            //                 'owner_resource' => 'product'
            //             ])
            //         ]);
            //     }
            // }

            // if (empty($updatePayload) && empty($customUpdates)) {
            //     $this->log("⚠️ No changes for SKU: {$shopifyData['sku']}");
            // }
        } catch (\Exception $e) {
            $this->log("❌ Exception updating product {$shopifyData['id']} - " . $e->getMessage());
        }
    }



    /**
     * Helper logging
     */
    private function log($message)
    {
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
        $this->log($errorMessage);
        Log::error('Daily import failed', [
            'exception' => $e,
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return Command::FAILURE;
    }
}
