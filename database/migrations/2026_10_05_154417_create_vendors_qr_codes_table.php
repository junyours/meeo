<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_qr_codes', function (Blueprint $table) {
            $table->id();

            // QR belongs to a vendor
            $table->foreignId('vendor_id')
                ->constrained('vendor_details')
                ->cascadeOnDelete();
            // Secure random token stored inside QR
            $table->string('qr_token', 100)->unique();
            // QR active/inactive status
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_qr_codes');
    }
};