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
        Schema::create('incharge_collector_details', function (Blueprint $table) {
            $table->id();
                    $table->unsignedBigInteger('user_id');
        $table->string('fullname');            
        $table->string('age');
        $table->string('gender');
        $table->string('contact_number');
        $table->string('emergency_contact');
        $table->string('address');
        $table->string('area')->nullable();

        $table->string('Status')->default('pending'); 
          

  $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incharge_collector_details');
    }
};
