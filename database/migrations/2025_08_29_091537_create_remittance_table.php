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
        Schema::create('remittance', function (Blueprint $table) {
            $table->id();
      $table->date('remit_date');
    $table->decimal('amount', 12, 2);
    $table->unsignedBigInteger('remitted_by');
    $table->unsignedBigInteger('received_by')->nullable();
    $table->string('status')->default('pending');

    // Polymorphic columns:

    $table->timestamps();

    $table->foreign('remitted_by')->references('id')->on('incharge_collector_details')->onDelete('cascade');
    $table->foreign('received_by')->references('id')->on('main_collector_details')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remittance');
    }
};
