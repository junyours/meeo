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
        Schema::table('notifications', function (Blueprint $table) {
           $table->unsignedBigInteger('customer_id')->nullable()->after('id');

            // Optional: add foreign key
            $table->foreign('customer_id')->references('id')->on('customer_details')->onDelete('set null');
      
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
              $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
