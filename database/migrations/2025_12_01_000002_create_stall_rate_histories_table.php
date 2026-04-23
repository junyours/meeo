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
        if (!Schema::hasTable('stall_rate_histories')) {
            Schema::create('stall_rate_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stall_id')->constrained('stall')->onDelete('cascade');
                $table->decimal('daily_rate', 10, 2)->nullable();
                $table->decimal('monthly_rate', 10, 2)->nullable();
                $table->date('effective_from')->comment('Date when this rate becomes effective');
                $table->timestamps();
                
                // Indexes for performance
                $table->index(['stall_id', 'effective_from']);
                $table->index('effective_from');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stall_rate_histories');
    }
};
