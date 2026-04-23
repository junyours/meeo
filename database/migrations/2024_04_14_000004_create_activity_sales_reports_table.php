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
        Schema::create('activity_sales_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('stall_id');
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->date('report_date');
            $table->integer('day_number')->nullable();
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('cash_sales', 12, 2)->default(0);
            $table->decimal('credit_sales', 12, 2)->default(0);
            $table->decimal('other_sales', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['stall_id', 'report_date']);
            $table->index(['activity_id', 'report_date']);
            $table->index(['stall_id', 'verified']);
            $table->index(['vendor_id', 'report_date']);
            $table->index('reported_by');
            $table->index('verified_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_sales_reports');
    }
};
