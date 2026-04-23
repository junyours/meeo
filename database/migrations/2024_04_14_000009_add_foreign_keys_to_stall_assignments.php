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
        Schema::table('stall_assignments', function (Blueprint $table) {
            $table->foreign('stall_id')->references('id')->on('event_stalls')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('event_vendors')->onDelete('cascade');
            $table->foreign('activity_id')->references('id')->on('event_activities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stall_assignments', function (Blueprint $table) {
            $table->dropForeign(['stall_id']);
            $table->dropForeign(['vendor_id']);
            $table->dropForeign(['activity_id']);
        });
    }
};
