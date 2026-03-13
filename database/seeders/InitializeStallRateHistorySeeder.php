<?php

use Illuminate\Database\Seeder;
use App\Models\Stalls;
use App\Services\StallRateHistoryService;

class InitializeStallRateHistorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $rateHistoryService = app(StallRateHistoryService::class);
        
        $this->command->info('Initializing rate history for existing stalls...');
        
        $initializedCount = $rateHistoryService->initializeRateHistoryForAllStalls();
        
        $this->command->info("Rate history initialized for {$initializedCount} stalls.");
    }
}
