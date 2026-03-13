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
        Schema::create('stall_removal_request', function (Blueprint $table) {
       $table->id();
            $table->unsignedBigInteger('rented_id');   // Link to rented table
            $table->unsignedBigInteger('vendor_id');   // Link to vendor table
            $table->unsignedBigInteger('stall_id');    // Link to stalls table
            $table->text('message')->nullable();       // Reason or message
            $table->string('status')->default('pending'); // 'pending', 'approved', 'rejected'
              $table->timestamps();

            // Optional foreign keys
            $table->foreign('rented_id')->references('id')->on('rented')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('vendor_details')->onDelete('cascade');
            $table->foreign('stall_id')->references('id')->on('stall')->onDelete('cascade');
           });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stall_removal_request');
    }
};
