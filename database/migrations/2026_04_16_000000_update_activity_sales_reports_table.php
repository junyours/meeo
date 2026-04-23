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
        Schema::table('activity_sales_reports', function (Blueprint $table) {
            // Add new columns for product-based reporting (only if they don't exist)
            if (!Schema::hasColumn('activity_sales_reports', 'products')) {
                $table->json('products')->nullable()->after('report_date');
            }
            
            // Remove old sales breakdown columns since we now use products (only if they exist)
            if (Schema::hasColumn('activity_sales_reports', 'cash_sales')) {
                $table->dropColumn('cash_sales');
            }
            if (Schema::hasColumn('activity_sales_reports', 'credit_sales')) {
                $table->dropColumn('credit_sales');
            }
            if (Schema::hasColumn('activity_sales_reports', 'other_sales')) {
                $table->dropColumn('other_sales');
            }
            
            // Remove notes column as it's removed from the modal (only if it exists)
            if (Schema::hasColumn('activity_sales_reports', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_sales_reports', function (Blueprint $table) {
            // Remove the products column (only if it exists)
            if (Schema::hasColumn('activity_sales_reports', 'products')) {
                $table->dropColumn('products');
            }
            
            // Add back the old columns (only if they don't exist)
            if (!Schema::hasColumn('activity_sales_reports', 'cash_sales')) {
                $table->decimal('cash_sales', 12, 2)->default(0)->after('total_sales');
            }
            if (!Schema::hasColumn('activity_sales_reports', 'credit_sales')) {
                $table->decimal('credit_sales', 12, 2)->default(0)->after('cash_sales');
            }
            if (!Schema::hasColumn('activity_sales_reports', 'other_sales')) {
                $table->decimal('other_sales', 12, 2)->default(0)->after('credit_sales');
            }
            if (!Schema::hasColumn('activity_sales_reports', 'notes')) {
                $table->text('notes')->nullable()->after('other_sales');
            }
        });
    }
};
