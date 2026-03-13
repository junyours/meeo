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
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
           $table->unsignedBigInteger('tenant_id');
    $table->string('main_source');
    $table->string('purchase_location');
    $table->string('purchase_frequency');
    $table->string('transport_mode');
    $table->timestamps();

    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
     });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
