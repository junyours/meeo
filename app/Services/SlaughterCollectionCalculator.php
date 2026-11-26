<?php

namespace App\Services;

use App\Models\Animal;

class SlaughterCollectionCalculator
{
    public function calculate(Animal $animal, array $weights): array
    {
        $quantity = count($weights);
        $totalKilos = array_sum($weights);
        $minimumWeight = (float) $animal->minimum_weight_kg;
        $minimumWeightFee = (float) $animal->minimum_weight_fee;
        $slaughterRate = (float) $animal->slaughter_fee_per_kilo;
        $hasMinimumCharge = $minimumWeight > 0 && $minimumWeightFee > 0;
        $kiloCharge = 0;

        foreach ($weights as $weight) {
            $kiloCharge += $hasMinimumCharge
                ? max(0, $weight - $minimumWeight) * $slaughterRate
                : $weight * $slaughterRate;
        }

        $charges = [
            'quantity' => $quantity,
            'total_kilos' => round($totalKilos, 3),
            'animal_weights' => $weights,
            'price_kilos' => round($kiloCharge, 2),
            'ante_mortem' => round((float) $animal->ante_mortem_fee_per_head * $quantity, 2),
            'post_mortem' => round((float) $animal->post_mortem_fee_per_kilo * $totalKilos, 2),
            'hides' => round((float) $animal->hides_fee_per_head * $quantity, 2),
            'slaughter_fee' => $hasMinimumCharge
                ? round($minimumWeightFee * $quantity, 2)
                : 0,
            'coral_fee' => round((float) $animal->coral_fee_per_head * $quantity, 2),
            'permit_to_slh' => round((float) $animal->permit_to_slh_fee_per_head * $quantity, 2),
        ];

        $charges['total_amount'] = round(
            $charges['price_kilos']
            + $charges['ante_mortem']
            + $charges['post_mortem']
            + $charges['hides']
            + $charges['slaughter_fee']
            + $charges['coral_fee']
            + $charges['permit_to_slh'],
            2
        );

        return $charges;
    }
}
