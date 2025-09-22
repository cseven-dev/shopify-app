<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->string('shopify_store_url')->nullable();
            $table->string('shopify_token')->nullable();
            $table->integer('product_limit')->default(10);
            $table->timestamps();
            $table->string('api_key')->nullable();
            $table->string('token')->nullable();
            $table->timestamp('token_expiry')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
