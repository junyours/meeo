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
        // Create event_stalls table with correct foreign key
        Schema::create('event_stalls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('event_activities')->onDelete('cascade');
            $table->string('stall_number')->nullable();
            $table->boolean('is_ambulant')->default(false);
            $table->string('stall_name')->nullable();
            $table->text('description')->nullable();
            $table->string('size');
            $table->string('location')->nullable();
            $table->decimal('daily_rate', 10, 2)->default(0);
            $table->integer('total_days')->nullable();
            $table->decimal('total_rent', 10, 2)->default(0);
            $table->enum('status', ['available', 'occupied', 'maintenance', 'reserved'])->default('available');
            $table->integer('row_position')->nullable();
            $table->integer('column_position')->nullable();
            $table->unsignedBigInteger('assigned_vendor_id')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'stall_number'], 'event_stalls_activity_stall_unique');
            $table->index(['activity_id', 'status']);
            $table->index('assigned_vendor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_stalls');
    }
};
