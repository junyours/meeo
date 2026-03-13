<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

public function up()
{
    Schema::create('motorpool', function (Blueprint $table) {
        $table->id();
        $table->decimal('amount', 10, 2);
        $table->unsignedBigInteger('collector_id');
        $table->string('status')->default('pending');
            $table->date('payment_date');
        $table->timestamps();


          $table->foreign('collector_id')
              ->references('id')
              ->on('incharge_collector_details')
              ->onDelete('cascade');
    });
}


    public function down(): void {
        Schema::dropIfExists('collections');
    }
};
