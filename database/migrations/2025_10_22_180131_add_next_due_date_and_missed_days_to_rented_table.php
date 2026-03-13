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
           $table->date('next_due_date')->nullable()->after('last_payment_date');
            $table->integer('missed_days')->default(0)->after('next_due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rented', function (Blueprint $table) {
        $table->dropColumn(['next_due_date', 'missed_days']);
        });
    }
};
