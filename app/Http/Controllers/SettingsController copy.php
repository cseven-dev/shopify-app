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
    public function index()
    {
        $settings = Setting::first();
        return view('settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $settings = Setting::firstOrCreate([]);
        $settings->update($request->only('email', 'product_limit'));
        return back()->with('success', 'Settings updated.');
    }

    public function createClient()
    {
        $settings = Setting::firstOrCreate([]);
        $email = $settings->email;

        if (!$email) {
            return back()->with('error', 'Email is required before creating a client.');
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://plugin-api.rugsimple.com/admin/create-client', [
            'email' => $email
        ]);

        //echo '<pre>'; // Debugging line to see the response
        //print_r($response->json()); // Print the response for debugging
        //echo '</pre>';

        //debug($response->json()); // Debugging line to see the response

        //dd($response->json());

        if ($response->successful() && isset($response['data']['API_KEY'])) {
            $settings->api_key = $response['data']['API_KEY'];
            $settings->save();

            return back()->with('success', 'Client created and API key saved.');
        }

        // If the response has an error, handle it
        if (isset($response['error'])) {
            return back()->with('error', 'Error: ' . $response['error']);
        }

        return back()->with('error', 'Failed to create client.');
    }

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
            ])->timeout(30)->delete($deleteUrl);

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
        set_time_limit(300); // 300 seconds = 5 minutes

        $settings = Setting::first();

        if (!$settings || !$settings->api_key) {
            return back()->with('error', 'API Key not found. Please create client first.');
        }

        // === Token Handling ===
        $tokenExpiry = $settings->token_expiry ? Carbon::parse($settings->token_expiry) : null;

        if (!$settings->token || !$tokenExpiry || $tokenExpiry->isPast()) {
            $tokenResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'x-api-key' => $settings->api_key,
            ])->post('https://plugin-api.rugsimple.com/api/token');

            if (!$tokenResponse->successful() || !isset($tokenResponse['token'])) {
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
        $skip = 0;

        $productResponse = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ])->timeout(3600)->get('https://plugin-api.rugsimple.com/api/rug', [
            'limit' => $limit,
            'skip' => $skip,
        ]);

        if (!$productResponse->successful()) {
            return back()->with('error', 'Failed to fetch products.');
        }

        $responseData = $productResponse->json();
        $products = $responseData['data'] ?? [];

        if (!$settings->shopify_store_url || !$settings->shopify_token) {
            return back()->with('error', 'Shopify store URL or access token is missing.');
        }

        $shopifyDomain = rtrim($settings->shopify_store_url, '/');
        $successCount = 0;

        // Process the product data
        $processedProducts = $this->process_product_data($products);

        foreach ($processedProducts as $product) {
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

            // Add category and subcategory as tags
            if (!empty($product['category'])) {
                $tags[] = $product['category'];
            }
            if (!empty($product['subCategory'])) {
                $tags[] = $product['subCategory'];
            }

            // Base product structure
            $shopifyProduct = [
                'product' => [
                    'title' => $product['title'] ?? 'No Title',
                    'body_html' => '<p>' . ($product['description'] ?? '') . '</p>',
                    'vendor' => 'Rugsimple',
                    'product_type' => $product['category'] ?? 'Unknown',
                    'tags' => implode(', ', array_unique($tags)),
                    'variants' => [
                        [
                            'option1' => 'Default',
                            'price' => $product['sellingPrice'] ?? $product['regularPrice'] ?? '0.00',
                            'sku' => $product['legacySKU'] ?? $product['rugID'] ?? 'SKU',
                            'weight' => $product['shipping']['weight'] ?? null,
                            'weight_unit' => 'kg',
                            'inventory_management' => $product['inventory']['manageStock'] ?? false ? 'shopify' : null,
                            'inventory_quantity' => $product['inventory']['quantityLevel'][0]['available'] ?? null,
                            'requires_shipping' => true,
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
            if (!empty($product['thumbnail'])) {
                $shopifyProduct['product']['image'] = [
                    'src' => $product['thumbnail']
                ];
            }

            // Add additional images as gallery
            foreach ($product['images'] as $index => $imageUrl) {
                // Skip the first image if it's already set as the featured image
                if ($index === 0 && isset($shopifyProduct['product']['image'])) {
                    continue;
                }
                $shopifyProduct['product']['images'][] = [
                    'src' => $imageUrl,
                    'position' => $index + 1
                ];
            }

            // Add rental variant if needed
            if (in_array($product['product_category'] ?? '', ['rental', 'both'])) {
                $shopifyProduct['product']['variants'][] = [
                    'option1' => 'Rental',
                    'price' => $product['rental_price_value'] ?? '0.00',
                    'sku' => ($product['legacySKU'] ?? $product['rugID'] ?? 'SKU') . '-RENTAL',
                    'weight' => $product['shipping']['weight'] ?? null,
                    'weight_unit' => 'kg',
                    'inventory_management' => $product['inventory']['manageStock'] ?? false ? 'shopify' : null,
                    'inventory_quantity' => $product['inventory']['quantityLevel'][0]['available'] ?? null,
                    'requires_shipping' => true,
                ];
            }

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

            //dd($shopifyProduct);

            try {
                // First create the product
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $settings->shopify_token,
                    'Content-Type' => 'application/json',
                ])->post("https://{$shopifyDomain}/admin/api/2023-10/products.json", $shopifyProduct);

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
                            ])->post("https://{$shopifyDomain}/admin/api/2023-10/products/{$productId}/metafields.json", [
                                'metafield' => $metafield
                            ]);
                        }

                        $successCount++;
                    }
                } else {
                    //
                }
            } catch (\Exception $e) {
                //\Log::error('Exception inserting product to Shopify: ' . $e->getMessage());
                continue;
            }
        }

        return back()->with('success', "{$successCount} product(s) imported to Shopify.");
    }


    function process_product_data($products)
    {
        $woo_insertion_data = [];

        foreach ($products as $product) {

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
