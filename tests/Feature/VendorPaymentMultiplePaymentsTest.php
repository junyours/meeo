<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VendorPaymentMultiplePaymentsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_requires_a_vendor_stall_and_at_least_one_payment_row(): void
    {
        Sanctum::actingAs(new User);

        $this->postJson('/api/vendor-payments/multiple')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vendor_id', 'rental_ids', 'payments']);
    }

    public function test_it_requires_payment_details_for_each_row(): void
    {
        Sanctum::actingAs(new User);

        $this->postJson('/api/vendor-payments/multiple', [
            'payments' => [[]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'vendor_id',
                'rental_ids',
                'payments.0.payment_date',
                'payments.0.or_number',
                'payments.0.amount',
            ]);
    }

    public function test_it_rejects_future_payment_dates_but_allows_today(): void
    {
        Sanctum::actingAs(new User);
        Carbon::setTestNow('2026-10-27 10:00:00');

        $this->postJson('/api/vendor-payments/multiple', [
            'payments' => [[
                'payment_date' => '2026-10-28',
                'or_number' => 'OR-123',
                'amount' => 100,
            ]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments.0.payment_date');
    }

    public function test_it_accepts_a_payment_date_from_today(): void
    {
        Sanctum::actingAs(new User);
        Carbon::setTestNow('2026-10-27 10:00:00');

        $this->postJson('/api/vendor-payments/multiple', [
            'payments' => [[
                'payment_date' => '2026-10-27',
                'or_number' => 'OR-123',
                'amount' => 100,
            ]],
        ])
            ->assertUnprocessable()
            ->assertJsonMissingValidationErrors('payments.0.payment_date');
    }
}
