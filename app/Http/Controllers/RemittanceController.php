<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Wharf;
use App\Models\Payments;
use App\Models\MotorPool;
use App\Models\Remittance;

use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\MainCollector;
use App\Models\Remittanceable;
use App\Models\SlaughterPayment;
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class RemittanceController extends Controller
{


    // Slaughter details
public function slaughterDetails($id) {
    $remittance = Remittance::with(['remittanceables.remittable'])->findOrFail($id);
    $entries = $remittance->remittanceables
          ->filter(fn($r) => $r->remittable_type === SlaughterPayment::class)
        ->map(fn($r) => [
            'animal_type' => $r->remittable->animal->animal_type ?? 'N/A',
            'customer_name' => $r->remittable->customer->fullname ?? 'N/A',
            'collector' => $r->remittable->collector->fullname ?? 'N/A',
            'received_by' => $r->remittance->receivedBy->fullname ?? 'N/A',
            'amount' => (float)$r->remittable->total_amount,
            'breakdown' => [
                'slaughter_fee' => $r->remittable->slaughter_fee,
                'ante_mortem' => $r->remittable->ante_mortem,
                'post_mortem' => $r->remittable->post_mortem,
                'coral_fee' => $r->remittable->coral_fee,
                'permit_to_slh' => $r->remittable->permit_to_slh,
                'quantity' => $r->remittable->quantity,
                'total_kilos' => $r->remittable->total_kilos,
                'per_kilos' => $r->remittable->per_kilos,
            ],
        ]);
    return response()->json($entries);
}

// Market details
public function marketDetails($id) {
    $remittance = Remittance::with(['remittanceables.remittable.rented.application.vendor', 'remittanceables.remittable.rented.stall', 'remittanceables.remittable.collector', 'remittanceables.remittance.receivedBy'])->findOrFail($id);
    $entries = $remittance->remittanceables
         ->filter(fn($r) => $r->remittable_type === Payments::class)
        ->map(fn($r) => [
            'vendor_name' => $r->remittable->rented->application->vendor->fullname ?? 'N/A',
            'vendor_contact' => $r->remittable->rented->application->vendor->contact_number ?? 'N/A',
            'section_name' => $r->remittable->rented->application->section->name ?? 'N/A',
            'stall_number' => $r->remittable->rented->stall->stall_number ?? 'N/A',
            'stall_size' => $r->remittable->rented->stall->size ?? 'N/A',
            'daily_rent' => (float)$r->remittable->rented->daily_rent,
            'monthly_rent' => (float)$r->remittable->rented->monthly_rent,
            'collector' => $r->remittable->collector->fullname ?? 'N/A',
            'received_by' => $r->remittance->receivedBy->fullname ?? 'N/A',
            'amount' => (float)$r->remittable->amount,
        ]);
    return response()->json($entries);
}

// MotorPool details
public function motorPoolDetails($id) {
    $remittance = Remittance::with(['remittanceables.remittable.collector', 'remittanceables.remittance.receivedBy'])->findOrFail($id);
    $entries = $remittance->remittanceables
          ->filter(fn($r) => $r->remittable_type === MotorPool::class)
        ->map(fn($r) => [
            'collector' => $r->remittable->collector->fullname ?? 'N/A',
            'received_by' => $r->remittance->receivedBy->fullname ?? 'N/A',
            'amount' => (float)$r->remittable->amount,
            'status' => $r->remittable->status ?? 'Pending',
        ]);
    return response()->json($entries);
}

// Wharf details
public function wharfDetails($id) {
    $remittance = Remittance::with(['remittanceables.remittable.collector', 'remittanceables.remittance.receivedBy'])->findOrFail($id);
    $entries = $remittance->remittanceables
        ->filter(fn($r) => $r->remittable_type === Wharf::class)
        ->map(fn($r) => [
            'collector' => $r->remittable->collector->fullname ?? 'N/A',
            'received_by' => $r->remittance->receivedBy->fullname ?? 'N/A',
            'amount' => (float)$r->remittable->amount,
            'status' => $r->remittable->status ?? 'Pending',
        ]);
    return response()->json($entries);
}


public function store(Request $request)
{
    $request->validate([
        'amount' => 'required|numeric',
        'remittable_type' => 'required|string',
        'remittable_ids' => 'required|array|min:1',
        'remittable_ids.*' => 'integer',
        'received_by' => 'nullable|integer|exists:main_collector_details,id',
    ]);

    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector) {
        return response()->json([
            'message' => 'Collector profile not found for the logged-in user.'
        ], 403);
    }

    $allowedMapping = [
        'slaughter' => 'App\Models\SlaughterPayment',
        'market'    => 'App\Models\Payments',
        'wharf'     => 'App\Models\Wharf',
        'motorpool' => 'App\Models\MotorPool',
    ];

    if (!isset($allowedMapping[$collector->area]) || $allowedMapping[$collector->area] !== $request->remittable_type) {
        return response()->json([
            'message' => 'You are not authorized to remit this payment type.'
        ], 403);
    }

    // Create the main remittance record
    $remittance = Remittance::create([
        'remit_date'   => now(),
        'amount'       => $request->amount,
        'remitted_by'  => $collector->id,
        'received_by'  => $request->received_by,
        'status'       => 'pending',
    ]);

    // Attach each remittable (Payments / SlaughterPayment / Wharf / MotorPool)
    foreach ($request->remittable_ids as $id) {
        Remittanceable::create([
            'remittance_id'   => $remittance->id,
            'remittable_id'   => $id,
            'remittable_type' => $request->remittable_type,
        ]);
    }

    // Update statuses depending on type
    if ($request->remittable_type === 'App\Models\SlaughterPayment') {
        SlaughterPayment::whereIn('id', $request->remittable_ids)
            ->update([
                'status' => 'remitted',
                'is_remitted' => true,
                'remitted_at' => now(),
            ]);
    }

    if ($request->remittable_type === 'App\Models\Wharf') {
        Wharf::whereIn('id', $request->remittable_ids)
            ->update(['status' => 'remitted']);
    }

    if ($request->remittable_type === 'App\Models\Payments') {
        $payments = Payments::whereIn('id', $request->remittable_ids)->get();
        foreach ($payments as $payment) {
            $payment->status = 'remitted';
            $payment->save();
        }
    }

    if ($request->remittable_type === 'App\Models\MotorPool') {
        MotorPool::whereIn('id', $request->remittable_ids)
            ->update(['status' => 'remitted']);
    }

    // ✅ Create a notification for the incharge
    Notification::create([
        'collector_id' => $collector->id,
        'title' => 'Remittance Successful',
        'message' => 'You have successfully remitted an amount of ₱' . number_format($request->amount, 2) . '. Great job!',
        'vendor_id' => null, // optional
        'is_read' => false,
    ]);

    return response()->json([
        'message' => 'Remittance successfully created. Awaiting approval.',
        'data' => $remittance,
    ], 201);
}
   public function approve(Request $request, $id)
{
    $userId = Auth::id();

    $staff = MainCollector::where('user_id', $userId)->first();

    if (!$staff) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Only main collectors can approve remittances.'
        ], 403);
    }

    $remittance = Remittance::findOrFail($id);

    if ($remittance->status !== 'pending') {
        return response()->json([
            'status' => 'error',
            'message' => 'Only pending remittances can be approved.'
        ], 400);
    }

    $remittance->status = 'approved';
    $remittance->received_by = $staff->id;  // Using main_collector id here
    $remittance->save();

    return response()->json([
        'status' => 'success',
        'message' => 'Remittance approved successfully.',
        'data' => $remittance,
    ]);
}

// Decline a remittance
public function decline(Request $request, $id)
{
    $userId = Auth::id();

    $staff = MainCollector::where('user_id', $userId)->first();

    if (!$staff) {
        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Only main collectors can decline remittances.'
        ], 403);
    }

    $remittance = Remittance::findOrFail($id);

    if ($remittance->status !== 'pending') {
        return response()->json([
            'status' => 'error',
            'message' => 'Only pending remittances can be declined.'
        ], 400);
    }

    $remittance->status = 'declined';
    $remittance->received_by = $staff->id;
    $remittance->save();

    return response()->json([
        'status' => 'success',
        'message' => 'Remittance declined successfully.',
        'data' => $remittance,
    ]);
}
public function index(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate   = $request->query('end_date');

    $query = Remittance::with([
        'remittedBy',
        'receivedBy',
        'remittanceables.remittable',
    ]);

    if ($startDate && $endDate) {
        $query->whereDate('remit_date', '>=', $startDate)
              ->whereDate('remit_date', '<=', $endDate);
    }

    $remittances = $query->orderBy('remit_date', 'asc')->get();

    $monthsMap = [];

    foreach ($remittances as $remit) {
        $date = Carbon::parse($remit->remit_date);
        $monthName = $date->format('F');
        $dayLabel  = $date->format('Y-m-d');

        if (!isset($monthsMap[$monthName])) {
            $monthsMap[$monthName] = [
                'month' => $monthName,
                'days'  => [],
            ];
        }

        if (!isset($monthsMap[$monthName]['days'][$dayLabel])) {
            $monthsMap[$monthName]['days'][$dayLabel] = [
                'day_label'    => '(' . strtoupper($date->format('D')) . ') ' . $date->format('M j'),
                'total_amount' => 0,
                'wharf'        => ['total_amount' => 0, 'details' => []],
                'motorpool'    => ['total_amount' => 0, 'details' => []],
                'market'       => ['total_amount' => 0, 'details' => []],
                'slaughter'    => ['total_amount' => 0, 'details' => []],
                'details'      => [],
            ];
        }

        foreach ($remit->remittanceables as $item) {
            $remittable = $item->remittable;
            if (!$remittable) continue;

            $deptKey = strtolower(class_basename($remittable)); 
            $deptMap = [
                'Wharf' => 'wharf',
                'MotorPool' => 'motorpool',
                'SlaughterPayment' => 'slaughter',
                'Payments' => 'market',
            ];
            $deptKey = $deptMap[class_basename($remittable)] ?? 'unknown';

            // Build department-specific details
            $detail = [];
            switch ($deptKey) {
                case 'market':
                    $rented = $remittable->rented;
                    $vendor = $rented?->application?->vendor;
                    $section = $rented?->application?->section;
                    $stall = $rented?->stall;
                    $receivedByNames = $remittable->remittances->pluck('receivedBy.fullname')->filter()->unique()->values()->toArray();

                    $detail = [
                        'vendor_name' => $vendor?->fullname ?? 'Unknown Vendor',
                        'vendor_contact' => $vendor?->contact_number ?? 'N/A',
                        'section_name' => $section?->name ?? 'Unknown Section',
                        'stall_number' => $stall?->stall_number ?? 'N/A',
                        'stall_size' => $stall?->size ?? 'N/A',
                        'daily_rent' => (float) ($rented?->daily_rent ?? 0),
                        'monthly_rent' => (float) ($rented?->monthly_rent ?? 0),
                        'payment_date' => $date->toDateTimeString(),
                        'collector' => $remittable->collector?->fullname ?? 'Unknown',
                        'received_by' => implode(', ', $receivedByNames) ?: 'N/A',
                        'payment_type' => $remittable->payment_type ?? 'Unknown',
                        'amount' => (float) $remittable->amount,
                    ];
                    break;

                case 'wharf':
                case 'motorpool':
                    $approvedRemittance = $remittable->approvedRemittances->first()?->remittance;
                    $detail = [
                        'payment_date' => $date->toDateTimeString(),
                        'collector' => $remittable->collector?->fullname ?? 'Unknown',
                        'received_by' => $approvedRemittance?->receivedBy?->fullname ?? 'Unknown',
                        'amount' => (float) $remittable->amount,
                    ];
                    break;

                case 'slaughter':
                    $mainCollector = optional($remittable->remittanceables->first()?->remittance?->receivedBy)->fullname;
                    $detail = [
                        'animal_type' => optional($remittable->animal)->animal_type ?? 'N/A',
                        'customer_name' => optional($remittable->customer)->fullname ?? 'N/A',
                        'payment_date' => $date->toDateTimeString(),
                        'collector' => optional($remittable->collector)->fullname ?? 'N/A',
                        'inspector' => optional($remittable->inspector)->fullname ?? 'N/A',
                        'received_by' => $mainCollector ?? 'N/A',
                        'amount' => (float) $remittable->total_amount,
                        'breakdown' => [
                            'slaughter_fee' => $remittable->slaughter_fee,
                            'ante_mortem' => $remittable->ante_mortem,
                            'post_mortem' => $remittable->post_mortem,
                            'coral_fee' => $remittable->coral_fee,
                            'permit_to_slh' => $remittable->permit_to_slh,
                            'quantity' => $remittable->quantity,
                            'total_kilos' => $remittable->total_kilos,
                            'per_kilos' => $remittable->per_kilos,
                        ],
                    ];
                    break;

                default:
                    $detail = [
                        'amount' => (float) ($remittable->amount ?? 0),
                        'payment_date' => $date->toDateTimeString(),
                    ];
            }

            // Add to day total
            $monthsMap[$monthName]['days'][$dayLabel]['total_amount'] += $detail['amount'] ?? 0;
            $monthsMap[$monthName]['days'][$dayLabel]['details'][] = $detail;

            // Add to department bucket
            if (in_array($deptKey, ['market', 'wharf', 'motorpool', 'slaughter'])) {
                $monthsMap[$monthName]['days'][$dayLabel][$deptKey]['total_amount'] += $detail['amount'] ?? 0;
                $monthsMap[$monthName]['days'][$dayLabel][$deptKey]['details'][] = $detail;
            }
        }
    }

    // Convert days to indexed array
    $months = [];
    foreach ($monthsMap as $month) {
        $month['days'] = array_values($month['days']);
        $months[] = $month;
    }

    return response()->json([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'months' => $months,
    ]);
}

public function allRemittances()
{
    $remittances = Remittance::with(['remittedBy', 'receivedBy', 'remittanceables.remittable'])
        ->orderBy('remit_date', 'desc')
        ->get();

    $data = $remittances->map(function($r) {
        return [
            'id' => $r->id,
            'amount' => $r->amount,
            'remit_date' => $r->remit_date,
            'status' => $r->status,
            'remitted_by' => $r->remittedBy?->fullname,
            'received_by' => $r->receivedBy?->fullname,
            'types' => $r->remittanceables->map(fn($remitable) => class_basename($remitable->remittable_type)),
        ];
    });

    return response()->json($data);
}


public function markethistory()
{
    $collector = InchargeCollector::where('user_id', auth()->id())->first();

    if (!$collector) {
        return response()->json([
            'status' => 'error',
            'message' => 'Collector profile not found for the logged-in user.'
        ], 403);
    }

    $remittances = Remittance::with('remittanceables')
        ->where('remitted_by', $collector->id)
        ->orderBy('remit_date', 'desc')
        ->get();

    // Map of model class names to friendly names
    $typeMap = [
        'App\\Models\\Payments' => 'market',
        'App\\Models\\SlaughterPayment' => 'slaughter',
        'App\\Models\\Wharf' => 'wharf',
        'App\\Models\\MotorPool' => 'motorpool',

    ];

    $remittanceData = $remittances->map(function ($remittance) use ($typeMap) {
        $fullType = optional($remittance->remittanceables->first())->remittable_type;
        $friendlyType = $typeMap[$fullType] ?? 'unknown';

        return [
            'id' => $remittance->id,
            'remit_date' => $remittance->remit_date,
            'amount' => $remittance->amount,
            'status' => $remittance->status,
            'remit_type' => $friendlyType,
        ];
    });

    return response()->json([
        'status' => 'success',
        'data' => $remittanceData,
    ]);
}

}
