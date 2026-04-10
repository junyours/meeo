<?php

namespace App\Http\Controllers;

use App\Models\VendorDetails;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Sections;
use App\Models\Payments;
use App\Models\Notification;
use App\Services\StallRateHistoryService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class VendorPaymentController extends Controller
{
    protected $rateHistoryService;

    public function __construct(StallRateHistoryService $rateHistoryService)
    {
        $this->rateHistoryService = $rateHistoryService;
    }
    /**
     * Get all vendors with their rented stalls and payment information
     */
public function index()
{
    $today = now();
    $currentYear = $today->year;

    $vendors = VendorDetails::with([
            'rented.stall.section',
            'rented.payments'
        ])
        ->where('status', 'active')
        ->orderBy('first_name')
        ->get();

    // Collect all unique stall IDs for bulk historical rate fetching
    $allStallIds = $vendors->pluck('rented.stall.id')->filter()->unique()->values();
    
    // Fetch all historical rates for all stalls in bulk (OPTIMIZATION!)
    $allHistoricalRates = [];
    if ($allStallIds->isNotEmpty()) {
        foreach ($allStallIds as $stallId) {
            for ($month = 1; $month <= 12; $month++) {
                $allHistoricalRates[$stallId][$month] = [
                    'daily' => $this->rateHistoryService->getDailyRateForMonth($stallId, $currentYear, $month),
                    'monthly' => $this->rateHistoryService->getMonthlyRateForMonth($stallId, $currentYear, $month),
                    'annual' => $this->rateHistoryService->getAnnualRateForMonth($stallId, $currentYear, $month),
                ];
            }
        }
    }

    $data = $vendors->map(function ($vendor) use ($today, $currentYear, $allHistoricalRates) {

        $rentals = $vendor->rented->filter(function ($rental) {
            return $rental->stall && $rental->stall->status !== 'vacant' && $rental->status !== 'unoccupied';
        });

        $mappedRentals = $rentals->map(function ($rental) use ($today, $currentYear, $allHistoricalRates) {

            $dailyRent = (float) ($rental->daily_rent ?? 0);
            $monthlyRent = (float) ($rental->monthly_rent ?? 0);
            $dbMissedDays = (int) ($rental->missed_days ?? 0);
            
            // Check if stall is monthly
            $isMonthlyStall = $rental->stall && $rental->stall->is_monthly;

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

            // ✅ Remaining balance calculation
            $remainingBalance = ($rental->remaining_balance !== null && $rental->remaining_balance > 0)
                ? (float) $rental->remaining_balance
                : ($missedDays * $dailyRent);

            // ✅ Calculate monthly balances for current year
            $monthlyBalances = $this->calculateMonthlyBalancesForRentalOptimized($rental, $currentYear, $allHistoricalRates[$rental->stall->id] ?? []);

            return [
                'rental_id' => $rental->id,
                'stall_number' => $rental->stall->stall_number,
                'section_name' => $rental->stall->section->name ?? 'N/A',
                'daily_rent' => $isMonthlyStall ? 0 : $dailyRent, // Set daily rent to 0 for monthly stalls
                'monthly_rent' => $isMonthlyStall ? ($monthlyRent ?: $rental->stall->monthly_rate) : $monthlyRent,
                'status' => $rental->status,
                'missed_days' => $isMonthlyStall ? 0 : $missedDays, // Set missed days to 0 for monthly stalls
                'remaining_balance' => $isMonthlyStall ? 0 : $remainingBalance, // Set remaining balance to 0 for monthly stalls
                'paid_today' => $paidToday,
                'last_payment_date' => $rental->last_payment_date,
                'next_due_date' => $rental->next_due_date,
                'monthly_balances' => $monthlyBalances,
                'is_monthly' => $isMonthlyStall, // Add this flag for frontend
            ];
        });

        // ✅ Calculate monthly balances for all rentals
        $vendorMonthlyBalances = $this->calculateVendorMonthlyBalances($mappedRentals, $currentYear);

        return [
            'id' => $vendor->id,
            'name' => $vendor->first_name . ' ' . $vendor->last_name,
            'contact_number' => $vendor->contact_number,
            'email' => $vendor->email,
            'total_stalls' => $mappedRentals->count(),
            'rentals' => array_values($mappedRentals->toArray()),
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
            'or_number' => 'required|string|max:50',
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
        // Check if rental is unoccupied before processing payment
        if ($rental->status === 'unoccupied') {
            return [
                'rental_id' => $rental->id,
                'success' => false,
                'message' => 'Cannot process payment for unoccupied rental.',
            ];
        }
        
        // Check if stall is monthly
        $isMonthlyStall = $rental->stall && $rental->stall->is_monthly;
        
        $missedDays = (int) ($rental->missed_days ?? 0);
        $dailyRent = (float) ($rental->daily_rent ?? 0);
        $monthlyRent = (float) ($rental->monthly_rent ?: ($rental->stall->monthly_rate ?? 0));

        // For monthly stalls, daily rent should be 0 and we use monthly rent
        if ($isMonthlyStall) {
            $dailyRent = 0;
            $missedDays = 0; // Monthly stalls don't have missed days
        }

        if ($dailyRent <= 0 && !$isMonthlyStall) {
            return [
                'rental_id' => $rental->id,
                'success' => false,
                'message' => 'Invalid daily rent for this rental.',
            ];
        }

        if ($isMonthlyStall && $monthlyRent <= 0) {
            return [
                'rental_id' => $rental->id,
                'success' => false,
                'message' => 'Invalid monthly rent for this monthly stall.',
            ];
        }

        $totalMissedAmount = $missedDays * $dailyRent;
        $effectiveRemaining = $rental->remaining_balance ?? $totalMissedAmount;

        $advanceDays = 0;
        $advanceUntil = null;
        $missedDaysPaid = 0;
        $missedDaysAfter = $missedDays;
        $reopened = false;

        if ($isMonthlyStall) {
            // Monthly stall logic - always treat as monthly payment
            $paymentType = 'monthly';
            $rental->last_payment_date = $paymentDate;
            
            // Set next due date to same day next month
            $nextDueDate = $paymentDate->copy()->addMonth();
            $rental->next_due_date = $nextDueDate->toDateString();
            $rental->status = 'occupied';
            
            // For monthly stalls, there's no missed days concept
            $missedDaysPaid = 0;
            $missedDaysAfter = 0;
            $rental->missed_days = 0;
            $rental->remaining_balance = 0;
            
        } elseif ($paymentType === 'daily') {
            // Daily payment - just record payment for today, don't deduct missed days
            $missedDaysPaid = 0;
            $missedDaysAfter = $missedDays; // Keep missed days unchanged
            $rental->last_payment_date = $paymentDate;
            $rental->next_due_date = $paymentDate->copy()->addDay()->toDateString();
            $rental->status = 'occupied';
        } elseif ($paymentType === 'partial') {
            // Partial payment - allow any amount
            $daysPaid = (int) floor($amount / $dailyRent);
            if ($daysPaid < 0) {
                $daysPaid = 0;
            }

            $daysPaid = min($daysPaid, $missedDays);
            $missedDaysPaid = $daysPaid;
            $missedDaysAfter = $missedDays - $daysPaid;
            
            // Calculate remaining balance after partial payment
            $remainingBalance = max(0, $effectiveRemaining - $amount);
            $rental->remaining_balance = $remainingBalance;
            
            // If no missed days left to pay, set status to occupied
            if ($missedDaysAfter <= 0) {
                $rental->status = 'occupied';
                $rental->next_due_date = $paymentDate->copy()->addDay()->toDateString();
            } else {
                $rental->status = 'partial';
            }
            
            $rental->last_payment_date = $paymentDate;
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

        if ($missedDaysAfter === 0 && !$advanceDays && !$isMonthlyStall) {
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
     * Calculate monthly balances for a specific rental using pre-fetched historical rates (OPTIMIZED VERSION)
     */
    private function calculateMonthlyBalancesForRentalOptimized($rental, $year, $historicalRates)
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $monthlyBalances = [];
        
        // Check for leap year and adjust February days
        $isLeapYear = ($year % 4 == 0 && ($year % 100 != 0 || $year % 400 == 0));
        $daysInMonths = [31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        
        // Check if stall is monthly
        $isMonthlyStall = $rental->stall && $rental->stall->is_monthly;
        
        // Get all individual payments for this rental in the current year
        $individualPayments = $rental->payments
            ->where('status', 'collected')
            ->filter(function ($payment) use ($year) {
                return \Carbon\Carbon::parse($payment->payment_date)->year == $year;
            })
            ->map(function ($payment) use ($isMonthlyStall, $rental, $historicalRates, $daysInMonths) {
                // Calculate monthly rate using pre-fetched historical rates
                $paymentDate = \Carbon\Carbon::parse($payment->payment_date);
                $targetMonth = $paymentDate->month;
                $targetYear = $paymentDate->year;
                
                $monthlyRate = $this->calculateMonthlyRateForRentalInMonthOptimized($rental, $targetYear, $targetMonth, $daysInMonths[$targetMonth - 1], $historicalRates[$targetMonth] ?? []);
                
                $deposit = max(0, $payment->amount - $monthlyRate);
                
                return [
                    'payment_id' => $payment->id,
                    'or_number' => $payment->or_number,
                    'payment_date' => $payment->payment_date,
                    'amount' => $payment->amount,
                    'monthly_rate' => (float) number_format($monthlyRate, 2, '.', ''),
                    'deposit' => (float) number_format($deposit, 2, '.', ''),
                    'month' => $paymentDate->format('M'),
                    'month_index' => $targetMonth - 1,
                ];
            })
            ->values();
        
        // Check which months have deposits based on total payments vs monthly rate
        $monthsWithDeposits = [];
        foreach ($months as $index => $month) {
            $targetMonth = $index + 1;
            
            // Calculate monthly rate using pre-fetched historical rates
            $monthlyRate = $this->calculateMonthlyRateForRentalInMonthOptimized($rental, $year, $targetMonth, $daysInMonths[$index], $historicalRates[$targetMonth] ?? []);
            
            // Get total payments for this month
            $monthPayments = $rental->payments
                ->where('status', 'collected')
                ->filter(function ($payment) use ($year, $targetMonth) {
                    $paymentDate = \Carbon\Carbon::parse($payment->payment_date);
                    return $paymentDate->year == $year && $paymentDate->month == $targetMonth;
                });
            
            $totalMonthlyPayment = $monthPayments->sum('amount');
            $monthDeposit = max(0, $totalMonthlyPayment - $monthlyRate);
            
            if ($monthDeposit > 0) {
                $monthsWithDeposits[] = $index;
            }
        }
        
        // Group by month for backward compatibility
        foreach ($months as $index => $month) {
            $targetMonth = $index + 1;
            
            // Calculate monthly rate using pre-fetched historical rates
            $monthlyRate = $this->calculateMonthlyRateForRentalInMonthOptimized($rental, $year, $targetMonth, $daysInMonths[$index], $historicalRates[$targetMonth] ?? []);
            
            // Get total payments for this month
            $monthPayments = $rental->payments
                ->where('status', 'collected')
                ->filter(function ($payment) use ($year, $targetMonth) {
                    $paymentDate = \Carbon\Carbon::parse($payment->payment_date);
                    return $paymentDate->year == $year && $paymentDate->month == $targetMonth;
                });
            
            $monthlyPayment = $monthPayments->sum('amount');
            
            // Calculate deposit (excess payment) - same logic as VendorAnalysisController
            $deposit = $monthlyPayment > $monthlyRate ? $monthlyPayment - $monthlyRate : 0;
            
            // If there's a deposit, the balance should be 0, otherwise calculate normally
            $balance = $deposit > 0 ? 0 : $monthlyRate - $monthlyPayment;
            
            // Format to 2 decimal places and handle negative zero
            $formattedPayment = number_format($monthlyPayment, 2, '.', '');
            $formattedMonthlyRate = number_format($monthlyRate, 2, '.', '');
            $formattedBalance = number_format($balance, 2, '.', '');
            $formattedDeposit = number_format($deposit, 2, '.', '');
            
            // Convert -0.00 to 0.00
            if (abs($formattedBalance) < 0.01) {
                $formattedBalance = '0.00';
            }
            
            $monthlyBalances[] = [
                'month' => $month,
                'monthly_rate' => (float) $formattedMonthlyRate,
                'payment' => (float) $formattedPayment,
                'balance' => (float) $formattedBalance,
                'deposit' => (float) $formattedDeposit,
                'payment_id' => null,
                'or_number' => null,
                'payment_date' => null,
                'has_deposit' => in_array($index, $monthsWithDeposits),
                'individual_payments' => in_array($index, $monthsWithDeposits) ? 
                    $individualPayments->filter(function ($payment) use ($index) {
                        return $payment['month_index'] == $index;
                    })->values() : [],
            ];
        }
        
        return $monthlyBalances;
    }

    /**
     * Calculate monthly rate for a rental in a specific month using pre-fetched historical rates (OPTIMIZED VERSION)
     */
    private function calculateMonthlyRateForRentalInMonthOptimized($rental, $targetYear, $targetMonth, $daysInMonth, $historicalRates)
    {
        $section = $rental->stall->section;
        $stall = $rental->stall;
        
        // Create an array to hold daily rates for each day of the month
        $dailyRates = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dailyRates[$day] = 0;
        }
        
        // Check if this rental was active during the target month
        $rentalStart = $rental->created_at->copy()->startOfDay();
        $monthStart = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, 1)->startOfDay();
        $monthEnd = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, $daysInMonth)->endOfDay();
        
        // Check if rental became unoccupied during this month or before
        $rentalEnd = null;
        if ($rental->status === 'unoccupied' && $rental->updated_at) {
            $rentalEnd = $rental->updated_at->copy()->endOfDay();
        }
        
        // Skip if rental wasn't active during this month at all
        if ($rentalStart->greaterThan($monthEnd) || 
            ($rentalEnd && $rentalEnd->lessThan($monthStart))) {
            return 0;
        }
        
        // Use pre-fetched historical rates instead of database queries (OPTIMIZATION!)
        $historicalDailyRate = $historicalRates['daily'] ?? null;
        $historicalMonthlyRate = $historicalRates['monthly'] ?? null;
        $historicalAnnualRate = $historicalRates['annual'] ?? null;
        
        $hasStallDailyRate = !is_null($historicalDailyRate) && $historicalDailyRate > 0;
        $hasStallMonthlyRate = !is_null($historicalMonthlyRate) && $historicalMonthlyRate > 0;
        $hasStallAnnualRate = !is_null($historicalAnnualRate) && $historicalAnnualRate > 0;
        
        // Check if stall is monthly
        $isMonthlyStall = $rental->stall && $rental->stall->is_monthly;
        
        // Determine the daily rate to use for this stall (same logic as VendorAnalysisController)
        $dailyRateToUse = 0;
        
        // Check if stall has annual rate and matches the specific annual rate pattern
        if ($hasStallAnnualRate && $historicalAnnualRate == 40000) {
            // Apply special monthly distribution for 40000 annual rate
            $monthlyRateForAnnualStall = $this->getMonthlyRateForAnnualStall($targetMonth);
            $dailyRateToUse = $monthlyRateForAnnualStall / $daysInMonth;
        } elseif ($hasStallDailyRate && $hasStallMonthlyRate) {
            // Check if stall is marked as monthly
            if ($isMonthlyStall) {
                // For monthly stalls, use monthly rate directly
                $dailyRateToUse = $historicalMonthlyRate / $daysInMonth;
            } elseif ($stall->stall_number == 16 && strtolower($section->name) === 'meat & fish') {
                // Special logic for stall number 16 in meat section - use monthly rate directly
                $dailyRateToUse = $historicalMonthlyRate / $daysInMonth;
            } else {
                // For non-monthly stalls, always use daily rate directly
                $dailyRateToUse = $hasStallDailyRate ? $historicalDailyRate : $rental->daily_rent;
            }
        } elseif ($section->rate_type === 'fixed') {
            // Use section fixed rate converted to daily
            $dailyRateToUse = floatval($section->monthly_rate ?? 0) / $daysInMonth;
        } else {
            // Use historical daily rate or rental daily rent - if stall is not monthly OR section rate type is not fixed
            $dailyRateToUse = $hasStallDailyRate ? $historicalDailyRate : $rental->daily_rent;
        }
        
        // Add this stall's daily rate to each day it was active
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDay = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, $day)->startOfDay();
            
            // Check if this stall is active on this specific day
            $isStallActiveOnDay = true;
            
            // If rental starts after this day, it's not active
            if ($rentalStart->greaterThan($currentDay)) {
                $isStallActiveOnDay = false;
            }
            
            // If rental ended before this day, it's not active
            if ($rentalEnd && $rentalEnd->lessThan($currentDay)) {
                $isStallActiveOnDay = false;
            }
            
            // Add the daily rate if the stall is active on this day
            if ($isStallActiveOnDay) {
                $dailyRates[$day] += $dailyRateToUse;
            }
        }
        
        // Sum up all daily rates to get the monthly rate
        return array_sum($dailyRates);
    }
    private function calculateMonthlyBalancesForRental($rental, $year)
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $monthlyBalances = [];
        
        // Check for leap year and adjust February days
        $isLeapYear = ($year % 4 == 0 && ($year % 100 != 0 || $year % 400 == 0));
        $daysInMonths = [31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        
        // Check if stall is monthly
        $isMonthlyStall = $rental->stall && $rental->stall->is_monthly;
        
        // Get all individual payments for this rental in the current year
        $individualPayments = $rental->payments
            ->where('status', 'collected')
            ->filter(function ($payment) use ($year) {
                return \Carbon\Carbon::parse($payment->payment_date)->year == $year;
            })
            ->map(function ($payment) use ($isMonthlyStall, $rental, $daysInMonths) {
                // Calculate monthly rate using the same logic as VendorAnalysisController
                $paymentDate = \Carbon\Carbon::parse($payment->payment_date);
                $targetMonth = $paymentDate->month;
                $targetYear = $paymentDate->year;
                
                $monthlyRate = $this->calculateMonthlyRateForRentalInMonth($rental, $targetYear, $targetMonth, $daysInMonths[$targetMonth - 1]);
                
                $deposit = max(0, $payment->amount - $monthlyRate);
                
                return [
                    'payment_id' => $payment->id,
                    'or_number' => $payment->or_number,
                    'payment_date' => $payment->payment_date,
                    'amount' => $payment->amount,
                    'monthly_rate' => (float) number_format($monthlyRate, 2, '.', ''),
                    'deposit' => (float) number_format($deposit, 2, '.', ''),
                    'month' => $paymentDate->format('M'),
                    'month_index' => $targetMonth - 1,
                ];
            })
            ->values();
        
        // Check which months have deposits based on total payments vs monthly rate
        $monthsWithDeposits = [];
        foreach ($months as $index => $month) {
            $targetMonth = $index + 1;
            
            // Calculate monthly rate using day-by-day approach
            $monthlyRate = $this->calculateMonthlyRateForRentalInMonth($rental, $year, $targetMonth, $daysInMonths[$index]);
            
            // Get total payments for this month
            $monthPayments = $rental->payments
                ->where('status', 'collected')
                ->filter(function ($payment) use ($year, $targetMonth) {
                    $paymentDate = \Carbon\Carbon::parse($payment->payment_date);
                    return $paymentDate->year == $year && $paymentDate->month == $targetMonth;
                });
            
            $totalMonthlyPayment = $monthPayments->sum('amount');
            $monthDeposit = max(0, $totalMonthlyPayment - $monthlyRate);
            
            if ($monthDeposit > 0) {
                $monthsWithDeposits[] = $index;
            }
        }
        
        // Group by month for backward compatibility
        foreach ($months as $index => $month) {
            $targetMonth = $index + 1;
            
            // Calculate monthly rate using day-by-day approach
            $monthlyRate = $this->calculateMonthlyRateForRentalInMonth($rental, $year, $targetMonth, $daysInMonths[$index]);
            
            // Get total payments for this month
            $monthPayments = $rental->payments
                ->where('status', 'collected')
                ->filter(function ($payment) use ($year, $targetMonth) {
                    $paymentDate = \Carbon\Carbon::parse($payment->payment_date);
                    return $paymentDate->year == $year && $paymentDate->month == $targetMonth;
                });
            
            $monthlyPayment = $monthPayments->sum('amount');
            
            // Calculate deposit (excess payment) - same logic as VendorAnalysisController
            $deposit = $monthlyPayment > $monthlyRate ? $monthlyPayment - $monthlyRate : 0;
            
            // If there's a deposit, the balance should be 0, otherwise calculate normally
            $balance = $deposit > 0 ? 0 : $monthlyRate - $monthlyPayment;
            
            // Format to 2 decimal places and handle negative zero
            $formattedPayment = number_format($monthlyPayment, 2, '.', '');
            $formattedMonthlyRate = number_format($monthlyRate, 2, '.', '');
            $formattedBalance = number_format($balance, 2, '.', '');
            $formattedDeposit = number_format($deposit, 2, '.', '');
            
            // Convert -0.00 to 0.00
            if (abs($formattedBalance) < 0.01) {
                $formattedBalance = '0.00';
            }
            
            $monthlyBalances[] = [
                'month' => $month,
                'monthly_rate' => (float) $formattedMonthlyRate,
                'payment' => (float) $formattedPayment,
                'balance' => (float) $formattedBalance,
                'deposit' => (float) $formattedDeposit,
                'payment_id' => null, // No single payment_id for monthly summary
                'or_number' => null,
                'payment_date' => null,
                'has_deposit' => in_array($index, $monthsWithDeposits), // Flag if month has any deposits
                'individual_payments' => in_array($index, $monthsWithDeposits) ? 
                    $individualPayments->filter(function ($payment) use ($index) {
                        return $payment['month_index'] == $index;
                    })->values() : [], // Include all payments for months with deposits
            ];
        }
        
        return $monthlyBalances;
    }

    /**
     * Calculate monthly rate for a rental in a specific month using day-by-day approach (same as VendorAnalysisController)
     */
    private function calculateMonthlyRateForRentalInMonth($rental, $targetYear, $targetMonth, $daysInMonth)
    {
        $section = $rental->stall->section;
        $stall = $rental->stall;
        
        // Create an array to hold daily rates for each day of the month
        $dailyRates = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dailyRates[$day] = 0;
        }
        
        // Check if this rental was active during the target month
        $rentalStart = $rental->created_at->copy()->startOfDay();
        $monthStart = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, 1)->startOfDay();
        $monthEnd = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, $daysInMonth)->endOfDay();
        
        // Check if rental became unoccupied during this month or before
        $rentalEnd = null;
        if ($rental->status === 'unoccupied' && $rental->updated_at) {
            $rentalEnd = $rental->updated_at->copy()->endOfDay();
        }
        
        // Skip if rental wasn't active during this month at all
        if ($rentalStart->greaterThan($monthEnd) || 
            ($rentalEnd && $rentalEnd->lessThan($monthStart))) {
            return 0;
        }
        
        // Get historical rates for this specific month (same as VendorAnalysisController)
        $historicalDailyRate = $this->rateHistoryService->getDailyRateForMonth($stall->id, $targetYear, $targetMonth);
        $historicalMonthlyRate = $this->rateHistoryService->getMonthlyRateForMonth($stall->id, $targetYear, $targetMonth);
        $historicalAnnualRate = $this->rateHistoryService->getAnnualRateForMonth($stall->id, $targetYear, $targetMonth);
        
        $hasStallDailyRate = !is_null($historicalDailyRate) && $historicalDailyRate > 0;
        $hasStallMonthlyRate = !is_null($historicalMonthlyRate) && $historicalMonthlyRate > 0;
        $hasStallAnnualRate = !is_null($historicalAnnualRate) && $historicalAnnualRate > 0;
        
        // Check if stall is monthly
        $isMonthlyStall = $rental->stall && $rental->stall->is_monthly;
        
        // Determine the daily rate to use for this stall (same logic as VendorAnalysisController)
        $dailyRateToUse = 0;
        
        // Check if stall has annual rate and matches the specific annual rate pattern
        if ($hasStallAnnualRate && $historicalAnnualRate == 40000) {
            // Apply special monthly distribution for 40000 annual rate
            $monthlyRateForAnnualStall = $this->getMonthlyRateForAnnualStall($targetMonth);
            $dailyRateToUse = $monthlyRateForAnnualStall / $daysInMonth;
        } elseif ($hasStallDailyRate && $hasStallMonthlyRate) {
            // Check if stall is marked as monthly
            if ($isMonthlyStall) {
                // For monthly stalls, use monthly rate directly
                $dailyRateToUse = $historicalMonthlyRate / $daysInMonth;
            } elseif ($stall->stall_number == 16 && strtolower($section->name) === 'meat & fish') {
                // Special logic for stall number 16 in meat section - use monthly rate directly
                $dailyRateToUse = $historicalMonthlyRate / $daysInMonth;
            } else {
                // For non-monthly stalls, always use daily rate directly
                $dailyRateToUse = $hasStallDailyRate ? $historicalDailyRate : $rental->daily_rent;
            }
        } elseif ($section->rate_type === 'fixed') {
            // Use section fixed rate converted to daily
            $dailyRateToUse = floatval($section->monthly_rate ?? 0) / $daysInMonth;
        } else {
            // Use historical daily rate or rental daily rent - if stall is not monthly OR section rate type is not fixed
            $dailyRateToUse = $hasStallDailyRate ? $historicalDailyRate : $rental->daily_rent;
        }
        
        // Add this stall's daily rate to each day it was active
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDay = \Carbon\Carbon::createFromDate($targetYear, $targetMonth, $day)->startOfDay();
            
            // Check if this stall is active on this specific day
            $isStallActiveOnDay = true;
            
            // If rental starts after this day, it's not active
            if ($rentalStart->greaterThan($currentDay)) {
                $isStallActiveOnDay = false;
            }
            
            // If rental ended before this day, it's not active
            if ($rentalEnd && $rentalEnd->lessThan($currentDay)) {
                $isStallActiveOnDay = false;
            }
            
            // Add the daily rate if the stall is active on this day
            if ($isStallActiveOnDay) {
                $dailyRates[$day] += $dailyRateToUse;
            }
        }
        
        // Sum up all daily rates to get the monthly rate
        return array_sum($dailyRates);
    }

    /**
     * Get the number of days in a specific month (handles leap years)
     */
    private function getDaysInMonth($year, $monthIndex)
    {
        $isLeapYear = ($year % 4 == 0 && ($year % 100 != 0 || $year % 400 == 0));
        $daysInMonths = [31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        return $daysInMonths[$monthIndex];
    }

    /**
     * Get monthly rate for annual stall (same as VendorAnalysisController)
     */
    private function getMonthlyRateForAnnualStall($month)
    {
        // Same logic as VendorAnalysisController for annual rate distribution
        $annualRates = [
            1 => 2500, // January
            2 => 2500, // February
            3 => 2500, // March
            4 => 2500, // April
            5 => 2500, // May
            6 => 2500, // June
            7 => 2500, // July
            8 => 2500, // August
            9 => 2500, // September
            10 => 2500, // October
            11 => 2500, // November
            12 => 2500, // December
        ];
        
        return $annualRates[$month] ?? 2500;
    }

    /**
     * Calculate aggregate monthly balances for all vendor rentals (same as VendorAnalysisController)
     */
    private function calculateVendorMonthlyBalances($mappedRentals, $year)
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $vendorBalances = [];
        
        // Initialize array to hold totals
        $monthlyTotals = array_fill_keys($months, [
            'monthly_rate' => 0,
            'payment' => 0,
            'balance' => 0,
            'deposit' => 0
        ]);
        
        // Sum up all rental data for each month
        foreach ($mappedRentals as $rental) {
            if (isset($rental['monthly_balances'])) {
                foreach ($rental['monthly_balances'] as $monthBalance) {
                    $month = $monthBalance['month'];
                    $monthlyTotals[$month]['monthly_rate'] += $monthBalance['monthly_rate'];
                    $monthlyTotals[$month]['payment'] += $monthBalance['payment'];
                    $monthlyTotals[$month]['balance'] += $monthBalance['balance'];
                    $monthlyTotals[$month]['deposit'] += $monthBalance['deposit'] ?? 0;
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
                'deposit' => (float) number_format($monthlyTotals[$month]['deposit'], 2, '.', '')
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

            // Calculate total balance for selected months using the same logic as VendorAnalysisController
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
                
                // Calculate monthly rate using the same sophisticated logic as VendorAnalysisController
                $monthlyRate = $this->calculateMonthlyRateForRentalInMonth($rental, $currentYear, $targetMonth, $this->getDaysInMonth($currentYear, $monthIndex));
                
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

    /**
     * Consume deposit for payment processing
     */
    public function consumeDeposit(Request $request, $vendorId)
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
            'or_number' => 'required|string|max:50',
            'payment_date' => 'required|date',
            'payment_id' => 'required|integer|exists:payments,id',
            'consume_deposit' => 'required|boolean',
            'custom_amount' => 'nullable|numeric|min:0',
        ]);

        $rentalIds = $request->input('rental_ids');
        $amounts = $request->input('amounts');
        $paymentTypes = $request->input('payment_types');
        $advanceDays = $request->input('advance_days', []);
        $orNumber = $request->input('or_number');
        $paymentDate = Carbon::parse($request->input('payment_date'));
        $depositPaymentId = $request->input('payment_id');
        $customAmount = $request->input('custom_amount'); // Get custom amount
        $now = now();
        $results = [];

        // Get the payment record that contains the deposit
        $depositPayment = Payments::findOrFail($depositPaymentId);
        
        // Verify this payment belongs to the vendor
        if ($depositPayment->vendor_id !== $vendor->id) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit payment does not belong to this vendor.',
            ], 403);
        }

        // Calculate available deposit from the month (not individual payment)
        $depositPaymentRental = $depositPayment->rented;
        $isMonthlyStall = $depositPaymentRental->stall && $depositPaymentRental->stall->is_monthly;
        
        // Get the month when the payment was made
        $paymentMonth = Carbon::parse($depositPayment->payment_date)->month;
        $paymentYear = Carbon::parse($depositPayment->payment_date)->year;
        
        // Calculate monthly rate for that month
        if ($isMonthlyStall) {
            $monthlyRate = $depositPaymentRental->monthly_rent ?: ($depositPaymentRental->stall->monthly_rate ?? 0);
        } else {
            // For daily stalls, get the actual days in that month
            $isLeapYear = ($paymentYear % 4 == 0 && ($paymentYear % 100 != 0 || $paymentYear % 400 == 0));
            $daysInMonth = [31, $isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$paymentMonth - 1];
            $monthlyRate = $depositPaymentRental->daily_rent * $daysInMonth;
        }
        
        // Get all payments for this rental in the same month
        $monthPayments = $depositPaymentRental->payments
            ->where('status', 'collected')
            ->filter(function ($payment) use ($paymentMonth, $paymentYear) {
                $paymentDate = Carbon::parse($payment->payment_date);
                return $paymentDate->month == $paymentMonth && $paymentDate->year == $paymentYear;
            });
        
        // Calculate total month payments and available deposit
        $totalMonthPayments = $monthPayments->sum('amount');
        $availableDeposit = max(0, $totalMonthPayments - $monthlyRate);

        if ($availableDeposit <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'No deposit available to consume.',
            ], 400);
        }

        // Calculate total amount needed for current payment
        $totalAmountNeeded = $customAmount ?: array_sum($amounts);

        if ($totalAmountNeeded > $availableDeposit) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient deposit. Available: ₱" . number_format($availableDeposit, 2) . ", Needed: ₱" . number_format($totalAmountNeeded, 2),
            ], 400);
        }

        // Process payments using deposit
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
                
                if ($nextDueDate->gt($now)) {
                    $results[] = [
                        'rental_id' => $rentalId,
                        'success' => false,
                        'message' => "This stall has an active advance payment and cannot be paid until {$nextDueDate->toDateString()}. Next due date: {$nextDueDate->toDateString()}.",
                    ];
                    continue;
                }
            }

            // Process payment using deposit - create a special payment record
            $payment = Payments::create([
                'rented_id' => $rental->id,
                'vendor_id' => $rental->vendor_id,
                'payment_type' => $paymentType,
                'amount' => $amount,
                'or_number' => $orNumber,
                'payment_date' => $paymentDate,
                'missed_days' => 0, // Deposit consumption doesn't cover missed days
                'advance_days' => 0, // Deposit consumption doesn't create advance days
                'status' => 'collected',
            ]);

            // Update rental status based on payment type
            if ($paymentType === 'daily') {
                $rental->last_payment_date = $paymentDate;
                $rental->next_due_date = $paymentDate->copy()->addDay()->toDateString();
                $rental->status = 'occupied';
            } elseif ($paymentType === 'fully paid') {
                $rental->last_payment_date = $paymentDate;
                $rental->next_due_date = $paymentDate->copy()->addDay()->toDateString();
                $rental->status = 'fully paid';
                $rental->missed_days = 0;
                $rental->remaining_balance = 0;
            }

            $rental->save();

            $results[] = [
                'rental_id' => $rentalId,
                'success' => true,
                'message' => 'Payment processed successfully using deposit.',
                'payment' => $payment,
                'or_number' => $orNumber,
            ];
        }

        // Update the original payment to track deposit consumption
        // Reduce the original payment amount to reflect consumed deposit
        $depositPayment->amount = $depositPayment->amount - $totalAmountNeeded;
        
        // If the entire payment amount is consumed, delete the payment record
        if ($depositPayment->amount <= 0) {
            $depositPayment->delete();
        } else {
            $depositPayment->save();
        }

        // Calculate remaining deposit for response
        $remainingDeposit = $availableDeposit - $totalAmountNeeded;

        // Send notification to vendor
        $successfulPayments = collect($results)->where('success', true);
        if ($successfulPayments->isNotEmpty()) {
            $stallCount = $successfulPayments->count();
            
            Notification::create([
                'vendor_id' => $vendor->id,
                'title' => 'Deposit Consumed',
                'message' => "Your deposit of ₱" . 
                            number_format($totalAmountNeeded, 2) . " has been consumed for {$stallCount} stall(s) with OR #{$orNumber}.",
                'is_read' => 0,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Deposit consumed successfully.',
            'results' => $results,
            'total_amount_consumed' => $totalAmountNeeded,
            'remaining_deposit' => $remainingDeposit,
        ]);
    }
}
