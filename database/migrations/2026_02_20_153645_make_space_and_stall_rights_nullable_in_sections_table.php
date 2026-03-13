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
        Schema::table('section', function (Blueprint $table) {
            $table->decimal('stall_rights', 10, 2)->nullable()->change();
            $table->decimal('space_right', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('section', function (Blueprint $table) {
          $table->decimal('space_rights', 10, 2)->nullable(false)->change();
            $table->decimal('stall_rights', 10, 2)->nullable(false)->change();
        });
    }
};
