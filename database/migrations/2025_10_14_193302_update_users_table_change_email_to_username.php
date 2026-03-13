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
        Schema::table('users', function (Blueprint $table) {
           $table->renameColumn('email', 'username');

            // Drop email_verified_at column
            $table->dropColumn(['email_verified_at','name']);
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
           $table->renameColumn('username', 'email');

            // Recreate 'email_verified_at' and 'name' if rolled back
            $table->timestamp('email_verified_at')->nullable();
            $table->string('name')->nullable();
        });
    }
};
