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
        Schema::create('stall', function (Blueprint $table) {
            $table->id();
                 $table->unsignedBigInteger('section_id');

            $table->string('stall_number');
        $table->integer('row_position');
        $table->integer('column_position');
        $table->decimal('size', 8, 2)->nullable();
        $table->enum('status', ['vacant', 'occupied'])->default('vacant');
            $table->timestamps();

            $table->foreign('section_id')->references('id')->on('section')->onDelete('cascade');
  
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stall');
    }
};
