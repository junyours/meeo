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
        Schema::table('inspection_record', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('animal_id');

            // Optional: add foreign key if you have a customers table
            $table->foreign('customer_id')->references('id')->on('customer_details')->onDelete('set null');
       
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inspection_record', function (Blueprint $table) {
           $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
