<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->decimal('cash_received', 12, 2)->nullable()->after('amount_to_pay');
            $table->decimal('change_due', 12, 2)->nullable()->after('cash_received');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['cash_received', 'change_due']);
        });
    }
};
