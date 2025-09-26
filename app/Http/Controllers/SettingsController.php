<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Setting;
use Shopify\Clients\Rest as ShopifyRestClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use function Psy\debug;

class SettingsController extends Controller
{

    public function showImportLogs()
    {
        $logFiles = [];
        $logDir = storage_path('logs/imports');

        if (file_exists($logDir)) {
            $logFiles = array_reverse(glob($logDir . '/*.log'));
            $logFiles = array_map('basename', $logFiles);
        }

        $selectedLog = request('log');
        $logEntries = [];

        if ($selectedLog && in_array($selectedLog, $logFiles)) {
            $logContent = file_get_contents($logDir . '/' . $selectedLog);
            $logEntries = $this->parseLogEntries($logContent);
        }

        return view('import-logs', [
            'logFiles' => $logFiles,
            'selectedLog' => $selectedLog,
            'logEntries' => $logEntries
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
    public function index()
    {
        $settings = Setting::first();
        return view('settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $settings = Setting::firstOrCreate([]);
        $settings->update($request->only('email', 'product_limit', 'product_skip'));
        return back()->with('success', 'Settings updated.');
    }

    public function createClient(Request $request)
    {
        try {

        //dd( $request->input('email') );

            $settings = Setting::firstOrCreate([]);
            // Ensure we always have a saved email
            if ($request->has('email')) {
                // Setting::updateOrCreate(['id' => 1], [ // or your settings key
                //     'email' => $request->input('email')
                // ]);
                 $settings->update($request->only('email'));
            }


             //return back()->with('error', $request->input('email'));



            $email = $settings->email;

            if (!$email) {
                return back()->with('error', 'Email is required before creating a client.');
            }

            $response = Http::retry(3, 1000)
                ->timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post('https://plugin-api.rugsimple.com/admin/create-client', [
                    'email' => $email
                ]);

            // Check if response was successful
            if ($response->successful()) {
                $responseData = $response->json();

                if (isset($responseData['data']['API_KEY'])) {
                    $settings->api_key = $responseData['data']['API_KEY'];
                    $settings->save();

                    return back()->with('success', 'Client created and API key saved.');
                }

                // If no API key but has error message
                if (isset($responseData['error'])) {
                    return back()->with('error', 'Error: ' . $responseData['error']);
                }
            }

            // If response failed
            return back()->with('error', 'Failed to create client. HTTP Status: ' . $response->status());
        } catch (\Exception $e) {
            return back()->with('error', 'Exception: ' . $e->getMessage());
        }
    }

    // public function createClient()
    // {

    //     //return back()->with('error', 'Email is required before creating a client.');

    //     $settings = Setting::firstOrCreate([]);

    //     //dd($settings->email);

    //     $email = $settings->email;

    //     if (!$email) {
    //         return back()->with('error', 'Email is required before creating a client.');
    //     }

    //     // $response = Http::withHeaders([
    //     //     'Content-Type' => 'application/json',
    //     //     'Accept' => 'application/json',
    //     // ])->post('https://plugin-api.rugsimple.com/admin/create-client', [
    //     //     'email' => $email
    //     // ]);

    //     $response = Http::retry(3, 1000) // Retry 3 times with 1 second delay
    //         ->timeout(3600) // 30 second timeout
    //         ->withHeaders([
    //             'Content-Type' => 'application/json',
    //             'Accept' => 'application/json',
    //         ])->post('https://plugin-api.rugsimple.com/admin/create-client', [
    //             'email' => $email
    //         ]);

    //     //echo '<pre>'; // Debugging line to see the response
    //     //print_r($response->json()); // Print the response for debugging
    //     //echo '</pre>';

    //     //debug($response->json()); // Debugging line to see the response

    //     //dd($response->json());

    //     if ($response->successful() && isset($response['data']['API_KEY'])) {
    //         $settings->api_key = $response['data']['API_KEY'];
    //         $settings->save();

    //         return back()->with('success', 'Client created and API key saved.');
    //     }

    //     // If the response has an error, handle it
    //     if (isset($response['error'])) {
    //         return back()->with('error', 'Error: ' . $response['error']);
    //     }

    //     return back()->with('error', 'Failed to create client.');
    // }

    public function deleteClient()
    {
        $settings = Setting::first();

        if (!$settings || !$settings->email || !$settings->api_key) {
            return back()->with('error', 'Email or API key missing. Please create client first.');
        }

        $email = $settings->email;
        $apiKey = $settings->api_key;
        $deleteUrl = 'https://plugin-api.rugsimple.com/api/client?email=' . urlencode($email);

        try {
            $response = Http::withHeaders([
                'x-api-key'    => $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(3600)->delete($deleteUrl);

            $status = $response->status();
            $body = $response->json();

            switch ($status) {
                case 200:
                case 201:
                case 204:
                    // Clear local DB keys
                    $settings->update([
                        'api_key' => null,
                        'token' => null,
                        'token_expiry' => null,
                        'email' => null
                    ]);

                    return back()->with('success', $body['message'] ?? 'Client deleted successfully.');

                case 404:
                    return back()->with('error', $body['error'] ?? 'Client not found.');

                case 401:
                case 403:
                    return back()->with('error', 'Unauthorized or forbidden request. Check your API key.');

                default:
                    return back()->with('error', $body['error'] ?? "Unexpected error. HTTP Code: $status");
            }
        } catch (\Exception $e) {
            return back()->with('error', 'API connection failed: ' . $e->getMessage());
        }
    }

    public function importProducts()
    {
        // Check if this is a streaming request


        // Set SSE headers
        @ini_set('zlib.output_compression', 0);
        @ini_set('output_buffering', 0);
        @ini_set('output_handler', '');
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Create log file name with timestamp
        $logFileName = 'import_log_' . now()->format('Y-m-d_H-i-s') . '.log';
        $logFilePath = storage_path('logs/imports/' . $logFileName);

        // Ensure directory exists
        if (!file_exists(dirname($logFilePath))) {
            mkdir(dirname($logFilePath), 0777, true);
        }

        // Function to send SSE messages
        // $sendMessage = function ($data) {
        //     echo "data: " . json_encode($data) . "\n\n";
        //     ob_flush();
        //     flush();
        //     if (connection_aborted()) exit();
        // };

        $sendMessage = function ($data) use ($logFilePath) {
            // Format log entry
            $timestamp = now()->format('Y-m-d H:i:s');
            $type = $data['type'] ?? 'info';
            $message = $data['message'] ?? '';

            // Format for file storage
            $logEntry = "[$timestamp] $message";
            file_put_contents($logFilePath, $logEntry . PHP_EOL, FILE_APPEND);

            // Format for SSE
            $data['timestamp'] = $timestamp;
            echo "data: " . json_encode($data) . "\n\n";
            ob_flush();
            flush();
        };

        set_time_limit(1000); // 1000 seconds = 16 minutes and 40 seconds

        $logs = [];
        $successCount = 0;
        $failCount = 0;
        $skippedCount = 0;



        $settings = Setting::first();

        if (!$settings || !$settings->api_key) {
            if (request()->has('stream')) {
                $sendMessage(['error' => 'API Key not found. Please create client first.']);
                exit;
            }
            return back()->with('error', 'API Key not found. Please create client first.');
        }

        // === Token Handling ===
        $tokenExpiry = $settings->token_expiry ? Carbon::parse($settings->token_expiry) : null;

        if (!$settings->token || !$tokenExpiry || $tokenExpiry->isPast()) {

            $tokenResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-api-key' => $settings->api_key,
            ])->timeout(3600)->post('https://plugin-api.rugsimple.com/api/token');

            if (!$tokenResponse->successful() || !isset($tokenResponse['token'])) {
                if (request()->has('stream')) {
                    $sendMessage(['error' => 'Failed to get token.']);
                    exit;
                }
                return back()->with('error', 'Failed to get token.');
            }

            $token = $tokenResponse['token'];
            $settings->token = $token;
            $settings->token_expiry = Carbon::now()->addHours(3);
            $settings->save();
        } else {
            $token = $settings->token;
        }

        // === Get Products ===
        $limit = $settings->product_limit ?? 10;
        $skip = $settings->product_skip ?? 0;

        $productResponse = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ])->timeout(3600)->get('https://plugin-api.rugsimple.com/api/rug', [
            'limit' => $limit,
            'skip' => $skip,
        ]);

        if (!$productResponse->successful()) {
            if (request()->has('stream')) {
                $sendMessage(['error' => 'Failed to fetch products.']);
                exit;
            }
            return back()->with('error', 'Failed to fetch products.');
        }

        $responseData = $productResponse->json();
        $products = $responseData['data'] ?? [];

        if (!$settings->shopify_store_url || !$settings->shopify_token) {
            if (request()->has('stream')) {
                $sendMessage(['error' => 'Shopify store URL or access token is missing.']);
                exit;
            }
            return back()->with('error', 'Shopify store URL or access token is missing.');
        }

        $shopifyDomain = rtrim($settings->shopify_store_url, '/');
        $successCount = 0;
        $skippedCount = 0;

        // Process the product data
        $processedProducts = $this->process_product_data($products);

        $totalProducts = count($processedProducts);
        $processed = 0;

        foreach ($processedProducts as $product) {

            $processed++;
            $progress = round(($processed / $totalProducts) * 100);

            if (request()->has('stream')) {
                $sendMessage([
                    'progress' => $progress,
                    'message' => 'Starting import of: ' . ($product['title'] ?? 'Untitled'),
                    'type' => 'progress'
                ]);
            }

            // Get the SKU we'll use to check for existing products
            $sku = $product['ID'] ?? null; // ID will be used for sku

            if (!$sku) {
                $logs[] = ['message' => '❌ Failed: Missing SKU.' . ($product['title'] ?? 'Untitled'), 'success' => false];
                $message = '❌ Failed: Missing SKU.' . ($product['title'] ?? 'Untitled');
                $failCount++;
                if (request()->has('stream')) {
                    $sendMessage([
                        'progress' => $progress,
                        'message' => $message,
                        'type' => 'progress',
                        'success' => false
                    ]);
                }
                continue; // Skip products without a SKU
            }

            if (empty($product['title'])) {
                $logs[] = ['message' => '❌ Failed: Missing Product Title. (' . $sku . ')  ', 'success' => false];
                $message = '❌ Failed: Missing Product Title. (' . $sku . ')';
                $failCount++;
                if (request()->has('stream')) {
                    $sendMessage([
                        'progress' => $progress,
                        'message' => $message,
                        'type' => 'progress',
                        'success' => false
                    ]);
                }
                continue; // Skip products without a SKU
            }

            if (empty($product['regularPrice'])) {
                $logs[] = ['message' => '❌ Failed: Missing Regular Price. (' . $sku . ')  ', 'success' => false];
                $message = '❌ Failed: Missing Regular Price. (' . $sku . ')';
                $failCount++;
                if (request()->has('stream')) {
                    $sendMessage([
                        'progress' => $progress,
                        'message' => $message,
                        'type' => 'progress',
                        'success' => false
                    ]);
                }
                continue; // Skip products without a regular Price
            }

            if (empty($product['product_category'])) {
                $logs[] = ['message' => '❌ Failed: Missing Product Category. Rental:Sale:Both (' . $sku . ')  ', 'success' => false];
                $message = '❌ Failed: Missing Product Category. Rental:Sale:Both (' . $sku . ')';
                $failCount++;
                if (request()->has('stream')) {
                    $sendMessage([
                        'progress' => $progress,
                        'message' => $message,
                        'type' => 'progress',
                        'success' => false
                    ]);
                }
                continue; // Skip products without a product category
            }

            if (empty($product['images'])) {
                $logs[] = ['message' => '❌ Failed: Missing Product Product Images. (' . $sku . ')  ', 'success' => false];
                $message = '❌ Failed: Missing Product Product Images. (' . $sku . ')';
                $failCount++;
                if (request()->has('stream')) {
                    $sendMessage([
                        'progress' => $progress,
                        'message' => $message,
                        'type' => 'progress',
                        'success' => false
                    ]);
                }
                continue; // Skip products without a product category
            }

            // Check if product already exists in Shopify
            $existingSkuProduct = $this->checkProductBySkuOrTitle($settings, $shopifyDomain, $sku, 'sku');

            if ($existingSkuProduct) {
                $logs[] = ['message' => '⏭️ Skipped (Sku : exists): ' . ($product['title'] . ' (' . $sku . ')' ?? 'Untitled'), 'success' => false];
                $message = '⏭️ Skipped (Sku : exists): ' . ($product['title'] . ' (' . $sku . ')' ?? 'Untitled');
                $skippedCount++;
                if (request()->has('stream')) {
                    $sendMessage([
                        'progress' => $progress,
                        'message' => $message,
                        'type' => 'progress',
                        'success' => false
                    ]);
                }
                continue;
            }

            // Check if product already exists in Shopify
            $existingTitleProduct = $this->checkProductBySkuOrTitle($settings, $shopifyDomain, $product['title'], 'title');

            if ($existingTitleProduct) {
                $logs[] = ['message' => '⏭️ Skipped (Title : exists): ' . ($product['title'] . ' (' . $sku . ')' ?? 'Untitled'), 'success' => false];
                $message = '⏭️ Skipped (Title : exists): ' . ($product['title'] . ' (' . $sku . ')' ?? 'Untitled');
                $skippedCount++;
                if (request()->has('stream')) {
                    $sendMessage([
                        'progress' => $progress,
                        'message' => $message,
                        'type' => 'progress',
                        'success' => false
                    ]);
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
                'condition',
                'constructionType',
                'country',
                'production',
                'primaryMaterial',
                'design',
                'palette',
                'pattern',
                'pile',
                'period',
                'styleTags',
                'otherTags',
                'foundation',
                'age',
                'quality',
                'region',
                'density',
                'knots',
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
            if (!empty($product['shapeCategoryTags'])) {
                $shapeTags = array_map('trim', explode(',', $product['shapeCategoryTags']));
                foreach ($shapeTags as $shapeTag) {
                    $tags[] = $shapeTag;
                }
            }

            // Add color tags (already processed as comma-separated string)
            if (!empty($product['colourTags'])) {
                $colorTags = array_map('trim', explode(',', $product['colourTags']));
                foreach ($colorTags as $colorTag) {
                    $tags[] = $colorTag;
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


            // Base product structure
            $shopifyProduct = [
                'product' => [
                    'title' => $product['title'] ?? 'No Title',
                    'body_html' => '<p>' . ($product['description'] ?? '') . '</p>',
                    'vendor' => 'Rugsimple',
                    //'product_type' => $product['category'] ?? 'Unknown',
                    'product_type' => $this->getProductType($product['product_category'] ?? ''),
                    'tags' => implode(', ', array_unique($tags)),
                    'variants' => [
                        [
                            'option1' => 'Default',
                            'price' => $price,
                            'compare_at_price' => $compareAtPrice,
                            // 'price' => $product['sellingPrice'] ?? $product['regularPrice'] ?? '0.00',
                            // 'compare_at_price' => (
                            //     isset($product['regularPrice'], $product['sellingPrice']) &&
                            //     $product['regularPrice'] > $product['sellingPrice']
                            // ) ? $product['regularPrice'] : null,
                            'sku' => $product['ID'] ?? '',
                            //'weight' => $product['shipping']['weight'] ?? null,
                            //'weight_unit' => 'kg',
                            'inventory_management' => $product['inventory']['manageStock'] ?? false ? 'shopify' : null,
                            'inventory_quantity' => $product['inventory']['quantityLevel'][0]['available'] ?? null,
                            //'requires_shipping' => true,
                        ],
                    ],
                    'images' => []
                ]
            ];

            // Add dimensions as metafields
            if (!empty($product['dimension'])) {
                $shopifyProduct['product']['metafields'] = [
                    [
                        'namespace' => 'custom',
                        'key' => 'length',
                        'value' => $product['dimension']['length'] ?? '',
                        'type' => 'single_line_text_field'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'width',
                        'value' => $product['dimension']['width'] ?? '',
                        'type' => 'single_line_text_field'
                    ],
                    [
                        'namespace' => 'custom',
                        'key' => 'height',
                        'value' => $product['dimension']['height'] ?? '',
                        'type' => 'single_line_text_field'
                    ]
                ];
            }

            // Add the first image as the featured image

            if (!empty($product['images'])) {
                $shopifyProduct['product']['images'] = array_map(function ($imgUrl, $i) {
                    return [
                        'src' => $imgUrl,
                        'position' => $i + 1,
                    ];
                }, $product['images'], array_keys($product['images']));
            }

            // if (!empty($product['thumbnail'])) {
            //     $shopifyProduct['product']['image'] = [
            //         'src' => $product['thumbnail']
            //     ];
            // }

            // // Add additional images as gallery
            // foreach ($product['images'] as $index => $imageUrl) {
            //     // Skip the first image if it's already set as the featured image
            //     if ($index === 0 && isset($shopifyProduct['product']['image'])) {
            //         continue;
            //     }
            //     $shopifyProduct['product']['images'][] = [
            //         'src' => $imageUrl,
            //         'position' => $index + 1
            //     ];
            // }

            // Add rental variant if needed
            // if (in_array($product['product_category'] ?? '', ['rental', 'both'])) {
            //     $shopifyProduct['product']['variants'][] = [
            //         'option1' => 'Rental',
            //         'price' => $product['rental_price_value'] ?? '0.00',
            //         'sku' => ($product['legacySKU'] ?? $product['rugID'] ?? 'SKU') . '-RENTAL',
            //         'weight' => $product['shipping']['weight'] ?? null,
            //         'weight_unit' => 'kg',
            //         'inventory_management' => $product['inventory']['manageStock'] ?? false ? 'shopify' : null,
            //         'inventory_quantity' => $product['inventory']['quantityLevel'][0]['available'] ?? null,
            //         'requires_shipping' => true,
            //     ];
            // }

            // Add all other custom fields as metafields
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
                if (!empty($product[$field])) {
                    $shopifyProduct['product']['metafields'][] = [
                        'namespace' => 'custom',
                        'key' => $key,
                        'value' => $product[$field],
                        'type' => 'single_line_text_field' // Now always string since we pre-formatted the data
                    ];
                }
            }

            // Add cost per square data as metafields
            if (!empty($product['costPerSquare'])) {
                if (!empty($product['costPerSquare']['foot'])) {
                    $shopifyProduct['product']['metafields'][] = [
                        'namespace' => 'custom',
                        'key' => 'cost_per_square_foot',
                        'value' => $product['costPerSquare']['foot'],
                        'type' => 'single_line_text_field'
                    ];
                }
                if (!empty($product['costPerSquare']['meter'])) {
                    $shopifyProduct['product']['metafields'][] = [
                        'namespace' => 'custom',
                        'key' => 'cost_per_square_meter',
                        'value' => $product['costPerSquare']['meter'],
                        'type' => 'single_line_text_field'
                    ];
                }
            }

            // save renrtal product price
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

            //dd($shopifyProduct);

            try {
                // First create the product
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $settings->shopify_token,
                    'Content-Type' => 'application/json',
                ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/products.json", $shopifyProduct);

                //dd($response->json());

                if ($response->successful()) {
                    $productData = $response->json();
                    $productId = $productData['product']['id'] ?? null;

                    if ($productId) {
                        // Now add metafields (Shopify sometimes has issues with creating them in the same request)
                        $metafields = $shopifyProduct['product']['metafields'] ?? [];
                        foreach ($metafields as $metafield) {
                            Http::withHeaders([
                                'X-Shopify-Access-Token' => $settings->shopify_token,
                                'Content-Type' => 'application/json',
                            ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/metafields.json", [
                                'metafield' => $metafield
                            ]);
                        }

                        // Now add the product to collections based on tags
                        $uniqueTags = array_unique($tags);

                        foreach ($uniqueTags as $tagName) {
                            // Check if collection exists
                            $existingCollection = Http::withHeaders([
                                'X-Shopify-Access-Token' => $settings->shopify_token,
                            ])->timeout(3600)->get("https://{$shopifyDomain}/admin/api/2025-07/custom_collections.json", [
                                'title' => $tagName
                            ]);

                            $collectionId = null;

                            if ($existingCollection->successful() && !empty($existingCollection['custom_collections'])) {
                                $collectionId = $existingCollection['custom_collections'][0]['id'];
                            } else {
                                // Create the collection
                                $createCollection = Http::withHeaders([
                                    'X-Shopify-Access-Token' => $settings->shopify_token,
                                    'Content-Type' => 'application/json',
                                ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/custom_collections.json", [
                                    'custom_collection' => [
                                        'title' => $tagName
                                    ]
                                ]);

                                if ($createCollection->successful()) {
                                    $collectionId = $createCollection['custom_collection']['id'];
                                }
                            }

                            // Assign product to collection
                            if ($collectionId) {
                                Http::withHeaders([
                                    'X-Shopify-Access-Token' => $settings->shopify_token,
                                    'Content-Type' => 'application/json',
                                ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/collects.json", [
                                    'collect' => [
                                        'product_id' => $productId,
                                        'collection_id' => $collectionId
                                    ]
                                ]);
                            }
                        }

                        $logs[] = ['message' => '✅ Imported: ' . ($product['title'] ?? 'Untitled'), 'success' => true];
                        $message = '✅ Imported: ' . ($product['title'] ?? 'Untitled');
                        if (request()->has('stream')) {
                            $sendMessage([
                                'progress' => $progress,
                                'message' => $message,
                                'type' => 'progress',
                                'success' => true
                            ]);
                        }
                        $successCount++;
                    }
                } else {
                    $logs[] = ['message' => '❌ Failed to import: ' . ($product['title'] ?? 'Untitled'), 'success' => false];
                    $message = '❌ Failed to import: ' . ($product['title'] ?? 'Untitled') . ' - ' . $response->body();
                    if (request()->has('stream')) {
                        $sendMessage([
                            'progress' => $progress,
                            'message' => $message,
                            'type' => 'progress',
                            'success' => false
                        ]);
                    }
                    $failCount++;
                }
            } catch (\Exception $e) {
                //\Log::error('Exception inserting product to Shopify: ' . $e->getMessage());
                $logs[] = ['message' => '❌ Exception: ' . ($product['title'] ?? 'Untitled') . ' - ' . $e->getMessage(), 'success' => false];
                $message = '❌ Exception: ' . ($product['title'] ?? 'Untitled') . ' - ' . $e->getMessage();
                if (request()->has('stream')) {
                    $sendMessage([
                        'progress' => $progress,
                        'message' => $message,
                        'type' => 'progress',
                        'success' => false
                    ]);
                }
                $failCount++;
                continue;
            }

            // Send progress update
            // $sendMessage([
            //     'progress' => $progress,
            //     'message' => 'Processing: ' . ($product['title'] ?? 'Unknown'),
            //     'type' => 'progress'
            // ]);
        }

        $message = "{$successCount} product(s) imported to Shopify.";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} existing product(s) skipped.";
        }

        //return back()->with('success', $message);

        if (request()->has('stream')) {
            $sendMessage([
                'progress' => 100,
                'message' => 'Import complete!',
                'type' => 'complete',
                'success_count' => $successCount,
                'failure_count' => $failCount,
                'skipped_count' => $skippedCount,
                'logs' => $logs
            ]);
            exit;
        }

        return response()->json([
            // 'success_count' => $successCount,
            // 'failure_count' => $failCount,
            // 'skipped_count' => $skippedCount,
            // 'logs' => $logs,
            //'progress' => 100,
            //'message' => 'Import complete!',
            'success_count' => $successCount,
            'failure_count' => $failCount,
            'skipped_count' => $skippedCount,
            'logs' => $logs,
        ]);

        //return back()->with('success', "{$successCount} product(s) imported to Shopify.");
    }


    // public function importProducts_OLD()
    // {
    //     set_time_limit(1000); // 300 seconds = 5 minutes

    //     $logs = [];
    //     $successCount = 0;
    //     $failCount = 0;

    //     $settings = Setting::first();

    //     if (!$settings || !$settings->api_key) {
    //         return back()->with('error', 'API Key not found. Please create client first.');
    //     }

    //     // === Token Handling ===
    //     $tokenExpiry = $settings->token_expiry ? Carbon::parse($settings->token_expiry) : null;

    //     if (!$settings->token || !$tokenExpiry || $tokenExpiry->isPast()) {

    //         $tokenResponse = Http::withHeaders([
    //             'Content-Type' => 'application/json',
    //             'Accept' => 'application/json',
    //             'x-api-key' => $settings->api_key,
    //         ])->timeout(3600)->post('https://plugin-api.rugsimple.com/api/token');

    //         if (!$tokenResponse->successful() || !isset($tokenResponse['token'])) {
    //             return back()->with('error', 'Failed to get token.');
    //         }

    //         $token = $tokenResponse['token'];
    //         $settings->token = $token;
    //         $settings->token_expiry = Carbon::now()->addHours(3);
    //         $settings->save();
    //     } else {
    //         $token = $settings->token;
    //     }

    //     // === Get Products ===
    //     $limit = $settings->product_limit ?? 10;
    //     $skip = $settings->product_skip ?? 0;

    //     $productResponse = Http::withHeaders([
    //         'Content-Type' => 'application/json',
    //         'Accept' => 'application/json',
    //         'Authorization' => 'Bearer ' . $token,
    //     ])->timeout(3600)->get('https://plugin-api.rugsimple.com/api/rug', [
    //         'limit' => $limit,
    //         'skip' => $skip,
    //     ]);

    //     if (!$productResponse->successful()) {
    //         return back()->with('error', 'Failed to fetch products.');
    //     }

    //     $responseData = $productResponse->json();
    //     $products = $responseData['data'] ?? [];

    //     if (!$settings->shopify_store_url || !$settings->shopify_token) {
    //         return back()->with('error', 'Shopify store URL or access token is missing.');
    //     }

    //     $shopifyDomain = rtrim($settings->shopify_store_url, '/');
    //     $successCount = 0;
    //     $skippedCount = 0;

    //     // Process the product data
    //     $processedProducts = $this->process_product_data($products);

    //     foreach ($processedProducts as $product) {

    //         // Get the SKU we'll use to check for existing products
    //         $sku = $product['ID'] ?? null; // ID will be used for sku

    //         if (!$sku) {
    //             $logs[] = ['message' => '❌ Failed: Missing SKU.' . ($product['title'] ?? 'Untitled'), 'success' => false];
    //             $failCount++;
    //             continue; // Skip products without a SKU
    //         }

    //         if (empty($product['title'])) {
    //             $logs[] = ['message' => '❌ Failed: Missing Product Title. (' . $sku . ')  ', 'success' => false];
    //             $failCount++;
    //             continue; // Skip products without a SKU
    //         }

    //         if (empty($product['regularPrice'])) {
    //             $logs[] = ['message' => '❌ Failed: Missing Regular Price. (' . $sku . ')  ', 'success' => false];
    //             $failCount++;
    //             continue; // Skip products without a regular Price
    //         }

    //         if (empty($product['product_category'])) {
    //             $logs[] = ['message' => '❌ Failed: Missing Product Category. Rental:Sale:Both (' . $sku . ')  ', 'success' => false];
    //             $failCount++;
    //             continue; // Skip products without a product category
    //         }

    //         if (empty($product['images'])) {
    //             $logs[] = ['message' => '❌ Failed: Missing Product Product Images. (' . $sku . ')  ', 'success' => false];
    //             $failCount++;
    //             continue; // Skip products without a product category
    //         }

    //         // Check if product already exists in Shopify
    //         $existingSkuProduct = $this->checkProductBySkuOrTitle($settings, $shopifyDomain, $sku, 'sku');

    //         if ($existingSkuProduct) {
    //             $logs[] = ['message' => '⏭️ Skipped (Sku : exists): ' . ($product['title'] . ' (' . $sku . ')' ?? 'Untitled'), 'success' => false];
    //             $skippedCount++;
    //             continue;
    //         }

    //         // Check if product already exists in Shopify
    //         $existingTitleProduct = $this->checkProductBySkuOrTitle($settings, $shopifyDomain, $product['title'], 'title');

    //         if ($existingTitleProduct) {
    //             $logs[] = ['message' => '⏭️ Skipped (Title : exists): ' . ($product['title'] . ' (' . $sku . ')' ?? 'Untitled'), 'success' => false];
    //             $skippedCount++;
    //             continue;
    //         }

    //         // Prepare tags array
    //         $tags = [];

    //         // Add product category tags
    //         if (!empty($product['product_category'])) {
    //             if ($product['product_category'] === 'both') {
    //                 $tags[] = 'Rugs for Rent';
    //                 $tags[] = 'Rugs for Sale';
    //             } elseif ($product['product_category'] === 'rental') {
    //                 $tags[] = 'Rugs for Rent';
    //             } elseif ($product['product_category'] === 'sale') {
    //                 $tags[] = 'Rugs for Sale';
    //             }
    //         }

    //         // Add other tags from WordPress taxonomies
    //         $tagFields = [
    //             'condition',
    //             'constructionType',
    //             'country',
    //             'production',
    //             'primaryMaterial',
    //             'design',
    //             'palette',
    //             'pattern',
    //             'pile',
    //             'period',
    //             'styleTags',
    //             'otherTags',
    //             'foundation',
    //             'age',
    //             'quality',
    //             'region',
    //             'density',
    //             'knots',
    //             'rugType',
    //             'productType'
    //         ];

    //         foreach ($tagFields as $field) {
    //             if (!empty($product[$field])) {
    //                 // Check if the field is a comma-separated string
    //                 if (is_string($product[$field]) && strpos($product[$field], ',') !== false) {
    //                     // Split the comma-separated string and add each value as a tag
    //                     $values = array_map('trim', explode(',', $product[$field]));
    //                     foreach ($values as $value) {
    //                         $tags[] = $value;
    //                     }
    //                 } else {
    //                     // Add the single value as a tag
    //                     $tags[] = $product[$field];
    //                 }
    //             }
    //         }

    //         // Add size category tags (already processed as comma-separated string)
    //         if (!empty($product['sizeCategoryTags'])) {
    //             $sizeTags = array_map('trim', explode(',', $product['sizeCategoryTags']));
    //             foreach ($sizeTags as $sizeTag) {
    //                 $tags[] = $sizeTag;
    //             }
    //         }

    //         // Add shape category tags (already processed as comma-separated string)
    //         if (!empty($product['shapeCategoryTags'])) {
    //             $shapeTags = array_map('trim', explode(',', $product['shapeCategoryTags']));
    //             foreach ($shapeTags as $shapeTag) {
    //                 $tags[] = $shapeTag;
    //             }
    //         }

    //         // Add color tags (already processed as comma-separated string)
    //         if (!empty($product['colourTags'])) {
    //             $colorTags = array_map('trim', explode(',', $product['colourTags']));
    //             foreach ($colorTags as $colorTag) {
    //                 $tags[] = $colorTag;
    //             }
    //         }

    //         // Add category and subcategory as tags
    //         if (!empty($product['category'])) {
    //             $tags[] = $product['category'];
    //         }
    //         if (!empty($product['subCategory'])) {
    //             $tags[] = $product['subCategory'];
    //         }


    //         $price = $product['regularPrice'] ?? '0.00';
    //         $compareAtPrice = null;

    //         if (!empty($product['sellingPrice']) && $product['regularPrice'] > $product['sellingPrice']) {
    //             $price = $product['sellingPrice'];
    //             $compareAtPrice = $product['regularPrice'];
    //         } else {
    //             $price = $product['regularPrice'] ?? '0.00';
    //             $compareAtPrice = null; // No sale
    //         }


    //         // Base product structure
    //         $shopifyProduct = [
    //             'product' => [
    //                 'title' => $product['title'] ?? 'No Title',
    //                 'body_html' => '<p>' . ($product['description'] ?? '') . '</p>',
    //                 'vendor' => 'Rugsimple',
    //                 //'product_type' => $product['category'] ?? 'Unknown',
    //                 'product_type' => $this->getProductType($product['product_category'] ?? ''),
    //                 'tags' => implode(', ', array_unique($tags)),
    //                 'variants' => [
    //                     [
    //                         'option1' => 'Default',
    //                         'price' => $price,
    //                         'compare_at_price' => $compareAtPrice,
    //                         // 'price' => $product['sellingPrice'] ?? $product['regularPrice'] ?? '0.00',
    //                         // 'compare_at_price' => (
    //                         //     isset($product['regularPrice'], $product['sellingPrice']) &&
    //                         //     $product['regularPrice'] > $product['sellingPrice']
    //                         // ) ? $product['regularPrice'] : null,
    //                         'sku' => $product['ID'] ?? '',
    //                         //'weight' => $product['shipping']['weight'] ?? null,
    //                         //'weight_unit' => 'kg',
    //                         'inventory_management' => $product['inventory']['manageStock'] ?? false ? 'shopify' : null,
    //                         'inventory_quantity' => $product['inventory']['quantityLevel'][0]['available'] ?? null,
    //                         //'requires_shipping' => true,
    //                     ],
    //                 ],
    //                 'images' => []
    //             ]
    //         ];

    //         // Add dimensions as metafields
    //         if (!empty($product['dimension'])) {
    //             $shopifyProduct['product']['metafields'] = [
    //                 [
    //                     'namespace' => 'custom',
    //                     'key' => 'length',
    //                     'value' => $product['dimension']['length'] ?? '',
    //                     'type' => 'single_line_text_field'
    //                 ],
    //                 [
    //                     'namespace' => 'custom',
    //                     'key' => 'width',
    //                     'value' => $product['dimension']['width'] ?? '',
    //                     'type' => 'single_line_text_field'
    //                 ],
    //                 [
    //                     'namespace' => 'custom',
    //                     'key' => 'height',
    //                     'value' => $product['dimension']['height'] ?? '',
    //                     'type' => 'single_line_text_field'
    //                 ]
    //             ];
    //         }

    //         // Add the first image as the featured image

    //         if (!empty($product['images'])) {
    //             $shopifyProduct['product']['images'] = array_map(function ($imgUrl, $i) {
    //                 return [
    //                     'src' => $imgUrl,
    //                     'position' => $i + 1,
    //                 ];
    //             }, $product['images'], array_keys($product['images']));
    //         }

    //         // if (!empty($product['thumbnail'])) {
    //         //     $shopifyProduct['product']['image'] = [
    //         //         'src' => $product['thumbnail']
    //         //     ];
    //         // }

    //         // // Add additional images as gallery
    //         // foreach ($product['images'] as $index => $imageUrl) {
    //         //     // Skip the first image if it's already set as the featured image
    //         //     if ($index === 0 && isset($shopifyProduct['product']['image'])) {
    //         //         continue;
    //         //     }
    //         //     $shopifyProduct['product']['images'][] = [
    //         //         'src' => $imageUrl,
    //         //         'position' => $index + 1
    //         //     ];
    //         // }

    //         // Add rental variant if needed
    //         // if (in_array($product['product_category'] ?? '', ['rental', 'both'])) {
    //         //     $shopifyProduct['product']['variants'][] = [
    //         //         'option1' => 'Rental',
    //         //         'price' => $product['rental_price_value'] ?? '0.00',
    //         //         'sku' => ($product['legacySKU'] ?? $product['rugID'] ?? 'SKU') . '-RENTAL',
    //         //         'weight' => $product['shipping']['weight'] ?? null,
    //         //         'weight_unit' => 'kg',
    //         //         'inventory_management' => $product['inventory']['manageStock'] ?? false ? 'shopify' : null,
    //         //         'inventory_quantity' => $product['inventory']['quantityLevel'][0]['available'] ?? null,
    //         //         'requires_shipping' => true,
    //         //     ];
    //         // }

    //         // Add all other custom fields as metafields
    //         $metaFields = [
    //             'sizeCategoryTags' => 'size_category_tags',
    //             'costType' => 'cost_type',
    //             'cost' => 'cost',
    //             'condition' => 'condition',
    //             'productType' => 'product_type',
    //             'rugType' => 'rug_type',
    //             'constructionType' => 'construction_type',
    //             'country' => 'country',
    //             'production' => 'production',
    //             'primaryMaterial' => 'primary_material',
    //             'design' => 'design',
    //             'palette' => 'palette',
    //             'pattern' => 'pattern',
    //             'pile' => 'pile',
    //             'period' => 'period',
    //             'styleTags' => 'style_tags',
    //             'otherTags' => 'other_tags',
    //             'colourTags' => 'color_tags',
    //             'foundation' => 'foundation',
    //             'age' => 'age',
    //             'quality' => 'quality',
    //             'conditionNotes' => 'condition_notes',
    //             'region' => 'region',
    //             'density' => 'density',
    //             'knots' => 'knots',
    //             'rugID' => 'rug_id',
    //             'size' => 'size',
    //             'isTaxable' => 'is_taxable',
    //             'subCategory' => 'subcategory',
    //             'created_at' => 'created_at',
    //             'updated_at' => 'updated_at',
    //             'consignmentisActive' => 'consignment_active',
    //             'consignorRef' => 'consignor_ref',
    //             'parentId' => 'parent_id',
    //             'agreedLowPrice' => 'agreed_low_price',
    //             'agreedHighPrice' => 'agreed_high_price',
    //             'payoutPercentage' => 'payout_percentage'
    //         ];

    //         foreach ($metaFields as $field => $key) {
    //             if (!empty($product[$field])) {
    //                 $shopifyProduct['product']['metafields'][] = [
    //                     'namespace' => 'custom',
    //                     'key' => $key,
    //                     'value' => $product[$field],
    //                     'type' => 'single_line_text_field' // Now always string since we pre-formatted the data
    //                 ];
    //             }
    //         }

    //         // Add cost per square data as metafields
    //         if (!empty($product['costPerSquare'])) {
    //             if (!empty($product['costPerSquare']['foot'])) {
    //                 $shopifyProduct['product']['metafields'][] = [
    //                     'namespace' => 'custom',
    //                     'key' => 'cost_per_square_foot',
    //                     'value' => $product['costPerSquare']['foot'],
    //                     'type' => 'single_line_text_field'
    //                 ];
    //             }
    //             if (!empty($product['costPerSquare']['meter'])) {
    //                 $shopifyProduct['product']['metafields'][] = [
    //                     'namespace' => 'custom',
    //                     'key' => 'cost_per_square_meter',
    //                     'value' => $product['costPerSquare']['meter'],
    //                     'type' => 'single_line_text_field'
    //                 ];
    //             }
    //         }

    //         // save renrtal product price
    //         $rental_price_data = $product['rental_price_value'];
    //         // Case 1: General Price Format
    //         $rentalPrice = '';
    //         if (isset($rental_price_data['key']) && $rental_price_data['key'] === 'general_price') {
    //             $rentalPrice = $rental_price_data['value'];
    //         } elseif (isset($rental_price_data['redq_day_ranges_cost']) && is_array($rental_price_data['redq_day_ranges_cost'])) { // Case 2: Day Range Pricing Format
    //             foreach ($rental_price_data['redq_day_ranges_cost'] as $range) {
    //                 if (!empty($range['range_cost'])) {
    //                     $rentalPrice = $range['range_cost'];
    //                 }
    //             }
    //         }

    //         if (!empty($rentalPrice)) {
    //             $shopifyProduct['product']['metafields'][] = [
    //                 'namespace' => 'custom',
    //                 'key' => 'rental_price',
    //                 'value' => $rentalPrice,
    //                 'type' => 'single_line_text_field'
    //             ];
    //         }

    //         //dd($shopifyProduct);

    //         try {
    //             // First create the product
    //             $response = Http::withHeaders([
    //                 'X-Shopify-Access-Token' => $settings->shopify_token,
    //                 'Content-Type' => 'application/json',
    //             ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/products.json", $shopifyProduct);

    //             //dd($response->json());

    //             if ($response->successful()) {
    //                 $productData = $response->json();
    //                 $productId = $productData['product']['id'] ?? null;

    //                 if ($productId) {
    //                     // Now add metafields (Shopify sometimes has issues with creating them in the same request)
    //                     $metafields = $shopifyProduct['product']['metafields'] ?? [];
    //                     foreach ($metafields as $metafield) {
    //                         Http::withHeaders([
    //                             'X-Shopify-Access-Token' => $settings->shopify_token,
    //                             'Content-Type' => 'application/json',
    //                         ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/products/{$productId}/metafields.json", [
    //                             'metafield' => $metafield
    //                         ]);
    //                     }

    //                     // Now add the product to collections based on tags
    //                     $uniqueTags = array_unique($tags);

    //                     foreach ($uniqueTags as $tagName) {
    //                         // Check if collection exists
    //                         $existingCollection = Http::withHeaders([
    //                             'X-Shopify-Access-Token' => $settings->shopify_token,
    //                         ])->timeout(3600)->get("https://{$shopifyDomain}/admin/api/2025-07/custom_collections.json", [
    //                             'title' => $tagName
    //                         ]);

    //                         $collectionId = null;

    //                         if ($existingCollection->successful() && !empty($existingCollection['custom_collections'])) {
    //                             $collectionId = $existingCollection['custom_collections'][0]['id'];
    //                         } else {
    //                             // Create the collection
    //                             $createCollection = Http::withHeaders([
    //                                 'X-Shopify-Access-Token' => $settings->shopify_token,
    //                                 'Content-Type' => 'application/json',
    //                             ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/custom_collections.json", [
    //                                 'custom_collection' => [
    //                                     'title' => $tagName
    //                                 ]
    //                             ]);

    //                             if ($createCollection->successful()) {
    //                                 $collectionId = $createCollection['custom_collection']['id'];
    //                             }
    //                         }

    //                         // Assign product to collection
    //                         if ($collectionId) {
    //                             Http::withHeaders([
    //                                 'X-Shopify-Access-Token' => $settings->shopify_token,
    //                                 'Content-Type' => 'application/json',
    //                             ])->timeout(3600)->post("https://{$shopifyDomain}/admin/api/2025-07/collects.json", [
    //                                 'collect' => [
    //                                     'product_id' => $productId,
    //                                     'collection_id' => $collectionId
    //                                 ]
    //                             ]);
    //                         }
    //                     }

    //                     $logs[] = ['message' => '✅ Imported: ' . ($product['title'] ?? 'Untitled'), 'success' => true];
    //                     $successCount++;
    //                 }
    //             } else {
    //                 $logs[] = ['message' => '❌ Failed to import: ' . ($product['title'] ?? 'Untitled'), 'success' => false];
    //                 $failCount++;
    //             }
    //         } catch (\Exception $e) {
    //             //\Log::error('Exception inserting product to Shopify: ' . $e->getMessage());
    //             $logs[] = ['message' => '❌ Exception: ' . ($product['title'] ?? 'Untitled') . ' - ' . $e->getMessage(), 'success' => false];
    //             $failCount++;
    //             continue;
    //         }
    //     }

    //     $message = "{$successCount} product(s) imported to Shopify.";
    //     if ($skippedCount > 0) {
    //         $message .= " {$skippedCount} existing product(s) skipped.";
    //     }

    //     //return back()->with('success', $message);

    //     return response()->json([
    //         'success_count' => $successCount,
    //         'failure_count' => $failCount,
    //         'skipped_count' => $skippedCount,
    //         'logs' => $logs,
    //     ]);

    //     //return back()->with('success', "{$successCount} product(s) imported to Shopify.");
    // }

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
    // private function checkProductExists($settings, $shopifyDomain, $sku)
    // {
    //     try {
    //         $response = Http::withHeaders([
    //             'X-Shopify-Access-Token' => $settings->shopify_token,
    //             'Content-Type' => 'application/json',
    //         ])->get("https://{$shopifyDomain}/admin/api/2025-07/products.json?sku={$sku}", [
    //             'limit' => 1
    //         ]);
    //         echo '('.$sku.')';
    //         echo "https://{$shopifyDomain}/admin/api/2025-07/products.json?sku={$sku}";
    //         if ($response->successful()) {
    //             $products = $response->json()['products'] ?? [];

    //             print_r($products);
    //             return count($products) > 0;
    //         }
    //     } catch (\Exception $e) {
    //         // If there's an error checking, assume product doesn't exist
    //         return false;
    //     }

    //     return false;
    // }

    private function checkProductBySkuOrTitle($settings, $shopifyDomain, $value = null, $action = 'title')
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


            if (empty($product['collectionDocs'])) {
                continue; // Skip products without collectionDocs
            }

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
