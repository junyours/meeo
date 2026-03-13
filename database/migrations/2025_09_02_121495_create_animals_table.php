<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB; // <-- Import DB facade

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('animals', function (Blueprint $table) {
           $table->id();
            $table->string('animal_type');
            $table->decimal('fixed_rate', 8, 2)->default(0);
            $table->decimal('ante_mortem_rate', 8, 2)->default(0);
            $table->decimal('post_mortem_rate', 8, 2)->default(0);
            $table->decimal('coral_fee_rate', 8, 2)->default(0);
            $table->decimal('permit_to_slh_rate', 8, 2)->default(0);
            $table->decimal('slaughter_fee_rate', 8, 2)->default(0);
            $table->decimal('excess_kilo_limit', 8, 2)->default(0);
            $table->timestamps();
        });

        // ✅ Insert default animals
        DB::table('animals')->insert([
            [
                'animal_type'        => 'Cow',
                'fixed_rate'         => 450.00,
                'ante_mortem_rate'   => 20.00,
                'post_mortem_rate'   => 0.50,
                'coral_fee_rate'     => 10.00,
                'permit_to_slh_rate' => 5.00,
                'slaughter_fee_rate' => 3.20,
                'excess_kilo_limit'  => 112.00,
            ],
            [
                'animal_type'        => 'Goat',
                'fixed_rate'         => 0,          
                'ante_mortem_rate'   => 20.00,
                'post_mortem_rate'   => 0.50,
                'coral_fee_rate'     => 10.00,
                'permit_to_slh_rate' => 5.00,
                'slaughter_fee_rate' => 500.00,     
                'excess_kilo_limit'  => 0,          
            ],
        ]);
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('animals');
    }
};
