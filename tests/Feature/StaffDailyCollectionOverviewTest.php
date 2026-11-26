<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffDailyCollectionOverviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');

        Schema::create('collection_sessions', function (Blueprint $table) {
            $table->id();
            $table->date('collection_date');
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('collection_session_id')->nullable();
            $table->string('client_transaction_id')->nullable();
            $table->decimal('amount_to_pay', 12, 2)->default(0);
            $table->boolean('is_collected')->default(false);
            $table->timestamp('collected_at')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('stall_id')->nullable();
            $table->unsignedBigInteger('collector_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
        });
    }

    private function makeUser(string $role): User
    {
        return new User([
            'username' => "{$role}-user",
            'email' => "{$role}@example.com",
            'password' => 'password123',
            'role' => $role,
        ]);
    }

    public function test_staff_can_access_their_daily_collection_overview(): void
    {
        $this->actingAs($this->makeUser('staff'), 'sanctum')
            ->getJson('/api/staff/daily-collections?date=2026-11-03')
            ->assertOk()
            ->assertJsonPath('date', '2026-11-03')
            ->assertJsonPath('summary.collection_count', 0)
            ->assertJsonPath('collections', []);
    }

    public function test_admin_cannot_use_staff_daily_collection_endpoint(): void
    {
        $this->actingAs($this->makeUser('admin'), 'sanctum')
            ->getJson('/api/staff/daily-collections')
            ->assertForbidden();
    }

    public function test_daily_collection_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/staff/daily-collections')->assertUnauthorized();
    }

    public function test_staff_daily_collection_date_must_use_iso_format(): void
    {
        $this->actingAs($this->makeUser('staff'), 'sanctum')
            ->getJson('/api/staff/daily-collections?date=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }
}