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
            $table->decimal('annual_rate', 10, 2)->nullable()->after('monthly_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stall', function (Blueprint $table) {
            $table->dropColumn('annual_rate');
        });
    }
};
