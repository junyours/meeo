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
            $table->foreign('activity_id')->references('id')->on('event_activities')->onDelete('cascade');
            $table->foreign('stall_id')->references('id')->on('event_stalls')->onDelete('cascade');
            $table->foreign('assignment_id')->references('id')->on('stall_assignments')->onDelete('set null');
            $table->foreign('vendor_id')->references('id')->on('event_vendors')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_sales_reports', function (Blueprint $table) {
            $table->dropForeign(['activity_id']);
            $table->dropForeign(['stall_id']);
            $table->dropForeign(['assignment_id']);
            $table->dropForeign(['vendor_id']);
        });
    }
};
