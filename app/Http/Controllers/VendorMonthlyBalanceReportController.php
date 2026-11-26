<?php

namespace App\Http\Controllers;

use App\Models\Payments;
use App\Models\Rented;
use App\Models\VendorDetails;
use App\Services\StallRateHistoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class VendorMonthlyBalanceReportController extends Controller
{
    public function __construct(
        private readonly StallRateHistoryService $rateHistoryService
    ) {
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'between:1900,2200'],
        ]);
        $year = (int) ($validated['year'] ?? now()->year);
        $vendors = VendorDetails::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name')
            ->get();
        $vendorIds = $vendors->pluck('id');

        $rentalsByVendor = Rented::with(['stall.section'])
            ->whereIn('vendor_id', $vendorIds)
            ->where(function ($query) use ($year) {
                $query->whereIn('status', ['active', 'occupied', 'advance', 'temp_closed', 'partial', 'fully paid'])
                    ->orWhere(function ($query) use ($year) {
                        $query->where('status', 'unoccupied')
                            ->whereYear('created_at', '<=', $year)
                            ->where(function ($query) use ($year) {
                                $query->whereYear('created_at', $year)
                                    ->orWhereYear('updated_at', $year);
                            });
                    });
            })
            ->get()
            ->groupBy('vendor_id');

        $paymentsByVendor = Payments::query()
            ->whereIn('vendor_id', $vendorIds)
            ->whereYear('payment_date', $year)
            ->whereIn('status', ['paid', 'collected'])
            ->get(['vendor_id', 'payment_date', 'amount'])
            ->groupBy('vendor_id');

        $reportRows = $vendors->map(function (VendorDetails $vendor) use ($year, $rentalsByVendor, $paymentsByVendor) {
            $balances = $this->calculateMonthlyBalances(
                $rentalsByVendor->get($vendor->id, collect()),
                $paymentsByVendor->get($vendor->id, collect()),
                $year
            );

            return [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->full_name,
                ...$balances,
                'total_balance' => round(array_sum($balances), 2),
            ];
        })->values();

        return response()->json([
            'year' => $year,
            'vendors' => $reportRows,
        ]);
    }

    private function calculateMonthlyBalances($rentals, $payments, int $year): array
    {
        $months = [
            1 => 'jan',
            2 => 'feb',
            3 => 'mar',
            4 => 'apr',
            5 => 'may',
            6 => 'jun',
            7 => 'jul',
            8 => 'aug',
            9 => 'sep',
            10 => 'oct',
            11 => 'nov',
            12 => 'dec',
        ];
        $paymentsByMonth = $payments->groupBy(
            fn (Payments $payment) => $payment->payment_date->month
        );
        $balances = [];

        foreach ($months as $monthNumber => $key) {
            $monthStart = Carbon::createFromDate($year, $monthNumber, 1)->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();
            $monthlyRate = 0;

            foreach ($rentals as $rental) {
                if (!$rental->stall || !$rental->stall->section) {
                    continue;
                }

                $rentalStart = $rental->created_at->copy()->startOfDay();
                $rentalEnd = $rental->status === 'unoccupied' && $rental->updated_at
                    ? $rental->updated_at->copy()->endOfDay()
                    : null;

                if ($rentalStart->greaterThan($monthEnd) ||
                    ($rentalEnd && $rentalEnd->lessThan($monthStart))) {
                    continue;
                }

                for ($day = 1; $day <= $monthStart->daysInMonth; $day++) {
                    $date = Carbon::createFromDate($year, $monthStart->month, $day)->startOfDay();
                    if ($date->lessThan($rentalStart) || ($rentalEnd && $date->greaterThan($rentalEnd))) {
                        continue;
                    }

                    $monthlyRate += $this->rateHistoryService->getDailyRentForRentalDate($rental, $date);
                }
            }

            $payment = (float) $paymentsByMonth->get(
                $monthStart->month,
                collect()
            )->sum('amount');
            $balances[$key] = round(max(0, $monthlyRate - $payment), 2);
        }

        return $balances;
    }
}
