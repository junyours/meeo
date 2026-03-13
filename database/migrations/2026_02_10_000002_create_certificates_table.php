<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('certificate_number')->unique();
            $table->string('template_name');
            $table->json('template_fields');
            $table->string('vendor_first_name');
            $table->string('vendor_middle_name')->nullable();
            $table->string('vendor_last_name');
            $table->string('stall_number')->nullable();
            $table->date('issue_date');
            $table->date('expiry_date');
            $table->enum('status', ['active', 'expired', 'revoked'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendor_details')->onDelete('set null');
            $table->foreignId('stall_id')->nullable()->constrained('stall')->onDelete('set null');
            $table->foreignId('issued_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
