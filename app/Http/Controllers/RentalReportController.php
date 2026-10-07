<?php

namespace App\Http\Controllers;

use App\Models\Rented;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalReportController extends Controller
{
    private const REPORT_STATUSES = [
        'active',
        'occupied',
        'advance',
        'temp_closed',
        'partial',
        'fully paid',
        'unoccupied',
    ];

    public function rentalReport()
    {
        $rentals = Rented::query()
            ->select(['id', 'vendor_id', 'stall_id', 'status', 'daily_rent', 'monthly_rent', 'created_at'])
            ->with([
                'vendor:id,first_name,middle_name,last_name',
                'stall:id,stall_number,section_id',
                'stall.section:id,name',
            ])
            ->whereIn('status', self::REPORT_STATUSES)
            ->orderByDesc('created_at')
            ->get();

        $data = $rentals
            ->filter(fn ($rental) => $rental->vendor && $rental->stall && $rental->stall->section)
            ->groupBy('vendor_id')
            ->map(function ($group) {
                $firstRental = $group->first();
                $currentStallRentals = $this->currentRentalPerStall($group);
                $rentableStalls = $currentStallRentals->filter(fn ($rental) => $rental->status !== 'unoccupied');
                $temporarilyClosedRental = $rentableStalls->first(fn ($rental) => $rental->status === 'temp_closed');
                $displayStatus = $temporarilyClosedRental?->status
                    ?? $rentableStalls->first()?->status
                    ?? 'unoccupied';

                return [
                    'vendor_id' => $firstRental->vendor_id,
                    'vendor_name' => $firstRental->vendor->full_name,
                    'section_name' => $currentStallRentals
                        ->map(fn ($rental) => $rental->stall->section->name)
                        ->unique()
                        ->implode(', '),
                    'stall_numbers' => $currentStallRentals->map(fn ($rental) => $rental->stall->stall_number)->unique()->values(),
                    'rental_statuses' => [$displayStatus],
                    'daily_rental_total' => (float) $rentableStalls->sum('daily_rent'),
                    'monthly_rental_total' => (float) $rentableStalls->sum('monthly_rent'),
                ];
            })
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $data,
            'totals' => [
                'daily_rental' => (float) $data->sum('daily_rental_total'),
                'monthly_rental' => (float) $data->sum('monthly_rental_total'),
            ],
        ]);
    }

    public function vendorDetails(Request $request)
    {
        $request->validate([
            'vendor_id' => 'required|integer|exists:vendor_details,id',
        ]);

        $rentals = Rented::query()
            ->select([
                'id',
                'vendor_id',
                'stall_id',
                'status',
                'updated_at',
                'daily_rent',
                'monthly_rent',
                'created_at',
                'last_payment_date',
            ])
            ->with([
                'vendor:id,first_name,middle_name,last_name',
                'stall:id,stall_number,section_id',
                'stall.section:id,name',
            ])
            ->whereIn('status', self::REPORT_STATUSES)
            ->where('vendor_id', $request->input('vendor_id'))
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($rental) => $rental->vendor && $rental->stall && $rental->stall->section);

        if ($rentals->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vendor rental details not found',
            ], 404);
        }

        $firstRental = $rentals->first();
        $currentStallRentals = $this->currentRentalPerStall($rentals);
        $rentableStalls = $currentStallRentals->filter(fn ($rental) => $rental->status !== 'unoccupied');
        $stallDetails = $rentals->map(function ($rental) {
            return [
                'rented_id' => $rental->id,
                'section_name' => $rental->stall?->section?->name,
                'stall_number' => $rental->stall?->stall_number,
                'status' => $rental->status,
                'end_date' => $rental->status === 'unoccupied' ? $rental->updated_at?->toISOString() : null,
                'daily_rent' => (float) $rental->daily_rent,
                'monthly_rent' => (float) $rental->monthly_rent,
                'created_at' => $rental->created_at?->toISOString(),
                'last_payment_date' => $rental->last_payment_date?->toISOString(),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'vendor_name' => $firstRental->vendor->full_name,
                'total_stalls' => $currentStallRentals->count(),
                'section_name' => $rentals->pluck('stall.section.name')->filter()->unique()->implode(', '),
                'total_daily_rental' => (float) $rentableStalls->sum('daily_rent'),
                'total_monthly_rental' => (float) $rentableStalls->sum('monthly_rent'),
                'stall_details' => $stallDetails,
            ],
        ]);
    }

    private function currentRentalPerStall($rentals)
    {
        return $rentals
            ->groupBy('stall_id')
            ->map(function ($stallRentals) {
                return $stallRentals->first(fn ($rental) => $rental->status !== 'unoccupied')
                    ?? $stallRentals->first();
            })
            ->values();
    }

    public function updateRentedAt(Request $request, $id)
    {
        $validated = $request->validate([
            'rented_at' => 'required|date',
        ]);

        $rented = Rented::findOrFail($id);
        $rented->created_at = Carbon::parse($validated['rented_at']);
        $rented->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Rented at date updated successfully',
            'data' => $rented->fresh(),
        ]);
    }

    public function deleteRecord($id)
    {
        DB::transaction(function () use ($id) {
            $rented = Rented::with(['payments', 'stall'])->findOrFail($id);
            $stall = $rented->stall;

            $rented->payments()->delete();
            $rented->delete();

            if ($stall && !Rented::where('stall_id', $stall->id)
                ->whereIn('status', array_diff(self::REPORT_STATUSES, ['unoccupied']))
                ->exists()) {
                $stall->update(['status' => 'vacant']);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Rented record deleted successfully',
        ]);
    }
}
