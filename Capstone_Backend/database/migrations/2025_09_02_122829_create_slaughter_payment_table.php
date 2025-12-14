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
        Schema::create('slaughter_payment', function (Blueprint $table) {
                  $table->id();

            // Relations
            $table->unsignedBigInteger('animals_id'); // Animal type
            $table->unsignedBigInteger('inspector_id'); // Meat inspector who audited
            $table->unsignedBigInteger('collector_id')->nullable(); // Collector assigned once collected

            // Customer info
            $table->string('customer_name');
            $table->string('contact_number')->nullable();

            // Slaughter details
            $table->integer('quantity'); // number of animals
            $table->decimal('total_kilos', 8, 2); // total kilos of all animals
            $table->json('per_kilos')->nullable(); // store per-animal kilo data as JSON

            // Fees (computed by inspector)
            $table->decimal('ante_mortem', 8, 2)->default(0);
            $table->decimal('post_mortem', 8, 2)->default(0);
            $table->decimal('coral_fee', 8, 2)->default(0);
            $table->decimal('permit_to_slh', 8, 2)->default(0);
            $table->decimal('slaughter_fee', 8, 2)->default(0);

            // Final total (inspector computes this)
            $table->decimal('total_amount', 10, 2);

            // Audit & collection tracking
                  $table->boolean('is_remitted')->default(false);
                    $table->timestamp('remitted_at')->nullable();
            $table->string('status')->default('pending'); 
            $table->date('payment_date')->nullable(); // set when collector confirms payment

            $table->timestamps();

            // Foreign keys
            $table->foreign('animals_id')->references('id')->on('animals')->onDelete('cascade');
            $table->foreign('inspector_id')->references('id')->on('meat_inspector_details')->onDelete('cascade'); 
            $table->foreign('collector_id')->references('id')->on('incharge_collector_details')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slaughter_payment');
    }
};
