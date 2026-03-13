<?php

namespace App\Http\Controllers;

use DateTime;
use Carbon\Carbon;
use App\Models\Wharf;
use Carbon\CarbonPeriod;
use App\Models\Remittance;
use Illuminate\Http\Request;
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\Auth;

class WharfPaymentController extends Controller
{
public function wharfCollectionSummary()
{
    $collector = InchargeCollector::where('user_id', Auth::id())->first();

    if (!$collector || $collector->area !== 'wharf') {
        return response()->json([
            'message' => 'Unauthorized or not assigned to wharf.'
        ], 403);
    }

    $startOfWeek = Carbon::now()->startOfWeek();
    $endOfWeek   = Carbon::now()->endOfWeek();

    $payments = Wharf::where('collector_id', $collector->id)
        ->whereBetween('payment_date', [$startOfWeek, $endOfWeek])
        ->get();

    $weekDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $dailyBreakdown = [];
    foreach ($weekDays as $day) {
        $dailyBreakdown[$day] = 0;
    }

    $actualToday = 0;      // total for today, all payments
    $pendingToday = 0;     // only pending payments for today
    $unremittedPayments = [];

    foreach ($payments as $payment) {
        $dayOfWeek = Carbon::parse($payment->payment_date)->format('l');

        if (isset($dailyBreakdown[$dayOfWeek])) {
            $dailyBreakdown[$dayOfWeek] += $payment->amount;
        }

        if (Carbon::parse($payment->payment_date)->isToday()) {
            $actualToday += $payment->amount; // all payments
            if ($payment->status === 'pending') {
                $pendingToday += $payment->amount; // only pending
            }
        }

        if ($payment->status === 'pending') {
            $unremittedPayments[] = $payment;
        }
    }

    return response()->json([
        'date'                => Carbon::now()->toDateString(),
        'actual_collection'   => $actualToday,    // all payments today
        'pending_collection'  => $pendingToday,   // only pending today
        'daily_breakdown'     => $dailyBreakdown,
        'unremitted_payments' => $unremittedPayments,
    ]);
}



    // ✅ Save new Wharf payment
    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $collector = InchargeCollector::where('user_id', Auth::id())->first();

        if (!$collector || $collector->area !== 'wharf') {
            return response()->json([
                'message' => 'Unauthorized or not assigned to wharf.'
            ], 403);
        }

        $wharfPayment = Wharf::create([
            'amount'       => $request->amount,
            'collector_id' => $collector->id,
            'status'       => 'pending',
            'payment_date' => now()->toDateString(),
        ]);

        return response()->json([
            'message' => 'Wharf payment recorded successfully.',
            'data'    => $wharfPayment
        ], 201);
    }

    // ✅ Remit unremitted Wharf payments
  
    // ✅ Get history
    public function history()
    {
        $collector = InchargeCollector::where('user_id', Auth::id())->first();

        $history = Remittance::where('collector_id', $collector->id)
            ->where('remittable_type', Wharf::class)
            ->latest()
            ->get();

        return response()->json([
            'data' => $history
        ]);
    }




public function wharfRemittanceReport($period)
{
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector || $collector->area !== 'wharf') {
        return response()->json(['message' => 'Unauthorized or not assigned to wharf.'], 403);
    }

    /** @var \Illuminate\Database\Eloquent\Collection<\App\Models\Wharf> $payments */
    $payments = Wharf::with([
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

        /** @var \App\Models\Wharf $payment */
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

        /** @var \App\Models\Wharf $payment */
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

        /** @var \App\Models\Wharf $payment */
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

        /** @var \App\Models\Wharf $payment */
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



    public function show($id)
        {
            // Load the Wharf payment with remittanceables and their remittances & remittedBy
            $wharf = Wharf::with(['remittanceables.remittance.remittedBy','remittanceables.remittance.receivedBy'])->findOrFail($id);

            // Flatten the remittanceables into a breakdown array
            $breakdown = $wharf->remittanceables->flatMap(function ($item) {
                $remittance = $item->remittance;

                // Some Wharf payments may split into multiple remittances
                return [[
                    'id' => $item->id,
                    'amount' => $remittance->amount ?? 0,
                    'status' => $remittance->status ?? 'N/A',
                    'collected_by' => $remittance->remittedBy->fullname,
                    'received_by' => $remittance->receivedBy->fullname ,
                    'collection_date' => $remittance->created_at
                        ? Carbon::parse($remittance->payment_date)->format('Y-m-d')
                        : null,
                ]];
            });

            return response()->json([
                'id' => $wharf->id,
                'total_amount' => $wharf->amount ?? 0,
                'collector' => $remittance->remittedBy->fullname ?? 'N/A',
                'received' => $remittance->receivedBy->fullname ?? 'N/A',
                'status' => $wharf->status ?? 'N/A',
                'breakdown' => $breakdown,
            ]);
        }




public function wharfReport(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate = $request->query('end_date');

    $payments = Wharf::with(['collector', 'approvedRemittances.remittance.receivedBy'])
        ->whereHas('approvedRemittances')
        ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        })
        
        ->get();

    $entries = [];
    foreach ($payments as $pmt) {
        $approvedRemittance = $pmt->approvedRemittances->first()?->remittance;
        $entries[] = [
            'id' => $pmt->id,
            'amount' => (float) $pmt->amount,
            'payment_date' => Carbon::parse($pmt->payment_date)->timezone('Asia/Manila'),
            'collector' => $pmt->collector?->fullname ?? 'Unknown',
            'received_by' => $approvedRemittance?->receivedBy?->fullname ?? 'Unknown',
            'status' => $pmt->status ?? 'Pending',
        ];
    }

    // Grouping remains same
    $grouped = [];
    foreach ($entries as $entry) {
        $month = $entry['payment_date']->format('F');
        $dayKey = $entry['payment_date']->format('Y-m-d');
        $dayLabel = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($grouped[$month])) $grouped[$month] = [];
        if (!isset($grouped[$month][$dayKey])) {
            $grouped[$month][$dayKey] = [
                'day_label' => $dayLabel,
                'total_amount' => 0,
                'details' => [],
            ];
        }

        $grouped[$month][$dayKey]['total_amount'] += $entry['amount'];
        $grouped[$month][$dayKey]['details'][] = $entry;
    }

    $finalData = [];
    foreach ($grouped as $month => $days) {
        $finalData[] = [
            'month' => $month,
            'days' => array_values($days),
        ];
    }

    return response()->json([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'months' => $finalData,
    ]);
}


public function wharfRemittance(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate = $request->query('end_date');

    $payments = Wharf::with(['collector', 'approvedRemittances.remittance.receivedBy'])
        ->whereHas('approvedRemittances')
        ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        })
        ->orderBy('payment_date', 'desc') // 🔹 order by latest payment_date first
        ->get();

    $entries = [];
    foreach ($payments as $pmt) {
        $approvedRemittance = $pmt->approvedRemittances->first()?->remittance;
        $entries[] = [
            'id' => $pmt->id,
            'amount' => (float) $pmt->amount,
            'payment_date' => Carbon::parse($pmt->payment_date)->timezone('Asia/Manila'),
            'collector' => $pmt->collector?->fullname ?? 'Unknown',
            'received_by' => $approvedRemittance?->receivedBy?->fullname ?? 'Unknown',
            'status' => $pmt->status ?? 'Pending',
        ];
    }

    // Grouping
    $grouped = [];
    foreach ($entries as $entry) {
        $month = $entry['payment_date']->format('F');
        $dayKey = $entry['payment_date']->format('Y-m-d');
        $dayLabel = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($grouped[$month])) $grouped[$month] = [];
        if (!isset($grouped[$month][$dayKey])) {
            $grouped[$month][$dayKey] = [
                'day_label' => $dayLabel,
                'total_amount' => 0,
                'details' => [],
            ];
        }

        $grouped[$month][$dayKey]['total_amount'] += $entry['amount'];
        $grouped[$month][$dayKey]['details'][] = $entry;
    }

    $finalData = [];
    foreach ($grouped as $month => $days) {
        // 🔹 sort days by date desc so latest day is first
        krsort($days); // because keys are 'Y-m-d', this gives descending by date

        $finalData[] = [
            'month' => $month,
            'days' => array_values($days),
        ];
    }

    return response()->json([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'months' => $finalData,
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

    $alreadyRemitted = Wharf::where('collector_id', $staff->id)
        ->whereDate('created_at', $today)
        ->where('status', 'remitted') // assuming status 'remitted' is used
        ->exists();

    return response()->json([
        'status' => 'success',
        'already_remitted' => $alreadyRemitted
    ]);
}

}
