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
        Schema::create('market_registration_renewal_requests', function (Blueprint $table) {
            $table->id();
             // Use unsignedBigInteger and define foreign key manually
            $table->unsignedBigInteger('registration_id');
            $table->foreign('registration_id')
                  ->references('id')
                  ->on('market_registration')
                  ->onDelete('cascade');

            $table->unsignedBigInteger('vendor_id');
            $table->foreign('vendor_id')
                  ->references('id')
                  ->on('vendor_details')
                  ->onDelete('cascade');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_registration_renewal_requests');
    }
};
