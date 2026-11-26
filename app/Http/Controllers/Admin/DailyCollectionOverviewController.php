<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use Illuminate\Http\Request;

class DailyCollectionOverviewController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Administrator access is required.');

        $date = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ])['date'] ?? today()->toDateString();

        $rows = Collection::query()
            ->whereHas('session', fn ($query) => $query->whereDate('collection_date', $date))
            ->with([
                'vendor:id,first_name,middle_name,last_name',
                'stall:id,stall_number,section_id',
                'stall.section:id,name',
                'collector:id,username',
                'payment:id,or_number',
            ])
            ->orderByDesc('id')
            ->get();

        $collections = $rows
            ->groupBy(fn (Collection $row) => $row->client_transaction_id
                ? "session-{$row->collection_session_id}-{$row->client_transaction_id}"
                : "collection-{$row->id}")
            ->map(function ($items) {
                $primary = $items->first();
                $expected = (float) $items->sum('amount_to_pay');
                $collectedItems = $items->filter(fn (Collection $item) => $item->is_collected);
                $uncollectedItems = $items->reject(fn (Collection $item) => $item->is_collected);
                $collected = (float) $collectedItems->sum('amount_to_pay');
                $outstanding = (float) $uncollectedItems->sum('amount_to_pay');
                $status = $uncollectedItems->isEmpty() ? 'collected' : 'not_collected';

                return [
                    'id' => (string) $primary->id,
                    'vendor_name' => $primary->vendor?->full_name ?? 'Unknown vendor',
                    'collector_name' => $primary->collector?->username ?? 'Unknown collector',
                    'stall_numbers' => $items
                        ->map(fn (Collection $item) => $item->stall?->stall_number)
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(', '),
                    'section_names' => $items
                        ->map(fn (Collection $item) => $item->stall?->section?->name)
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(', '),
                    'amount_to_pay' => number_format($expected, 2, '.', ''),
                    'collected_amount' => number_format($collected, 2, '.', ''),
                    'uncollected_amount' => number_format($outstanding, 2, '.', ''),
                    'status' => $status,
                    'or_number' => $items
                        ->map(fn (Collection $item) => $item->payment?->or_number)
                        ->filter()
                        ->map(fn ($number) => (string) $number)
                        ->unique()
                        ->values()
                        ->implode(', '),
                    'collected_at' => $items
                        ->filter(fn (Collection $item) => $item->is_collected)
                        ->max('collected_at'),
                ];
            })
            ->values();

        return response()->json([
            'date' => $date,
            'summary' => [
                'expected_total' => number_format((float) $rows->sum('amount_to_pay'), 2, '.', ''),
                'collected_total' => number_format(
                    (float) $rows->filter(fn (Collection $item) => $item->is_collected)->sum('amount_to_pay'),
                    2,
                    '.',
                    ''
                ),
                'uncollected_total' => number_format(
                    (float) $rows->reject(fn (Collection $item) => $item->is_collected)->sum('amount_to_pay'),
                    2,
                    '.',
                    ''
                ),
                'collection_count' => $collections->count(),
                'collected_count' => $collections->where('status', 'collected')->count(),
                'uncollected_count' => $collections->where('status', 'not_collected')->count(),
            ],
            'collections' => $collections,
        ]);
    }
}
