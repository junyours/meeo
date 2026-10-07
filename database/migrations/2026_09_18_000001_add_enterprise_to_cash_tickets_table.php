<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_tickets') && !Schema::hasColumn('cash_tickets', 'enterprise')) {
            Schema::table('cash_tickets', function (Blueprint $table) {
                $table->string('enterprise')->nullable()->after('type');
                $table->index('enterprise');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cash_tickets') && Schema::hasColumn('cash_tickets', 'enterprise')) {
            Schema::table('cash_tickets', function (Blueprint $table) {
                $table->dropIndex(['enterprise']);
                $table->dropColumn('enterprise');
            });
        }
    }
};
