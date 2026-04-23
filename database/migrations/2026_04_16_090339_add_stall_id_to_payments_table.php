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
            if (!Schema::hasColumn('payments', 'stall_id')) {
                $table->foreignId('stall_id')->nullable()->after('activity_id')->constrained('event_stalls')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'stall_id')) {
                $table->dropForeign(['payments_stall_id_foreign']);
                $table->dropColumn('stall_id');
            }
        });
    }
};
