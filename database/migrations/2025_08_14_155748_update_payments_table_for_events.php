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
        Schema::table('payments', function (Blueprint $table) {
            // Check if columns already exist before adding
            if (!Schema::hasColumn('payments', 'activity_id')) {
                $table->foreignId('activity_id')->nullable()->after('rented_id')->constrained('event_activities')->nullOnDelete();
            }
            
            // Only add payment_type if it doesn't exist (for existing systems)
            if (!Schema::hasColumn('payments', 'payment_type')) {
                $table->enum('payment_type', ['regular', 'event'])->default('regular')->after('vendor_id');
            }
            
            // Add indexes for better performance
            if (Schema::hasColumn('payments', 'activity_id')) {
                $table->index(['payment_type', 'activity_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'activity_id')) {
                $table->dropForeign(['payments_activity_id_foreign']);
                $table->dropColumn('activity_id');
            }
            
            if (Schema::hasColumn('payments', 'payment_type')) {
                $table->dropColumn('payment_type');
            }
            
            $table->dropIndex(['payments_payment_type_activity_id_index']);
        });
    }
};
