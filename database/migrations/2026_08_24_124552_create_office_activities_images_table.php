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
        Schema::create('office_activities_images', function (Blueprint $table) {
             $table->id();
    $table->foreignId('office_activity_id')
          ->constrained('office_activities')
          ->onDelete('cascade');
    $table->longText('image');
    $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_activities_images');
    }
};
