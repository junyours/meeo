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
        Schema::create('customer_details', function (Blueprint $table) {
            $table->id();
             $table->unsignedBigInteger('user_id')->unique();
            $table->string('fullname');
            $table->string('age', 10);
            $table->string('gender', 20);
            $table->string('contact_number', 20);
            $table->string('emergency_contact', 20)->nullable();
            $table->string('address');
            $table->string('profile_picture')->nullable();
            $table->string('status')->default('pending'); // string status
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
   
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_details');
    }
};
