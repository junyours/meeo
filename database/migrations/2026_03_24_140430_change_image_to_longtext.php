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
        Schema::table('market_products', function (Blueprint $table) {
    $table->longText('image')->nullable()->change();
});

Schema::table('categories', function (Blueprint $table) {
    $table->longText('image')->nullable()->change();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
     Schema::table('market_products', function (Blueprint $table) {
    $table->longText('image')->nullable()->change();
});

Schema::table('categories', function (Blueprint $table) {
    $table->longText('image')->nullable()->change();
});   Schema::table('longtext', function (Blueprint $table) {
            //
        });
    }
};
