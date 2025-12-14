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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
                 $table->unsignedBigInteger('tenant_id');
    $table->decimal('week1', 10, 2)->nullable();
    $table->decimal('week2', 10, 2)->nullable();
    $table->decimal('week3', 10, 2)->nullable();
    $table->decimal('week4', 10, 2)->nullable();
    $table->decimal('total_sales', 10, 2)->nullable();
    $table->timestamps();

    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
       });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
