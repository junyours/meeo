<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Animals;
use App\Models\Remittance;
use Illuminate\Http\Request;
use App\Models\MeatInspector;
use App\Models\InspectionRecord;
use App\Models\SlaughterPayment;
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class SlaughterPaymentController extends Controller
{
    /**
     * Display all payments
     */

public function getUnremittedPayments()
{
    // Get logged-in user
    $user = auth()->user();

    // Find collector associated with the user
    $collector = InchargeCollector::where('user_id', $user->id)->first();

    if (!$collector) {
        return response()->json(['message' => 'Collector not found'], 404);
    }

    // Fetch unremitted slaughter payments for this collector
    $payments = SlaughterPayment::with(['animal', 'customer', 'inspector', 'collector'])
        ->where('status', 'collected')
        ->where('is_remitted', 0)               // only unremitted
        ->where('collector_id', $collector->id) // only this collector
        ->get();

    return response()->json($payments);
}

public function getCustomerPayments($customerId)
{
    $payments = SlaughterPayment::with('animal')
        ->where('customer_id', $customerId)
        ->orderBy('updated_at', 'desc')
        
        ->get();

    return response()->json($payments);
}


public function index()
{
    // Get pending payments with related models
    $payments = SlaughterPayment::with(['animal', 'collector', 'customer'])
        ->where('status', 'pending_collection')
        ->whereHas('customer.inspectionRecords', function ($query) {
            $query->where('inspection_type', 'post-mortem')
                  ->where('health_status', 'Healthy')
                  ->whereColumn('animal_id', 'slaughter_payment.animals_id'); // match animal
        })
        ->get();

    // Check if the logged-in collector has already remitted today
    $collector = InchargeCollector::where('user_id', auth()->id())->first();
    $alreadyRemitted = false;

    if ($collector) {
        $alreadyRemitted = Remittance::where('remitted_by', $collector->id)
            ->whereDate('remit_date', now()->toDateString())
            ->where('status', 'pending') // or 'approved', depending on your workflow
            ->exists();
    }

    return response()->json([
        'payments' => $payments,
        'already_remitted' => $alreadyRemitted,
    ]);
}


    /**
     * Store a new payment
     */
public function store(Request $request)
{
    $validated = $request->validate([
        'animals_id'   => 'required|exists:animals,id',
        'quantity'     => 'required|integer|min:1',
        'total_kilos'  => 'required|numeric|min:0',
        'per_kilos'    => 'required|array',
        'per_kilos.*'  => 'numeric|min:0',
        'customer_id'  => 'required|exists:customer_details,id',
    ]);

    $user = $request->user();
    $inspector = MeatInspector::where('user_id', $user->id)->first();

    if (!$inspector) {
        return response()->json([
            'message' => 'Inspector profile not found for this user.'
        ], 403);
    }

    $animal     = Animals::findOrFail($validated['animals_id']);
    $quantity   = $validated['quantity'];
    $totalKilos = $validated['total_kilos'];
    $perKilos   = $validated['per_kilos'];

    if (count($perKilos) !== $quantity) {
        return response()->json([
            'message' => 'Mismatch between quantity and per-kilo entries.'
        ], 422);
    }

    // 🐄 Fee computations
    $ante_mortem   = $quantity * $animal->ante_mortem_rate;
    $post_mortem   = $totalKilos * $animal->post_mortem_rate;
    $coral_fee     = $quantity * $animal->coral_fee_rate;
    $permit_to_slh = $quantity * $animal->permit_to_slh_rate;

    $baseFee       = 0;
    $slaughter_fee = 0;
    $excessFee     = 0;
    $excessDetails = [];

    if ($animal->fixed_rate > 0) {
        $baseFee = $quantity * $animal->fixed_rate;

        if ($animal->excess_kilo_limit > 0) {
            foreach ($perKilos as $index => $kilo) {
                if ($kilo > $animal->excess_kilo_limit) {
                    $excess = ($kilo - $animal->excess_kilo_limit) * $animal->slaughter_fee_rate;
                    $excessFee += $excess;

                    $excessDetails[] = [
                        'animal_index' => $index + 1,
                        'kilos'        => $kilo,
                        'excess_kilos' => $kilo - $animal->excess_kilo_limit,
                        'excess_fee'   => $excess,
                    ];
                }
            }
            $slaughter_fee = $excessFee;
        } else {
            $slaughter_fee = $quantity * $animal->slaughter_fee_rate;
        }
    } else {
        $slaughter_fee = $quantity * $animal->slaughter_fee_rate;
    }

    $total_amount = $ante_mortem + $post_mortem + $coral_fee + $permit_to_slh + $baseFee + $slaughter_fee;

    $payment = SlaughterPayment::create([
        'animals_id'   => $validated['animals_id'],
        'customer_id'  => $validated['customer_id'],
        'quantity'     => $quantity,
        'total_kilos'  => $totalKilos,
        'per_kilos'    => $perKilos,
        'slaughter_fee'=> $slaughter_fee,
        'ante_mortem'  => $ante_mortem,
        'post_mortem'  => $post_mortem,
        'permit_to_slh'=> $permit_to_slh,
        'coral_fee'    => $coral_fee,
        'total_amount' => $total_amount,
        'collector_id' => null,
        'inspector_id' => $inspector->id,
    ]);

    return response()->json([
        'message'        => 'Slaughter payment created successfully!',
        'payment'        => $payment->load('animal', 'inspector', 'customer'),
        'excess_details' => $excessDetails,
    ], 201);
}


public function pendingCollections()
{
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector || $collector->area !== 'slaughter') {
        return response()->json(['message' => 'Unauthorized or not assigned to slaughter.'], 403);
    }

    // Step 1: get all healthy post-mortem inspections
    $healthyInspections = InspectionRecord::where('inspection_type', 'post-mortem')
        ->where('health_status', 'healthy')
        ->get();

    $payments = collect();

    foreach ($healthyInspections as $inspection) {
        // Step 2: find the corresponding SlaughterPayment for the same customer
        $payment = SlaughterPayment::with(['animal', 'customer'])
            ->where('customer_id', $inspection->customer_id)
            ->whereNull('collector_id') // only uncollected
            ->whereDoesntHave('remittanceables') // not remitted
            ->first();

        if ($payment) {
            $payments->push($payment);
        }
    }

    return response()->json($payments->values());
}
    /**
     * Show a single payment
     */
    public function show($id)
    {
        $payment = SlaughterPayment::with('animal')->findOrFail($id);
        return response()->json($payment);
    }

    /**
     * Delete a payment
     */
    public function destroy($id)
    {
        $payment = SlaughterPayment::findOrFail($id);
        $payment->delete();

        return response()->json(['message' => 'Slaughter payment deleted successfully!']);
    }





    /**
     * Slaughter Collection Summary (Daily + Weekly Breakdown)
     */
public function slaughterRemittanceReport($period)
{
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector || $collector->area !== 'slaughter') {
        return response()->json(['message' => 'Unauthorized or not assigned to slaughter.'], 403);
    }

    $query = Remittance::with([
        'remittanceables.remittable.animal',
        'remittanceables.remittable.customer',
        'remittedBy',
        'receivedBy',
    ])
    ->where('status', 'approved')
    ->whereHas('remittedBy', fn($q) => $q->where('area', 'slaughter'));

    $processPayments = function($remittances) {
        $animalTotals = [];
        $collectorPerformance = [];
        $groupedData = [];

        foreach ($remittances as $remit) {
            $collectorName = $remit->remittedBy->fullname ?? 'N/A';

            foreach ($remit->remittanceables as $item) {
                $payment = $item->remittable;
                if (!$payment) continue;

                $dayOrLabel = Carbon::parse($remit->remit_date)->format('l');

                if (!isset($collectorPerformance[$collectorName])) {
                    $collectorPerformance[$collectorName] = ['collections'=>0,'total_time_seconds'=>0];
                }
                $collectorPerformance[$collectorName]['collections'] += 1;
                if ($payment->payment_date && $remit->remit_date) {
                    $timeDiff = Carbon::parse($remit->remit_date)->diffInSeconds(Carbon::parse($payment->payment_date));
                    $collectorPerformance[$collectorName]['total_time_seconds'] += $timeDiff;
                }

                $animalType = $payment->animal->animal_type ?? 'N/A';
                $animalTotals[$animalType] = ($animalTotals[$animalType] ?? 0) + $payment->total_amount;

                $groupedData[$dayOrLabel]['payments'][] = [
                    'remit_date' => $remit->remit_date,
                    'animal' => ['animal_type' => $payment->animal->animal_type ?? 'N/A'],
                    'customer' => ['fullname' => $payment->customer->fullname ?? 'N/A'],
                    'total_kilos' => $payment->total_kilos ?? 0,
                    'per_kilo' => $payment->per_kilos ?? 0,
                    'ante_mortem_fee' => $payment->ante_mortem ?? 0,
                    'post_mortem_fee' => $payment->post_mortem ?? 0,
                    'coral_fee' => $payment->coral_fee ?? 0,
                    'permit_fee' => $payment->permit_to_slh ?? 0,
                    'slaughter_fee' => $payment->slaughter_fee ?? 0,
                    'total_amount' => $payment->total_amount ?? 0,
                    'collected_by' => $collectorName,
                    'received_by' => $remit->receivedBy->fullname ?? 'N/A',
                    'status' => 'collected',
                    'payment_date' => $payment->payment_date,
                ];

                $groupedData[$dayOrLabel]['total'] = ($groupedData[$dayOrLabel]['total'] ?? 0) + ($payment->total_amount ?? 0);
            }
        }

        foreach ($collectorPerformance as $name => $data) {
            $avg = $data['collections'] ? $data['total_time_seconds'] / $data['collections'] : 0;
            $collectorPerformance[$name]['avg_time_seconds'] = round($avg);
            unset($collectorPerformance[$name]['total_time_seconds']);
        }

        return [$groupedData, $animalTotals, $collectorPerformance];
    };

    // === Period handling ===
    $periods = ['daily','weekly','monthly','yearly'];
    if(!in_array($period, $periods)) return response()->json(['message'=>'Invalid period'],400);

    [$grouped, $animalTotals, $collectorPerformance] = $processPayments($query->get());

    if ($period === 'daily') {
        $daysOfWeek = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $groupedWithDays = array_fill_keys($daysOfWeek, ['payments'=>[], 'total'=>0]);
        foreach ($daysOfWeek as $day) {
            if(isset($grouped[$day])) $groupedWithDays[$day] = $grouped[$day];
        }
        return response()->json([
            'period'=>$period,
            'days'=>$groupedWithDays,
            'animalData'=>$animalTotals,
            'collectorPerformance'=>$collectorPerformance,
        ]);
    }

    elseif ($period === 'weekly') {
        $startMonth = now()->startOfMonth();
        $endMonth = now()->endOfMonth();
        $weeks = [];
        $weekIndex = 1;
        $currentWeekStart = $startMonth->copy()->startOfWeek(Carbon::MONDAY);
        while ($currentWeekStart->lte($endMonth)) {
            $currentWeekEnd = $currentWeekStart->copy()->endOfWeek(Carbon::SUNDAY);
            $weeks[$weekIndex] = ['week'=>$weekIndex,'start_date'=>$currentWeekStart->toDateString(),'end_date'=>$currentWeekEnd->toDateString(),'payments'=>[],'total'=>0];
            $weekIndex++;
            $currentWeekStart->addWeek();
        }

        foreach ($grouped as $day => $data) {
            if(!empty($data['payments'])) {
                $date = Carbon::parse($data['payments'][0]['payment_date']);
                $weekNumber = $date->weekOfMonth;
                if(isset($weeks[$weekNumber])) {
                    $weeks[$weekNumber]['payments'] = array_merge($weeks[$weekNumber]['payments'],$data['payments']);
                    $weeks[$weekNumber]['total'] += $data['total'] ?? 0;
                }
            }
        }

        return response()->json([
            'period'=>$period,
            'weeks'=>array_values($weeks),
            'animalData'=>$animalTotals,
            'collectorPerformance'=>$collectorPerformance,
        ]);
    }

    elseif ($period === 'monthly') {
        $months = [];
        foreach ($grouped as $day => $data) {
            if(!empty($data['payments'])) {
                $monthName = Carbon::parse($data['payments'][0]['payment_date'])->format('F');
                if(!isset($months[$monthName])) $months[$monthName] = ['month'=>$monthName,'payments'=>[],'total'=>0];
                $months[$monthName]['payments'] = array_merge($months[$monthName]['payments'],$data['payments']);
                $months[$monthName]['total'] += $data['total'] ?? 0;
            }
        }
        $ordered = collect($months)->sortBy(fn($item)=>\DateTime::createFromFormat('F',$item['month'])->format('n'))->values()->all();

        return response()->json([
            'period'=>$period,
            'months'=>$ordered,
            'animalData'=>$animalTotals,
            'collectorPerformance'=>$collectorPerformance,
        ]);
    }

    elseif ($period === 'yearly') {
        $years = [];
        foreach ($grouped as $day => $data) {
            if(!empty($data['payments'])) {
                $yr = Carbon::parse($data['payments'][0]['payment_date'])->year;
                if(!isset($years[$yr])) $years[$yr] = ['year'=>$yr,'payments'=>[],'total'=>0];
                $years[$yr]['payments'] = array_merge($years[$yr]['payments'],$data['payments']);
                $years[$yr]['total'] += $data['total'] ?? 0;
            }
        }
        krsort($years);

        return response()->json([
            'period'=>$period,
            'years'=>array_values($years),
            'animalData'=>$animalTotals,
            'collectorPerformance'=>$collectorPerformance,
        ]);
    }
}



public function slaughterCollectionSummary(Request $request)
{
    $today = Carbon::now()->toDateString();
    $startOfWeek = Carbon::now()->startOfWeek(); // Monday
    $endOfWeek = Carbon::now()->endOfWeek();     // Sunday

    $collector = InchargeCollector::where('user_id', Auth::id())->first();
    if (!$collector) {
        return response()->json(['message' => 'Collector not found.'], 404);
    }

    // Today's actual collection
    $todayPaymentsQuery = SlaughterPayment::where('collector_id', $collector->id)
        ->whereDate('payment_date', $today)
        ->where('status', '!=', 'cancelled');

    $todayCollection = $todayPaymentsQuery->sum('total_amount');
    $todayAnimals = $todayPaymentsQuery->sum('quantity');

    // Weekly breakdown (group by day)
    $weeklyPayments = SlaughterPayment::select(
            DB::raw('DAYNAME(payment_date) as day'),
            DB::raw('SUM(total_amount) as total')
        )
        ->where('collector_id', $collector->id)
        ->whereBetween('payment_date', [$startOfWeek, $endOfWeek])
        ->where('status', '!=', 'cancelled')
        ->groupBy(DB::raw('DAYNAME(payment_date)'))
        ->get()
        ->pluck('total', 'day')
        ->toArray();

    // Make sure all days exist in the array
    $weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $dailyBreakdown = [];
    $dailyDates = [];
    $weeklyTotal = 0;

    foreach ($weekDays as $idx => $day) {
        $dailyBreakdown[$day] = isset($weeklyPayments[$day]) ? (float)$weeklyPayments[$day] : 0;
        $weeklyTotal += $dailyBreakdown[$day];

        // Map day name to actual date
        $dailyDates[$day] = $startOfWeek->copy()->addDays($idx)->toDateString();
    }

    // Get unremitted payments
    $unremittedPayments = SlaughterPayment::where('collector_id', $collector->id)
        ->where('is_remitted', false)
        ->where('status', '!=', 'remitted')
        ->whereDate('payment_date', $today)
        ->get(['id', 'total_amount', 'payment_date', 'quantity']);

    return response()->json([
        'actual_collection' => (float)$todayCollection,
        'today_animals' => (int)$todayAnimals,
        'daily_breakdown' => $dailyBreakdown,
        'daily_dates' => $dailyDates, // <-- added this
        'weekly_total' => $weeklyTotal,
        'unremitted_payments' => $unremittedPayments,
    ]);
}


public function collect(Request $request, $id)
{
    // Get logged-in user
    $user = $request->user();

    // Get the incharge collector profile for this user
    $collector = InchargeCollector::where('user_id', $user->id)->first();

    if (!$collector || $collector->Status !== 'approved' || $collector->area !== 'slaughter') {
        return response()->json(['message' => 'Unauthorized or not assigned to slaughter.'], 403);
    }

    // Get the payment
    $payment = SlaughterPayment::findOrFail($id);

    if ($payment->status !== 'pending_collection') {
        return response()->json(['message' => 'Payment already collected.'], 409);
    }

    // ✅ Assign the collector and mark as collected
    $payment->update([
        'status' => 'collected',
        'payment_date' => now(),
        'collector_id' => $collector->id,
    ]);

    return response()->json([
        'message' => 'Payment collected successfully.',
        'payment' => $payment->load('collector', 'animal', 'inspector'),
    ]);
}

public function getAnalytics()
{
    $today = now()->toDateString();
    $startOfMonth = now()->startOfMonth();
    $endOfMonth = now()->endOfMonth();

    // 1. Daily Collection Today (only collected or remitted)
    $dailyTotal = SlaughterPayment::whereDate('updated_at', $today)
        ->whereIn('status', ['collected', 'remitted'])
        ->sum('total_amount');
    $dailyTotal = $dailyTotal ?? 0;

    // 2. Weekly Breakdown (4 weeks of current month)
    $weeklyBreakdown = [];
    for ($week = 0; $week < 4; $week++) {
        $weekStart = $startOfMonth->copy()->addDays($week * 7);
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

        // Avoid going past the end of the month
        if ($weekEnd->gt($endOfMonth)) {
            $weekEnd = $endOfMonth->copy()->endOfDay();
        }

        $total = SlaughterPayment::whereBetween('created_at', [$weekStart, $weekEnd])
            ->whereIn('status', ['collected', 'remitted'])
            ->sum('total_amount');

        $weeklyBreakdown["Week " . ($week + 1)] = (float)$total;
    }

    // 3. Monthly Breakdown by day
    $monthlyPayments = SlaughterPayment::selectRaw('DATE(created_at) as date, SUM(total_amount) as total')
        ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
        ->whereIn('status', ['collected', 'remitted'])
        ->groupBy('date')
        ->get()
        ->keyBy('date');

    $monthlyBreakdown = [];
    $daysInMonth = now()->daysInMonth;
    for ($i = 0; $i < $daysInMonth; $i++) {
        $date = $startOfMonth->copy()->addDays($i)->toDateString();
        $monthlyBreakdown[$date] = isset($monthlyPayments[$date]) ? (float)$monthlyPayments[$date]->total : 0;
    }

    // 4. Top animals by revenue (all time)
    $topAnimals = SlaughterPayment::select('animals_id', DB::raw('SUM(total_amount) as total_revenue'))
        ->whereIn('status', ['collected', 'remitted'])
        ->groupBy('animals_id')
        ->orderByDesc('total_revenue')
        ->with('animal')
        ->take(5)
        ->get();

    return response()->json([
        'daily_total' => (float)$dailyTotal,
        'weekly_breakdown' => $weeklyBreakdown,
        'monthly_breakdown' => $monthlyBreakdown,
        'top_animals' => $topAnimals->map(function ($item) {
            $item->total_revenue = (float)$item->total_revenue;
            return $item;
        }),
    ]);
}



public function inspectorPaymentReport($period)
{
    $user = auth()->user();
    $inspector = MeatInspector::where('user_id', $user->id)->first();

    if (!$inspector) {
        return response()->json(['message' => 'Inspector profile not found.'], 403);
    }

    $query = SlaughterPayment::with('customer', 'animal') // eager load customer & animal
        ->where('inspector_id', $inspector->id)
        ->where('is_remitted', true); // filter only remitted payments

    $total = 0;
    $labels = [];
    $data = [];
    $details = [];

    // Fetch payments based on period
    $payments = match($period) {
        'daily' => $query->whereBetween('updated_at', [now()->startOfWeek(), now()->endOfWeek()])->get(),
        'weekly' => $query->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])->get(),
        'monthly' => $query->whereYear('updated_at', now()->year)->get(),
        'yearly' => $query->get(),
        default => [],
    };

    if ($period === 'daily') {
        $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $breakdown = array_fill_keys($days, 0);

        foreach ($payments as $payment) {
            $day = Carbon::parse($payment->updated_at)->format('l');
            $breakdown[$day] += $payment->total_amount;
            $total += $payment->total_amount;

            $details[] = [
                'customer_name' => $payment->customer?->fullname ?? 'N/A',
                'date' => Carbon::parse($payment->updated_at)->format('Y-m-d'),
                'animal_type' => $payment->animal?->animal_type ?? '-',
                'total_kilos' => round((float)$payment->total_kilos, 2),
                'per_kilos' => $payment->per_kilos ?? [],
                'total_amount' => round((float)$payment->total_amount, 2),
            ];
        }

        $labels = array_keys($breakdown);
        $data = array_map(fn($val) => round((float)$val,2), array_values($breakdown));
    }

    if ($period === 'weekly') {
        $weeks = ['Week 1'=>0,'Week 2'=>0,'Week 3'=>0,'Week 4'=>0];

        foreach ($payments as $payment) {
            $week = ceil(Carbon::parse($payment->updated_at)->day / 7);
            $weeks['Week '.$week] += $payment->total_amount;
            $total += $payment->total_amount;

            $details[] = [
                'customer_name' => $payment->customer?->fullname ?? 'N/A',
                'date' => Carbon::parse($payment->updated_at)->format('Y-m-d'),
                'animal_type' => $payment->animal?->animal_type ?? '-',
                'total_kilos' => round((float)$payment->total_kilos, 2),
                'per_kilos' => $payment->per_kilos ?? [],
                'total_amount' => round((float)$payment->total_amount, 2),
            ];
        }

        $labels = array_keys($weeks);
        $data = array_map(fn($val) => round((float)$val,2), array_values($weeks));
    }

    if ($period === 'monthly') {
        $months = [];
        for ($m=1;$m<=12;$m++) {
            $months[Carbon::create()->month($m)->format('F')] = 0;
        }

        foreach ($payments as $payment) {
            $monthName = Carbon::parse($payment->created_at)->format('F');
            $months[$monthName] += $payment->total_amount;
            $total += $payment->total_amount;

            $details[] = [
                'customer_name' => $payment->customer?->fullname ?? 'N/A',
                'date' => Carbon::parse($payment->updated_at)->format('Y-m-d'),
                'animal_type' => $payment->animal?->animal_type ?? '-',
                'total_kilos' => round((float)$payment->total_kilos, 2),
                'per_kilos' => $payment->per_kilos ?? [],
                'total_amount' => round((float)$payment->total_amount, 2),
            ];
        }

        $labels = array_keys($months);
        $data = array_map(fn($val) => round((float)$val,2), array_values($months));
    }

    if ($period === 'yearly') {
        $years = [];
        foreach ($payments as $payment) {
            $yr = Carbon::parse($payment->updated_at)->format('Y');
            if(!isset($years[$yr])) $years[$yr] = 0;
            $years[$yr] += $payment->total_amount;
            $total += $payment->total_amount;

            $details[] = [
                'customer_name' => $payment->customer?->fullname ?? 'N/A',
                'date' => Carbon::parse($payment->updated_at)->format('Y-m-d'),
                'animal_type' => $payment->animal?->animal_type ?? '-',
                'total_kilos' => round((float)$payment->total_kilos, 2),
                'per_kilos' => $payment->per_kilos ?? [],
                'total_amount' => round((float)$payment->total_amount, 2),
            ];
        }

        $labels = array_keys($years);
        $data = array_map(fn($val) => round((float)$val,2), array_values($years));
    }

    return response()->json([
        'period' => $period,
        'labels' => $labels,
        'data' => $data,
        'total' => round((float)$total,2),
        'details' => $details,
    ]);
}


public function checkUnremitted(Request $request)
{
    $user = $request->user();
    $collector = InchargeCollector::where('user_id', $user->id)->first();

    if (!$collector) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $today = Carbon::today();

    $count = SlaughterPayment::where('collector_id', $collector->id)
        ->where('status', 'collected')
        ->where('is_remitted', false)
        ->whereDate('created_at', $today) // only payments created today
        ->count();

    return response()->json(['unremitted_count' => $count]);
}

  
    
}
