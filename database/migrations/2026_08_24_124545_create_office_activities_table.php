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
        Schema::create('office_activities', function (Blueprint $table) {
              $table->id();
    $table->string('title');
    $table->text('description')->nullable();
    $table->string('activity_type');
    $table->date('activity_date');
    $table->longText('image');
    $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_activities');
    }
};
