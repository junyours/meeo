<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_other_payments', function (Blueprint $table) {
            $table->string('payer_type')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_other_payments', function (Blueprint $table) {
            $table->dropColumn('payer_type');
        });
    }
};
