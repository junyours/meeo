<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();

            // Daily collector session
            $table->foreignId('collection_session_id')
                ->constrained('collection_sessions')
                ->cascadeOnDelete();

            // Vendor
            $table->foreignId('vendor_id')
                ->constrained('vendor_details')
                ->cascadeOnDelete();

            // Specific rental/stall
            $table->foreignId('rented_id')
                ->constrained('rented')
                ->cascadeOnDelete();

            // Specific stall
            $table->foreignId('stall_id')
                ->constrained('stall')
                ->cascadeOnDelete();

            // Collector who created this collection
            $table->foreignId('collector_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Amount the vendor said they will pay
            $table->decimal('amount_to_pay', 12, 2);

            // Number of rental days covered
            $table->unsignedInteger('days_covered')->default(0);

            // daily / advance / missed / partial / etc.
            $table->string('payment_type')->nullable();

            // false = expected only
            // true = collector actually received money
            $table->boolean('is_collected')->default(false);

            // Time actual money was received
            $table->timestamp('collected_at')->nullable();

            $table->timestamps();

            $table->index([
                'collection_session_id',
                'is_collected'
            ]);

            $table->index('vendor_id');
            $table->index('rented_id');
            $table->index('collector_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};