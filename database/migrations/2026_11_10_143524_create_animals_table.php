<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('animals', function (Blueprint $table) {
            $table->id();

            $table->string('name')->unique();

            $table->decimal('slaughter_fee_per_kilo', 10, 2)->default(0);
            $table->decimal('ante_mortem_fee_per_head', 10, 2)->default(0);
            $table->decimal('post_mortem_fee_per_kilo', 10, 2)->default(0);
            $table->decimal('coral_fee_per_head', 10, 2)->default(0);
            $table->decimal('permit_to_slh_fee_per_head', 10, 2)->default(0);

            $table->decimal('minimum_weight_kg', 10, 3)->default(0);
            $table->decimal('minimum_weight_fee', 10, 2)->default(0);

            $table->decimal('hides_fee_per_head', 10, 2)->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('animals');
    }
};