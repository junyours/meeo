<?php

namespace Tests\Unit;

use App\Models\Animal;
use App\Services\SlaughterCollectionCalculator;
use PHPUnit\Framework\TestCase;

class SlaughterCollectionCalculatorTest extends TestCase
{
    public function test_minimum_fee_is_charged_with_only_excess_kilos_at_the_slaughter_rate(): void
    {
        $animal = new Animal([
            'minimum_weight_kg' => 68,
            'minimum_weight_fee' => 200,
            'slaughter_fee_per_kilo' => 3.20,
        ]);

        $charges = (new SlaughterCollectionCalculator())->calculate($animal, [70]);

        $this->assertSame(6.4, $charges['price_kilos']);
        $this->assertSame(200.0, $charges['slaughter_fee']);
        $this->assertSame(206.4, $charges['total_amount']);
    }

    public function test_weight_is_charged_at_the_full_rate_when_minimum_settings_are_incomplete(): void
    {
        $animal = new Animal([
            'minimum_weight_kg' => 68,
            'minimum_weight_fee' => 0,
            'slaughter_fee_per_kilo' => 3.20,
        ]);

        $charges = (new SlaughterCollectionCalculator())->calculate($animal, [70]);

        $this->assertSame(224.0, $charges['price_kilos']);
        $this->assertSame(0, $charges['slaughter_fee']);
        $this->assertSame(224.0, $charges['total_amount']);
    }
}
