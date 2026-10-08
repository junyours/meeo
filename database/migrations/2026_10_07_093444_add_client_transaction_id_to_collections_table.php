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
        Schema::table('collections', function (Blueprint $table) {
            $table->uuid('client_transaction_id')
                ->nullable()
                ->unique();
            $table->foreignId('payment_id')
                ->nullable()
                ->unique()
                ->constrained('payments')
                ->nullOnDelete();
            $table->json('rental_snapshot')->nullable();
        });

        Schema::table('collection_sessions', function (Blueprint $table) {
            $table->uuid('turnover_client_id')
                ->nullable()
                ->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collection_sessions', function (Blueprint $table) {
            $table->dropUnique(['turnover_client_id']);
            $table->dropColumn('turnover_client_id');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn('rental_snapshot');
            $table->dropUnique(['client_transaction_id']);
            $table->dropColumn('client_transaction_id');
        });
    }
};
