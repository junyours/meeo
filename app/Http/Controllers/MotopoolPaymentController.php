<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\Models\MotorPool;
use App\Models\Remittance;
use Illuminate\Http\Request;
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\Auth;

class MotopoolPaymentController extends Controller
{
   public function motorPoolCollectionSummary()
    {
        $collector = InchargeCollector::where('user_id', Auth::id())->first();

        if (!$collector || $collector->area !== 'motorpool') {
            return response()->json(['message' => 'Unauthorized or not assigned to motor pool.'], 403);
        }

        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek   = Carbon::now()->endOfWeek();

        $payments = MotorPool::where('collector_id', $collector->id)
            ->whereBetween('payment_date', [$startOfWeek, $endOfWeek])
            ->get();

        $weekDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $dailyBreakdown = [];
        foreach ($weekDays as $day) $dailyBreakdown[$day] = 0;

        $actualToday = 0;
        $unremittedPayments = [];

        foreach ($payments as $payment) {
            $dayOfWeek = Carbon::parse($payment->payment_date)->format('l');

            if (isset($dailyBreakdown[$dayOfWeek])) {
                $dailyBreakdown[$dayOfWeek] += $payment->amount;
            }

            if (Carbon::parse($payment->payment_date)->isToday()) {
                $actualToday += $payment->amount;
            }

            if ($payment->status === 'pending') {
                $unremittedPayments[] = $payment;
            }
        }

        return response()->json([
            'date' => Carbon::now()->toDateString(),
            'actual_collection' => $actualToday,
            'daily_breakdown' => $dailyBreakdown,
            'unremitted_payments' => $unremittedPayments,
        ]);
    }

    public function alreadyRemittedToday()
{
    $userId = Auth::id();

    $staff = InchargeCollector::where('user_id', $userId)->first();

    if (!$staff) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Only incharge collectors can check remittance.'
        ], 403);
    }

    $today = Carbon::today();

    $alreadyRemitted = MotorPool::where('collector_id', $staff->id)
        ->whereDate('payment_date', $today)
        ->where('status', 'remitted') // assuming status 'remitted' is used
        ->exists();

    return response()->json([
        'status' => 'success',
        'already_remitted' => $alreadyRemitted
    ]);
}
    // ✅ Store new MotorPool payment
    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $collector = InchargeCollector::where('user_id', Auth::id())->first();

        if (!$collector || $collector->area !== 'motorpool') {
            return response()->json(['message' => 'Unauthorized or not assigned to motor pool.'], 403);
        }

        $payment = MotorPool::create([
            'amount' => $request->amount,
            'collector_id' => $collector->id,
            'status' => 'pending',
            'payment_date' => now()->toDateString(),
        ]);

        return response()->json([
            'message' => 'MotorPool payment recorded successfully.',
            'data' => $payment
        ], 201);
    }

    // ✅ Remittance report (daily/weekly/monthly/yearly)
public function motorPoolRemittanceReport($period)
{
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector || $collector->area !== 'motorpool') {
        return response()->json(['message' => 'Unauthorized or not assigned to motor pool.'], 403);
    }

    $query = MotorPool::with([
        'collector',
        'remittanceables.remittance.remittedBy',
        'remittanceables.remittance.receivedBy',
    ])->whereHas('approvedRemittances');

    $payments = $query->get();
    $now = Carbon::now();

    $mapPayment = function ($payment) {
        $remittance = $payment->remittanceables->first()?->remittance;
        return [
            'id'           => $payment->id,
            'remit_date'   => $remittance?->remit_date ?? $payment->payment_date,
            'payment_date' => $payment->payment_date,
            'amount'       => (float) $payment->amount,
            'status'       => 'Approved',
            'collector'    => $payment->collector?->fullname ?? 'N/A',
            'approved_by'  => $remittance?->receivedBy?->fullname ?? 'N/A',
            'remitted_by'  => $remittance?->remittedBy?->fullname ?? 'N/A',
        ];
    };

// ------------------ DAILY (current week) ------------------
if ($period === 'daily') {
    $startOfWeek = $now->copy()->startOfWeek(); // Monday
    $endOfWeek = $now->copy()->endOfWeek();     // Sunday

    $weekDays = [];
    // Initialize all days of current week
    for ($d = $startOfWeek->copy(); $d <= $endOfWeek; $d->addDay()) {
        $weekDays[$d->format('l')] = ['payments' => [], 'total' => 0];
    }

    // Assign payments to the correct day
    foreach ($payments as $payment) {
        $date = Carbon::parse($payment->payment_date);
        if ($date->between($startOfWeek, $endOfWeek)) {
            $dayName = $date->format('l');
            $data = $mapPayment($payment);
            $weekDays[$dayName]['payments'][] = $data;
            $weekDays[$dayName]['total'] += $payment->amount;
        }
    }

    return response()->json(['period' => $period, 'days' => $weekDays]);
}


    // ------------------ WEEKLY ------------------
    if ($period === 'weekly') {
        $startOfMonth = $now->copy()->startOfMonth()->startOfWeek();
        $endOfMonth = $now->copy()->endOfMonth()->endOfWeek();

        $weeks = [];
        $weekCounter = 1;
        $current = $startOfMonth->copy();

        // initialize weeks
        while ($current <= $endOfMonth) {
            $weekStart = $current->copy()->startOfWeek();
            $weekEnd = $current->copy()->endOfWeek();
            $weeks[$weekStart->format('Y-m-d')] = [
                'week' => $weekCounter++,
                'start_date' => $weekStart->toDateString(),
                'end_date' => $weekEnd->toDateString(),
                'payments' => [],
                'total' => 0,
            ];
            $current->addWeek();
        }

        // assign payments to week
        foreach ($payments as $payment) {
            $date = Carbon::parse($payment->payment_date);
            $weekStart = $date->copy()->startOfWeek()->format('Y-m-d');
            if (isset($weeks[$weekStart])) {
                $data = $mapPayment($payment);
                $weeks[$weekStart]['payments'][] = $data;
                $weeks[$weekStart]['total'] += $payment->amount;
            }
        }

        return response()->json(['period' => $period, 'weeks' => array_values($weeks)]);
    }

    // ------------------ MONTHLY ------------------
    if ($period === 'monthly') {
        $months = [];
        foreach (range(1, 12) as $m) {
            $monthName = Carbon::create()->month($m)->format('F');
            $months[$monthName] = ['month' => $monthName, 'payments' => [], 'total' => 0];
        }

        foreach ($payments as $payment) {
            $monthName = Carbon::parse($payment->payment_date)->format('F');
            $data = $mapPayment($payment);
            $months[$monthName]['payments'][] = $data;
            $months[$monthName]['total'] += $payment->amount;
        }

        return response()->json(['period' => $period, 'months' => array_values($months)]);
    }

    // ------------------ YEARLY ------------------
    if ($period === 'yearly') {
        $years = [];
        $currentYear = now()->year;
        foreach (range($currentYear - 1, $currentYear) as $y) {
            $years[$y] = ['year' => $y, 'payments' => [], 'total' => 0];
        }

        foreach ($payments as $payment) {
            $year = Carbon::parse($payment->payment_date)->year;
            $data = $mapPayment($payment);
            $years[$year]['payments'][] = $data;
            $years[$year]['total'] += $payment->amount;
        }

        return response()->json(['period' => $period, 'years' => array_values($years)]);
    }

    return response()->json(['message' => 'Invalid period'], 400);
}

public function show($id)
{
    $motorpool = MotorPool::with([
        'remittanceables.remittance.remittedBy', 
        'remittanceables.remittance.receivedBy',
        'collector'
    ])->findOrFail($id);

    $breakdown = $motorpool->remittanceables->map(function($item) {
        $remittance = $item->remittance;

        return [
            'id' => $item->id,
            'amount' => $remittance->amount ?? 0,
            'status' => $remittance->status ?? 'N/A',
            'collector' => $remittance->remittedBy?->fullname ?? 'N/A',
            'received_by' => $remittance->receivedBy?->fullname ?? 'N/A',
            'collection_date' => $remittance->created_at ? Carbon::parse($remittance->created_at)->format('Y-m-d') : null,
        ];
    });

    return response()->json([
        'id' => $motorpool->id,
        'total_amount' => $motorpool->amount ?? 0,
        'collector' => $motorpool->collector?->fullname ?? 'N/A',
     'received_by' => $motorpool->remittanceables->first()?->remittance?->receivedBy?->fullname ?? 'N/A',
        'status' => $motorpool->status ?? 'N/A',
        'collection_date' => $motorpool->payment_date ? Carbon::parse($motorpool->payment_date)->format('Y-m-d') : null,
        'breakdown' => $breakdown,
    ]);
}


    
    // ✅ Remittance history
    public function history()
    {
        $collector = InchargeCollector::where('user_id', Auth::id())->first();

        $history = Remittance::where('collector_id', $collector->id)
            ->where('remittable_type', MotorPool::class)
            ->latest()
            ->get();

        return response()->json(['data'=>$history]);
    }

    // ✅ General MotorPool report (grouped)
public function motorPoolReport(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate   = $request->query('end_date');

    $payments = MotorPool::with(['collector', 'approvedRemittances.remittance.receivedBy'])
        ->whereHas('approvedRemittances')
        ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        })
        ->orderBy('payment_date', 'asc')
        ->get();

    $entries = [];
    foreach ($payments as $pmt) {
        $approvedRemittance = $pmt->approvedRemittances->first()?->remittance;

        $paymentDate = Carbon::parse($pmt->payment_date)->timezone('Asia/Manila');

        $entries[] = [
            'id'           => $pmt->id,
            'amount'       => (float) $pmt->amount,
            'payment_date' => $paymentDate->toDateString(), // simple string for frontend
            'collector'    => $pmt->collector?->fullname ?? 'Unknown',
            'received_by'  => $approvedRemittance?->receivedBy?->fullname ?? 'Unknown',
            'status'       => $pmt->status ?? 'Pending',
        ];
    }

    // ---------- GROUP BY DAY ----------
    $dailyData = [];
    foreach ($entries as $entry) {
        $date = Carbon::parse($entry['payment_date'])->timezone('Asia/Manila');
        $dayKey   = $date->format('Y-m-d');
        $dayLabel = '(' . strtoupper($date->format('D')) . ') ' . $date->format('M j');

        if (!isset($dailyData[$dayKey])) {
            $dailyData[$dayKey] = [
                'day_key'      => $dayKey,
                'day_label'    => $dayLabel,
                'total_amount' => 0,
                'details'      => [],
                'date'         => $date, // keep Carbon for sorting, strip later
            ];
        }

        $dailyData[$dayKey]['total_amount'] += $entry['amount'];
        $dailyData[$dayKey]['details'][]     = $entry;
    }

    // ---------- GROUP BY MONTH (YEAR-MONTH) ----------
    $monthlyGrouped = [];
    foreach ($dailyData as $dayKey => $dayData) {
        /** @var \Carbon\Carbon $dayDate */
        $dayDate     = $dayData['date'];
        $monthKey    = $dayDate->format('Y-m');          // e.g. 2025-10
        $monthLabel  = $dayDate->format('F Y');          // e.g. October 2025

        if (!isset($monthlyGrouped[$monthKey])) {
            $monthlyGrouped[$monthKey] = [
                'month_key'   => $monthKey,
                'month'       => $monthLabel,            // label used by frontend
                'month_name'  => $dayDate->format('F'),  // pure name if you still need it
                'year'        => $dayDate->format('Y'),
                'days'        => [],
                'month_start' => $dayDate->copy()->startOfMonth(),
            ];
        }

        $monthlyGrouped[$monthKey]['days'][$dayKey] = $dayData;
    }

    // ---------- SORT MONTHS (LATEST FIRST) ----------
    usort($monthlyGrouped, function ($a, $b) {
        // compare using real dates
        return $b['month_start']->timestamp - $a['month_start']->timestamp;
    });

    // ---------- SORT DAYS INSIDE EACH MONTH DESC & CLEAN ----------
    foreach ($monthlyGrouped as &$month) {
        $days = $month['days'];

        uasort($days, function ($a, $b) {
            return $b['date']->timestamp - $a['date']->timestamp;
        });

        // remove internal Carbon "date"
        foreach ($days as &$day) {
            unset($day['date']);
        }

        $month['days'] = array_values($days);

        // also remove month_start from final response
        unset($month['month_start']);
    }
    unset($month);

    // ---------- BUILD FINAL DAILY ARRAY FOR CHARTS ----------
    $dailyForChart = array_values(array_map(function ($day) {
        // convert Carbon "date" to string and strip from output
        $date = $day['date'];
        $day['date'] = $date->toDateString();
        return $day;
    }, $dailyData));

    return response()->json([
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'daily'      => $dailyForChart,   // for chart
        'months'     => $monthlyGrouped,  // for table
    ]);
}

public function motorRemittanceReport($period)
{
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector || $collector->area !== 'wharf') {
        return response()->json(['message' => 'Unauthorized or not assigned to wharf.'], 403);
    }

    /** @var \Illuminate\Database\Eloquent\Collection<\App\Models\Wharf> $payments */
    $payments = MotorPool::with([
        'collector',
        'remittanceables.remittance.remittedBy',
        'remittanceables.remittance.receivedBy',
    ])
    ->whereHas('approvedRemittances')
    ->get();

    // -----------------------------
    // DAILY - only current week
    // -----------------------------
    if ($period === 'daily') {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        /** @var \App\Models\Motorpool $payment */
        $grouped = [];
        foreach ($payments as $payment) {
            $paymentDate = Carbon::parse($payment->payment_date);
            if ($paymentDate->lt($startOfWeek) || $paymentDate->gt($endOfWeek)) {
                continue; // skip payments not in this week
            }

            $day = $paymentDate->format('Y-m-d');
            $remittance = $payment->remittanceables->first()?->remittance;

            if (!isset($grouped[$day])) {
                $grouped[$day] = ['payments' => [], 'total' => 0];
            }

            $grouped[$day]['payments'][] = [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'updated_at' => $payment->updated_at,
                'amount' => (float) $payment->amount,
                'status' => $payment->status ?? 'Pending',
                'collector' => $payment->collector?->fullname ?? 'N/A',
                'remitted_by' => $remittance?->remittedBy?->fullname ?? 'N/A',
                'received_by' => $remittance?->receivedBy?->fullname ?? 'N/A',
            ];

            $grouped[$day]['total'] += $payment->amount;
        }

        return response()->json(['period' => $period, 'days' => $grouped]);
    }

    // -----------------------------
    // WEEKLY - only current month
    // -----------------------------
    if ($period === 'weekly') {
        $startOfMonth = now()->startOfMonth()->startOfWeek();
        $endOfMonth = now()->endOfMonth()->endOfWeek();
        $weeks = [];
        $current = $startOfMonth->copy();
        $weekCounter = 1;

        while ($current <= $endOfMonth) {
            $weekKey = $current->format('o-\WW');
            $weeks[$weekKey] = [
                'week' => $weekCounter++,
                'start_date' => $current->toDateString(),
                'end_date' => $current->copy()->endOfWeek()->toDateString(),
                'payments' => [],
                'total' => 0,
            ];
            $current->addWeek();
        }

        /** @var \App\Models\Motorpool $payment */
        foreach ($payments as $payment) {
            $date = Carbon::parse($payment->payment_date);
            if ($date->lt($startOfMonth) || $date->gt($endOfMonth)) {
                continue; // skip payments not in this month
            }

            $weekKey = $date->copy()->startOfWeek()->format('o-\WW');
            $remittance = $payment->remittanceables->first()?->remittance;

            if (!isset($weeks[$weekKey])) continue;

            $weeks[$weekKey]['payments'][] = [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'updated_at' => $payment->updated_at,

                'amount' => (float) $payment->amount,
                'status' => $payment->status ?? 'Pending',
                'collector' => $payment->collector?->fullname ?? 'N/A',
                'remitted_by' => $remittance?->remittedBy?->fullname ?? 'N/A',
                'received_by' => $remittance?->receivedBy?->fullname ?? 'N/A',
            ];

            $weeks[$weekKey]['total'] += $payment->amount;
        }

        return response()->json(['period' => $period, 'weeks' => array_values($weeks)]);
    }

    // -----------------------------
    // MONTHLY - group by month of current year
    // -----------------------------
    if ($period === 'monthly') {
        $months = [];
        foreach (range(1, 12) as $m) {
            $monthName = Carbon::create()->month($m)->format('F');
            $months[$monthName] = ['month' => $monthName, 'payments' => [], 'total' => 0];
        }

        /** @var \App\Models\Motorpool $payment */
        foreach ($payments as $payment) {
            $monthName = Carbon::parse($payment->payment_date)->format('F');
            $remittance = $payment->remittanceables->first()?->remittance;

            $months[$monthName]['payments'][] = [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'amount' => (float) $payment->amount,
                'updated_at' => $payment->updated_at,

                'status' => $payment->status ?? 'Pending',
                'collector' => $payment->collector?->fullname ?? 'N/A',
                'remitted_by' => $remittance?->remittedBy?->fullname ?? 'N/A',
                'received_by' => $remittance?->receivedBy?->fullname ?? 'N/A',
            ];

            $months[$monthName]['total'] += $payment->amount;
        }

        return response()->json(['period' => $period, 'months' => array_values($months)]);
    }

    // -----------------------------
    // YEARLY - group by year
    // -----------------------------
    if ($period === 'yearly') {
        $years = [];
        $currentYear = now()->year;
        foreach (range($currentYear - 1, $currentYear) as $y) {
            $years[$y] = ['year' => $y, 'payments' => [], 'total' => 0];
        }

        /** @var \App\Models\Motorpool $payment */
        foreach ($payments as $payment) {
            $year = Carbon::parse($payment->payment_date)->year;
            $remittance = $payment->remittanceables->first()?->remittance;

            $years[$year]['payments'][] = [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date,
                'amount' => (float) $payment->amount,
                'updated_at' => $payment->updated_at,

                'status' => $payment->status ?? 'Pending',
                'collector' => $payment->collector?->fullname ?? 'N/A',
                'remitted_by' => $remittance?->remittedBy?->fullname ?? 'N/A',
                'received_by' => $remittance?->receivedBy?->fullname ?? 'N/A',
            ];

            $years[$year]['total'] += $payment->amount;
        }

        return response()->json(['period' => $period, 'years' => array_values($years)]);
    }

    return response()->json(['message' => 'Invalid period'], 400);
}

}
