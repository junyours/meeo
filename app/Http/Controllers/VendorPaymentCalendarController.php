<?php

namespace App\Http\Controllers;

use App\Models\VendorDetails;
use App\Models\Payments;
use App\Models\Rented;
use Illuminate\Http\Request;
use Carbon\Carbon;

class VendorPaymentCalendarController extends Controller
{
    /**
     * Get all vendors with their payment calendar for a specific month
     */
    public function index(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $month = $request->input('month');
        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate = Carbon::parse($month . '-01')->endOfMonth();
        
        // Get all vendors with their rentals (more permissive filtering)
        $vendors = VendorDetails::with(['rented' => function($query) use ($startDate, $endDate) {
            // More permissive rental filtering - get all rentals that might have payments
            $query->where(function($q) use ($startDate, $endDate) {
                $q->where('created_at', '<=', $endDate)
                  ->orWhereHas('payments', function($paymentQuery) use ($startDate, $endDate) {
                      $paymentQuery->whereBetween('payment_date', [$startDate, $endDate]);
                  });
            });
        }, 'rented.stall', 'rented.payments' => function($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        }])
        ->where('status', 'active')
        ->orderBy('last_name')
        ->get();

        $vendorCalendar = [];
        $daysInMonth = $startDate->daysInMonth;

        foreach ($vendors as $vendor) {
            $paymentsByDay = [];
            $totalMonthlyAmount = 0;
            $missedDays = [];
            $expectedPaymentDays = [];
            $advanceCoverageDays = [];
            $todayUnpaidDays = [];

            // Initialize all days with null (no payment)
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $paymentsByDay[$day] = null;
            }

            // Process payments first to track advance coverage
            foreach ($vendor->rented as $rental) {
                foreach ($rental->payments as $payment) {
                    $paymentDate = Carbon::parse($payment->payment_date);
                    $paymentDay = $paymentDate->day;
                    
                    // Track advance payments and their coverage
                    if ($payment->payment_type === 'advance' && isset($payment->advance_days) && $payment->advance_days > 0) {
                        $coverageStartDay = $paymentDay + 1; // Day after payment
                        $coverageEndDay = min($paymentDay + $payment->advance_days, $daysInMonth);
                        $coverageEndDate = $startDate->copy()->addDays($coverageEndDay - 1);
                        
                        for ($day = $coverageStartDay; $day <= $coverageEndDay; $day++) {
                            if (!isset($advanceCoverageDays[$day])) {
                                $advanceCoverageDays[$day] = [];
                            }
                            $advanceCoverageDays[$day][] = [
                                'rental_id' => $rental->id,
                                'stall_number' => $rental->stall->stall_number ?? 'N/A',
                                'payment_date' => $payment->payment_date,
                                'advance_days' => $payment->advance_days,
                                'coverage_from' => $coverageStartDay,
                                'coverage_to' => $coverageEndDay,
                                'coverage_end_date' => $coverageEndDate->toDateString(),
                                'next_due_date' => $coverageEndDate->addDay()->toDateString(),
                            ];
                        }
                    }
                }
            }

            // Determine expected payment days for each rental
            foreach ($vendor->rented as $rental) {
                $rentalStartDate = Carbon::parse($rental->created_at);
                
                // For daily payment rentals, expect payment every day from rental start
                if (in_array($rental->status, ['active', 'occupied', 'daily'])) {
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $currentDate = $startDate->copy()->addDays($day - 1);
                        if ($currentDate->gte($rentalStartDate)) {
                            // Skip if this day is covered by advance payment
                            if (!isset($advanceCoverageDays[$day])) {
                                if (!isset($expectedPaymentDays[$day])) {
                                    $expectedPaymentDays[$day] = [];
                                }
                                $expectedPaymentDays[$day][] = [
                                    'rental_id' => $rental->id,
                                    'stall_number' => $rental->stall->stall_number ?? 'N/A',
                                    'daily_rent' => (float) ($rental->daily_rent ?? 0),
                                    'monthly_rent' => (float) ($rental->monthly_rent ?? 0),
                                    'status' => $rental->status,
                                ];
                            }
                        }
                    }
                }
                // For monthly payment rentals, expect payment on the 1st of each month
                elseif (in_array($rental->status, ['advance', 'partial', 'fully_paid'])) {
                    $day = 1; // First day of month
                    if ($startDate->copy()->addDays($day - 1)->gte($rentalStartDate)) {
                        // Skip if this day is covered by advance payment
                        if (!isset($advanceCoverageDays[$day])) {
                            if (!isset($expectedPaymentDays[$day])) {
                                $expectedPaymentDays[$day] = [];
                            }
                            $expectedPaymentDays[$day][] = [
                                'rental_id' => $rental->id,
                                'stall_number' => $rental->stall->stall_number ?? 'N/A',
                                'daily_rent' => (float) ($rental->daily_rent ?? 0),
                                'monthly_rent' => (float) ($rental->monthly_rent ?? 0),
                                'status' => $rental->status,
                            ];
                        }
                    }
                }
            }

            // Process payments for each rental
            foreach ($vendor->rented as $rental) {
                foreach ($rental->payments as $payment) {
                    $day = Carbon::parse($payment->payment_date)->day;
                    
                    if (!isset($paymentsByDay[$day])) {
                        $paymentsByDay[$day] = [];
                    }
                    
                    $paymentsByDay[$day][] = [
                        'id' => $payment->id,
                        'amount' => (float) $payment->amount,
                        'payment_type' => $payment->payment_type,
                        'status' => $payment->status,
                        'stall_number' => $rental->stall->stall_number ?? 'N/A',
                        'daily_rent' => (float) ($rental->daily_rent ?? 0),
                        'monthly_rent' => (float) ($rental->monthly_rent ?? 0),
                        'missed_days' => $payment->missed_days ?? 0,
                        'advance_days' => $payment->advance_days ?? 0,
                        'payment_date' => $payment->payment_date,
                    ];
                    
                    $totalMonthlyAmount += (float) $payment->amount;
                }
            }

            // Identify missed days
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $currentDate = $startDate->copy()->addDays($day - 1);
                
                // Check if this day was expected to have payment but didn't
                if (isset($expectedPaymentDays[$day]) && (!isset($paymentsByDay[$day]) || empty($paymentsByDay[$day]))) {
                    // Only consider as missed if it's a past day (not today)
                    if ($currentDate->lt(Carbon::today())) {
                        $missedDays[$day] = [
                            'date' => $currentDate->toDateString(),
                            'day_of_week' => $currentDate->format('l'),
                            'expected_payments' => $expectedPaymentDays[$day],
                            'total_expected_amount' => array_sum(array_column($expectedPaymentDays[$day], 'daily_rent')) + 
                                                      array_sum(array_column($expectedPaymentDays[$day], 'monthly_rent')),
                            'stalls_count' => count($expectedPaymentDays[$day]),
                            'covered_by_advance' => false,
                        ];
                    }
                    // If it's today, mark as today's unpaid day
                    elseif ($currentDate->isToday()) {
                        $todayUnpaidDays[$day] = [
                            'date' => $currentDate->toDateString(),
                            'day_of_week' => $currentDate->format('l'),
                            'expected_payments' => $expectedPaymentDays[$day],
                            'total_expected_amount' => array_sum(array_column($expectedPaymentDays[$day], 'daily_rent')) + 
                                                      array_sum(array_column($expectedPaymentDays[$day], 'monthly_rent')),
                            'stalls_count' => count($expectedPaymentDays[$day]),
                            'covered_by_advance' => false,
                        ];
                    }
                }
            }

            // Add advance coverage information to the vendor data
            $advanceCoverageInfo = [];
            foreach ($advanceCoverageDays as $day => $coverages) {
                $advanceCoverageInfo[$day] = [
                    'covered' => true,
                    'coverages' => $coverages,
                    'total_covered_amount' => array_sum(array_map(function($coverage) use ($vendor) {
                        $rental = $vendor->rented->firstWhere('id', $coverage['rental_id']);
                        return $rental ? ($rental->daily_rent ?? $rental->monthly_rent ?? 0) : 0;
                    }, $coverages))
                ];
            }

            $vendorCalendar[] = [
                'vendor' => [
                    'id' => $vendor->id,
                    'fullname' => trim($vendor->first_name . ' ' . $vendor->middle_name . ' ' . $vendor->last_name),
                    'first_name' => $vendor->first_name,
                    'last_name' => $vendor->last_name,
                    'contact_number' => $vendor->contact_number,
                    'email' => $vendor->email,
                ],
                'rentals' => $vendor->rented->map(function($rental) {
                    return [
                        'id' => $rental->id,
                        'stall_number' => $rental->stall->stall_number ?? 'N/A',
                        'daily_rent' => (float) ($rental->daily_rent ?? 0),
                        'monthly_rent' => (float) ($rental->monthly_rent ?? 0),
                        'status' => $rental->status,
                        'missed_days' => $rental->missed_days ?? 0,
                        'remaining_balance' => (float) ($rental->remaining_balance ?? 0),
                    ];
                }),
                'payments_by_day' => $paymentsByDay,
                'missed_days' => $missedDays,
                'today_unpaid_days' => $todayUnpaidDays,
                'advance_coverage' => $advanceCoverageInfo,
                'total_monthly_amount' => $totalMonthlyAmount,
                'payment_days_count' => count(array_filter($paymentsByDay, fn($day) => $day !== null)),
                'missed_days_count' => count($missedDays),
                'total_missed_amount' => array_sum(array_column($missedDays, 'total_expected_amount')),
                'advance_covered_days' => count($advanceCoverageInfo),
                'total_advance_covered_amount' => array_sum(array_column($advanceCoverageInfo, 'total_covered_amount')),
            ];
        }

        return response()->json([
            'month' => $month,
            'days_in_month' => $daysInMonth,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'vendors' => $vendorCalendar,
            'summary' => [
                'total_vendors' => count($vendorCalendar),
                'total_amount_collected' => array_sum(array_column($vendorCalendar, 'total_monthly_amount')),
                'total_payment_days' => array_sum(array_column($vendorCalendar, 'payment_days_count')),
                'total_missed_days' => array_sum(array_column($vendorCalendar, 'missed_days_count')),
                'total_missed_amount' => array_sum(array_column($vendorCalendar, 'total_missed_amount')),
                'total_advance_covered_days' => array_sum(array_column($vendorCalendar, 'advance_covered_days')),
                'total_advance_covered_amount' => array_sum(array_column($vendorCalendar, 'total_advance_covered_amount')),
            ]
        ]);
    }

    /**
     * Get payment details for a specific vendor and date
     */
    public function getVendorPaymentsByDate(Request $request, $vendorId)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $date = Carbon::parse($request->input('date'));
        $startDate = $date->copy()->startOfDay();
        $endDate = $date->copy()->endOfDay();

        $vendor = VendorDetails::with(['rented' => function($query) {
            $query->whereIn('status', ['active', 'occupied', 'advance', 'daily', 'partial', 'fully_paid', 'temp_closed']);
        }, 'rented.stall', 'rented.payments' => function($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        }])
        ->findOrFail($vendorId);

        $payments = [];
        foreach ($vendor->rented as $rental) {
            foreach ($rental->payments as $payment) {
                $payments[] = [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'payment_type' => $payment->payment_type,
                    'status' => $payment->status,
                    'stall_number' => $rental->stall->stall_number ?? 'N/A',
                    'daily_rent' => (float) ($rental->daily_rent ?? 0),
                    'monthly_rent' => (float) ($rental->monthly_rent ?? 0),
                    'missed_days' => $payment->missed_days ?? 0,
                    'advance_days' => $payment->advance_days ?? 0,
                    'payment_date' => $payment->payment_date,
                    'created_at' => $payment->created_at,
                ];
            }
        }

        return response()->json([
            'vendor' => [
                'id' => $vendor->id,
                'fullname' => trim($vendor->first_name . ' ' . $vendor->middle_name . ' ' . $vendor->last_name),
                'contact_number' => $vendor->contact_number,
                'email' => $vendor->email,
            ],
            'date' => $request->input('date'),
            'payments' => $payments,
            'total_amount' => array_sum(array_column($payments, 'amount')),
        ]);
    }

    /**
     * Get monthly payment statistics
     */
    public function getMonthlyStats(Request $request)
    {
        $request->validate([
            'month' => 'required|date_format:Y-m',
        ]);

        $month = $request->input('month');
        $startDate = Carbon::parse($month . '-01')->startOfMonth();
        $endDate = Carbon::parse($month . '-01')->endOfMonth();

        $stats = Payments::whereBetween('payment_date', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_payments,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                COUNT(DISTINCT vendor_id) as unique_vendors,
                COUNT(CASE WHEN payment_type = "daily" THEN 1 END) as daily_payments,
                COUNT(CASE WHEN payment_type = "advance" THEN 1 END) as advance_payments,
                COUNT(CASE WHEN payment_type = "partial" THEN 1 END) as partial_payments,
                COUNT(CASE WHEN payment_type = "fully paid" THEN 1 END) as fully_paid_payments
            ')
            ->first();

        return response()->json([
            'month' => $month,
            'stats' => [
                'total_payments' => (int) $stats->total_payments,
                'total_amount' => (float) $stats->total_amount,
                'average_amount' => (float) $stats->average_amount,
                'unique_vendors' => (int) $stats->unique_vendors,
                'payment_types' => [
                    'daily' => (int) $stats->daily_payments,
                    'advance' => (int) $stats->advance_payments,
                    'partial' => (int) $stats->partial_payments,
                    'fully_paid' => (int) $stats->fully_paid_payments,
                ]
            ]
        ]);
    }
}
