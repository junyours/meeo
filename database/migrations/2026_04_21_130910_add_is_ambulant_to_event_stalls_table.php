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
        Schema::table('event_stalls', function (Blueprint $table) {
            // Add is_ambulant column (only if it doesn't exist)
            if (!Schema::hasColumn('event_stalls', 'is_ambulant')) {
                $table->boolean('is_ambulant')->default(false)->after('stall_number');
            }
            
            // Make stall_number nullable for ambulant stalls (only if it's not already nullable)
            if (Schema::hasColumn('event_stalls', 'stall_number')) {
                \DB::statement('ALTER TABLE event_stalls MODIFY stall_number VARCHAR(255) NULL');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_stalls', function (Blueprint $table) {
            // Remove the is_ambulant column (only if it exists)
            if (Schema::hasColumn('event_stalls', 'is_ambulant')) {
                $table->dropColumn('is_ambulant');
            }
        });
    }
};
