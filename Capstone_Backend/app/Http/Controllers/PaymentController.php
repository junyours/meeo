<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Wharf;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Payments;
use App\Models\MotorPool;
use App\Models\Remittance;
use Illuminate\Http\Request;
use App\Models\SlaughterPayment;
use App\Models\InchargeCollector;

class PaymentController extends Controller
{

    // In your controller
public function collectionDetails(Request $request)
{
    $day = $request->query('day'); // e.g., 'Monday'
    $startOfWeek = now()->startOfWeek();
    $dayIndex = match($day) {
        'Monday' => 0,
        'Tuesday' => 1,
        'Wednesday' => 2,
        'Thursday' => 3,
        'Friday' => 4,
        'Saturday' => 5,
        'Sunday' => 6,
        default => 0,
    };

    $dayStart = $startOfWeek->copy()->addDays($dayIndex)->startOfDay();
    $dayEnd = $startOfWeek->copy()->addDays($dayIndex)->endOfDay();

    $payments = Payments::whereBetween('payment_date', [$dayStart, $dayEnd])->get();

    $details = $payments->map(fn($p) => [
        'id' => $p->id,
        'vendor_name' => $p->rented->application->vendor->fullname ?? 'Unknown',
        'stall_number' => $p->rented->stall->stall_number ?? null,
        'payment_type' => $p->payment_type ?? 'Unknown',
        'amount' => (float) $p->amount,
        'payment_date' => $p->payment_date,
    ]);

    return response()->json($details);
}

   public function vendorPayments()
{
    $vendor = auth()->user()->vendorDetails; // adjust relationship

    $payments = Payments::where('vendor_id', $vendor->id)
        ->with('rented.stall')
        ->orderBy('payment_date', 'desc')
        ->get()
        ->map(function ($p) {
            return [
                'id' => $p->id,
                'stall_number' => $p->rented->stall->stall_number ?? 'N/A',
                'payment_type' => $p->payment_type,
                'amount' => $p->amount,
                'payment_date' => $p->payment_date,
            ];
        });

    return response()->json(['payments' => $payments]);
}


public function MarketunremittedPayments()
{
    // Get the logged-in collector
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector) {
        return response()->json(['message' => 'Collector not found'], 404);
    }

    // Fetch payments collected by this collector but not remitted
    $payments = Payments::with('vendor')
        ->where('collector_id', $collector->id)
        ->where('status', 'collected')
        ->whereDoesntHave('remittanceables') // ensures payment is not remitted
        ->orderBy('payment_date', 'desc')
        ->get()
        ->map(function ($payment) {
            return [
                'id' => $payment->id,
                'vendor_name' => $payment->vendor?->fullname ?? 'N/A',
                'stall_number' => $payment->rented?->stall?->stall_number ?? 'N/A',
                'amount' => $payment->amount,
                'payment_date' => $payment->payment_date,
                'advance_days' => $payment->advance_days,
                'missed_days' => $payment->missed_days,

                'payment_type' => $payment->payment_type,
                'updated_at' => $payment->updated_at,

            ];
        });

    return response()->json([
        'success' => true,
        'unremitted_market_payment' => $payments,
    ]);
}

public function collectionSummary()
{
    $today = now()->toDateString();

    // Get the logged-in collector
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    $collectorName = $collector?->fullname ?? 'Unknown'; // <-- NEW

    // All payments collected today
    $allTodayPayments = Payments::whereDate('payment_date', $today)->get();
    $actualCollection = $allTodayPayments->sum('amount');

    // Pending remittance (unremitted payments)
    $pendingPayments = Payments::whereDate('payment_date', $today)
        ->where('status', 'collected')
        ->whereDoesntHave('remittanceables')
        ->get();
    $pendingRemittance = $pendingPayments->sum('amount');

    // Check if the collector has already remitted today
    $alreadyRemitted = false;
    if ($collector) {
        $alreadyRemitted = Remittance::where('remitted_by', $collector->id)
            ->whereDate('remit_date', $today)
            ->exists();
    }

    // Estimated collection = sum of daily_rent of all active rented stalls
    $rentedStalls = Rented::all(); // filter by 'active' if needed
    $estimatedCollection = $rentedStalls->sum('daily_rent');
    $stallCount = $rentedStalls->count();

    // Weekly breakdown
    $startOfWeek = now()->startOfWeek();
    $endOfWeek = now()->endOfWeek();
    $weeklyPayments = Payments::whereBetween('payment_date', [$startOfWeek, $endOfWeek])->get();

    $weekDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $dailyBreakdown = [];
    foreach ($weekDays as $index => $day) {
        $dayStart = $startOfWeek->copy()->addDays($index)->startOfDay();
        $dayEnd = $startOfWeek->copy()->addDays($index)->endOfDay();

        $dailyBreakdown[$day] = $weeklyPayments
            ->filter(fn($p) => Carbon::parse($p->payment_date)->between($dayStart, $dayEnd))
            ->sum('amount');
    }

    // Map unremitted payments for frontend
    $unremittedMarketPayment = $pendingPayments->map(fn($p) => [
        'id' => $p->id,
        'vendor_name' => $p->rented->application->vendor->fullname ?? 'Unknown',
        'payment_type' => $p->payment_type ?? 'Unknown',
        'stall_number' => $p->rented->stall->stall_number ?? null,
        'amount' => (float) $p->amount,
        'payment_date' => $p->payment_date,
        'advance_days' => $p->advance_days,
    ]);

    return response()->json([
        'date' => $today,
        'collector_name' => $collectorName, // <-- send collector name
        'actual_collection' => (float) $actualCollection,
        'pending_remittance' => (float) $pendingRemittance,
        'already_remitted' => $alreadyRemitted,
        'daily_breakdown' => $dailyBreakdown,
        'unremitted_market_payment' => $unremittedMarketPayment,
        'estimated_rent_collection' => [
            'total_amount' => (float) $estimatedCollection,
            'stall_count' => $stallCount,
        ],
    ]);
}




public function marketRemittanceReport($period)
{
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector || $collector->area !== 'market') {
        return response()->json([
            'message' => 'Unauthorized or not assigned to market.'
        ], 403);
    }

    $query = Remittance::with([
        'remittanceables.remittable.vendor',
        'remittanceables.remittable.rented.application.section',
        'remittanceables.remittable.rented.stall',
        'receivedBy',
        'remittedBy',
    ])->where('status', 'approved')
      ->whereHas('remittedBy', function ($q) {
          $q->where('area', 'market');
      });

    if ($period === 'daily') {
        $remittances = $query->whereBetween('remit_date', [now()->startOfWeek(), now()->endOfWeek()])->get();

        $weekDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $grouped = [];
        foreach ($weekDays as $day) {
            $grouped[$day] = [];
        }

        foreach ($remittances as $remit) {
            $dayOfWeek = Carbon::parse($remit->remit_date)->format('l');
            foreach ($remit->remittanceables as $r) {
                $payment = $r->remittable;
                if (!$payment) continue;

                $vendorName = $payment->vendor->fullname ?? 'N/A';
                if (!isset($grouped[$dayOfWeek][$vendorName])) {
                    $grouped[$dayOfWeek][$vendorName] = ['vendor' => $vendorName, 'payments' => [], 'total' => 0];
                }

                $grouped[$dayOfWeek][$vendorName]['payments'][] = [
                        'vendor' => $vendorName,
                    'remit_date' => $remit->remit_date,
                    'section' => $payment->rented->application->section->name ?? 'N/A',
                    'stall_number' => $payment->rented->stall->stall_number ?? 'N/A',
                    'amount' => $payment->amount,
                    'payment_date' => $payment->payment_date,
                    'collector' => $remit->remittedBy->fullname ?? 'N/A',
                    'approved_by' => $remit->receivedBy->fullname ?? 'N/A',
                ];

                $grouped[$dayOfWeek][$vendorName]['total'] += $payment->amount;
            }
        }

        // Convert inner arrays to values to reset keys
        foreach ($weekDays as $day) {
            $grouped[$day] = array_values($grouped[$day]);
        }

        return response()->json(['period' => $period, 'days' => $grouped]);
    }

   elseif ($period === 'weekly') {
    $startMonth = now()->startOfMonth(); // first day of month
    $endMonth = now()->endOfMonth();     // last day of month

    $remittances = $query->whereBetween('remit_date', [$startMonth, $endMonth])->get();

    $weeks = [];
    $weekNumber = 1;

    // Calculate weeks strictly within the month (Mon-Sun)
    $weekStart = $startMonth->copy();
    // Adjust first week start to first Monday of the month
    if ($weekStart->dayOfWeek !== Carbon::MONDAY) {
        $weekStart->modify('next monday');
    }

    while ($weekStart->lessThanOrEqualTo($endMonth)) {
        $weekEnd = $weekStart->copy()->modify('next sunday');
        if ($weekEnd->greaterThan($endMonth)) {
            $weekEnd = $endMonth->copy();
        }

        $weeks[$weekNumber] = [
            'week' => $weekNumber,
            'start_date' => $weekStart->toDateString(),
            'end_date' => $weekEnd->toDateString(),
            'vendors' => []
        ];

        $weekStart->addWeek(); // next week Monday
        $weekNumber++;
    }

    // Fill payments into correct weeks
    foreach ($remittances as $remit) {
        $date = Carbon::parse($remit->remit_date);
        foreach ($weeks as &$week) {
            if ($date->between(Carbon::parse($week['start_date']), Carbon::parse($week['end_date']))) {
                foreach ($remit->remittanceables as $r) {
                    $payment = $r->remittable;
                    if (!$payment) continue;

                    $vendorName = $payment->vendor->fullname ?? 'N/A';
                    if (!isset($week['vendors'][$vendorName])) {
                        $week['vendors'][$vendorName] = ['vendor'=>$vendorName,'payments'=>[],'total'=>0];
                    }

                    $week['vendors'][$vendorName]['payments'][] = [
                            'vendor' => $vendorName,
                        'remit_date' => $remit->remit_date,
                        'section' => $payment->rented->application->section->name ?? 'N/A',
                        'stall_number' => $payment->rented->stall->stall_number ?? 'N/A',
                        'amount' => $payment->amount,
                        'payment_date' => $payment->payment_date,
                        'collector' => $remit->remittedBy->fullname ?? 'N/A',
                        'approved_by' => $remit->receivedBy->fullname ?? 'N/A',
                    ];

                    $week['vendors'][$vendorName]['total'] += $payment->amount;
                }
            }
        }
    }

    // Reset keys
    foreach ($weeks as &$week) {
        $week['vendors'] = array_values($week['vendors']);
    }

    return response()->json(['period' => $period, 'weeks' => array_values($weeks)]);
}


    elseif ($period === 'monthly') {
        $remittances = $query->whereYear('remit_date', now()->year)->get();
        $monthsList = collect(range(1,12))->map(fn($m)=>Carbon::create()->month($m)->format('F'))->toArray();

        $months = [];
        foreach($monthsList as $monthName){
            $months[$monthName] = ['month'=>$monthName,'vendors'=>[]];
        }

        foreach($remittances as $remit){
            $monthName = Carbon::parse($remit->remit_date)->format('F');
            foreach($remit->remittanceables as $r){
                $payment = $r->remittable;
                if(!$payment) continue;

                $vendorName = $payment->vendor->fullname ?? 'N/A';
                if(!isset($months[$monthName]['vendors'][$vendorName])){
                    $months[$monthName]['vendors'][$vendorName] = ['vendor'=>$vendorName,'payments'=>[],'total'=>0];
                }

                $months[$monthName]['vendors'][$vendorName]['payments'][] = [
                        'vendor' => $vendorName,
                    'remit_date'=>$remit->remit_date,
                    'section'=>$payment->rented->application->section->name ?? 'N/A',
                    'stall_number'=>$payment->rented->stall->stall_number ?? 'N/A',
                    'amount'=>$payment->amount,
                    'payment_date'=>$payment->payment_date,
                    'collector'=>$remit->remittedBy->fullname ?? 'N/A',
                    'approved_by'=>$remit->receivedBy->fullname ?? 'N/A',
                ];

                $months[$monthName]['vendors'][$vendorName]['total'] += $payment->amount;
            }
        }

        foreach($months as &$month){
            $month['vendors'] = array_values($month['vendors']);
        }

        return response()->json(['period'=>$period,'months'=>array_values($months)]);
    }

    elseif ($period === 'yearly') {
        $yearsRange = [now()->year, now()->addYear()->year, now()->addYears(2)->year];
        $years = [];
        foreach($yearsRange as $y){
            $years[$y] = ['year'=>$y,'vendors'=>[]];
        }

        $remittances = $query->whereBetween('remit_date',[now()->copy()->startOfYear(), now()->copy()->addYears(2)->endOfYear()])->get();

        foreach($remittances as $remit){
            $year = Carbon::parse($remit->remit_date)->year;
            foreach($remit->remittanceables as $r){
                $payment = $r->remittable;
                if(!$payment) continue;

                $vendorName = $payment->vendor->fullname ?? 'N/A';
                if(!isset($years[$year]['vendors'][$vendorName])){
                    $years[$year]['vendors'][$vendorName] = ['vendor'=>$vendorName,'payments'=>[],'total'=>0];
                }

                $years[$year]['vendors'][$vendorName]['payments'][] = [
                        'vendor' => $vendorName,
                    'remit_date'=>$remit->remit_date,
                    'section'=>$payment->rented->application->section->name ?? 'N/A',
                    'stall_number'=>$payment->rented->stall->stall_number ?? 'N/A',
                    'amount'=>$payment->amount,
                    'payment_date'=>$payment->payment_date,
                    'collector'=>$remit->remittedBy->fullname ?? 'N/A',
                    'approved_by'=>$remit->receivedBy->fullname ?? 'N/A',
                ];

                $years[$year]['vendors'][$vendorName]['total'] += $payment->amount;
            }
        }

        foreach($years as &$year){
            $year['vendors'] = array_values($year['vendors']);
        }

        return response()->json(['period'=>$period,'years'=>array_values($years)]);
    }

    return response()->json(['message'=>'Invalid period'],400);
}




public function UnremittedPayments()
{
    // 1️⃣ Market / Vendor Payments (status = Collected only)
    $vendorPayments = Payments::with(['vendor', 'collector', 'rented.stall'])
        ->whereDoesntHave('remittances')
        ->where('status', 'collected') // 🔹 only collected
        ->orderBy('payment_date', 'desc') // 🔹 latest first
        ->get()
        ->groupBy(function ($p) {
            return $p->vendor_id . '|' . $p->payment_type . '|' . $p->payment_date;
        })
        ->map(function ($group) {
            $first = $group->first();
            $totalAmount = $group->sum('amount');
            $stallNumbers = $group->pluck('rented.stall.stall_number')->unique()->implode(', ');

            return [
                'id' => $first->id,
                'vendor_name' => $first->vendor->fullname ?? 'N/A',
                'stall_number' => $stallNumbers,
                'amount' => $totalAmount,
                'collection_date' => $first->payment_date,
                'collection_time' => $first->updated_at, // 🔹 use updated_at as time collected
                'payment_type' => $first->payment_type,
                'collector' => $first->collector->fullname ?? 'N/A',
                'status' => $first->status ?? 'Collected',
            ];
        })
        ->values();

    // 2️⃣ Slaughter Payments (status = Collected only)
    $slaughterPayments = SlaughterPayment::with(['collector', 'animal'])
        ->where('status', 'collected')
        ->orderBy('payment_date', 'desc')
        ->get()
        ->map(function ($p) {
            return [
                'id' => $p->id,
                'vendor_name' => $p->customer_name ?? 'N/A',
                'animal' => $p->animal->animal_type ?? 'N/A',
                'amount' => $p->total_amount,
                'collection_date' => $p->payment_date ?? $p->created_at,
                'collection_time' => $p->updated_at, // 🔹 time collected from updated_at
                'collector' => $p->collector->fullname ?? 'N/A',
                'status' => $p->status ?? 'Collected',
            ];
        });

    // 3️⃣ Wharf Payments (status = Collected only)
    $wharfPayments = Wharf::with(['collector'])
        ->where('status', 'collected')
        ->orderBy('payment_date', 'desc')
        ->get()
        ->map(function ($p) {
            return [
                'id' => $p->id,
                'vendor_name' => 'N/A',
                'amount' => $p->amount,
                'collection_date' => $p->payment_date ?? $p->created_at,
                'collection_time' => $p->updated_at, // 🔹 time collected from updated_at
                'collector' => $p->collector->fullname ?? 'N/A',
                'status' => $p->status ?? 'Collected',
            ];
        });

    // 4️⃣ MotorPool Payments (status = Collected only)
    $motorPoolPayments = MotorPool::with(['collector'])
        ->where('status', 'collected')
        ->orderBy('payment_date', 'desc')
        ->get()
        ->map(function ($p) {
            return [
                'id' => $p->id,
                'vendor_name' => 'N/A',
                'amount' => $p->amount,
                'collection_date' => $p->payment_date ?? $p->created_at,
                'collection_time' => $p->updated_at, // 🔹 time collected from updated_at
                'collector' => $p->collector->fullname ?? 'N/A',
                'status' => $p->status ?? 'Collected',
            ];
        });

    return response()->json([
        'market' => $vendorPayments,
        'slaughter' => $slaughterPayments,
        'wharf' => $wharfPayments,
        'motorpool' => $motorPoolPayments,
    ]);
}


public function stats(Request $request)
{
    $user = auth()->user(); // logged-in user

    // Get the collector record linked to this user
    $collector = InchargeCollector::where('user_id', $user->id)->first();

    if (!$collector) {
        return response()->json(['message' => 'Collector not found for this user'], 404);
    }

    $collectorId = $collector->id;

    // Define statuses to include in total collections
    $collectionStatuses = ['collected', 'remitted'];

    // Total Collections (all collected or remitted payments)
    $totalCollections = Payments::where('collector_id', $collectorId)
        ->whereIn('status', $collectionStatuses)
        ->sum('amount');

    $totalCollections += SlaughterPayment::where('collector_id', $collectorId)
        ->whereIn('status', $collectionStatuses)
        ->sum('total_amount');

    $totalCollections += Wharf::where('collector_id', $collectorId)
        ->whereIn('status', $collectionStatuses)
        ->sum('amount');

    $totalCollections += MotorPool::where('collector_id', $collectorId)
        ->whereIn('status', $collectionStatuses)
        ->sum('amount');

    // Today's Collections
    $today = now()->toDateString();
    $todaysCollections = Payments::where('collector_id', $collectorId)
        ->whereIn('status', $collectionStatuses)
        ->whereDate('updated_at', $today)
        ->sum('amount');

    $todaysCollections += SlaughterPayment::where('collector_id', $collectorId)
        ->whereIn('status', $collectionStatuses)
        ->whereDate('updated_at', $today)
        ->sum('total_amount');

    $todaysCollections += Wharf::where('collector_id', $collectorId)
        ->whereIn('status', $collectionStatuses)
        ->whereDate('updated_at', $today)
        ->sum('amount');

    $todaysCollections += MotorPool::where('collector_id', $collectorId)
        ->whereIn('status', $collectionStatuses)
        ->whereDate('updated_at', $today)
        ->sum('amount');

    // Pending Advances (only if assigned_area = 'market')
    $pendingAdvances = 0;
 
        $pendingAdvances = Payments::
            where('payment_type', 'advance')
            ->where('status', 'pending')
            ->sum('amount');
    

    return response()->json([
        'totalCollections' => $totalCollections,
        'todaysCollections' => $todaysCollections,
        'pendingAdvances' => $pendingAdvances,
    ]);
}




}

