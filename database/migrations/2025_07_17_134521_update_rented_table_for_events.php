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
        Schema::table('rented', function (Blueprint $table) {
            // Add new columns for event rentals support
            if (!Schema::hasColumn('rented', 'event_stall_id')) {
                $table->unsignedBigInteger('event_stall_id')->nullable()->after('stall_id');
            }
            
            if (!Schema::hasColumn('rented', 'rent_type')) {
                $table->enum('rent_type', ['regular', 'event'])->default('regular')->after('daily_rent');
            }
            
            // Add indexes for better performance
            if (Schema::hasColumn('rented', 'event_stall_id')) {
                $table->index(['rent_type', 'event_stall_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rented', function (Blueprint $table) {
            if (Schema::hasColumn('rented', 'event_stall_id')) {
                $table->dropForeign(['rented_event_stall_id_foreign']);
                $table->dropColumn('event_stall_id');
            }
            
            if (Schema::hasColumn('rented', 'rent_type')) {
                $table->dropColumn('rent_type');
            }
            
            $table->dropIndex(['rented_event_stall_id_index']);
        });
    }
};
