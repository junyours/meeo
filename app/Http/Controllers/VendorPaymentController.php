<?php

namespace App\Http\Controllers;

use App\Models\VendorDetails;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Sections;
use App\Models\Payments;
use App\Models\Notification;
use Illuminate\Http\Request;
use Carbon\Carbon;

class VendorPaymentController extends Controller
{
    /**
     * Get all vendors with their rented stalls and payment information
     */
public function index()
{
    $today = now();

    $vendors = VendorDetails::with([
            'rented.stall.section',
            'rented.payments'
        ])
        ->where('status', 'active')
        ->orderBy('first_name')
        ->get();

    $data = $vendors->map(function ($vendor) use ($today) {

        $rentals = $vendor->rented->filter(function ($rental) {
            return $rental->stall && $rental->stall->status !== 'vacant';
        });

        $mappedRentals = $rentals->map(function ($rental) use ($today) {

            $dailyRent = (float) ($rental->daily_rent ?? 0);
            $dbMissedDays = (int) ($rental->missed_days ?? 0);

            // ✅ Compute missed days: DB value first, else calculate from next_due_date
            if ($dbMissedDays > 0) {
                $missedDays = $dbMissedDays;
            } elseif ($rental->next_due_date) {
                $nextDueDate = Carbon::parse($rental->next_due_date);
                $missedDays = $today->gt($nextDueDate) ? $today->diffInDays($nextDueDate) : 0;
            } else {
                $missedDays = 0;
            }

            // ✅ Check if paid today
            $paidToday = $rental->payments
                ->whereBetween('payment_date', [
                    $today->copy()->startOfDay(),
                    $today->copy()->endOfDay()
                ])
                ->isNotEmpty();

            // ✅ Remaining balance
            $remainingBalance = ($rental->remaining_balance !== null && $rental->remaining_balance > 0)
                ? (float) $rental->remaining_balance
                : ($missedDays * $dailyRent);

            // ✅ Calculate monthly balances for current year
            $monthlyBalances = $this->calculateMonthlyBalancesForRental($rental, $today->year);

            return [
                'rental_id' => $rental->id,
                'stall_number' => $rental->stall->stall_number,
                'section_name' => $rental->stall->section->name ?? 'N/A',
                'daily_rent' => $dailyRent,
                'monthly_rent' => $rental->monthly_rent,
                'status' => $rental->status,
                'missed_days' => $missedDays,
                'remaining_balance' => $remainingBalance,
                'paid_today' => $paidToday,
                'last_payment_date' => $rental->last_payment_date,
                'next_due_date' => $rental->next_due_date,
                'monthly_balances' => $monthlyBalances,
            ];
        });

        // ✅ Calculate monthly balances for all rentals
        $vendorMonthlyBalances = $this->calculateVendorMonthlyBalances($mappedRentals, $today->year);

        return [
            'id' => $vendor->id,
            'name' => $vendor->first_name . ' ' . $vendor->last_name,
            'contact_number' => $vendor->contact_number,
            'email' => $vendor->email,
            'total_stalls' => $mappedRentals->count(),
            'rentals' => $mappedRentals,
            'total_remaining_balance' => $mappedRentals->sum('remaining_balance'),
            'total_missed_days' => $mappedRentals->sum('missed_days'),
            'paid_today_count' => $mappedRentals->where('paid_today', true)->count(),
            'monthly_balances' => $vendorMonthlyBalances,
        ];
    });

    return response()->json([
        'success' => true,
        'data' => $data,
    ]);
}




    /**
     * Get payment history for a specific vendor
     */
    public function getPaymentHistory($vendorId)
    {
        $vendor = VendorDetails::with(['rented.stall.section', 'rented.payments'])
            ->findOrFail($vendorId);

        $payments = $vendor->rented->flatMap(function ($rental) {
            return $rental->payments->map(function ($payment) use ($rental) {
                return [
                    'id' => $payment->id,
                    'payment_date' => $payment->payment_date,
                    'amount' => $payment->amount,
                    'payment_type' => $payment->payment_type,
                    'missed_days' => $payment->missed_days,
                    'advance_days' => $payment->advance_days,
                    'status' => $payment->status,
                    'stall_number' => $rental->stall->stall_number,
                    'section_name' => $rental->stall->section->name ?? 'N/A',
                    'daily_rent' => $rental->daily_rent,
                ];
            });
        })->sortByDesc('payment_date')->values();

        return response()->json([
            'success' => true,
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->first_name . ' ' . $vendor->last_name,
                'contact_number' => $vendor->contact_number,
                'email' => $vendor->email,
            ],
            'payments' => $payments,
        ]);
    }

    /**
     * Bulk payment processing for vendor
     */
    public function bulkPayment(Request $request, $vendorId)
    {
        $vendor = VendorDetails::findOrFail($vendorId);
        
        $request->validate([
            'rental_ids' => 'required|array',
            'rental_ids.*' => 'exists:rented,id',
            'amounts' => 'required|array',
            'amounts.*' => 'required|numeric|min:1',
            'payment_types' => 'required|array',
            'payment_types.*' => 'required|in:partial,fully paid,advance,daily',
            'advance_days' => 'nullable|array',
            'advance_days.*' => 'nullable|integer|min:0',
            'or_number' => 'required|string|max:50|unique:payments,or_number',
            'payment_date' => 'required|date|before_or_equal:today',
        ]);

        $rentalIds = $request->input('rental_ids');
        $amounts = $request->input('amounts');
        $paymentTypes = $request->input('payment_types');
        $advanceDays = $request->input('advance_days', []); // Get frontend advance days
        $paymentDate = Carbon::parse($request->input('payment_date'));
        $now = now();
        $results = [];

        foreach ($rentalIds as $index => $rentalId) {
            $amount = $amounts[$index];
            $paymentType = $paymentTypes[$index];
            $frontendAdvanceDays = isset($advanceDays[$index]) ? $advanceDays[$index] : null;
            
            $rental = Rented::with(['vendor', 'stall', 'payments'])->findOrFail($rentalId);
            
            if ($rental->vendor_id !== $vendor->id) {
                $results[] = [
                    'rental_id' => $rentalId,
                    'success' => false,
                    'message' => 'This rental does not belong to the specified vendor.',
                ];
                continue;
            }

            // Check if stall has active advance payment
            if ($rental->status === 'advance' && $rental->next_due_date) {
                $nextDueDate = Carbon::parse($rental->next_due_date);
                
                // If next due date is after today, advance is still active
                if ($nextDueDate->gt($now)) {
                    $results[] = [
                        'rental_id' => $rentalId,
                        'success' => false,
                        'message' => "This stall has an active advance payment and cannot be paid until {$nextDueDate->toDateString()}. Next due date: {$nextDueDate->toDateString()}.",
                    ];
                    continue;
                }
            }

            // Process payment using frontend advance days if provided
            $result = $this->processPaymentForRental($rental, $amount, $paymentType, $paymentDate, $frontendAdvanceDays, $request->input('or_number'));
            $results[] = $result;
        }

        // Send notification to vendor
        $successfulPayments = collect($results)->where('success', true);
        if ($successfulPayments->isNotEmpty()) {
            $totalAmount = $successfulPayments->sum('amount');
            $stallCount = $successfulPayments->count();
            
            Notification::create([
                'vendor_id' => $vendor->id,
                'title' => 'Bulk Payment Processed',
                'message' => "Your bulk payment for {$stallCount} stall(s) totaling ₱" . 
                            number_format($totalAmount, 2) . " with OR #{$request->input('or_number')} has been processed successfully.",
                'is_read' => 0,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Bulk payment processing completed.',
            'results' => $results,
        ]);
    }

    /**
     * Get market collection report (daily or monthly)
     */
    public function getMarketCollectionReport(Request $request)
    {
        $request->validate([
            'type' => 'required|in:daily,monthly',
            'date' => 'nullable|date',
            'month' => 'nullable|date_format:Y-m',
            'year' => 'nullable|integer|min:2020|max:2030',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $type = $request->input('type');
        $date = $request->input('date');
        $month = $request->input('month');
        $year = $request->input('year');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        if ($type === 'daily') {
            // If date range is provided, get all payments for that range
            if ($startDate && $endDate) {
                return $this->getDailyReportByDateRange($startDate, $endDate);
            }
            // If month is provided for daily type, get all payments for that month
            elseif ($month) {
                return $this->getDailyReportByMonth($month);
            } else {
                return $this->getDailyReport($date);
            }
        } else {
            // For monthly type, if year is provided, get yearly summary
            if ($year) {
                return $this->getYearlyReport($year);
            } else {
                return $this->getMonthlyReport($month ?: date('Y-m'));
            }
        }
    }

    /**
     * Generate daily collection report by date range
     */
    private function getDailyReportByDateRange($startDate, $endDate)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        
        $payments = Payments::with([
                'rented.stall.section',
                'vendor'
            ])
            ->whereBetween('payment_date', [$start, $end])
            ->where('status', 'collected')
            ->orderBy('payment_date', 'desc')
            ->get();

        $reportData = $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'vendor_name' => $payment->vendor->first_name . ' ' . $payment->vendor->last_name,
                'vendor_contact' => $payment->vendor->contact_number,
                'stall_number' => $payment->rented->stall->stall_number,
                'section_name' => $payment->rented->stall->section->name ?? 'N/A',
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
                'missed_days' => $payment->missed_days,
                'advance_days' => $payment->advance_days,
                'daily_rent' => $payment->rented->daily_rent,
                'monthly_rent' => $payment->rented->monthly_rent,
            ];
        });

        $summary = [
            'total_collections' => $payments->sum('amount'),
            'total_transactions' => $payments->count(),
            'daily_payments' => $payments->where('payment_type', 'daily')->count(),
            'partial_payments' => $payments->where('payment_type', 'partial')->count(),
            'fully_paid_payments' => $payments->where('payment_type', 'fully paid')->count(),
            'advance_payments' => $payments->where('payment_type', 'advance')->count(),
            'total_missed_days_covered' => $payments->sum('missed_days'),
            'total_advance_days' => $payments->sum('advance_days'),
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days_covered' => $start->diffInDays($end) + 1
            ],
        ];

        return response()->json([
            'success' => true,
            'type' => 'daily_range',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'data' => $reportData,
            'summary' => $summary,
        ]);
    }

    /**
     * Generate yearly collection report (monthly totals from Jan to Dec)
     */
    private function getYearlyReport($year)
    {
        $startDate = Carbon::parse($year . '-01-01')->startOfYear();
        $endDate = Carbon::parse($year . '-12-31')->endOfYear();
        
        $payments = Payments::with([
                'rented.stall.section',
                'vendor'
            ])
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', 'collected')
            ->orderBy('payment_date', 'desc')
            ->get();

        // Group by month and calculate totals for each month
        $monthlyTotals = [];
        $monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                       'July', 'August', 'September', 'October', 'November', 'December'];
        
        for ($i = 1; $i <= 12; $i++) {
            $monthDate = Carbon::create($year, $i, 1);
            $monthStart = $monthDate->copy()->startOfMonth();
            $monthEnd = $monthDate->copy()->endOfMonth();
            
            $monthPayments = $payments->filter(function($payment) use ($monthStart, $monthEnd) {
                $paymentDate = Carbon::parse($payment->payment_date);
                return $paymentDate->between($monthStart, $monthEnd);
            });
            
            $monthlyTotals[] = [
                'month' => $monthNames[$i - 1],
                'month_number' => $i,
                'total_collections' => $monthPayments->sum('amount'),
                'total_transactions' => $monthPayments->count(),
                'payment_types' => [
                    'daily' => $monthPayments->where('payment_type', 'daily')->count(),
                    'partial' => $monthPayments->where('payment_type', 'partial')->count(),
                    'fully_paid' => $monthPayments->where('payment_type', 'fully paid')->count(),
                    'advance' => $monthPayments->where('payment_type', 'advance')->count(),
                ],
                'total_missed_days' => $monthPayments->sum('missed_days'),
                'total_advance_days' => $monthPayments->sum('advance_days'),
            ];
        }

        // Get all payment details for the year
        $reportData = $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'vendor_name' => $payment->vendor->first_name . ' ' . $payment->vendor->last_name,
                'vendor_contact' => $payment->vendor->contact_number,
                'stall_number' => $payment->rented->stall->stall_number,
                'section_name' => $payment->rented->stall->section->name ?? 'N/A',
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
                'missed_days' => $payment->missed_days,
                'advance_days' => $payment->advance_days,
                'daily_rent' => $payment->rented->daily_rent,
                'monthly_rent' => $payment->rented->monthly_rent,
            ];
        });

        $summary = [
            'total_collections' => $payments->sum('amount'),
            'total_transactions' => $payments->count(),
            'daily_payments' => $payments->where('payment_type', 'daily')->count(),
            'partial_payments' => $payments->where('payment_type', 'partial')->count(),
            'fully_paid_payments' => $payments->where('payment_type', 'fully paid')->count(),
            'advance_payments' => $payments->where('payment_type', 'advance')->count(),
            'total_missed_days_covered' => $payments->sum('missed_days'),
            'total_advance_days' => $payments->sum('advance_days'),
            'average_monthly_collection' => $payments->sum('amount') / 12,
            'highest_month' => collect($monthlyTotals)->max('total_collections'),
            'lowest_month' => collect($monthlyTotals)->min('total_collections'),
        ];

        return response()->json([
            'success' => true,
            'type' => 'yearly',
            'year' => $year,
            'monthly_totals' => $monthlyTotals,
            'data' => $reportData,
            'summary' => $summary,
        ]);
    }

    /**
     * Generate daily collection report by month
     */
    private function getDailyReportByMonth($month)
    {
        $monthDate = Carbon::parse($month . '-01');
        $startDate = $monthDate->copy()->startOfMonth();
        $endDate = $monthDate->copy()->endOfMonth();
        
        $payments = Payments::with([
                'rented.stall.section',
                'vendor'
            ])
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', 'collected')
            ->orderBy('payment_date', 'desc')
            ->get();

        $reportData = $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'vendor_name' => $payment->vendor->first_name . ' ' . $payment->vendor->last_name,
                'vendor_contact' => $payment->vendor->contact_number,
                'stall_number' => $payment->rented->stall->stall_number,
                'section_name' => $payment->rented->stall->section->name ?? 'N/A',
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
                'missed_days' => $payment->missed_days,
                'advance_days' => $payment->advance_days,
                'daily_rent' => $payment->rented->daily_rent,
                'monthly_rent' => $payment->rented->monthly_rent,
            ];
        });

        $summary = [
            'total_collections' => $payments->sum('amount'),
            'total_transactions' => $payments->count(),
            'daily_payments' => $payments->where('payment_type', 'daily')->count(),
            'partial_payments' => $payments->where('payment_type', 'partial')->count(),
            'fully_paid_payments' => $payments->where('payment_type', 'fully paid')->count(),
            'advance_payments' => $payments->where('payment_type', 'advance')->count(),
            'total_missed_days_covered' => $payments->sum('missed_days'),
            'total_advance_days' => $payments->sum('advance_days'),
        ];

        return response()->json([
            'success' => true,
            'type' => 'daily',
            'month' => $month,
            'data' => $reportData,
            'summary' => $summary,
        ]);
    }

    /**
     * Generate daily collection report
     */
    private function getDailyReport($date)
    {
        $reportDate = Carbon::parse($date);
        
        $payments = Payments::with([
                'rented.stall.section',
                'vendor'
            ])
            ->whereDate('payment_date', $reportDate)
            ->where('status', 'collected')
            ->orderBy('payment_date', 'desc')
            ->get();

        $reportData = $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'vendor_name' => $payment->vendor->first_name . ' ' . $payment->vendor->last_name,
                'vendor_contact' => $payment->vendor->contact_number,
                'stall_number' => $payment->rented->stall->stall_number,
                'section_name' => $payment->rented->stall->section->name ?? 'N/A',
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
                'missed_days' => $payment->missed_days,
                'advance_days' => $payment->advance_days,
                'daily_rent' => $payment->rented->daily_rent,
                'monthly_rent' => $payment->rented->monthly_rent,
            ];
        });

        $summary = [
            'total_collections' => $payments->sum('amount'),
            'total_transactions' => $payments->count(),
            'daily_payments' => $payments->where('payment_type', 'daily')->count(),
            'partial_payments' => $payments->where('payment_type', 'partial')->count(),
            'fully_paid_payments' => $payments->where('payment_type', 'fully paid')->count(),
            'advance_payments' => $payments->where('payment_type', 'advance')->count(),
            'total_missed_days_covered' => $payments->sum('missed_days'),
            'total_advance_days' => $payments->sum('advance_days'),
        ];

        return response()->json([
            'success' => true,
            'type' => 'daily',
            'date' => $date,
            'data' => $reportData,
            'summary' => $summary,
        ]);
    }

    /**
     * Generate monthly collection report
     */
    private function getMonthlyReport($month)
    {
        $monthDate = Carbon::parse($month . '-01');
        $startDate = $monthDate->copy()->startOfMonth();
        $endDate = $monthDate->copy()->endOfMonth();

        $payments = Payments::with([
                'rented.stall.section',
                'vendor'
            ])
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->where('status', 'collected')
            ->orderBy('payment_date', 'desc')
            ->get();

        $reportData = $payments->map(function ($payment) {
            return [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'vendor_name' => $payment->vendor->first_name . ' ' . $payment->vendor->last_name,
                'vendor_contact' => $payment->vendor->contact_number,
                'stall_number' => $payment->rented->stall->stall_number,
                'section_name' => $payment->rented->stall->section->name ?? 'N/A',
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
                'missed_days' => $payment->missed_days,
                'advance_days' => $payment->advance_days,
                'daily_rent' => $payment->rented->daily_rent,
                'monthly_rent' => $payment->rented->monthly_rent,
            ];
        });

        // Group by day for daily breakdown in monthly report
        $dailyBreakdown = $payments->groupBy(function($payment) {
            return Carbon::parse($payment->payment_date)->format('Y-m-d');
        })->map(function($dayPayments, $date) {
            return [
                'date' => $date,
                'total_collections' => $dayPayments->sum('amount'),
                'total_transactions' => $dayPayments->count(),
                'payment_types' => [
                    'daily' => $dayPayments->where('payment_type', 'daily')->count(),
                    'partial' => $dayPayments->where('payment_type', 'partial')->count(),
                    'fully_paid' => $dayPayments->where('payment_type', 'fully paid')->count(),
                    'advance' => $dayPayments->where('payment_type', 'advance')->count(),
                ]
            ];
        })->sortBy('date')->values();

        $summary = [
            'total_collections' => $payments->sum('amount'),
            'total_transactions' => $payments->count(),
            'daily_payments' => $payments->where('payment_type', 'daily')->count(),
            'partial_payments' => $payments->where('payment_type', 'partial')->count(),
            'fully_paid_payments' => $payments->where('payment_type', 'fully paid')->count(),
            'advance_payments' => $payments->where('payment_type', 'advance')->count(),
            'total_missed_days_covered' => $payments->sum('missed_days'),
            'total_advance_days' => $payments->sum('advance_days'),
            'average_daily_collection' => $payments->sum('amount') / $monthDate->daysInMonth,
        ];

        return response()->json([
            'success' => true,
            'type' => 'monthly',
            'month' => $month,
            'data' => $reportData,
            'daily_breakdown' => $dailyBreakdown,
            'summary' => $summary,
        ]);
    }

    /**
     * Process payment for a single rental (extracted from payMissedForRented)
     */
    private function processPaymentForRental($rental, $amount, $paymentType, $paymentDate, $frontendAdvanceDays = null, $orNumber = null)
    {
        $missedDays = (int) ($rental->missed_days ?? 0);
        $dailyRent = (float) ($rental->daily_rent ?? 0);

        if ($dailyRent <= 0) {
            return [
                'rental_id' => $rental->id,
                'success' => false,
                'message' => 'Invalid daily rent for this rental.',
            ];
        }

        $totalMissedAmount = $missedDays * $dailyRent;
        $effectiveRemaining = $rental->remaining_balance ?? $totalMissedAmount;

        $advanceDays = 0;
        $advanceUntil = null;
        $missedDaysPaid = 0;
        $missedDaysAfter = $missedDays;
        $reopened = false;

        if ($paymentType === 'daily') {
            // Daily payment - just record payment for today, don't deduct missed days
            $missedDaysPaid = 0;
            $missedDaysAfter = $missedDays; // Keep missed days unchanged
            $rental->last_payment_date = $paymentDate;
            $rental->next_due_date = $paymentDate->copy()->addDay()->toDateString();
            $rental->status = 'occupied';
        } elseif ($amount < $effectiveRemaining) {
            // Partial payment
            $daysPaid = (int) floor($amount / $dailyRent);
            if ($daysPaid <= 0) {
                return [
                    'rental_id' => $rental->id,
                    'success' => false,
                    'message' => 'Payment amount is too small to cover even one full missed day.',
                ];
            }

            $daysPaid = min($daysPaid, $missedDays);
            $missedDaysPaid = $daysPaid;
            $missedDaysAfter = $missedDays - $daysPaid;
            $rental->status = 'partial';
        } elseif ($paymentType === 'fully paid') {
            // Fully paid - covers all missed days + today (if not already paid)
            $missedDaysPaid = $missedDays;
            $missedDaysAfter = 0;
            $reopened = true;
            $rental->status = 'fully paid';
            $rental->next_due_date = $paymentDate->copy()->addDay()->toDateString();
       } else {
    // Determine if today's rent is already covered
    $todayDue = 0;

    if (!$rental->last_payment_date || 
        $rental->last_payment_date->toDateString() !== $paymentDate->toDateString()) {
        $todayDue = $dailyRent;
    }

    $totalRequired = $effectiveRemaining + $todayDue;

    if ($amount < $totalRequired) {
        // Not enough to cover balance + today
        $daysPaid = (int) floor(($amount - $effectiveRemaining) / $dailyRent);
        $daysPaid = max(0, $daysPaid);
    }

    $missedDaysPaid = $missedDays;
    $missedDaysAfter = 0;

    // Use frontend advance days if provided, otherwise calculate
    if ($frontendAdvanceDays !== null && $paymentType === 'advance') {
        $advanceDays = (int) $frontendAdvanceDays;
    } else {
        $extraAmount = $amount - $totalRequired;
        if ($extraAmount > 0) {
            $extraDays = (int) floor($extraAmount / $dailyRent);
            if ($extraDays > 0) {
                $advanceDays = $extraDays;
            }
        }
    }

    if ($advanceDays > 0) {
        $paymentType = 'advance';
        $advanceUntil = $paymentDate->copy()->addDays($advanceDays)->toDateString();
        $rental->status = 'advance';
        $rental->next_due_date = $advanceUntil;
    } else {
        $rental->status = 'fully paid';
        $rental->next_due_date = $paymentDate->copy()->addDay()->toDateString();
    }
}


        // Create payment record
        $payment = Payments::create([
            'rented_id' => $rental->id,
            'vendor_id' => $rental->vendor_id,
            'payment_type' => $paymentType,
            'amount' => $amount,
            'or_number' => $orNumber,
            'payment_date' => $paymentDate,
            'missed_days' => $missedDaysPaid,
            'advance_days' => $advanceDays,
            'status' => 'collected',
        ]);

        // Update rental
        $rental->missed_days = $missedDaysAfter;
        $rental->remaining_balance = $missedDaysAfter * $dailyRent;
        $rental->last_payment_date = $paymentDate;

        if ($missedDaysAfter === 0 && !$advanceDays) {
            $rental->next_due_date = $paymentDate->copy()->addDay()->toDateString();
        }

        $rental->save();

        // Update stall status if needed
        $stall = $rental->stall;
        if ($reopened && in_array($stall->status, ['missed', 'temp_closed'])) {
            $stall->status = 'occupied';
            $stall->save();
        }

        return [
            'rental_id' => $rental->id,
            'success' => true,
            'message' => 'Payment processed successfully.',
            'payment' => $payment,
            'amount' => $amount,
            'or_number' => $orNumber,
            'missed_days_paid' => $missedDaysPaid,
            'remaining_balance' => $rental->remaining_balance,
            'advance_days' => $advanceDays,
            'advance_until' => $advanceUntil,
        ];
    }

    /**
     * Calculate monthly balances for a specific rental
     */
    private function calculateMonthlyBalancesForRental($rental, $year)
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $monthlyBalances = [];
        
        // Check for leap year and adjust February days
        $isLeapYear = ($year % 4 == 0 && ($year % 100 != 0 || $year % 400 == 0));
        $daysInMonths = [31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        
        // Get payments for this rental grouped by month
        $paymentsByMonth = $rental->payments
            ->where('status', 'collected')
            ->groupBy(function ($payment) use ($year) {
                return \Carbon\Carbon::parse($payment->payment_date)->format('n');
            })
            ->map(function ($monthPayments) {
                return $monthPayments->sum('amount');
            });
        
        foreach ($months as $index => $month) {
            $targetMonth = $index + 1;
            
            // Calculate monthly rate based on daily rent * days in month
            $monthlyRate = $rental->daily_rent * $daysInMonths[$index];
            
            // Get payments for this month from the grouped collection
            $monthlyPayment = $paymentsByMonth->get($targetMonth, 0);
            
            // Calculate balance: monthly rate - payment
            $balance = max(0, $monthlyRate - $monthlyPayment);
            
            $monthlyBalances[] = [
                'month' => $month,
                'monthly_rate' => (float) number_format($monthlyRate, 2, '.', ''),
                'payment' => (float) number_format($monthlyPayment, 2, '.', ''),
                'balance' => (float) number_format($balance, 2, '.', ''),
            ];
        }
        
        return $monthlyBalances;
    }

    /**
     * Calculate aggregate monthly balances for all vendor rentals
     */
    private function calculateVendorMonthlyBalances($mappedRentals, $year)
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $vendorBalances = [];
        
        // Initialize array to hold totals
        $monthlyTotals = array_fill_keys($months, [
            'monthly_rate' => 0,
            'payment' => 0,
            'balance' => 0
        ]);
        
        // Sum up all rental data for each month
        foreach ($mappedRentals as $rental) {
            if (isset($rental['monthly_balances'])) {
                foreach ($rental['monthly_balances'] as $monthBalance) {
                    $month = $monthBalance['month'];
                    $monthlyTotals[$month]['monthly_rate'] += $monthBalance['monthly_rate'];
                    $monthlyTotals[$month]['payment'] += $monthBalance['payment'];
                    $monthlyTotals[$month]['balance'] += $monthBalance['balance'];
                }
            }
        }
        
        // Format the final balances
        foreach ($months as $month) {
            $vendorBalances[] = [
                'month' => $month,
                'monthly_rate' => (float) number_format($monthlyTotals[$month]['monthly_rate'], 2, '.', ''),
                'payment' => (float) number_format($monthlyTotals[$month]['payment'], 2, '.', ''),
                'balance' => (float) number_format($monthlyTotals[$month]['balance'], 2, '.', ''),
            ];
        }
        
        return $vendorBalances;
    }

    /**
     * Process payment for selected months
     */
    public function processSelectedMonthsPayment(Request $request, $vendorId)
    {
        $vendor = VendorDetails::findOrFail($vendorId);
        
        $request->validate([
            'selected_months' => 'required|array',
            'selected_months.*' => 'integer|min:0|max:11',
            'rental_ids' => 'required|array',
            'rental_ids.*' => 'exists:rented,id',
            'or_number' => 'required|string|max:50|unique:payments,or_number',
            'payment_date' => 'required|date|before_or_equal:today',
            'custom_amount' => 'nullable|numeric|min:0'
        ]);

        $selectedMonths = $request->input('selected_months');
        $rentalIds = $request->input('rental_ids');
        $orNumber = $request->input('or_number');
        $paymentDate = Carbon::parse($request->input('payment_date'));
        $customAmount = $request->input('custom_amount'); // Get custom amount
        $currentYear = $paymentDate->year;
        
        $results = [];

        foreach ($rentalIds as $index => $rentalId) {
            $rental = Rented::with(['vendor', 'stall', 'payments'])->findOrFail($rentalId);
            
            if ($rental->vendor_id !== $vendor->id) {
                $results[] = [
                    'rental_id' => $rentalId,
                    'success' => false,
                    'message' => 'This rental does not belong to the specified vendor.',
                ];
                continue;
            }

            // Calculate total balance for selected months
            $totalSelectedBalance = 0;
            foreach ($selectedMonths as $monthIndex) {
                $targetMonth = $monthIndex + 1;
                
                // Get payments for this specific month using collection filtering
                $monthlyPayment = $rental->payments
                    ->filter(function ($payment) use ($currentYear, $targetMonth) {
                        $paymentDate = \Carbon\Carbon::parse($payment->payment_date);
                        return $payment->status === 'collected' && 
                               $paymentDate->year == $currentYear && 
                               $paymentDate->month == $targetMonth;
                    })
                    ->sum('amount');
                
                // Calculate monthly rate (daily rent * days in month)
                $isLeapYear = ($currentYear % 4 == 0 && ($currentYear % 100 != 0 || $currentYear % 400 == 0));
                $daysInMonths = [31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                $monthlyRate = $rental->daily_rent * $daysInMonths[$monthIndex];
                
                // Add to total balance if there's an outstanding balance
                $balance = max(0, $monthlyRate - $monthlyPayment);
                $totalSelectedBalance += $balance;
            }

            // Use custom amount if provided, otherwise use calculated total balance
            $paymentAmount = $customAmount ? floatval($customAmount) : $totalSelectedBalance;

            if ($paymentAmount <= 0) {
                $results[] = [
                    'rental_id' => $rentalId,
                    'success' => false,
                    'message' => 'Payment amount must be greater than 0.',
                ];
                continue;
            }

            // Process the payment for selected months
            $result = $this->processPaymentForRental($rental, $paymentAmount, 'fully paid', $paymentDate, null, $orNumber);
            $result['selected_months'] = $selectedMonths;
            $result['total_balance_paid'] = $totalSelectedBalance;
            $result['custom_amount_used'] = $customAmount ? true : false;
            $results[] = $result;
        }

        // Send notification to vendor
        $successfulPayments = collect($results)->where('success', true);
        if ($successfulPayments->isNotEmpty()) {
            $totalAmount = $successfulPayments->sum('total_balance_paid');
            $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $selectedMonthNames = collect($selectedMonths)->map(function($monthIndex) use ($monthNames) {
                return $monthNames[$monthIndex] ?? '';
            })->filter()->join(', ');
            
            Notification::create([
                'vendor_id' => $vendor->id,
                'title' => 'Selected Months Payment Processed',
                'message' => "Your payment for months: {$selectedMonthNames} totaling ₱" . 
                            number_format($totalAmount, 2) . " with OR #{$orNumber} has been processed successfully.",
                'is_read' => 0,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Selected months payment processing completed.',
            'results' => $results,
            'selected_months' => $selectedMonths,
            'total_amount' => $successfulPayments->sum('total_balance_paid'),
        ]);
    }
}
