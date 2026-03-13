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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
             $table->unsignedBigInteger('rented_id');
                 $table->unsignedBigInteger('vendor_id');
              $table->unsignedBigInteger('collector_id')->nullable();
                $table->string('payment_type', 50);
            $table->decimal('amount', 10, 2);
             $table->integer('missed_days')->default(0);
                $table->integer('advance_days')->default(0);
            $table->date('payment_date');

            $table->timestamps();
  $table->foreign('collector_id')
              ->references('id')
              ->on('incharge_collector_details')
              ->onDelete('cascade');
            $table->foreign('rented_id')->references('id')->on('rented')->onDelete('cascade'); 
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
        Schema::dropIfExists('payments');
    }
};
