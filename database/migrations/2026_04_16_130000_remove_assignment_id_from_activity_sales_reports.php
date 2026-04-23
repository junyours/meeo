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
            // Check if column exists before trying to drop it
            if (Schema::hasColumn('activity_sales_reports', 'assignment_id')) {
                // Try to drop foreign key if it exists
                try {
                    $table->dropForeign(['assignment_id']);
                } catch (\Exception $e) {
                    // Foreign key doesn't exist, continue
                }
                
                // Then drop the column
                $table->dropColumn('assignment_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_sales_reports', function (Blueprint $table) {
            // Add back the column only if it doesn't exist
            if (!Schema::hasColumn('activity_sales_reports', 'assignment_id')) {
                $table->unsignedBigInteger('assignment_id')->nullable()->after('stall_id');
                
                // Add back the foreign key only if stall_assignments table exists
                if (Schema::hasTable('stall_assignments')) {
                    $table->foreign('assignment_id')
                          ->references('id')
                          ->on('stall_assignments')
                          ->onDelete('set null');
                }
            }
        });
    }
};
