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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();

               $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('section_id');
                    $table->json('stall_ids');
            $table->string('business_name');
               $table->string('payment_type')->default('monthly');
                 $table->string('letter_of_intent');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();

             $table->foreign('vendor_id')->references('id')->on('vendor_details')->onDelete('cascade');
            $table->foreign('section_id')->references('id')->on('section')->onDelete('cascade');
           
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
