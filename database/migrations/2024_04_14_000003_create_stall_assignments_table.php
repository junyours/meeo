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
        Schema::create('stall_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stall_id');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('activity_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active', 'ended', 'terminated'])->default('active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stall_id', 'status']);
            $table->index(['vendor_id', 'status']);
            $table->index(['activity_id', 'status']);
            $table->index('assigned_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stall_assignments');
    }
};
