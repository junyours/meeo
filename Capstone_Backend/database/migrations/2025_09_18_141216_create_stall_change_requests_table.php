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
        Schema::create('stall_change_requests', function (Blueprint $table) {
            $table->id();
       // Foreign keys as unsigned big integers
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('vendor_id');

            $table->json('new_stall_ids');
              $table->json('old_stall_ids');

            $table->string('status')->default('pending'); // ✅ string instead of enum
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('application_id')
                  ->references('id')->on('applications')
                  ->onDelete('cascade');

            $table->foreign('vendor_id')
                  ->references('id')->on('vendor_details')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stall_change_requests');
    }
};
