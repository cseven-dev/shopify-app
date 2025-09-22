<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'email',
        'shopify_store_url',
        'shopify_token',
        'product_limit',
        'product_skip',
        'api_key',
        'token',
        'token_expiry',
    ];
}
