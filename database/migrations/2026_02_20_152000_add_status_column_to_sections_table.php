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
        Schema::table('section', function (Blueprint $table) {
           $table->enum('rights_type', ['space_right', 'stall_right'])
                 
                  ->after('daily_rate');
         $table->decimal('stall_rights',8,2,)->after('rights_type');
                 $table->decimal('space_right',8,2,)->after('stall_rights');


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            //
        });
    }
};
