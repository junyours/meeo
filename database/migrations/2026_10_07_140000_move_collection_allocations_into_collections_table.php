<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropUnique(['client_transaction_id']);
            $table->index('client_transaction_id');
        });

        if (! Schema::hasTable('collection_allocations')) {
            return;
        }

        DB::transaction(function () {
            $allocationGroups = DB::table('collection_allocations')
                ->orderBy('collection_id')
                ->orderBy('id')
                ->get()
                ->groupBy('collection_id');

            foreach ($allocationGroups as $collectionId => $allocations) {
                $parent = DB::table('collections')->where('id', $collectionId)->first();
                if (! $parent) {
                    continue;
                }

                $first = $allocations->first();
                DB::table('collections')->where('id', $collectionId)->update([
                    'rented_id' => $first->rented_id,
                    'stall_id' => $first->stall_id,
                    'payment_id' => $first->payment_id,
                    'amount_to_pay' => $first->amount_to_pay,
                    'days_covered' => $first->days_covered,
                    'payment_type' => $first->payment_type,
                    'rental_snapshot' => $first->rental_snapshot,
                    'updated_at' => now(),
                ]);

                foreach ($allocations->skip(1) as $allocation) {
                    DB::table('collections')->insert([
                        'collection_session_id' => $parent->collection_session_id,
                        'vendor_id' => $parent->vendor_id,
                        'rented_id' => $allocation->rented_id,
                        'stall_id' => $allocation->stall_id,
                        'collector_id' => $parent->collector_id,
                        'amount_to_pay' => $allocation->amount_to_pay,
                        'days_covered' => $allocation->days_covered,
                        'payment_type' => $allocation->payment_type,
                        'is_collected' => $parent->is_collected,
                        'collected_at' => $parent->collected_at,
                        'created_at' => $allocation->created_at,
                        'updated_at' => $allocation->updated_at,
                        'client_transaction_id' => $parent->client_transaction_id,
                        'payment_id' => $allocation->payment_id,
                        'rental_snapshot' => $allocation->rental_snapshot,
                    ]);
                }
            }
        });

        Schema::dropIfExists('collection_allocations');
    }

    public function down(): void
    {
        Schema::create('collection_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('rented_id')->constrained('rented')->cascadeOnDelete();
            $table->foreignId('stall_id')->constrained('stall')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->nullOnDelete();
            $table->decimal('amount_to_pay', 12, 2);
            $table->unsignedInteger('days_covered')->default(0);
            $table->string('payment_type')->nullable();
            $table->json('rental_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['collection_id', 'rented_id']);
            $table->index('rented_id');
        });

        DB::transaction(function () {
            $groups = DB::table('collections')
                ->whereNotNull('client_transaction_id')
                ->orderBy('id')
                ->get()
                ->groupBy('client_transaction_id')
                ->filter(fn ($rows) => $rows->count() > 1);

            foreach ($groups as $rows) {
                $parent = $rows->first();
                foreach ($rows as $row) {
                    DB::table('collection_allocations')->insert([
                        'collection_id' => $parent->id,
                        'rented_id' => $row->rented_id,
                        'stall_id' => $row->stall_id,
                        'payment_id' => $row->payment_id,
                        'amount_to_pay' => $row->amount_to_pay,
                        'days_covered' => $row->days_covered,
                        'payment_type' => $row->payment_type,
                        'rental_snapshot' => $row->rental_snapshot,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);
                }

                DB::table('collections')->where('id', $parent->id)->update([
                    'amount_to_pay' => $rows->sum('amount_to_pay'),
                    'days_covered' => $rows->sum('days_covered'),
                    'payment_type' => $rows->pluck('payment_type')->filter()->unique()->count() === 1
                        ? $rows->pluck('payment_type')->filter()->first()
                        : 'combined',
                    'payment_id' => null,
                ]);
                DB::table('collections')->whereIn('id', $rows->skip(1)->pluck('id'))->delete();
            }
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropIndex(['client_transaction_id']);
            $table->unique('client_transaction_id');
        });
    }
};
