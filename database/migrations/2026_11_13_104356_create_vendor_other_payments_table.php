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
        Schema::create('vendor_other_payments', function (Blueprint $table) {
            $table->id();

            // Related records
            $table->foreignId('vendor_id')
                ->nullable()
                ->constrained('vendor_details')
                ->nullOnDelete();

            $table->foreignId('stall_id')
                ->nullable()
                ->constrained('stall')
                ->nullOnDelete();

            $table->foreignId('section_id')
                ->nullable()
                ->constrained('section')
                ->nullOnDelete();

            $table->foreignId('rented_id')
                ->nullable()
                ->constrained('rented')
                ->nullOnDelete();

            // For certification payments from non-vendors
            $table->string('payer_name')->nullable();

            // stall_rights, space_right, certification, dst
            $table->string('payment_type', 50);

            // Official receipt details
            $table->string('or_number', 100)->nullable()->index();
            $table->decimal('amount', 12, 2);

            $table->date('payment_date');

            // paid or void
            $table->string('status', 20)->default('paid');

          

            $table->timestamps();

            $table->index(['vendor_id', 'payment_type']);
            $table->index(['stall_id', 'payment_type']);
            $table->index(['payment_type', 'payment_date']);
            $table->index(['status', 'payment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_other_payments');
    }
};
