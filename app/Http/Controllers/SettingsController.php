<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use Shopify\Clients\Rest as ShopifyRestClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use function Psy\debug;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SettingsController extends Controller
{

    public function updateProductAjax(Request $request)
    {
        try {
            $response = $this->updateProduct($request); // call existing function
            return response()->json([
                'success' => true,
                'message' => 'Products Updated Successfully!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function updateProduct(Request $request)
    {
        try {
            $settings = Setting::first();
            if (!$settings || !$settings->shopify_store_url || !$settings->shopify_token) {
                return back()->with('error', 'Shopify credentials are missing.');
            }

            Log::info("[UPDATE_PRODUCT_DEBUG] ");

            // $allProducts = [];
            // $nextPageInfo = null;
            // $limit = 250;
            // $pageCount = 1;

            // do {

            //     // if($pageCount == 1){
            //     //     $pageCount++;
            //     //     continue;
            //     // }

            //     $url = "https://{$settings->shopify_store_url}/admin/api/2025-07/products.json?limit={$limit}";
            //     if ($nextPageInfo) {
            //         $url .= "&page_info={$nextPageInfo}";
            //     }

            //     $response = Http::withHeaders([
            //         'X-Shopify-Access-Token' => $settings->shopify_token,
            //         'Content-Type' => 'application/json',
            //     ])->timeout(3600)->get($url);

            //     if (!$response->successful()) {
            //         return back()->with('error', "Shopify API Failed: " . $response->status());
            //     }

            //     $products = $response->json('products', []);
            //     if (empty($products)) break;

            //     $allProducts = array_merge($allProducts, $products);

            //     $linkHeader = $response->header('Link');
            //     if ($linkHeader && preg_match('/page_info=([^&>]+)/', $linkHeader, $matches)) {
            //         $nextPageInfo = $matches[1];
            //         $pageCount++;
            //     } else {
            //         $nextPageInfo = null;
            //     }
            //     //sleep(10);
            //     // if ($pageCount == 2) {
            //     //     break;
            //     // }
            // } while ($nextPageInfo);


            $limit = 250;
            $nextPageInfo = null;

            // Build first page request URL
            //$url = "https://{$settings->shopify_store_url}/admin/api/2025-07/products.json?limit={$limit}";

            $url = "https://rugs-simple.myshopify.com/admin/api/2025-07/products.json?limit=250&page_info=eyJkaXJlY3Rpb24iOiJuZXh0IiwibGFzdF9pZCI6ODU0Nzg5MzU0MzA2NSwibGFzdF92YWx1ZSI6IlR1cmtpc2ggT3VzaGFrIFRhdXBlXC9UYXVwZSJ9";

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $settings->shopify_token,
                'Content-Type' => 'application/json',
            ])->timeout(3600)->get($url);

            if (!$response->successful()) {
                Log::error("Shopify API Failed: " . $response->status());
                return;
            }

            // Grab the first batch of products
            $allProducts = $response->json('products', []);

            // Log product IDs or full objects (be mindful of giant logs!)
            // Log::info('Fetched First Page Products', [
            //     'product_count' => count($allProducts),
            //     'product_ids' => collect($allProducts)->pluck('id')
            // ]);

            // Find the next page via Link header
            $linkHeader = $response->header('Link');
            $nextPageUrl = null;

            if ($linkHeader && preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
                $nextPageUrl = $matches[1];
            }

            // Log the next URL
            Log::info('Next Pagination URL:', [
                'next_url' => $nextPageUrl ?? 'No more pages'
            ]);

            //Log::info("[UPDATE_PRODUCT_DEBUG] Fetched Products: " . count($allProducts));

            if (empty($allProducts)) {
                Log::error("Shopify API Failed: No products found");
                return back()->with('error', 'No products found in Shopify.');
            }

            [$shopifyProducts, $sku_list] = $this->buildSkuList($allProducts);

            if (empty($sku_list)) {
                return back()->with('error', 'No valid SKUs found in Shopify.');
            }

            Log::info("[UPDATE_PRODUCT_DEBUG] Fetched Products: " . json_encode($sku_list));

            $rugToken = $settings->token;
            $tokenExpiry = $settings->token_expiry ? Carbon::parse($settings->token_expiry) : null;

            if (!$rugToken || !$tokenExpiry || $tokenExpiry->isPast()) {
                $tokenResponse = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'x-api-key' => $settings->api_key,
                ])->post('https://plugin-api.rugsimple.com/api/token');

                if (!$tokenResponse->successful()) {
                    Log::error("[UPDATE_PRODUCT_DEBUG] Failed to get Rug API token: " . $tokenResponse->body());
                    return back()->with('error', 'Failed to get Rug API token.');
                }

                $rugToken = $tokenResponse['token'];
                $settings->update([
                    'token' => $rugToken,
                    'token_expiry' => Carbon::now()->addHours(3)
                ]);
            }

            Log::info("[UPDATE_PRODUCT_DEBUG] Fetched before fetchRugProducts");

            $rugProducts = $this->fetchRugProducts($rugToken, $sku_list);

            //Log::info("[UPDATE_PRODUCT_DEBUG] Fetched Products: " . count($rugProducts));

            if (empty($rugProducts)) {
                return back()->with('error', 'No Rug Product Data Found.');
            }

            Log::info("[UPDATE_PRODUCT_DEBUG] Fetched Rug Products: " . count($rugProducts));

            $processedProducts = $this->process_product_data($rugProducts);
            $shopifyDomain = rtrim($settings->shopify_store_url, '/');

            $updatedCount = 0;
            $failedCount = 0;

            foreach ($processedProducts as $rug) {
                $sku = $rug['ID'] ?? '';
                if (!$sku || !isset($shopifyProducts[$sku])) continue;

                $shopifyData = $shopifyProducts[$sku];
                $productId = $shopifyData['product_id'];
                $updatePayload = [];

                $size = $rug['size'];
                $updatedTitle = $rug['title'] . ' #' . $rug['ID'];

                if (isset($size) && $size != '') {
                    $updatedTitle = $size . ' ' . $updatedTitle;
                }

                //Log::info("[UPDATE_PRODUCT_DEBUG] Shopify Title: {$shopifyData['title']}");
                //Log::info("[UPDATE_PRODUCT_DEBUG] Updated Title: {$updatedTitle}");

                if (!empty($updatedTitle) && $updatedTitle !== $shopifyData['title']) {
                    $updatePayload['title'] = $updatedTitle;

                    Log::info("[UPDATE_PRODUCT_DEBUG] Updated Title: {$updatedTitle}");
                }

                if (!empty($updatePayload)) {
                    $url = "https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json";
                    $response = Http::withHeaders([
                        'X-Shopify-Access-Token' => $settings->shopify_token,
                        'Content-Type' => 'application/json',
                    ])->put($url, [
                        'product' => $updatePayload
                    ]);

                    if ($response->successful()) {
                        $updatedCount++;
                        Log::info("[UPDATE_PRODUCT_DEBUG] Updated Title: {$updatedTitle}");
                    } else {
                        $failedCount++;
                        Log::info("[UPDATE_PRODUCT_DEBUG] failed: {$sku}");
                    }
                }

                // sleep(2);
            }

            return back()->with('success', "Successfully updated: {$updatedCount}, Failed: {$failedCount}");
        } catch (\Exception $e) {
            return back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    private function buildSkuList($allProducts)
    {
        $products = [];
        $sku_list = [];

        foreach ($allProducts as $product) {
            foreach ($product['variants'] ?? [] as $variant) {
                $sku = $variant['sku'] ?? null;
                if ($sku) {
                    $sku_list[] = $sku;
                    $products[$sku] = [
                        'title' => $product['title'],
                        'product_id' => $product['id'],
                    ];
                }
            }
        }
        return [$products, $sku_list];
    }

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


    public function index(Request $request)
    {
        $shop = $request->query('shop'); // URL se shop mil jayega

        $isVerified = false;
        $settings = null;

        if ($shop) {
            // Check shop exists in DB or not
            $settings = Setting::where('shopify_store_url', $shop)->first();

            if ($settings && $settings->shopify_token) {
                try {
                    $response = Http::withHeaders([
                        'X-Shopify-Access-Token' => $settings->shopify_token,
                        'Content-Type' => 'application/json',
                    ])->get("https://{$shop}/admin/api/2025-07/shop.json");

                    if ($response->successful()) {
                        $isVerified = true;
                    }
                } catch (\Exception $e) {
                    $isVerified = false;
                }
            }
        }

        return view('settings', compact('isVerified', 'settings', 'shop'));
    }


    public function showImportLogs(Request $request)
    {
        $shop = $request->query('shop') ?? session('shop');
        $shopSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', parse_url($shop, PHP_URL_HOST) ?? $shop);
        $logDir = storage_path('logs/imports/' . $shopSlug);

        $logFiles = [];
        if (file_exists($logDir)) {
            $logFiles = array_reverse(glob($logDir . '/*.log'));
            $logFiles = array_map('basename', $logFiles);
        }

        $selectedLog = $request->query('log');
        $logEntries = [];

        if ($selectedLog && in_array($selectedLog, $logFiles)) {
            $logContent = file_get_contents($logDir . '/' . $selectedLog);
            $logEntries = $this->parseLogEntries($logContent);
        }

        return view('import-logs', [
            'logFiles' => $logFiles,
            'selectedLog' => $selectedLog,
            'logEntries' => $logEntries,
            'shopSlug' => $shopSlug,
            'shop' => $shop,
        ]);
    }


    protected function parseLogEntries($logContent)
    {
        $entries = [];
        $lines = explode("\n", trim($logContent));

        foreach ($lines as $line) {
            if (empty($line)) continue;

            // Parse timestamp
            $timestamp = '';
            if (preg_match('/^\[([^\]]+)\]/', $line, $matches)) {
                $timestamp = $matches[1];
                $line = substr($line, strlen($matches[0]) + 1);
            }

            // Determine entry type
            $type = 'info';
            if (str_contains($line, '❌')) $type = 'error';
            if (str_contains($line, '✅')) $type = 'success';
            if (str_contains($line, '⚠️')) $type = 'warning';

            $entries[] = [
                'timestamp' => $timestamp,
                'message' => trim($line),
                'type' => $type
            ];
        }

        return $entries;
    }


    public function update(Request $request)
    {
        $request->validate([
            'shop' => 'required|string'
        ]);

        $shopUrl = rtrim($request->shop, '/');

        // ✅ Find store record
        $settings = Setting::where('shopify_store_url', $shopUrl)->first();

        if (!$settings) {
            return back()->with('error', 'Store not found! Please save Shopify credentials first.');
        }

        // ✅ Only update allowed fields
        $settings->update($request->only('email', 'product_limit', 'product_skip'));

        return back()->with('success', '✅ Settings updated successfully.');
    }


    public function saveShopifyCredentials(Request $request)
    {
        $request->validate([
            'shopify_store_url' => 'required|string',
            'shopify_token' => 'required|string',
        ], [
            'shopify_store_url.required' => 'The Shopify store URL is required.',
            'shopify_token.required' => 'The Shopify Admin Token is required.',
        ]);

        $shopUrl = rtrim($request->shopify_store_url, '/');
        $accessToken = $request->shopify_token;

        try {
            // Validate token with Shopify API
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->get("https://{$shopUrl}/admin/api/2025-07/shop.json");

            if ($response->successful()) {

                // ✅ Check using the shop URL
                $settings = Setting::where('shopify_store_url', $shopUrl)->first();

                if ($settings) {
                    // ✅ Update existing store record
                    $settings->update([
                        'shopify_token' => $accessToken
                    ]);

                    return back()->with('success', '✅ Store credentials updated successfully.');
                } else {
                    // ✅ Create new store record
                    Setting::create([
                        'shopify_store_url' => $shopUrl,
                        'shopify_token' => $accessToken,
                    ]);

                    return back()->with('success', '✅ New store credentials saved successfully.');
                }
            }

            return back()->with('error', '❌ Invalid credentials. Please check your URL or token.');
        } catch (\Exception $e) {
            return back()->with('error', '❌ Failed to connect to Shopify: ' . $e->getMessage());
        }
    }

    public function createClient(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'shop' => 'required|string'
            ]);

            $shopUrl = rtrim($request->shop, '/');

            // ✅ Check specific store exists
            $settings = Setting::where('shopify_store_url', $shopUrl)->first();

            if (!$settings) {
                return back()->with('error', 'Store not found! Please save Shopify credentials first.');
            }

            // ✅ Update new email for this shop
            $settings->update([
                'email' => $request->email
            ]);

            $email = $settings->email;
            if (!$email) {
                return back()->with('error', 'Email is required before creating a client.');
            }

            // ✅ API request to create client
            $response = Http::retry(3, 1000)
                ->timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post('https://plugin-api.rugsimple.com/admin/create-client', [
                    'email' => $email
                ]);

            if ($response->successful()) {
                $responseData = $response->json();

                if (isset($responseData['data']['API_KEY'])) {
                    // ✅ Update only this shop record
                    $settings->update([
                        'api_key' => $responseData['data']['API_KEY']
                    ]);

                    return back()->with('success', '✅ Client created and API key saved.');
                }

                if (isset($responseData['error'])) {
                    return back()->with('error', '❌ Error: ' . $responseData['error']);
                }
            }

            return back()->with('error', '❌ Failed to create client. HTTP Status: ' . $response->status());
        } catch (\Exception $e) {
            return back()->with('error', '❌ Exception: ' . $e->getMessage());
        }
    }

    public function deleteClient(Request $request)
    {
        $request->validate([
            'shop' => 'required|string'
        ]);

        $shopUrl = rtrim($request->shop, '/');

        // ✅ Find store record
        $settings = Setting::where('shopify_store_url', $shopUrl)->first();

        if (!$settings) {
            return back()->with('error', 'Store not found! Please save Shopify credentials first.');
        }

        if (!$settings->email || !$settings->api_key) {
            return back()->with('error', 'Email or API key missing. Please create client first.');
        }

        $email = $settings->email;
        $apiKey = $settings->api_key;
        $deleteUrl = 'https://plugin-api.rugsimple.com/api/client?email=' . urlencode($email);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(3600)->delete($deleteUrl);

            $status = $response->status();
            $body = $response->json();

            switch ($status) {
                case 200:
                case 201:
                case 204:
                    // ✅ Clear ONLY the current store's credentials
                    $settings->update([
                        'api_key' => null,
                        'token' => null,
                        'token_expiry' => null,
                        'email' => null,
                    ]);

                    return back()->with('success', $body['message'] ?? '✅ Client deleted successfully.');

                case 404:
                    return back()->with('error', $body['error'] ?? '❌ Client not found.');

                case 401:
                case 403:
                    return back()->with('error', '❌ Unauthorized: Invalid or expired API key.');

                default:
                    return back()->with('error', $body['error'] ?? "Unexpected error. HTTP Code: $status");
            }
        } catch (\Exception $e) {
            return back()->with('error', '❌ API connection failed: ' . $e->getMessage());
        }
    }

    private function buildProductTags(array $product): array
    {
        $tags = [];

        // Product category
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

        // Taxonomy tag fields
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
            'otherTags',
        ];

        foreach ($tagFields as $field) {
            if (!empty($product[$field])) {
                if (is_string($product[$field]) && strpos($product[$field], ',') !== false) {
                    foreach (array_map('trim', explode(',', $product[$field])) as $value) {
                        $tags[] = $value;
                    }
                } else {
                    $tags[] = $product[$field];
                }
            }
        }

        // Size category tags
        if (!empty($product['sizeCategoryTags'])) {
            foreach (array_map('trim', explode(',', $product['sizeCategoryTags'])) as $tag) {
                $tags[] = $tag;
            }
        }

        // Shape category tags
        if (!empty($product['shapeCategoryTags'])) {
            foreach (array_map('trim', explode(',', $product['shapeCategoryTags'])) as $tag) {
                $tags[] = $tag;
            }
        }

        // Colour tags
        if (!empty($product['colourTags'])) {
            foreach (array_map('trim', explode(',', $product['colourTags'])) as $tag) {
                $tags[] = $tag;
            }
        }

        // Collection names
        if (!empty($product['collectionDocs'])) {
            foreach ($product['collectionDocs'] as $collection) {
                if (!empty($collection['name'])) {
                    $tags[] = trim($collection['name']);
                }
            }
        }

        // Category / subcategory
        if (!empty($product['category'])) {
            $tags[] = $product['category'];
        }
        if (!empty($product['subCategory'])) {
            $tags[] = $product['subCategory'];
        }

        return array_values(array_unique($tags));
    }




    /**
     * updateShopifyProduct
     *
     * Your rug API inventory structure:
     *   inventory.quantityLevel[0].available = 1   (single value, no per-sku breakdown)
     *   inventory.manageStock = true
     *
     * Key fixes:
     *  1. Variant: isSingleItem=true means ONE variant per product. We update only that
     *     variant — no multi-variant loops creating wrong duplicates.
     *  2. Inventory: reads directly from quantityLevel[0].available (no sku-map needed).
     *     Sets inventory_management='shopify' on variant first, THEN calls inventory_levels/set.
     *  3. Tags, metafields (upsert), images, status all fully updated.
     */
    private function updateShopifyProduct(array $rug, $settings, callable $sendMessage, int $progress = 0, ?callable $rateLimiter = null, ?int $cachedLocationId = null): bool
    {
        $sku           = $rug['ID'];
        $shopifyDomain = rtrim($settings->shopify_store_url, '/');

        // Use provided rate limiter or create a local one
        $shopifyRateLimit = $rateLimiter ?? function () {
            usleep(550000); // 550ms fallback
        };

        $sendMessage([
            'type'     => 'progress',
            'progress' => $progress,
            'message'  => "   🔧 Updating SKU: {$sku}",
        ]);

        try {

            // ============================================================
            // STEP 1: FIND VARIANT BY SKU
            // ============================================================
            $searchResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                ->get("https://{$shopifyDomain}/admin/api/2025-07/variants.json", ['sku' => $sku]);

            if (!$searchResponse->successful() || empty($searchResponse->json('variants'))) {
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "   ❌ No product found for SKU: {$sku}", 'success' => false]);
                return false;
            }

            $matchedVariant = $searchResponse->json('variants')[0];
            $variantId      = $matchedVariant['id'];
            $productId      = $matchedVariant['product_id'];

            $shopifyRateLimit();

            // ============================================================
            // STEP 2: FETCH FULL PRODUCT
            // ============================================================
            $productResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                ->get("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json");

            if (!$productResponse->successful()) {
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "   ❌ Failed to fetch product for SKU: {$sku}", 'success' => false]);
                return false;
            }

            $fullProduct = $productResponse->json('product');
            $shopifyRateLimit();

            // ============================================================
            // STEP 3: FETCH EXISTING METAFIELDS
            // ============================================================
            $existingMetafields = [];
            $metafieldsResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                ->get("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/metafields.json");

            if ($metafieldsResponse->successful()) {
                foreach ($metafieldsResponse->json('metafields') as $meta) {
                    $existingMetafields[$meta['namespace'] . '.' . $meta['key']] = $meta;
                }
            }

            $shopifyRateLimit();

            $updatedFields = [];

            // ============================================================
            // PREPARE SHARED DATA
            // ============================================================
            $size        = $rug['size'] ?? '';
            $shapeTags   = [];
            if (!empty($rug['shapeCategoryTags'])) {
                $shapeTags = array_map('ucfirst', array_map('trim', explode(',', $rug['shapeCategoryTags'])));
            }

            $nominalSize = $this->convertSizeToNominal($size);
            if (!empty($shapeTags)) {
                $nominalSize .= ' ' . implode(' ', $shapeTags);
            }

            $regularPrice   = $rug['regularPrice'] ?? null;
            $sellingPrice   = $rug['sellingPrice'] ?? null;
            $currentPrice   = !empty($sellingPrice) ? $sellingPrice : $regularPrice;
            $compareAtPrice = (!empty($sellingPrice) && !empty($regularPrice) && $sellingPrice < $regularPrice)
                ? $regularPrice : null;

            $updatedTitle = $rug['title'] . ' #' . $rug['ID'];
            if (!empty($size)) {
                $updatedTitle = $size . ' ' . $updatedTitle;
            }

            // Inventory: your API has a single quantityLevel[0].available — read it directly
            $newQty      = (int)($rug['inventory']['quantityLevel'][0]['available'] ?? 0);
            $manageStock = ($rug['inventory']['manageStock'] ?? false) === true;

            // Status based on stock
            $status = ($newQty <= 0) ? 'draft' : (($rug['status'] ?? '') === 'available' ? 'active' : 'draft');

            // ============================================================
            // BUILD TAGS (same logic as importProducts)
            // ============================================================
            $tags = [];

            if (!empty($rug['product_category'])) {
                if ($rug['product_category'] === 'both') {
                    $tags[] = 'Rugs for Rent';
                    $tags[] = 'Rugs for Sale';
                } elseif ($rug['product_category'] === 'rental') {
                    $tags[] = 'Rugs for Rent';
                } elseif ($rug['product_category'] === 'sale') {
                    $tags[] = 'Rugs for Sale';
                }
            }

            foreach (['constructionType', 'country', 'primaryMaterial', 'design', 'palette', 'pattern', 'styleTags', 'otherTags', 'foundation', 'region', 'rugType', 'productType'] as $field) {
                if (!empty($rug[$field])) {
                    if (is_string($rug[$field]) && strpos($rug[$field], ',') !== false) {
                        foreach (array_map('trim', explode(',', $rug[$field])) as $v) {
                            $tags[] = $v;
                        }
                    } else {
                        $tags[] = $rug[$field];
                    }
                }
            }

            foreach (['sizeCategoryTags', 'shapeCategoryTags', 'colourTags'] as $field) {
                if (!empty($rug[$field])) {
                    foreach (array_map('trim', explode(',', $rug[$field])) as $t) {
                        $tags[] = $t;
                    }
                }
            }

            if (!empty($rug['collectionDocs'])) {
                foreach ($rug['collectionDocs'] as $col) {
                    if (!empty($col['name'])) {
                        $tags[] = trim($col['name']);
                    }
                }
            }

            if (!empty($rug['category'])) {
                $tags[] = $rug['category'];
            }
            if (!empty($rug['subCategory'])) {
                $tags[] = $rug['subCategory'];
            }

            // ============================================================
            // STEP 4: UPDATE PRODUCT (title, body, vendor, type, tags, status)
            // ============================================================
            $basicResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                ->put("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json", [
                    'product' => [
                        'title'        => $updatedTitle,
                        'body_html'    => '<p>' . ($rug['description'] ?? '') . '</p>',
                        'vendor'       => $rug['vendor'] ?? 'Oriental Rug Mart',
                        'product_type' => !empty($rug['constructionType']) ? ucfirst($rug['constructionType']) : '',
                        'tags'         => implode(',', array_unique(array_filter($tags))),
                        'status'       => $status,
                    ],
                ]);

            if ($basicResponse->successful()) {
                $updatedFields[] = 'basic_info';
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ✓ Basic info updated (status: {$status}, qty: {$newQty})"]);
            } else {
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Basic info failed ({$basicResponse->status()}): " . $basicResponse->body()]);
            }

            $shopifyRateLimit();

            // ============================================================
            // STEP 5: ENSURE OPTIONS (Size + Nominal Size)
            // ============================================================
            $currentOptions = $fullProduct['options'] ?? [];
            $hasSize = $hasNominal = false;
            foreach ($currentOptions as $opt) {
                $n = strtolower($opt['name'] ?? '');
                if ($n === 'size')         $hasSize    = true;
                if ($n === 'nominal size') $hasNominal = true;
            }

            if (!$hasSize || !$hasNominal) {
                $optResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                    ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                    ->put("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json", [
                        'product' => [
                            'options' => [
                                ['name' => 'Size',         'values' => [$size ?: 'Default']],
                                ['name' => 'Nominal Size', 'values' => [$nominalSize ?: 'Default']],
                            ],
                        ],
                    ]);

                if ($optResponse->successful()) {
                    $updatedFields[] = 'options';
                    $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ✓ Options set (Size / Nominal Size)"]);
                    $shopifyRateLimit();
                    $refresh = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                        ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                        ->get("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json");
                    if ($refresh->successful()) {
                        $fullProduct = $refresh->json('product');
                    }
                } else {
                    $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Options update failed: " . $optResponse->body()]);
                }
                $shopifyRateLimit();
            }

            // ============================================================
            // STEP 6: FIND THE CORRECT VARIANT
            // ============================================================
            $currentVariant = collect($fullProduct['variants'])->firstWhere('id', $variantId);
            if (!$currentVariant) {
                foreach ($fullProduct['variants'] as $v) {
                    if ($v['sku'] === $sku) {
                        $currentVariant = $v;
                        $variantId = $v['id'];
                        break;
                    }
                }
            }

            if (!$currentVariant) {
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ❌ Cannot find variant for SKU: {$sku}", 'success' => false]);
                return false;
            }

            // ============================================================
            // STEP 7: UPDATE VARIANT
            // Set inventory_management='shopify' here so Step 9 inventory set works.
            // Do NOT pass inventory_quantity — use inventory_levels/set instead.
            // ============================================================

            $variantResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                ->put("https://{$shopifyDomain}/admin/api/2025-07/variants/{$variantId}.json", [
                    'variant' => [
                        'sku'                  => $sku,
                        'price'                => $currentPrice,
                        'compare_at_price'     => $compareAtPrice,
                        'option1'              => $size ?: 'Default',
                        'option2'              => $nominalSize ?: 'Default',
                        'inventory_management' => $manageStock ? 'shopify' : null,
                        'requires_shipping'    => true,
                        'taxable'              => true,
                        'fulfillment_service'  => 'manual',
                        'grams'                => $rug['weight_grams'] ?? 0,
                    ],
                ]);

            if ($variantResponse->successful()) {
                $updatedFields[] = 'variant';
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ✓ Variant updated (price: {$currentPrice})"]);
            } else {
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Variant update failed ({$variantResponse->status()}): " . $variantResponse->body()]);
            }

            $shopifyRateLimit();

            // 🔥 Always refresh variant directly
            $variantRefresh = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                ->get("https://{$shopifyDomain}/admin/api/2025-07/variants/{$variantId}.json");

            if ($variantRefresh->successful()) {
                $freshVariant = $variantRefresh->json('variant');
                $inventoryItemId = $freshVariant['inventory_item_id'] ?? null;
            } else {
                $inventoryItemId = null;
            }




            // ============================================================
            // STEP 8: DELETE DUPLICATE VARIANTS
            // isSingleItem=true means only 1 variant should exist.
            // ============================================================
            $allVariants = $fullProduct['variants'] ?? [];
            if (count($allVariants) > 1) {
                foreach ($allVariants as $v) {
                    if ($v['id'] !== $variantId) {
                        try {
                            $delResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                                ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                                ->delete("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/variants/{$v['id']}.json");

                            $sendMessage([
                                'type' => 'progress',
                                'progress' => $progress,
                                'message' => $delResponse->successful()
                                    ? "      ✓ Removed duplicate variant: {$v['id']} (SKU: {$v['sku']})"
                                    : "      ⚠️ Could not remove variant {$v['id']}: " . $delResponse->body(),
                            ]);
                        } catch (\Exception $e) {
                            $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Delete variant exception: " . $e->getMessage()]);
                        }
                        $shopifyRateLimit();
                    }
                }
            }

            // ============================================================
            // STEP 9: SET INVENTORY
            //
            // Your API: inventory.quantityLevel[0].available (e.g. 1)
            // No per-sku breakdown — one value applied to the single variant.
            //
            // Requires inventory_management='shopify' (set in Step 7 above).
            // Uses inventory_item_id from the matched variant.
            // ============================================================
            //$inventoryItemId = $currentVariant['inventory_item_id'] ?? null;
            $sendMessage(['message' => "Inventory Item Used: {$inventoryItemId}"]);
            if (!$inventoryItemId) {
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ No inventory_item_id — inventory skipped"]);
            } elseif (!$manageStock) {
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ℹ️ manageStock=false — skipping inventory"]);
            } else {
                $locationId = $cachedLocationId;
                if (!$locationId) {
                    try {
                        $shopifyRateLimit();
                        $locResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                            ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                            ->get("https://{$shopifyDomain}/admin/api/2025-07/locations.json");

                        if ($locResponse->successful() && !empty($locResponse->json('locations'))) {
                            $locationId = $locResponse->json('locations')[0]['id'];
                            $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ✓ Location: {$locationId}"]);
                        } else {
                            $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ No locations found"]);
                        }
                    } catch (\Exception $e) {
                        $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Location fetch failed: " . $e->getMessage()]);
                    }
                }

                $shopifyRateLimit();

                if ($locationId && $inventoryItemId) {
                    try {

                        // 1️⃣ Connect inventory to location
                        Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                            ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                            ->post("https://{$shopifyDomain}/admin/api/2025-07/inventory_levels/connect.json", [
                                'location_id'       => $locationId,
                                'inventory_item_id' => $inventoryItemId,
                            ]);

                        $shopifyRateLimit();


                        $invResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                            ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                            ->post("https://{$shopifyDomain}/admin/api/2025-07/inventory_levels/set.json", [
                                'location_id'       => $locationId,
                                'inventory_item_id' => $inventoryItemId,
                                'available'         => $newQty,
                            ]);

                        if ($invResponse->successful()) {
                            $updatedFields[] = 'inventory';
                            $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ✓ Inventory set to {$newQty}"]);
                        } else {
                            $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Inventory set failed ({$invResponse->status()}): " . $invResponse->body()]);
                        }
                    } catch (\Exception $e) {
                        $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Inventory exception: " . $e->getMessage()]);
                    }
                    $shopifyRateLimit();
                }
            }

            // ============================================================
            // STEP 10: UPDATE IMAGES
            // ============================================================
            if (!empty($rug['images'])) {
                try {
                    $imgResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                        ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                        ->put("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}.json", [
                            'product' => [
                                'images' => array_map(fn($img) => ['src' => str_replace(' ', '%20', $img)], $rug['images']),
                            ],
                        ]);

                    if ($imgResponse->successful()) {
                        $updatedFields[] = 'images';
                        $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ✓ Images updated (" . count($rug['images']) . ")"]);
                    } else {
                        $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Images failed: " . $imgResponse->body()]);
                    }
                } catch (\Exception $e) {
                    $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ⚠️ Images exception: " . $e->getMessage()]);
                }
                $shopifyRateLimit();
            }

            // ============================================================
            // STEP 11: UPSERT ALL METAFIELDS
            // ============================================================

            $upsertMeta = function (string $namespace, string $key, string $type, $value) use (
                $shopifyDomain,
                $settings,
                $productId,
                &$existingMetafields
            ): bool {
                $lookupKey = "{$namespace}.{$key}";
                $metaId    = $existingMetafields[$lookupKey]['id'] ?? null;
                try {
                    if ($metaId) {
                        $r = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                            ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                            ->put("https://{$shopifyDomain}/admin/api/2025-07/metafields/{$metaId}.json", [
                                'metafield' => ['value' => $value, 'type' => $type],
                            ]);
                    } else {
                        $r = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                            ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                            ->post("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/metafields.json", [
                                'metafield' => ['namespace' => $namespace, 'key' => $key, 'type' => $type, 'value' => $value],
                            ]);
                        if ($r->successful()) {
                            $existingMetafields[$lookupKey] = $r->json('metafield');
                        }
                    }
                    usleep(550000);
                    return $r->successful();
                } catch (\Exception $e) {
                    usleep(550000);
                    return false;
                }
            };

            $metaUpdates = 0;

            // Product dimensions
            foreach (['length', 'width', 'height'] as $dim) {
                if (isset($rug['dimension'][$dim]) && $rug['dimension'][$dim] !== '') {
                    if ($upsertMeta('custom', $dim, 'dimension', json_encode(['value' => (float)$rug['dimension'][$dim], 'unit' => 'INCHES']))) {
                        $metaUpdates++;
                    }
                }
            }

            // Shipping dimensions
            foreach (['height' => 'packageheight', 'length' => 'packagelength', 'width' => 'packagewidth'] as $field => $key) {
                if (isset($rug['shipping'][$field]) && $rug['shipping'][$field] !== '') {
                    if ($upsertMeta('custom', $key, 'dimension', json_encode(['value' => (float)$rug['shipping'][$field], 'unit' => 'INCHES']))) {
                        $metaUpdates++;
                    }
                }
            }

            // Text metafields
            foreach (
                [
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
                    'payoutPercentage' => 'payout_percentage',
                ] as $field => $key
            ) {
                if (array_key_exists($field, $rug) && $rug[$field] !== null && $rug[$field] !== '') {
                    if ($upsertMeta('custom', $key, 'single_line_text_field', (string)$rug[$field])) {
                        $metaUpdates++;
                    }
                }
            }

            // Rental price
            $rentalPrice = '';
            $rpData = $rug['rental_price_value'] ?? null;
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
                if ($upsertMeta('custom', 'rental_price', 'single_line_text_field', (string)$rentalPrice)) {
                    $metaUpdates++;
                }
            }

            // source flag (create only if missing)
            if (empty($existingMetafields['custom.source'])) {
                Http::timeout(60)->connectTimeout(30)
                    ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token, 'Content-Type' => 'application/json'])
                    ->post("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/metafields.json", [
                        'metafield' => [
                            'namespace' => 'custom',
                            'key'       => 'source',
                            'type'      => 'boolean',
                            'value'     => 'true', // ✅ fixed: string instead of boolean
                        ],
                    ]);
            } else {
                $metafieldId = $existingMetafields['custom.source']['id'];

                Http::timeout(60)->connectTimeout(30)
                    ->withHeaders([
                        'X-Shopify-Access-Token' => $settings->shopify_token,
                        'Content-Type'           => 'application/json'
                    ])
                    ->put("https://{$shopifyDomain}/admin/api/2025-07/metafields/{$metafieldId}.json", [
                        'metafield' => [
                            'id'    => $metafieldId,
                            'type'  => 'boolean',
                            'value' => 'true', // ✅ fixed: string instead of boolean
                        ],
                    ]);
            }

            if ($metaUpdates > 0) {
                $updatedFields[] = "{$metaUpdates}_metafields";
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ✓ {$metaUpdates} metafields upserted"]);
            } else {
                $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "      ℹ️ No metafield changes"]);
            }

            // ============================================================
            // DONE
            // ============================================================
            if (!empty($updatedFields)) {
                $sendMessage([
                    'type'     => 'progress',
                    'progress' => $progress,
                    'message'  => "   ✅ SKU {$sku} updated: " . implode(', ', $updatedFields),
                    'success'  => true,
                ]);
                return true;
            }

            $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "   ⚠️ Nothing updated for SKU: {$sku}", 'success' => false]);
            return false;
        } catch (\Exception $e) {
            $sendMessage(['type' => 'progress', 'progress' => $progress, 'message' => "   ❌ Exception for SKU {$sku}: " . $e->getMessage(), 'success' => false]);
            return false;
        }
    }

    public function importProducts(Request $request)
    {
        $shop = $request->input('shop');
        $skus = $request->input('skus');
        $settings = Setting::where('shopify_store_url', $shop)->first();

        if (!$settings) {
            return back()->with('error', 'Shop not found.');
        }

        // Create StreamedResponse for SSE (real-time browser updates)
        $response = new StreamedResponse(function () use ($request, $settings, $shop, $skus) {

            // Ensure real-time flushing works
            @ini_set('zlib.output_compression', 0);
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', true);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);

            echo str_repeat(' ', 4096); // kickstart output buffer
            flush();

            // === Shop-specific log directory ===
            $shopSlug = preg_replace('/[^a-zA-Z0-9_-]/', '_', parse_url($shop, PHP_URL_HOST) ?? $shop);
            $logDir = storage_path('logs/imports/' . $shopSlug);
            if (!file_exists($logDir)) {
                mkdir($logDir, 0777, true);
            }

            $logFileName = 'import_log_' . now()->format('Y-m-d_H-i-s') . '.log';
            $logFilePath = $logDir . '/' . $logFileName;

            // === Helper: Stream + File Log ===
            $sendMessage = function (array $data) use ($logFilePath) {
                $timestamp = now()->format('Y-m-d H:i:s');
                $message = $data['message'] ?? '';
                $type = strtoupper($data['type'] ?? 'INFO');

                // Write to file
                $logLine = "[$timestamp][$type] $message";
                file_put_contents($logFilePath, $logLine . PHP_EOL, FILE_APPEND);

                // Send to client (SSE)
                $data['timestamp'] = $timestamp;
                echo "data: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                @ob_flush();
                @flush();
            };

            // === ADD RATE LIMITING HELPER HERE (RIGHT AFTER $sendMessage) ===
            $lastRequestTime = microtime(true);
            $shopifyRateLimit = function () use (&$lastRequestTime) {
                $minDelay = 0.55; // 550ms — stays safely under 2 requests/second
                $elapsed = microtime(true) - $lastRequestTime;

                if ($elapsed < $minDelay) {
                    usleep(($minDelay - $elapsed) * 1000000);
                }

                $lastRequestTime = microtime(true);
            };
            // === END OF RATE LIMITING HELPER ===

            try {

                $sendMessage(['type' => 'info', 'message' => 'Starting product import...']);

                $logs = [];
                $successCount = 0;
                $failCount = 0;
                $skippedCount = 0;
                $updatedCount = 0;

                // === Process SKUs if provided ===
                $skuArray = [];
                if (!empty($skus)) {
                    $skuArray = array_map('trim', explode(',', $skus));
                    $skuArray = array_filter($skuArray); // Remove empty values

                    if (!empty($skuArray)) {
                        $sendMessage(['type' => 'info', 'message' => 'Filtering by ' . count($skuArray) . ' SKUs: ' . implode(', ', $skuArray)]);
                    }
                } else {
                    $sendMessage(['type' => 'info', 'message' => 'No SKUs provided, importing products based on limit and skip settings.']);
                }



                if (!$settings || !$settings->api_key) {
                    $sendMessage(['type' => 'error', 'message' => 'API key not found. Please configure your client settings.']);
                    return;
                }

                // === Token Handling ===
                $tokenExpiry = $settings->token_expiry ? Carbon::parse($settings->token_expiry) : null;

                if (!$settings->token || !$tokenExpiry || $tokenExpiry->isPast()) {
                    // Request a new token
                    $tokenResponse = Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'x-api-key' => $settings->api_key,
                    ])->timeout(3600)->post('https://plugin-api.rugsimple.com/api/token');

                    if (!$tokenResponse->successful() || !isset($tokenResponse['token'])) {

                        $sendMessage(['type' => 'error', 'message' => 'Failed to get token for this shop.']);
                        return;
                    }

                    // Save new token and expiry against the correct shop record
                    $token = $tokenResponse['token'];
                    $settings->update([
                        'token' => $token,
                        'token_expiry' => now()->addHours(3),
                    ]);

                    $sendMessage([
                        'type' => 'info',
                        'message' => 'New token generated successfully for shop: ' . $settings->shopify_store_url,
                    ]);
                } else {
                    $token = $settings->token;
                    $sendMessage([
                        'type' => 'info',
                        'message' => 'Using existing valid token for shop: ' . $settings->shopify_store_url,
                    ]);
                }

                // === Get Products ===
                if (!empty($skuArray)) {
                    // Fetch products by SKUs using POST request
                    $sendMessage(['type' => 'info', 'message' => 'Fetching products by SKUs...']);

                    try {
                        $productResponse = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $token,
                        ])
                            ->timeout(120) // Increased timeout to 120 seconds
                            ->connectTimeout(30) // Connection timeout
                            ->retry(3, 2000) // Retry 3 times with 2 second delay
                            ->post('https://plugin-api.rugsimple.com/api/rug', [
                                'ids' => $skuArray
                            ]);

                        if (!$productResponse->successful()) {
                            $sendMessage(['type' => 'error', 'message' => 'Failed to fetch products by SKUs. Status: ' . $productResponse->status()]);
                            return;
                        }
                    } catch (\Exception $e) {
                        $sendMessage(['type' => 'error', 'message' => 'Error fetching products by SKUs: ' . $e->getMessage()]);
                        return;
                    }
                } else {
                    // Fetch products using limit and skip
                    $limit = $settings->product_limit ?? 10;
                    $skip = $settings->product_skip ?? 0;

                    $sendMessage(['type' => 'info', 'message' => "Fetching products with limit: {$limit}, skip: {$skip}"]);

                    try {
                        $productResponse = Http::withHeaders([
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $token,
                        ])
                            ->timeout(120) // Increased timeout
                            ->connectTimeout(30)
                            ->retry(3, 2000)
                            ->get('https://plugin-api.rugsimple.com/api/rug', [
                                'limit' => $limit,
                                'skip' => $skip,
                            ]);

                        if (!$productResponse->successful()) {
                            $sendMessage(['type' => 'error', 'message' => 'Failed to fetch products. Status: ' . $productResponse->status()]);
                            return;
                        }
                    } catch (\Exception $e) {
                        $sendMessage(['type' => 'error', 'message' => 'Error fetching products: ' . $e->getMessage()]);
                        return;
                    }
                }

                $responseData = $productResponse->json();
                $products = $responseData['data'] ?? [];

                if (!$settings->shopify_store_url || !$settings->shopify_token) {
                    $sendMessage(['type' => 'error', 'message' => 'Shopify store URL or access token is missing.']);
                    return;
                }

                $shopifyDomain = rtrim($settings->shopify_store_url, '/');

                // Process the product data
                $processedProducts = $this->process_product_data($products);

                $totalProducts = count($processedProducts);

                if ($totalProducts === 0) {
                    $sendMessage(['type' => 'info', 'message' => 'No products found to import.']);
                    $sendMessage([
                        'type' => 'complete',
                        'progress' => 100,
                        'message' => "No products to import",
                        'success_count' => 0,
                        'failure_count' => 0,
                        'skipped_count' => 0,
                    ]);

                    echo "event: done\n";
                    echo "data: [DONE]\n\n";
                    @ob_flush();
                    @flush();
                    return;
                }

                $sendMessage(['type' => 'info', 'message' => "Found {$totalProducts} products to process."]);
                $processed = 0;

                // Pre-fetch location ID once (avoids calling locations.json per product)
                $cachedLocationId = null;
                try {
                    $shopifyRateLimit();
                    $locResponse = Http::timeout(60)->connectTimeout(30)->retry(3, 2000)
                        ->withHeaders(['X-Shopify-Access-Token' => $settings->shopify_token])
                        ->get("https://{$shopifyDomain}/admin/api/2025-07/locations.json");

                    if ($locResponse->successful() && !empty($locResponse->json('locations'))) {
                        $cachedLocationId = $locResponse->json('locations')[0]['id'];
                        $sendMessage(['type' => 'info', 'message' => "✓ Location pre-fetched: {$cachedLocationId}"]);
                    }
                } catch (\Exception $e) {
                    $sendMessage(['type' => 'info', 'message' => "⚠️ Could not pre-fetch location: " . $e->getMessage()]);
                }

                foreach ($processedProducts as $index => $product) {

                    $processed++;
                    $progress = round((($index + 1) / $totalProducts) * 100);

                    $title = $product['title'] ?? 'Untitled Product';

                    $sendMessage([
                        'type' => 'progress',
                        'progress' => $progress,
                        'message' => "Processing product " . ($index + 1) . "/{$totalProducts}: {$title}"
                    ]);

                    // Get the SKU we'll use to check for existing products
                    $sku = $product['ID'] ?? null; // ID will be used for sku

                    if (!$sku) {
                        $logs[] = ['message' => '❌ Failed: Missing SKU.' . ($product['title'] ?? 'Untitled'), 'success' => false];
                        $message = '❌ Failed: Missing SKU.' . ($product['title'] ?? 'Untitled');
                        $failCount++;

                        $sendMessage([
                            'progress' => $progress,
                            'message' => $message,
                            'type' => 'progress',
                            'success' => false
                        ]);

                        continue; // Skip products without a SKU
                    }

                    if (empty($product['title'])) {
                        $logs[] = ['message' => '❌ Failed: Missing Product Title. (' . $sku . ')  ', 'success' => false];
                        $message = '❌ Failed: Missing Product Title. (' . $sku . ')';
                        $failCount++;

                        $sendMessage([
                            'progress' => $progress,
                            'message' => $message,
                            'type' => 'progress',
                            'success' => false
                        ]);

                        continue; // Skip products without a SKU
                    }

                    if (empty($product['regularPrice'])) {
                        $logs[] = ['message' => '❌ Failed: Missing Regular Price. (' . $sku . ')  ', 'success' => false];
                        $message = '❌ Failed: Missing Regular Price. (' . $sku . ')';
                        $failCount++;

                        $sendMessage([
                            'progress' => $progress,
                            'message' => $message,
                            'type' => 'progress',
                            'success' => false
                        ]);

                        continue; // Skip products without a regular Price
                    }

                    if (empty($product['product_category'])) {
                        $logs[] = ['message' => '❌ Failed: Missing Product Category. Rental:Sale:Both (' . $sku . ')  ', 'success' => false];
                        $message = '❌ Failed: Missing Product Category. Rental:Sale:Both (' . $sku . ')';
                        $failCount++;

                        $sendMessage([
                            'progress' => $progress,
                            'message' => $message,
                            'type' => 'progress',
                            'success' => false
                        ]);

                        continue; // Skip products without a product category
                    }

                    if (empty($product['images'])) {
                        $logs[] = ['message' => '❌ Failed: Missing Product Product Images. (' . $sku . ')  ', 'success' => false];
                        $message = '❌ Failed: Missing Product Product Images. (' . $sku . ')';
                        $failCount++;

                        $sendMessage([
                            'progress' => $progress,
                            'message' => $message,
                            'type' => 'progress',
                            'success' => false
                        ]);

                        continue; // Skip products without a product category
                    }

                    // Check if product already exists in Shopify having same sku
                    $existingSkuProduct = $this->checkProductBySkuOrTitle($settings, $shopifyDomain, $sku, 'sku');

                    if ($existingSkuProduct) {
                        $updateResult = $this->updateShopifyProduct($product, $settings, $sendMessage, $progress, $shopifyRateLimit, $cachedLocationId);
                        if ($updateResult) {
                            $updatedCount++;
                        } else {
                            $failCount++;
                        }
                        continue;
                    }

                    // Prepare tags array
                    $tags = [];

                    // Add product category tags
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

                    // Add other tags from WordPress taxonomies
                    $tagFields = [
                        //'condition',
                        'constructionType',
                        'country',
                        //'production',
                        'primaryMaterial',
                        'design',
                        'palette',
                        'pattern',
                        //'pile',
                        //'period',
                        'styleTags',
                        'otherTags',
                        'foundation',
                        //'age',
                        //'quality',
                        'region',
                        //'density',
                        //'knots',
                        'rugType',
                        'productType'
                    ];

                    foreach ($tagFields as $field) {
                        if (!empty($product[$field])) {
                            // Check if the field is a comma-separated string
                            if (is_string($product[$field]) && strpos($product[$field], ',') !== false) {
                                // Split the comma-separated string and add each value as a tag
                                $values = array_map('trim', explode(',', $product[$field]));
                                foreach ($values as $value) {
                                    $tags[] = $value;
                                }
                            } else {
                                // Add the single value as a tag
                                $tags[] = $product[$field];
                            }
                        }
                    }

                    // Add size category tags (already processed as comma-separated string)
                    if (!empty($product['sizeCategoryTags'])) {
                        $sizeTags = array_map('trim', explode(',', $product['sizeCategoryTags']));
                        foreach ($sizeTags as $sizeTag) {
                            $tags[] = $sizeTag;
                        }
                    }

                    // Add shape category tags (already processed as comma-separated string)
                    $shapeTags = [];
                    if (!empty($product['shapeCategoryTags'])) {
                        $shapeTags = array_map('trim', explode(',', $product['shapeCategoryTags']));
                        foreach ($shapeTags as $shapeTag) {
                            $tags[] = $shapeTag;
                        }
                    }

                    // Add color tags (already processed as comma-separated string)
                    $variationColors = [];
                    if (!empty($product['colourTags'])) {
                        $colorTags = array_map('trim', explode(',', $product['colourTags']));
                        foreach ($colorTags as $colorTag) {
                            $tags[] = $colorTag;
                            $variationColors[] = $colorTag;
                        }
                    }

                    if (!empty($product['collectionDocs'])) {
                        foreach ($product['collectionDocs'] as $collection) {
                            if (!empty($collection['name'])) {
                                $tags[] = trim($collection['name']);
                            }
                        }
                    }

                    // Add category and subcategory as tags
                    if (!empty($product['category'])) {
                        $tags[] = $product['category'];
                    }
                    if (!empty($product['subCategory'])) {
                        $tags[] = $product['subCategory'];
                    }


                    $price = $product['regularPrice'] ?? '0.00';
                    $compareAtPrice = null;

                    if (!empty($product['sellingPrice']) && $product['regularPrice'] > $product['sellingPrice']) {
                        $price = $product['sellingPrice'];
                        $compareAtPrice = $product['regularPrice'];
                    } else {
                        $price = $product['regularPrice'] ?? '0.00';
                        $compareAtPrice = null; // No sale
                    }


                    $size = $product['size'];
                    $nominalSize = $this->convertSizeToNominal($size);

                    if (!empty($shapeTags)) {
                        $shapeTags = array_map('ucfirst', $shapeTags); // Capitalize first letter of each tag
                        $nominalSize .= ' ' . implode(' ', $shapeTags);
                    }

                    // Get prices from your API response
                    $regularPrice = $product['regularPrice'] ?? null;    // Original/normal price
                    $sellingPrice = $product['sellingPrice'] ?? null;    // Sale/discounted price

                    $currentPrice = !empty($sellingPrice) ? $sellingPrice : $regularPrice;

                    // //Build variants
                    $variants = [];

                    // Build variants
                    $variantData = [
                        "option1" => $size,
                        "option2" => $nominalSize,
                        "price" => $currentPrice,
                        'inventory_management' => ($product['inventory']['manageStock'] ?? false) ? 'shopify' : null,
                        'inventory_quantity' => $product['inventory']['quantityLevel'][0]['available'] ?? null,
                        'sku' => $product['ID'] ?? '',
                        "requires_shipping" => true,
                        "taxable" => true,
                        "fulfillment_service" => "manual",
                        "grams" => $product['weight_grams'] ?? 0,
                    ];

                    // Only add compare_at_price if product is on sale
                    if (!empty($sellingPrice) && !empty($regularPrice) && $sellingPrice < $regularPrice) {
                        $variantData['compare_at_price'] = $regularPrice;
                    }

                    $variants[] = $variantData;

                    $updatedTitle = $product['title'] . ' #' . $product['ID'];

                    if (isset($size) && $size != '') {
                        $updatedTitle = $size . ' ' . $updatedTitle;
                    }


                    $shopifyProduct = [
                        "product" => [
                            'title' => $updatedTitle ?? 'No Title',
                            'body_html' => '<p>' . ($product['description'] ?? '') . '</p>',
                            'vendor' => 'Oriental Rug Mart',
                            'category' => 'Home & Garden > Decor > Rug',
                            'product_type' => isset($product['constructionType']) && $product['constructionType'] !== '' ? ucfirst($product['constructionType']) : '',
                            "options" => [
                                ["name" => "Size", "values" => [$size]],
                                ["name" => "Nominal Size", "values" => [$nominalSize]],
                            ],
                            'images' => [],
                            'tags' => implode(', ', array_unique($tags)),
                            "variants"     => $variants,
                            'gift_card' => false,
                            'status' => ($product['inventory']['quantityLevel'][0]['available'] ?? 0) <= 0 ? 'draft' : (($product['status'] ?? '') === 'available' ? 'active' : 'draft'),
                        ]
                    ];

                    // Add the first image as the featured image
                    if (!empty($product['images'])) {
                        $shopifyProduct['product']['images'] = array_map(function ($imgUrl, $i) {
                            return [
                                'src' => str_replace(' ', '%20', $imgUrl), // Encode spaces in URL
                                'position' => $i + 1,
                            ];
                        }, $product['images'], array_keys($product['images']));
                    }

                    $metafields = [
                        ['namespace' => 'custom', 'key' => 'height', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['dimension']['height'] ?? 0), 'unit' => 'INCHES'])],
                        ['namespace' => 'custom', 'key' => 'length', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['dimension']['length'] ?? 0), 'unit' => 'INCHES'])],
                        ['namespace' => 'custom', 'key' => 'width', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['dimension']['width'] ?? 0), 'unit' => 'INCHES'])],
                        ['namespace' => 'custom', 'key' => 'packageheight', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['shipping']['height'] ?? 0), 'unit' => 'INCHES'])],
                        ['namespace' => 'custom', 'key' => 'packagelength', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['shipping']['length'] ?? 0), 'unit' => 'INCHES'])],
                        ['namespace' => 'custom', 'key' => 'packagewidth', 'type' => 'dimension', 'value' => json_encode(['value' => (float)($product['shipping']['width'] ?? 0), 'unit' => 'INCHES'])],
                    ];

                    // Additional metafield list
                    $extraMetaFields = [
                        'sizeCategoryTags'   => 'size_category_tags',
                        'costType'           => 'cost_type',
                        'cost'               => 'cost',
                        'condition'          => 'condition',
                        'productType'        => 'product_type',
                        'rugType'            => 'rug_type',
                        'constructionType'   => 'construction_type',
                        'country'            => 'country',
                        'production'         => 'production',
                        'primaryMaterial'    => 'primary_material',
                        'design'             => 'design',
                        'palette'            => 'palette',
                        'pattern'            => 'pattern',
                        'pile'               => 'pile',
                        'period'             => 'period',
                        'styleTags'          => 'style_tags',
                        'otherTags'          => 'other_tags',
                        'colourTags'         => 'color_tags',
                        'foundation'         => 'foundation',
                        'age'                => 'age',
                        'quality'            => 'quality',
                        'conditionNotes'     => 'condition_notes',
                        'region'             => 'region',
                        'density'            => 'density',
                        'knots'              => 'knots',
                        'rugID'              => 'rug_id',
                        'size'               => 'size',
                        'isTaxable'          => 'is_taxable',
                        'subCategory'        => 'subcategory',
                        'created_at'         => 'created_at',
                        'updated_at'         => 'updated_at',
                        'consignmentisActive' => 'consignment_active',
                        'consignorRef'       => 'consignor_ref',
                        'parentId'           => 'parent_id',
                        'agreedLowPrice'     => 'agreed_low_price',
                        'agreedHighPrice'    => 'agreed_high_price',
                        'payoutPercentage'   => 'payout_percentage'
                    ];

                    // Loop and merge extra metafields
                    foreach ($extraMetaFields as $field => $key) {
                        if (!empty($product[$field])) {
                            $metafields[] = [
                                'namespace' => 'custom',
                                'key'       => $key,
                                'type'      => 'single_line_text_field',
                                'value'     => (string)$product[$field]
                            ];
                        }
                    }

                    // Sets the source value to identify products imported via the app
                    $metafields[] = [
                        'namespace' => 'custom',
                        'key' => 'source',
                        'type' => 'boolean',
                        'value' => true
                    ];

                    // Get existing metafields for this product
                    // save rental product price
                    $rental_price_data = $product['rental_price_value'];
                    // Case 1: General Price Format
                    $rentalPrice = '';
                    if (isset($rental_price_data['key']) && $rental_price_data['key'] === 'general_price') {
                        $rentalPrice = $rental_price_data['value'];
                    } elseif (isset($rental_price_data['redq_day_ranges_cost']) && is_array($rental_price_data['redq_day_ranges_cost'])) { // Case 2: Day Range Pricing Format
                        foreach ($rental_price_data['redq_day_ranges_cost'] as $range) {
                            if (!empty($range['range_cost'])) {
                                $rentalPrice = $range['range_cost'];
                            }
                        }
                    }

                    if (!empty($rentalPrice)) {
                        $shopifyProduct['product']['metafields'][] = [
                            'namespace' => 'custom',
                            'key' => 'rental_price',
                            'value' => $rentalPrice,
                            'type' => 'single_line_text_field'
                        ];
                    }

                    try {

                        $shopifyRateLimit();
                        // First create the product
                        $response = Http::withHeaders([
                            'X-Shopify-Access-Token' => $settings->shopify_token,
                            'Content-Type' => 'application/json',
                        ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/products.json", $shopifyProduct);


                        if ($response->successful()) {
                            $productData = $response->json();
                            $productId = $productData['product']['id'] ?? null;

                            if ($productId) {
                                // Now add metafields (Shopify sometimes has issues with creating them in the same request)

                                foreach ($metafields as $metafield) {

                                    $shopifyRateLimit();
                                    Http::withHeaders([
                                        'X-Shopify-Access-Token' => $settings->shopify_token,
                                        'Content-Type' => 'application/json',
                                    ])->timeout(3600)->retry(3, 1000)->post("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/metafields.json", [
                                        'metafield' => $metafield
                                    ]);
                                }

                                $logs[] = ['message' => '✅ Imported: ' . ($product['title'] ?? 'Untitled'), 'success' => true];
                                $message = '✅ Imported: ' . ($product['title'] ?? 'Untitled');

                                $sendMessage([
                                    'progress' => $progress,
                                    'message' => $message,
                                    'type' => 'progress',
                                    'success' => true
                                ]);

                                $successCount++;
                            }
                        } else {
                            $logs[] = ['message' => '❌ Failed to import: ' . ($product['title'] ?? 'Untitled'), 'success' => false];
                            $message = '❌ Failed to import: ' . ($product['title'] ?? 'Untitled') . ' - ' . $response->body();

                            $sendMessage([
                                'progress' => $progress,
                                'message' => $message,
                                'type' => 'progress',
                                'success' => false
                            ]);

                            $failCount++;
                        }
                    } catch (\Exception $e) {
                        //\Log::error('Exception inserting product to Shopify: ' . $e->getMessage());
                        $logs[] = ['message' => '❌ Exception: ' . ($product['title'] ?? 'Untitled') . ' - ' . $e->getMessage(), 'success' => false];
                        $message = '❌ Exception: ' . ($product['title'] ?? 'Untitled') . ' - ' . $e->getMessage();

                        $sendMessage([
                            'progress' => $progress,
                            'message' => $message,
                            'type' => 'progress',
                            'success' => false
                        ]);

                        $failCount++;
                        continue;
                    }
                }

                // === Complete ===
                $sendMessage([
                    'type' => 'complete',
                    'progress' => 100,
                    'message' => "Import completed successfully!",
                    'success_count' => $successCount,
                    'failure_count' => $failCount,
                    'skipped_count' => $skippedCount,
                    'updated_count' => $updatedCount,
                ]);

                //$sendMessage(['type' => 'info', 'message' => "Logs saved in {$logFilePath}"]);

                echo "event: done\n";
                echo "data: [DONE]\n\n";
                @ob_flush();
                @flush();
            } catch (\Throwable $e) {
                $sendMessage(['type' => 'error', 'message' => 'Unexpected error: ' . $e->getMessage()]);
            }
        });

        // === Required SSE headers ===
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    public function verifyToken()
    {
        $settings = Setting::firstOrCreate([]);

        if (!$settings || !$settings->shopify_store_url || !$settings->shopify_token) {
            return response()->json(['valid' => false, 'message' => 'No credentials found']);
        }

        $storeUrl = $settings->shopify_store_url;
        $token = $settings->shopify_token;

        try {
            $url = "https://{$storeUrl}/admin/api/2024-01/shop.json";

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $token,
                'Content-Type' => 'application/json',
            ])->get($url);

            if ($response->successful()) {
                return response()->json(['valid' => true]);
            } else {
                return response()->json(['valid' => false, 'message' => 'Invalid credentials']);
            }
        } catch (\Exception $e) {
            return response()->json(['valid' => false, 'message' => 'Connection failed']);
        }
    }

    function convertSizeToNominal($sizeString)
    {
        if (empty(trim($sizeString))) {
            return '';
        }
        // Example input: 5' 8" x 7' 8"
        $dimensions = explode('x', strtolower($sizeString));
        $nominalValues = [];

        foreach ($dimensions as $dim) {
            // Extract feet and inches using regex
            preg_match("/(\d+)'[\s]*([\d]+)?\"?/", trim($dim), $matches);

            $feet = isset($matches[1]) ? (int)$matches[1] : 0;
            $inches = isset($matches[2]) ? (int)$matches[2] : 0;

            // If there are any inches, round up to next foot
            if ($inches >= 5) {
                $feet += 1;
            }

            $nominalValues[] = $feet;
        }

        // Join rounded values into "WxH" format
        return implode('x', $nominalValues);
    }

    /**
     * Determine Shopify product type based on product category
     */
    private function getProductType($productCategory)
    {
        switch ($productCategory) {
            case 'both':
                return 'Rugs for Sale & Rent';
            case 'rental':
                return 'Rugs for Rent';
            case 'sale':
                return 'Rugs for Sale';
            default:
                return 'Unknown';
        }
    }

    /**
     * Check if a product already exists in Shopify by SKU
     */
    private function checkProductExists($settings, $shopifyDomain, $sku)
    {
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $settings->shopify_token,
                'Content-Type' => 'application/json',
            ])->get("https://{$shopifyDomain}/admin/api/2025-07/products.json?sku={$sku}", [
                'limit' => 1
            ]);
            echo '(' . $sku . ')';
            echo "https://{$shopifyDomain}/admin/api/2025-07/products.json?sku={$sku}";
            if ($response->successful()) {
                $products = $response->json()['products'] ?? [];

                print_r($products);
                return count($products) > 0;
            }
        } catch (\Exception $e) {
            // If there's an error checking, assume product doesn't exist
            return false;
        }

        return false;
    }

    public function checkProductBySkuOrTitle($settings, $shopifyDomain, $value = null, $action = 'title')
    {
        if (!$value || !in_array($action, ['sku', 'title'])) {
            return false; // Invalid usage
        }

        try {
            if ($action === 'sku') {
                $query = <<<GQL
            {
              productVariants(first: 1, query: "sku:$value") {
                edges {
                  node {
                    id
                  }
                }
              }
            }
            GQL;
            } else {
                $titleEscaped = addslashes($value);
                $query = <<<GQL
            {
              products(first: 1, query: "title:$titleEscaped") {
                edges {
                  node {
                    id
                  }
                }
              }
            }
            GQL;
            }

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $settings->shopify_token,
                'Content-Type' => 'application/json',
            ])->post("https://{$shopifyDomain}/admin/api/2025-07/graphql.json", [
                'query' => $query
            ]);

            $data = $response->json();

            if ($action === 'sku' && !empty($data['data']['productVariants']['edges'])) {
                return true;
            } elseif ($action === 'title' && !empty($data['data']['products']['edges'])) {
                return true;
            }
        } catch (\Exception $e) {
            // Optionally log $e->getMessage()
            return false;
        }

        return false;
    }

    function process_product_data($products)
    {
        $woo_insertion_data = [];



        foreach ($products as $product) {


            // $allowed_ids = [
            //     '002853',
            //     '004892',
            //     '010301',
            //     '016390',
            //     '017724',
            //     '018196',
            //     '018531',
            //     '018608'
            // ];

            // if (!in_array((string) $product['ID'], $allowed_ids, true)) {
            //     continue;
            // }


            //if (empty($product['collectionDocs'])) {
            //continue; // Skip products without collectionDocs
            // }

            // if (isset($product['consignment']['title']) && !empty($product['consignment']['title'])) {
            //     error_log('Consignment Product: ' . $product['consignment']['title']);
            // }

            // if (isset($product['owned']['title']) && !empty($product['owned']['title'])) {
            //     error_log('Owned Product: ' . $product['owned']['title']);
            // }

            // owned is count as a selling product
            $productCatge = '';

            $ownedActive  = isset($product['owned']['isActive']) && $product['owned']['isActive'] === true;
            $rentalActive = isset($product['rental']['isActive']) && $product['rental']['isActive'] === true;
            $consignmentActive = isset($product['consignment']['isActive']) && $product['consignment']['isActive'] === true;

            $rental_price_value = isset($product['rental']['price_value']) && $product['rental']['price_value'] != null ? $product['rental']['price_value'] : '';

            if ($ownedActive && $rentalActive) {
                $productCatge = 'both';
                //continue;
            } elseif ($rentalActive) {
                $productCatge = 'rental';
                //continue;
            } elseif ($ownedActive) {
                $productCatge = 'sale';
                //continue;
            } elseif ($consignmentActive) { // consignment sale active
                $productCatge = 'sale';
                //continue;
            }

            // Process images - sort by position and extract URLs
            $images = $product['images'] ?? [];
            usort($images, function ($a, $b) {
                return ($a['position'] ?? 0) <=> ($b['position'] ?? 0);
            });

            $image_urls = array_map(function ($image) {
                return $image['url'] ?? $image['thumbnail'] ?? '';
            }, $images);

            $woo_insertion_data[] = [
                'title' => $product['owned']['title'] ?? $product['consignment']['title'] ?? $product['rental']['title'] ?? '',
                'product_category' => $productCatge,
                'description' => $product['description'] ?? '',
                'rental_price_value' => $rental_price_value,
                'regularPrice' => $product['price']['regularPrice'] ?? '',
                'sellingPrice' => $product['price']['sellingPrice'] ?? '',
                'dimension' => $product['dimension'] ?? '',
                'isOnSale' => $product['price']['isOnSale'] ?? false,
                'onSale' => $product['price']['onSale'] ?? [],
                'costType' => $product['price']['costType'] ?? '',
                'cost' => $product['price']['cost'] ?? '',
                'costPerSquare' => $product['price']['costPerSquare'] ?? [],
                //'images' => $product['images'] ?? [],
                'isTaxable' => $product['price']['isTaxable'] ?? false,
                'condition' => $product['condition'] ?? '',
                'productData' => $product['productData'] ?? '',
                'status' => $product['status'] ?? '',
                'legacySKU' => $product['legacySKU'] ?? '',
                'ID' => $product['ID'] ?? '',
                'rugID' => $product['rugID'] ?? '',
                'productType' => $product['productType'] ?? '',
                'rugType' => isset($product['rugType']) ? str_replace('id-', '', $product['rugType']) : '',
                'category' => $product['category']['name'] ?? '',
                'subCategory' => $product['subCategory']['name'] ?? '',
                //'colourTags' => $product['colourTags'] ?? [],
                'colourTags' => $this->formatArrayField($product['colourTags'] ?? []),
                'attributes' => $product['attributes'] ?? [],
                'constructionType' => $product['constructionType'] ?? '',
                'country' => $product['country'] ?? '',
                'collections' => $product['collections'] ?? [],
                'collectionDocs' => $product['collectionDocs'] ?? [],
                'customFields' => $product['customFields'] ?? [],
                'production' => $product['production'] ?? '',
                'primaryMaterial' => $product['primaryMaterial'] ?? '',
                'design' => $product['design'] ?? '',
                'palette' => $product['palette'] ?? '',
                'pattern' => $product['pattern'] ?? '',
                'pile' => $product['pile'] ?? '',
                'period' => $product['period'] ?? '',
                'created_at' => $product['created_at'] ?? '',
                'updated_at' => $product['updated_at'] ?? '',
                'inventory' => $product['inventory'] ?? [],
                'shipping' => $product['shipping'] ?? [],
                //'otherTags' => $product['otherTags'] ?? [],
                'otherTags' => $this->formatArrayField($product['otherTags'] ?? []),
                'sizeCategoryTags' => $this->formatArrayField($product['sizeCategoryTags'] ?? []),
                'styleTags' => $this->formatArrayField($product['styleTags'] ?? []),
                'shapeCategoryTags' => $this->formatArrayField($product['shapeCategoryTags'] ?? []),
                'foundation' => $product['foundation'] ?? '',
                'age' => $product['age'] ?? '',
                'quality' => $product['quality'] ?? '',
                'conditionNotes' => $product['conditionNotes'] ?? '',
                'region' => $product['region'] ?? '',
                'density' => $product['density'] ?? '',
                'knots' => $product['knots'] ?? '',
                'size' => $product['size'] ?? '',
                'consignmentisActive' => $consignmentActive,
                'consignorRef' => $product['consignment']['consignorRef'] ?? '',
                'parentId' => $product['consignment']['parentId'] ?? '',
                'agreedLowPrice' => $product['consignment']['agreedLowPrice'] ?? '',
                'agreedHighPrice' => $product['consignment']['agreedHighPrice'] ?? '',
                'payoutPercentage' => $product['consignment']['payoutPercentage'] ?? '',
                'images' => $image_urls,
                'thumbnail' => !empty($image_urls) ? $image_urls[0] : '',
            ];
        }

        return $woo_insertion_data;
    }

    // Helper function to format array fields
    private function formatArrayField($arrayData)
    {
        if (empty($arrayData)) {
            return '';
        }

        // Handle both array of strings and array of objects with 'name' property
        $values = [];
        foreach ($arrayData as $item) {
            if (is_array($item)) {
                if (isset($item['name'])) {
                    $values[] = $item['name'];
                }
            } else {
                $values[] = $item;
            }
        }

        return implode(', ', $values);
    }
    private function getFakeProducts($limit)
    {
        return collect(range(1, $limit))->map(function ($i) {
            return [
                'title' => "Product $i",
                'body_html' => '<strong>Great product</strong>',
                'vendor' => 'Sample Vendor',
                'product_type' => 'Category',
                'variants' => [
                    [
                        'option1' => 'Default',
                        'price' => '29.99',
                        'sku' => 'SKU' . $i
                    ]
                ],
                'images' => [
                    ['src' => 'https://via.placeholder.com/300']
                ]
            ];
        });
    }
}
