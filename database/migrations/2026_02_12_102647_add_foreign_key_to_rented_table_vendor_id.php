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
             $table->foreign('vendor_id')
                  ->references('id')
                  ->on('vendor_details')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rented', function (Blueprint $table) {
           $table->dropForeign(['vendor_id']);
        });
    }
};
