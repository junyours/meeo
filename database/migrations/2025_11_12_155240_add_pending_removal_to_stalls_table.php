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
              $table->boolean('pending_removal')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stalls', function (Blueprint $table) {
                 $table->dropColumn(['pending_removal']);

        });
    }
};
