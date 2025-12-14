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
            Schema::dropIfExists('market_registration_renewal_requests');
      
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('market_registration_renewal_requests', function (Blueprint $table) {
            //
        });
    }
};
