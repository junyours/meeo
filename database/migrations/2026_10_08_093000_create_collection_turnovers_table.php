<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_turnovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_session_id')
                ->constrained('collection_sessions')
                ->cascadeOnDelete();
            $table->uuid('turnover_client_id')->unique();
            $table->decimal('expected_total', 12, 2)->default(0);
            $table->decimal('collected_total', 12, 2)->default(0);
            $table->decimal('cash_turned_over', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index('collection_session_id');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->foreignId('collection_turnover_id')
                ->nullable()
                ->after('collection_session_id')
                ->constrained('collection_turnovers')
                ->nullOnDelete();
        });

        DB::transaction(function () {
            DB::table('collection_sessions')
                ->where(function ($query) {
                    $query->where('status', 'submitted')
                        ->orWhereNotNull('cash_turned_over');
                })
                ->orderBy('id')
                ->chunkById(100, function ($sessions) {
                    foreach ($sessions as $session) {
                        $turnoverId = DB::table('collection_turnovers')->insertGetId([
                            'collection_session_id' => $session->id,
                            'turnover_client_id' => $session->turnover_client_id ?: (string) Str::uuid(),
                            'expected_total' => $session->expected_total,
                            'collected_total' => $session->collected_total,
                            'cash_turned_over' => $session->cash_turned_over ?? 0,
                            'difference' => $session->difference ?? 0,
                            'remarks' => $session->remarks,
                            'submitted_at' => $session->updated_at ?? now(),
                            'created_at' => $session->created_at ?? now(),
                            'updated_at' => $session->updated_at ?? now(),
                        ]);

                        DB::table('collections')
                            ->where('collection_session_id', $session->id)
                            ->where('is_collected', true)
                            ->whereNull('collection_turnover_id')
                            ->update(['collection_turnover_id' => $turnoverId]);
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collection_turnover_id');
        });

        Schema::dropIfExists('collection_turnovers');
    }
};
