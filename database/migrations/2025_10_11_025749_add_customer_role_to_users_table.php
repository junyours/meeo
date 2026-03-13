<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
              DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin', 'meat_inspector', 'vendor','incharge_collector','main_collector','motorpool','wharf','customer') NOT NULL DEFAULT 'vendor'");
 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin', 'meat_inspector', 'vendor','incharge_collector','main_collector','motorpool','wharf') NOT NULL DEFAULT 'vendor'");
 
        });
    }
};
