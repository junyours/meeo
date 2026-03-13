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
        Schema::create('market_registration', function (Blueprint $table) {
            $table->id();
                 
            $table->unsignedBigInteger('application_id');
            $table->date('date_issued');
                     $table->date('expiry_date'); // new column for expiry
            $table->unsignedTinyInteger('pdf_generated_count')->default(0);
        

            $table->foreign('application_id')
                  ->references('id')
                  ->on('applications')
                  ->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_registration');
    }
};
