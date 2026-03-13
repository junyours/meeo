<?php

namespace App\Http\Controllers;

use App\Models\Payments;
use App\Models\VendorDetails;
use App\Models\Rented;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentMonitoringController extends Controller
{
    public function getMonthlyMonitoring(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2030',
            'area_id' => 'nullable|exists:areas,id',
            'section_id' => 'nullable|exists:section,id',
            'stall_id' => 'nullable|exists:stall,id',
        ]);

        $month = $validated['month'];
        $year = $validated['year'];
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;

        $query = VendorDetails::with([
            'rentals.stall.section.area',
            'rentals.payments' => function($q) use ($month, $year) {
                $q->whereMonth('payment_date', $month)
                  ->whereYear('payment_date', $year);
            }
        ])
        ->whereHas('rentals', function($q) {
            $q->where('status', 'active');
        });

        if ($validated['area_id']) {
            $query->whereHas('rentals.stall.section', function($q) use ($validated) {
                $q->where('area_id', $validated['area_id']);
            });
        }

        if ($validated['section_id']) {
            $query->whereHas('rentals.stall', function($q) use ($validated) {
                $q->where('section_id', $validated['section_id']);
            });
        }

        if ($validated['stall_id']) {
            $query->whereHas('rentals', function($q) use ($validated) {
                $q->where('stall_id', $validated['stall_id']);
            });
        }

        $vendors = $query->get();

        $monitoringData = $vendors->map(function($vendor) use ($month, $year, $daysInMonth) {
            $rental = $vendor->rentals->where('status', 'active')->first();
            $payments = $rental ? $rental->payments : collect();

            $paymentCalendar = [];
            $totalPaid = 0;
            $totalDays = 0;
            $missedDays = 0;

            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = Carbon::create($year, $month, $day);
                $dayPayment = $payments->first(function($payment) use ($date) {
                    return Carbon::parse($payment->payment_date)->format('Y-m-d') === $date->format('Y-m-d');
                });

                if ($dayPayment) {
                    $paymentCalendar[$day] = [
                        'status' => 'paid',
                        'amount' => $dayPayment->amount,
                        'payment_type' => $dayPayment->payment_type,
                    ];
                    $totalPaid += $dayPayment->amount;
                    $totalDays++;
                } else {
                    $paymentCalendar[$day] = [
                        'status' => 'unpaid',
                        'amount' => 0,
                        'payment_type' => null,
                    ];
                    if ($date->isPast()) {
                        $missedDays++;
                    }
                }
            }

            return [
                'vendor' => $vendor,
                'rental' => $rental,
                'payment_calendar' => $paymentCalendar,
                'total_paid' => $totalPaid,
                'total_days' => $totalDays,
                'missed_days' => $missedDays,
                'outstanding_balance' => $rental ? ($rental->monthly_rate - $totalPaid) : 0,
            ];
        });

        return response()->json([
            'month' => $month,
            'year' => $year,
            'days_in_month' => $daysInMonth,
            'vendors' => $monitoringData,
        ]);
    }

    public function recordPayment(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendor_details,id',
            'stall_id' => 'required|exists:stall,id',
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'payment_type' => 'required|in:partial,fully_paid,advance',
            'days_covered' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        $vendor = VendorDetails::findOrFail($validated['vendor_id']);
        $rental = Rented::where('vendor_id', $vendor->id)
                       ->where('stall_id', $validated['stall_id'])
                       ->where('status', 'active')
                       ->firstOrFail();

        DB::transaction(function() use ($validated, $vendor, $rental) {
            $payment = Payments::create([
                'rented_id' => $rental->id,
                'vendor_id' => $vendor->id,
                'collector_id' => auth()->id(),
                'payment_date' => $validated['payment_date'],
                'amount' => $validated['amount'],
                'payment_type' => $validated['payment_type'],
                'missed_days' => 0,
                'advance_days' => $validated['payment_type'] === 'advance' ? ($validated['days_covered'] ?? 1) : 0,
                'status' => 'paid',
            ]);

            AdminActivity::log(
                auth()->id(),
                'create',
                'payment_monitoring',
                "Recorded payment for vendor {$vendor->fullname}",
                null,
                [
                    'payment_id' => $payment->id,
                    'vendor_id' => $vendor->id,
                    'amount' => $validated['amount'],
                    'payment_type' => $validated['payment_type'],
                ]
            );
        });

        return response()->json(['message' => 'Payment recorded successfully']);
    }

    public function getVendorPaymentSummary(Request $request, VendorDetails $vendor)
    {
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2030',
        ]);

        $rental = Rented::with(['stall.section.area'])
                       ->where('vendor_id', $vendor->id)
                       ->where('status', 'active')
                       ->first();

        if (!$rental) {
            return response()->json(['message' => 'No active rental found for this vendor'], 404);
        }

        $payments = Payments::where('vendor_id', $vendor->id)
                           ->whereMonth('payment_date', $validated['month'])
                           ->whereYear('payment_date', $validated['year'])
                           ->orderBy('payment_date')
                           ->get();

        $totalPaid = $payments->sum('amount');
        $missedDays = 0;
        $advanceBalance = 0;

        foreach ($payments as $payment) {
            if ($payment->payment_type === 'advance') {
                $advanceBalance += $payment->advance_days;
            }
        }

        $daysInMonth = Carbon::create($validated['year'], $validated['month'])->daysInMonth;
        $expectedDays = $daysInMonth;
        $paidDays = $payments->count();
        $missedDays = max(0, $expectedDays - $paidDays - $advanceBalance);

        return response()->json([
            'vendor' => $vendor,
            'rental' => $rental,
            'payments' => $payments,
            'summary' => [
                'total_paid' => $totalPaid,
                'expected_amount' => $rental->monthly_rate,
                'outstanding_balance' => max(0, $rental->monthly_rate - $totalPaid),
                'paid_days' => $paidDays,
                'missed_days' => $missedDays,
                'advance_balance' => $advanceBalance,
                'days_in_month' => $daysInMonth,
            ],
        ]);
    }

    public function getMissedDaysReport(Request $request)
    {
        $validated = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2030',
        ]);

        $vendors = VendorDetails::with([
            'rentals.stall.section.area',
            'rentals.payments' => function($q) use ($validated) {
                $q->whereMonth('payment_date', $validated['month'])
                  ->whereYear('payment_date', $validated['year']);
            }
        ])
        ->whereHas('rentals', function($q) {
            $q->where('status', 'active');
        })
        ->get();

        $missedDaysReport = $vendors->map(function($vendor) use ($validated) {
            $rental = $vendor->rentals->where('status', 'active')->first();
            if (!$rental) return null;

            $payments = $rental->payments ?? collect();
            $daysInMonth = Carbon::create($validated['year'], $validated['month'])->daysInMonth;
            $paidDays = $payments->count();
            $missedDays = max(0, $daysInMonth - $paidDays);

            if ($missedDays > 0) {
                return [
                    'vendor' => $vendor,
                    'rental' => $rental,
                    'missed_days' => $missedDays,
                    'total_paid' => $payments->sum('amount'),
                    'outstanding_balance' => max(0, $rental->monthly_rate - $payments->sum('amount')),
                ];
            }

            return null;
        })->filter();

        return response()->json($missedDaysReport->values());
    }
}
