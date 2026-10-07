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
         Schema::dropIfExists('inspection_record');
                Schema::dropIfExists('slaughter_payment');
        Schema::dropIfExists('animals');
                Schema::dropIfExists('market_registration_renewal_requests');
              Schema::dropIfExists('market_registration');
                      Schema::dropIfExists('stall_change_requests');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('customer_details');
                Schema::dropIfExists('motorpool');
                        Schema::dropIfExists('wharf');
           Schema::dropIfExists('market_collection');
                Schema::dropIfExists('remittanceables');
                 Schema::dropIfExists('remittance');
        Schema::dropIfExists('incharge_collector_details');
        Schema::dropIfExists('main_collector_details');
        Schema::dropIfExists('meat_inspector_details');
        Schema::dropIfExists('permits');
        Schema::dropIfExists('products');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('stall_removal_request');
        Schema::dropIfExists('stall_status_logs');
        Schema::dropIfExists('targets');
        Schema::dropIfExists('tenants');









    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
