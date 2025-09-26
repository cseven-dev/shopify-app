<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ShopifyController extends Controller
{
    public function app(Request $request)
    {
        // This is the entry point Shopify loads in the Admin iframe
        // You can just redirect or include your settings page here
        return redirect()->route('/settings');
    }
}