<?php

namespace Tests\Unit;

use App\Services\StallRateHistoryService;
use App\Models\Rented;
use App\Models\Sections;
use App\Models\Stalls;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StallRateHistoryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('stall_rate_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stall_id');
            $table->decimal('daily_rate', 10, 2)->nullable();
            $table->decimal('monthly_rate', 10, 2)->nullable();
            $table->decimal('annual_rate', 10, 2)->nullable();
            $table->date('effective_from');
            $table->timestamps();
        });

        DB::table('stall_rate_histories')->insert([
            [
                'stall_id' => 1,
                'daily_rate' => 350,
                'effective_from' => '2026-10-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'stall_id' => 1,
                'daily_rate' => 150,
                'effective_from' => '2026-10-13',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_rate_change_is_inclusive_and_uses_each_calendar_day(): void
    {
        $service = new StallRateHistoryService();
        $stall = new Stalls([
            'daily_rate' => 350,
            'monthly_rate' => 0,
            'is_monthly' => false,
            'stall_number' => 1,
        ]);
        $stall->setAttribute('id', 1);
        $section = new Sections();
        $section->setAttribute('name', 'General');
        $section->setAttribute('rate_type', 'daily');
        $stall->setRelation('section', $section);

        $rental = new Rented();
        $rental->setAttribute('daily_rent', 350);
        $rental->setAttribute('monthly_rent', 0);
        $rental->setRelation('stall', $stall);
        $octoberTotal = 0;

        for ($day = 1; $day <= 31; $day++) {
            $date = sprintf('2026-10-%02d', $day);
            $octoberTotal += $service->getDailyRentForRentalDate($rental, $date);
        }

        $this->assertSame(350.0, (float) $service->getRatesForDate(1, '2026-10-12')->daily_rate);
        $this->assertSame(150.0, (float) $service->getRatesForDate(1, '2026-10-13')->daily_rate);
        $this->assertSame(7050.0, $octoberTotal);
    }
}
