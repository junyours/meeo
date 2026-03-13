<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cash_tickets_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_ticket_id')->constrained()->onDelete('cascade');
            $table->decimal('amount_paid', 10, 2);
            $table->date('payment_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('cash_ticket_id');
            $table->index('payment_date');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_tickets_payments');
    }
};
