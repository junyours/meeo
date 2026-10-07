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
        Schema::create('market_product_price_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('market_product_id')
                ->constrained('market_products')
                ->cascadeOnDelete();
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('new_price', 10, 2);
            $table->decimal('change_amount', 10, 2)->nullable();
            $table->decimal('change_percentage', 10, 2)->nullable();
            $table->dateTime('effective_date');


            $table->timestamps();

            // Makes price history queries faster
          
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_product_price_histories');
    }
};
