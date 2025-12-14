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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('collector_id')->nullable();
               $table->unsignedBigInteger('vendor_id')->nullable();

            $table->string('message');
            $table->boolean('is_read');
            $table->string('title');
           $table->timestamps(); // includes created_at
            $table->foreign('collector_id')->references('id')->on('incharge_collector_details')->onDelete('cascade');
        $table->foreign('vendor_id')
                  ->references('id')
                  ->on('vendor_details') // or your vendors table
                  ->onDelete('cascade');
           
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
