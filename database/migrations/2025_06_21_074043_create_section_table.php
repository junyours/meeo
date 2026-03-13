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
        Schema::create('section', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('area_id');

                $table->enum('rate_type', ['per_sqm', 'fixed'])->default('per_sqm');
    $table->decimal('rate', 8, 2)->nullable();
    $table->decimal('monthly_rate', 8, 2)->nullable(); 
    $table->decimal('daily_rate', 8, 2)->nullable(); 
      $table->unsignedInteger('column_index')->default(0);
            $table->unsignedInteger('row_index')->default(0);
            $table->timestamps();
              $table->foreign('area_id')->references('id')->on('areas')->onDelete('cascade');
  
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('section');
    }
};
