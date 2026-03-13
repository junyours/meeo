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
        Schema::table('payments', function (Blueprint $table) {
           $table->dropForeign(['collector_id']);

            // Then drop the column
            $table->dropColumn('collector_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            //
       $table->unsignedBigInteger('collector_id')->nullable();

            // Recreate foreign key
            $table->foreign('collector_id')
                ->references('id')
                ->on('incharge_collector_details')
                ->onDelete('cascade');
        });
    }
};
