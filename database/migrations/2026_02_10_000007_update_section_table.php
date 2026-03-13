<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('section', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('row_index');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('section', function (Blueprint $table) {
            $table->dropColumn(['sort_order']);
            $table->dropSoftDeletes();
        });
    }
};
