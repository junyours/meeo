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
        Schema::create('stall_status_logs', function (Blueprint $table) {
            $table->id();
        $table->unsignedBigInteger('stall_id');
        $table->boolean('is_active');        // true = active, false = inactive
        $table->text('message')->nullable(); // reason / note from admin
        $table->timestamps();

        $table->foreign('stall_id')->references('id')->on('stall')->onDelete('cascade');
   
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stall_status_logs');
    }
};
