<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CashTicket;

class CashTicketSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultTypes = [
            [
                'type' => 'Market',
                'quantity' => 1,
                'amount' => 50.00,
                'notes' => 'Market stall fees'
            ],
            [
                'type' => 'Toilet',
                'quantity' => 1,
                'amount' => 10.00,
                'notes' => 'Toilet usage fees'
            ],
            [
                'type' => 'Parking',
                'quantity' => 1,
                'amount' => 30.00,
                'notes' => 'Vehicle parking fees'
            ],
        ];

        foreach ($defaultTypes as $type) {
            CashTicket::create($type);
        }
    }
}
