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
            // Tracks how much missed balance is still outstanding for this rental
            $table->decimal('remaining_balance', 10, 2)->default(0);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rented', function (Blueprint $table) {
               $table->dropColumn('remaining_balance');
        });
    }
};
