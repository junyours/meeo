<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('rented_id')->constrained('rented')->cascadeOnDelete();
            $table->foreignId('stall_id')->constrained('stall')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
            $table->decimal('amount_to_pay', 12, 2);
            $table->unsignedInteger('days_covered')->default(0);
            $table->string('payment_type')->nullable();
            $table->json('rental_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['collection_id', 'rented_id']);
            $table->index('rented_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_allocations');
    }
};
