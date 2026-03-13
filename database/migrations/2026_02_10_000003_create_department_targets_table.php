<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->onDelete('cascade');
            $table->decimal('annual_target', 15, 2);
            $table->integer('year');
            $table->json('monthly_targets')->nullable();
            $table->decimal('january_collection', 15, 2)->default(0);
            $table->decimal('february_collection', 15, 2)->default(0);
            $table->decimal('march_collection', 15, 2)->default(0);
            $table->decimal('april_collection', 15, 2)->default(0);
            $table->decimal('may_collection', 15, 2)->default(0);
            $table->decimal('june_collection', 15, 2)->default(0);
            $table->decimal('july_collection', 15, 2)->default(0);
            $table->decimal('august_collection', 15, 2)->default(0);
            $table->decimal('september_collection', 15, 2)->default(0);
            $table->decimal('october_collection', 15, 2)->default(0);
            $table->decimal('november_collection', 15, 2)->default(0);
            $table->decimal('december_collection', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['department_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_targets');
    }
};
