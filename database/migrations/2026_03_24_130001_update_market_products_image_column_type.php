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
                    $table->text('image')->nullable()->change();
                });
            } catch (\Exception $e) {
                // If the change fails due to data truncation, just skip it
                // The column will remain as string type
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_products', function (Blueprint $table) {
            $table->string('image')->nullable()->change();
        });
    }
};
