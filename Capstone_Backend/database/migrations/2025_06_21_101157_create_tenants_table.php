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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
               $table->unsignedBigInteger('stall_id');
    $table->string('fullname');
    $table->integer('age');
    $table->string('gender');
    $table->string('contact_number');
    $table->string('address');
    $table->string('emergency_contact')->nullable();
    $table->string('business_name')->nullable();
    $table->integer('years_in_operation')->nullable();
    $table->timestamps();

    $table->foreign('stall_id')->references('id')->on('stall')->onDelete('cascade')->onUpdate('cascade');

       });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
