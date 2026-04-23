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
        // Skip this migration if there's existing data that might be truncated
        if (Schema::hasTable('market_products') && Schema::hasColumn('market_products', 'image')) {
            try {
                Schema::table('market_products', function (Blueprint $table) {
                    $table->longText('image')->nullable()->change();
                });
            } catch (\Exception $e) {
                // If the change fails due to data truncation, just skip it
                // The column will remain as current type
            }
        }

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'image')) {
            try {
                Schema::table('categories', function (Blueprint $table) {
                    $table->longText('image')->nullable()->change();
                });
            } catch (\Exception $e) {
                // If the change fails due to data truncation, just skip it
                // The column will remain as current type
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('market_products') && Schema::hasColumn('market_products', 'image')) {
            Schema::table('market_products', function (Blueprint $table) {
                $table->string('image')->nullable()->change();
            });
        }

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'image')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('image')->nullable()->change();
            });
        }
    }
};
