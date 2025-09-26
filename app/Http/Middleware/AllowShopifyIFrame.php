<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AllowShopifyIframe
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Remove X-Frame-Options header so Shopify can embed
        $response->headers->remove('X-Frame-Options');

        // Allow embedding from Shopify
        $response->headers->set('Content-Security-Policy', "frame-ancestors https://*.myshopify.com https://admin.shopify.com;");

        return $response;
    }
}
