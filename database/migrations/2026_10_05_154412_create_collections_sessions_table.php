<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_sessions', function (Blueprint $table) {
            $table->id();

            // Collector who owns this collection session
            $table->foreignId('collector_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Date of collection
            $table->date('collection_date');

            // Automatically calculated from collections
            $table->decimal('expected_total', 12, 2)->default(0);
            $table->decimal('collected_total', 12, 2)->default(0);

            // Actual physical cash handed over by collector
            $table->decimal('cash_turned_over', 12, 2)
                ->nullable();

            // cash_turned_over - collected_total
            $table->decimal('difference', 12, 2)
                ->nullable();

            // pending / submitted / verified / shortage / overage
            $table->string('status')->default('pending');

            // Office Staff who verified the cash
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();

            $table->text('remarks')->nullable();

            $table->timestamps();

            // A collector should only have one session per day
            $table->unique([
                'collector_id',
                'collection_date'
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_sessions');
    }
};