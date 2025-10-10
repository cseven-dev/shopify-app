<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowShopifyIframe
{
    public function handle(Request $request, Closure $next)
    {
        // Allow install/auth routes freely
        if ($request->is('auth*') || $request->is('install*')) {
            return $next($request);
        }

        // If shop/host are present in request → store in session
        if ($request->has('shop') && $request->has('host')) {
            $request->session()->put('shop', $request->get('shop'));
            $request->session()->put('host', $request->get('host'));
            $request->session()->put('shopify_authenticated', true);
        }

        $shop = $request->session()->get('shop');
        $shopifyAuthenticated = $request->session()->get('shopify_authenticated');

        // ✅ Block if shop is missing or session not authenticated
        if (!$shop || !str_contains($shop, '.myshopify.com') || !$shopifyAuthenticated) {
            abort(404);
        }

        // ✅ Additional security: Check if request is within iframe context
        // This header is sent by browsers when page is loaded in iframe
        $secFetchDest = $request->header('Sec-Fetch-Dest');
        $secFetchMode = $request->header('Sec-Fetch-Mode');

        // Allow if it's an iframe request OR if session is already authenticated (for internal navigation)
        if (!$shopifyAuthenticated && $secFetchDest !== 'iframe' && $secFetchMode !== 'navigate') {
            // First-time access attempt not from iframe
            abort(404);
        }

        $response = $next($request);

        // Required headers for Shopify IFrame
        $response->headers->remove('X-Frame-Options');
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors https://*.myshopify.com https://admin.shopify.com;"
        );

        return $response;
    }
}