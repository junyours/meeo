<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Wharf;
use App\Models\Rented;
use App\Models\Payments;
use App\Models\MotorPool;
use App\Models\Remittance;
use Illuminate\Http\Request;
use App\Models\MainCollector;
use App\Models\SlaughterPayment;
use App\Models\InchargeCollector;

class MainCollectorController extends Controller
{

      public function show(Request $request)
    {
        $user = $request->user();
        $profile = MainCollector::where('user_id', $user->id)->first();

        return response()->json($profile, 200);
    }


    // POST create or update profile
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'fullname' => 'required|string|max:255',
            'age' => 'required|string|max:10',
            'gender' => 'required|string|max:20',
            'contact_number' => 'required|string|max:20',
            'emergency_contact' => 'required|string|max:20',
            'address' => 'required|string|max:255',
        ]);

        $profile = MainCollector::updateOrCreate(
            ['user_id' => $user->id],
            [
                'fullname' => $validated['fullname'],
                'age' => $validated['age'],
                'gender' => $validated['gender'],
                'contact_number' => $validated['contact_number'],
                'emergency_contact' => $validated['emergency_contact'],
                'address' => $validated['address'],
                'Status' => 'pending', 
            ]
        );

        return response()->json($profile, 201);
    }


     public function assignArea(Request $request, $id)
    {
        $validated = $request->validate([
            'area' => 'required|string|max:255',
        ]);

        $profile = MainCollector::findOrFail($id);

        if ($profile->Status !== 'approved') {
            return response()->json([
                'message' => 'Cannot assign area unless profile is approved.'
            ], 400);
        }

        $profile->area = $validated['area'];
        $profile->save();

        return response()->json([
            'message' => "Area '{$validated['area']}' assigned successfully.",
            'profile' => $profile,
        ], 200);
    }


    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $profile = MainCollector::findOrFail($id);
        $profile->status = $validated['status'];
        $profile->save();

        return response()->json([
            'message' => "Profile status updated to {$validated['status']}",
            'profile' => $profile,
        ], 200);
    }

    
    public function index()
    {
        $profiles = MainCollector::all();
        return response()->json($profiles, 200);
    }


    public function remitCollectionByType(Request $request)
{
    $request->validate([
        'remitted_by' => 'required|exists:incharge_collectors,id',
        'remit_type' => 'required|in:daily,monthly',
        'received_by' => 'required|exists:main_collectors,id',
    ]);

    $remittedBy = $request->remitted_by;
    $receivedBy = $request->received_by;
    $remitType = $request->remit_type;
    $today = now()->toDateString();

    // Fetch today’s payments
    $paymentsToday = Payments::with('rented.application')
        ->whereDate('payment_date', $today)
        ->get();

    $amount = 0;
    foreach ($paymentsToday as $payment) {
        $paymentType = $payment->rented->application->payment_type ?? 'daily';
        if ($paymentType === $remitType) {
            $amount += $payment->amount;
        }
    }

    if ($amount <= 0) {
        return response()->json([
            'success' => false,
            'message' => "No {$remitType} collection available to remit today.",
        ]);
    }

    // Prevent duplicate remittance for this type today
    $exists = Remittance::where('remitted_by', $remittedBy)
        ->where('remit_date', $today)
        ->where('remit_type', $remitType)
        ->first();

    if ($exists) {
        return response()->json([
            'success' => false,
            'message' => "You have already remitted the {$remitType} collection today.",
        ]);
    }

    $remittance = Remittance::create([
        'remit_date' => $today,
        'amount' => $amount,
        'remit_type' => $remitType,
        'remitted_by' => $remittedBy,
        'received_by' => $receivedBy,
    ]);

    return response()->json([
        'success' => true,
        'message' => ucfirst($remitType) . " collection remitted successfully!",
        'remittance' => $remittance,
        'amount' => $amount,
    ]);
}


public function mainCollectorsByArea($area)
{
    // Fetch main collectors assigned to this area and approved status
    $mainCollectors = MainCollector::where('area', $area)
        ->where('Status', 'approved') // only approved collectors
        ->get(['user_id', 'fullname']); // return minimal info for dropdown

    return response()->json($mainCollectors);
}

public function MainCollectorReport()
{
    $today = Carbon::today();

    // 1. Daily Estimated Collections
    $slaughterEstimated = SlaughterPayment::whereDate('payment_date', $today)->sum('total_amount');
    $marketEstimated = Rented::sum('daily_rent');
    $wharfEstimated = Wharf::whereDate('payment_date', $today)->sum('amount');
    $motorpoolEstimated = MotorPool::whereDate('payment_date', $today)->sum('amount');

    // 2. Actual Remitted Amounts
    $slaughterRemitted = SlaughterPayment::whereDate('payment_date', $today)
        ->where('is_remitted', 1)
        ->sum('total_amount');
    $marketRemitted = Payments::whereDate('payment_date', $today)
        ->where('status', 'remitted')
        ->sum('amount');
    $wharfRemitted = Wharf::whereDate('payment_date', $today)
        ->where('status', 'remitted')
        ->sum('amount');
    $motorpoolRemitted = MotorPool::whereDate('payment_date', $today)
        ->where('status', 'remitted')
        ->sum('amount');

    // 3. Pending Remittances
    $pendingRemittances = Remittance::with('remittedBy')
        ->where('status', 'pending')
        ->get();

    // 4. Collector Performance
    $collectors = InchargeCollector::with(['payments' => function($q) use ($today) {
        $q->whereDate('payment_date', $today)
          ->where('status', 'remitted');
    }])->get()->filter(fn($collector) => $collector->payments->count() > 0)
      ->map(fn($collector) => [
          'id' => $collector->id,
          'fullname' => $collector->fullname,
          'area' => $collector->area,
          'remitted' => $collector->payments->sum('amount'),
      ]);

    // 5. Remittance History (last 7 days)
    $remittanceHistory = Remittance::with('remittedBy')
        ->whereDate('created_at', '>=', $today->copy()->subDays(7))
        ->get();

    // 6. Remittance Summary
    $remittanceSummary = [
        'pending' => Remittance::where('status', 'pending')->sum('amount'),
        'approved' => Remittance::where('status', 'approved')->sum('amount'),
        'declined' => Remittance::where('status', 'declined')->sum('amount'),
    ];

    // 7. Totals for Dashboard Cards (Estimated vs Remitted)
    $totals = [
        'wharf' => [
            'estimated' => $wharfEstimated,
            'remitted'  => $wharfRemitted,
        ],
        'motorpool' => [
            'estimated' => $motorpoolEstimated,
            'remitted'  => $motorpoolRemitted,
        ],
        'market' => [
            'estimated' => $marketEstimated,
            'remitted'  => $marketRemitted,
        ],
        'slaughter' => [
            'estimated' => $slaughterEstimated,
            'remitted'  => $slaughterRemitted,
        ],
        'totalApproved' => $remittanceSummary['approved'],
        'totalPending' => $remittanceSummary['pending'],
    ];

    return response()->json([
        'totals' => $totals,
        'collectors' => $collectors,
        'remittanceSummary' => $remittanceSummary,
        'data' => [
            'pending' => $pendingRemittances,
            'history' => $remittanceHistory,
        ],
    ]);
}



public function collectors()
{
    $today = Carbon::today();
    $dueTime = Carbon::today()->setHour(17)->setMinute(0)->setSecond(0); // 5:00 PM today
    $weekAgo = $today->copy()->subDays(7);

    $collectors = InchargeCollector::with(['payments' => function($q) use ($weekAgo, $today) {
        $q->whereBetween('payment_date', [$weekAgo, $today])
          ->with(['vendor', 'rented.application', 'rented.stall', 'remittanceables.remittance']);
    }])->get()
    ->filter(fn($collector) => $collector->payments->count() > 0)
    ->map(function($collector) use ($dueTime) {

        $paymentsGrouped = $collector->payments->groupBy(fn($payment) => $payment->payment_date->format('Y-m-d'))
            ->map(function($payments, $date) use ($dueTime) {

                $totalAmount = $payments->sum('amount');
                $totalDailyRent = $payments->sum(fn($p) => optional($p->rented)->daily_rent ?? 0);

                $paymentsDetails = $payments->map(fn($p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'type' => $p->payment_type,
                    'stall_number' => optional($p->rented->stall)->stall_number,
                    'vendor_name' => optional($p->vendor)->fullname,
                    'daily_rent' => optional($p->rented)->daily_rent ?? 0,
                    'payment_date' => $p->payment_date,
                    'remitted_by' => optional($p->remittanceables->first()->remittance->remittedBy)->fullname ?? null,
                    'received_by' => optional($p->remittanceables->first()->remittance->receivedBy)->fullname ?? null,
                    'remittance_status' => optional($p->remittanceables->first()->remittance->status),
                ])->values();

                // Overdue if today and not remitted before 5:00 PM
                $isOverdue = $payments->some(function($p) use ($dueTime) {
                    $remittance = optional($p->remittanceables->first()->remittance);
                    return Carbon::parse($p->payment_date)->isToday() && 
                           (!$remittance || Carbon::parse($remittance->remitted_at)->gt($dueTime));
                });

                return [
                    'date' => $date,
                    'total' => $totalAmount,
                    'total_daily_rent' => $totalDailyRent,
                    'payments' => $paymentsDetails,
                    'is_overdue' => $isOverdue,
                ];
            })->values();

        return [
            'id' => $collector->id,
            'fullname' => $collector->fullname,
            'area' => $collector->area,
            'payments_grouped' => $paymentsGrouped,
        ];
    });

    return response()->json($collectors);
}
    
}
