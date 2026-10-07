<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `users` MODIFY `role` VARCHAR(255) NOT NULL DEFAULT 'vendor'"
        );
    }

    public function down(): void
    {
        if (DB::table('users')->whereIn('role', ['collector', 'staff','admin'])->exists()) {
            throw new RuntimeException(
                'Cannot remove collector and staff roles while accounts with those roles exist.'
            );
        }

        DB::statement(
            "ALTER TABLE `users` MODIFY `role` ENUM('admin', 'meat_inspector', 'vendor', 'incharge_collector', 'main_collector', 'motorpool', 'wharf', 'customer') NOT NULL DEFAULT 'vendor'"
        );
    }
};
 