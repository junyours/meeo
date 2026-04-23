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
        Schema::table('stall', function (Blueprint $table) {
            if (Schema::hasColumn('stall', 'change_rate_at')) {
                $table->dropColumn('change_rate_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stall', function (Blueprint $table) {
            $table->timestamp('change_rate_at')->nullable()->after('monthly_rate');
        });
    }
};
