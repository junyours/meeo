<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slaughterhouse_collections', function (Blueprint $table) {
            $table->uuid('client_transaction_id')->nullable()->unique();
        });

        Schema::table('slaughterhouse_collection_items', function (Blueprint $table) {
            $table->decimal('permit_to_slh', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('slaughterhouse_collection_items', function (Blueprint $table) {
            $table->dropColumn('permit_to_slh');
        });

        Schema::table('slaughterhouse_collections', function (Blueprint $table) {
            $table->dropUnique(['client_transaction_id']);
            $table->dropColumn('client_transaction_id');
        });
    }
};
