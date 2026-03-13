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
        Schema::table('vendor_details', function (Blueprint $table) {
            // Add new columns for first name, middle name, last name
        
            
            // Add soft deletes
    
            
            // Drop the old fullname column (we'll use an accessor)
            $table->dropColumn('fullname');
            
            // Remove unnecessary columns (but keep address and add Status)
            $table->dropColumn(['age', 'gender', 'emergency_contact']);
            $table->dropColumn(['business_permit', 'sanitary_permit', 'dti_permit']);
            
            // Add Status column if it doesn't exist
            if (!Schema::hasColumn('vendor_details', 'Status')) {
                $table->string('Status')->default('active')->after('address');
            }
            
            // Remove user_id and its foreign key
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_details', function (Blueprint $table) {
            // Add back the old columns
            $table->unsignedBigInteger('user_id')->after('id');
            $table->string('fullname')->after('id');
            $table->string('age')->nullable();
            $table->string('gender')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('address')->nullable();
            $table->string('business_permit')->nullable();
            $table->string('sanitary_permit')->nullable();
            $table->string('dti_permit')->nullable();
            
            // Remove new columns
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
            $table->dropSoftDeletes();
            
            // Re-add foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Drop Status column if it exists
            if (Schema::hasColumn('vendor_details', 'Status')) {
                $table->dropColumn('Status');
            }
        });
    }
};
