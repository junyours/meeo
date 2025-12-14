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
        Schema::create('rented', function (Blueprint $table) {
            $table->id();
                 $table->unsignedBigInteger('application_id');
                 $table->unsignedBigInteger('stall_id');
  $table->decimal('monthly_rent', 10, 2);
            $table->decimal('daily_rent', 10, 2);
               $table->date('last_payment_date')->nullable();
     
            $table->timestamps();

            $table->foreign('application_id')->references('id')->on('applications')->onDelete('cascade');
  $table->foreign('stall_id')->references('id')->on('stall')->onDelete('cascade');
  
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rented');
    }
};
