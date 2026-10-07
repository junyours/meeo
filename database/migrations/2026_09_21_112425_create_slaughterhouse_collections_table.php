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
         Schema::create('slaughterhouse_collections', function (Blueprint $table) {
            $table->id();
            $table->date('collection_date');
            $table->string('or_number');
            $table->string('customer_name');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('collection_date');
            $table->index('or_number');
            $table->index('customer_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slaughterhouse_collections');
    }
};
