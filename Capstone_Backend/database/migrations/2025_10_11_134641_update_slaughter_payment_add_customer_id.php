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
        Schema::table('slaughter_payment', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->after('animals_id')->nullable();

            // Add foreign key constraint
            $table->foreign('customer_id')->references('id')->on('customer_details')->onDelete('cascade');

            // Drop customer_name and contact_number
            $table->dropColumn(['customer_name', 'contact_number']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slaughter_payment', function (Blueprint $table) {
            $table->string('customer_name')->after('animals_id');
            $table->string('contact_number')->after('customer_name');

            // Drop foreign key and customer_id column
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
