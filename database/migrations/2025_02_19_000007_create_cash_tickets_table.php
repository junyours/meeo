<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cash_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // Type of cash ticket (market, toilet, parking)
            $table->integer('quantity'); // Number of tickets
            $table->decimal('amount', 10, 2); // Amount per ticket
            $table->date('payment_date'); // Date of collection
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'payment_date']);
            $table->index('payment_date');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_tickets');
    }
};
