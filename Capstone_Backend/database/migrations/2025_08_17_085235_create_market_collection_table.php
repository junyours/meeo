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
        Schema::create('market_collection', function (Blueprint $table) {
            $table->id();
                $table->unsignedBigInteger('payment_id');
    $table->unsignedBigInteger('collector_id');
            $table->timestamps();

              $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
    $table->foreign('collector_id')->references('id')->on('incharge_collector_details')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_collection');
    }
};
