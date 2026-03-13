<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stall', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('pending_removal');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('stall', function (Blueprint $table) {
            $table->dropColumn(['sort_order']);
            $table->dropSoftDeletes();
        });
    }
};
