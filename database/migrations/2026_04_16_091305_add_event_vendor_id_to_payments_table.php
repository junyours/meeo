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
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'event_vendor_id')) {
                $table->foreignId('event_vendor_id')->nullable()->after('stall_id')->constrained('event_vendors')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'event_vendor_id')) {
                $table->dropForeign(['payments_event_vendor_id_foreign']);
                $table->dropColumn('event_vendor_id');
            }
        });
    }
};
