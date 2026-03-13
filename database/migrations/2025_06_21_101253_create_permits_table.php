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
        Schema::create('permits', function (Blueprint $table) {
            $table->id();
                  $table->unsignedBigInteger('tenant_id');
    $table->boolean('business_permit')->default(false);
    $table->boolean('sanitary_permit')->default(false);
    $table->boolean('market_registration')->default(false);
    $table->boolean('dti_registration')->default(false);
    $table->string('remarks')->nullable(); 
    $table->timestamps();

    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
       });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permits');
    }
};
