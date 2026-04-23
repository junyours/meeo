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
        Schema::table('market_registration', function (Blueprint $table) {
            if (Schema::hasColumn('market_registration', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('market_registration', 'renewal_requested')) {
                $table->dropColumn('renewal_requested');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_registration', function (Blueprint $table) {
            //
        });
    }
};
