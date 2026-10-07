<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaughterhouseCollectionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_can_be_created_with_multiple_animal_entries_and_total_is_calculated(): void
    {
        $user = User::create([
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);

        $payload = [
            'collection_date' => '2026-09-21',
            'or_number' => '479700',
            'customer_name' => 'Juan Dela Cruz',
            'items' => [
                [
                    'quantity' => 1,
                    'animal_type' => 'Cattle',
                    'total_kilos' => 150,
                    'price_kilos' => 8,
                    'ante_mortem' => 100,
                    'post_mortem' => 50,
                    'hides' => 25,
                    'slaughter_fee' => 200,
                    'coral_fee' => 75,
                ],
                [
                    'quantity' => 2,
                    'animal_type' => 'Goat',
                    'total_kilos' => 35,
                    'price_kilos' => 10,
                    'ante_mortem' => 60,
                    'post_mortem' => 40,
                    'hides' => 15,
                    'slaughter_fee' => 120,
                    'coral_fee' => 30,
                ],
            ],
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/slaughterhouse/collections', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.grand_total', 733.0)
            ->assertJsonPath('data.items.0.total_amount', 8 + 100 + 50 + 25 + 200 + 75)
            ->assertJsonPath('data.items.1.total_amount', 10 + 60 + 40 + 15 + 120 + 30)
            ->assertJsonPath('data.transaction.customer_name', 'Juan Dela Cruz');

        $this->assertDatabaseHas('slaughterhouse_collection_items', ['animal_type' => 'Cattle']);
        $this->assertDatabaseHas('slaughterhouse_collection_items', ['animal_type' => 'Goat']);
    }
}
