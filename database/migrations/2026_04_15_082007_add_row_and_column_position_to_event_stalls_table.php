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
        Schema::table('event_stalls', function (Blueprint $table) {
            if (!Schema::hasColumn('event_stalls', 'row_position')) {
                $table->integer('row_position')->nullable();
            }
            if (!Schema::hasColumn('event_stalls', 'column_position')) {
                $table->integer('column_position')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_stalls', function (Blueprint $table) {
            if (Schema::hasColumn('event_stalls', 'row_position')) {
                $table->dropColumn('row_position');
            }
            if (Schema::hasColumn('event_stalls', 'column_position')) {
                $table->dropColumn('column_position');
            }
        });
    }
};
