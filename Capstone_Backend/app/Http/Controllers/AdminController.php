<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Payments;
use Carbon\CarbonPeriod;
use App\Models\Remittance;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\MainCollector;
use App\Models\MeatInspector;
use App\Models\VendorDetails;
use App\Models\SlaughterPayment;
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\DB;
use App\Models\StallRemovalRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{

public function getRemovalRequests()
{
    // Fetch all stall removal requests with related stall, section, rented, and vendor
    $requests = StallRemovalRequest::with([
        'stall.section',       // Stall and its section
        'vendor',              // Vendor who made the request
        'rented.application.vendor' // Rented info and application vendor
    ])->get();

    $data = $requests->map(function ($request) {
        $stall = $request->stall;
        $rented = $request->rented;

        return [
            'id' => $request->id, // ID of the removal request
            'stall_number' => $stall->stall_number ?? 'N/A',
            'section' => [
                'name' => $stall->section->name ?? 'N/A'
            ],
            'vendor_name' => $rented->application->vendor->fullname 
                              ?? $request->vendor->fullname 
                              ?? 'N/A',
            'daily_rent' => $rented->daily_rent ?? 0,
            'monthly_rent' => $rented->monthly_rent ?? 0,
            'pending_removal' => $stall->pending_removal ?? false,
            'stall_status' => $stall->status ?? 'N/A',
            'request_status' => $request->status, // pending / approved / rejected
            'request_message' => $request->message ?? '-', // message or rejection reason
        ];
    });

    return response()->json([
        'success' => true,
        'requests' => $data
    ]);
}


// Reject Removal
public function rejectRemoval(Request $req, $id)
{
    $request = StallRemovalRequest::find($id);
    if (!$request) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid request.'
        ], 400);
    }

    $stall = $request->stall;
    $vendor = $request->vendor;

    DB::transaction(function () use ($stall, $request, $req, $vendor) {
        // Reject request
        $request->status = 'rejected';
        $request->message = $req->input('reason', 'No reason provided');
        $request->save();

        // Reset pending_removal on stall
        if ($stall) {
            $stall->pending_removal = false;
            $stall->save();
        }

        // Notify vendor
        if ($vendor) {
            Notification::create([
                'vendor_id' => $vendor->id,
                'title' => 'Stall Removal Rejected',
                'message' => 'Your stall removal request was rejected. Reason: ' . $request->message,
                'is_read' => false,
            ]);
        }
    });

    return response()->json([
        'success' => true,
        'message' => 'Stall removal rejected, pending_removal reset, and vendor notified.'
    ]);
}


// Approve Removal
public function approveRemoval($id)
{
    $request = StallRemovalRequest::find($id);
    if (!$request) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid request.'
        ], 400);
    }

    $stall = $request->stall;
    $rented = $request->rented;
    $vendor = $request->vendor;

    DB::transaction(function () use ($stall, $rented, $request, $vendor) {
        // Approve request
        $request->status = 'approved';
        $request->save();

        // Update rented record
        if ($rented) {
            $rented->status = 'unoccupied';
            $rented->save();
        }

        // Update stall
        if ($stall) {
            $stall->pending_removal = false;
            $stall->status = 'vacant';
            $stall->is_active = true;
            $stall->save();
        }

        // Optional: notify vendor
        if ($vendor) {
            Notification::create([
                'vendor_id' => $vendor->id,
                'title' => 'Stall Removal Approved',
                'message' => 'Your request for stall removal has been approved.',
                'is_read' => false,
            ]);
        }
    });

    return response()->json([
        'success' => true,
        'message' => 'Stall removal approved successfully.'
    ]);
}

public function register(Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'role' => 'required|in:incharge_collector,collector_staff,main_collector', 
        ]);

        // Create new user
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => $user,
        ], 201);
    }

public function getAdminNotifications()
{
    // All admin notifications, read or unread
    $notifications = Notification::whereNull('vendor_id')
        ->whereNull('customer_id')
        ->whereNull('collector_id')
        ->orderBy('created_at', 'desc')
        ->get();

    return response()->json($notifications);
}

public function markAsReadNotification($id)
{
    $notification = Notification::find($id);
    if (!$notification) return response()->json(['message' => 'Not found'], 404);

    $notification->is_read = 1;
    $notification->save();

    return response()->json(['message' => 'Notification marked as read']);
}

public function getRoles()
{
    $allRoles = ['admin', 'meat_inspector', 'vendor', 'incharge_collector', 'main_collector'];
    $excludedRoles = ['admin', 'vendor'];

    $filteredRoles = array_values(array_filter($allRoles, function ($role) use ($excludedRoles) {
        return !in_array($role, $excludedRoles);
    }));

    return response()->json([
        'roles' => $filteredRoles
    ]);
}


public function listVendorProfiles()
{
    $vendors = VendorDetails::with('user:id,username')->get();

    // convert permit paths to full URLs using snake_case
    $vendors->transform(function ($vendor) {
        foreach (['business_permit', 'sanitary_permit', 'dti_permit'] as $permit) {
            $vendor->$permit = $vendor->$permit ? asset('storage/' . $vendor->$permit) : null;
        }
        return $vendor;
    });

    return response()->json($vendors);
}

public function validateVendor(Request $request, $id)
{
    $request->validate([
        'status' => 'required|in:approved,rejected',
    ]);

    $vendor = VendorDetails::findOrFail($id);
    $vendor->Status = $request->status;
    $vendor->save();

    // Create a notification for the vendor
    $message = $vendor->Status === 'approved'
        ? 'Your profiling has been approved by the admin. You can do an application next.'
        : 'Your profiling has been rejected. You can submit profiling again.';

    Notification::create([
        'vendor_id' => $vendor->id,
        'message'   => $message,
        'title'     => 'Vendor Profiling Update',
        'is_read'   => 0, // unread by default
    ]);

    return response()->json(['message' => 'Vendor validation updated and notification created.']);
}


public function display(Request $request)
{
        $rentedStalls = Rented::count();
        $availableStalls = Stalls::where('status', 'vacant')->count();
    $vendorCount = VendorDetails::count();
    $inchargeCount = InchargeCollector::count();
     $mainCount = MainCollector::count();
     $meatCount = MeatInspector::count();
    return response()->json([
        'rentedStalls' => $rentedStalls,
        'availableStalls' => $availableStalls,
        'vendors' => $vendorCount,
        'incharges' => $inchargeCount,
        'meat' => $meatCount,
        'main' => $mainCount,

    ]);
}
public function slaughterReport(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate = $request->query('end_date');

    $payments = SlaughterPayment::with([
            'animal',
            'customer',
            'collector',
            'inspector',
            'remittanceables.remittance.receivedBy',
        ])
        ->where('status', 'remitted')
        ->where('is_remitted', 1)
        ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        })
        ->orderBy('payment_date', 'desc')
        ->get();

    $entries = [];
    foreach ($payments as $payment) {
        $mainCollector = optional($payment->remittanceables->first()?->remittance?->receivedBy)->fullname;

        $entries[] = [
            'animal_type' => optional($payment->animal)->animal_type ?? 'N/A',
            'customer_name' => optional($payment->customer)->fullname ?? 'N/A',
            'payment_date' => Carbon::parse($payment->payment_date)->timezone('Asia/Manila'),
            'collector' => optional($payment->collector)->fullname ?? 'N/A',
            'inspector' => optional($payment->inspector)->fullname ?? 'N/A',
            'received_by' => $mainCollector ?? 'N/A',
            'amount' => (float) $payment->total_amount,
            'breakdown' => [
                'slaughter_fee' => $payment->slaughter_fee,
                'ante_mortem' => $payment->ante_mortem,
                'post_mortem' => $payment->post_mortem,
                'coral_fee' => $payment->coral_fee,
                'permit_to_slh' => $payment->permit_to_slh,
                'quantity' => $payment->quantity,
                'total_kilos' => $payment->total_kilos,
                'per_kilos' => $payment->per_kilos,
            ],
        ];
    }

    // Group and format same as before
    $grouped = [];
    foreach ($entries as $entry) {
        $monthName = $entry['payment_date']->format('F');
        $dayKey = $entry['payment_date']->format('Y-m-d');
        $dayLabel = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($grouped[$monthName])) $grouped[$monthName] = [];
        if (!isset($grouped[$monthName][$dayKey])) {
            $grouped[$monthName][$dayKey] = [
                'day_label' => $dayLabel,
                'total_amount' => 0,
                'details' => [],
            ];
        }

        $grouped[$monthName][$dayKey]['total_amount'] += $entry['amount'];
        $grouped[$monthName][$dayKey]['details'][] = $entry;
    }

    $finalData = [];
    foreach ($grouped as $monthName => $days) {
        $finalData[] = [
            'month' => $monthName,
            'days' => array_values($days),
        ];
    }

    return response()->json([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'months' => $finalData,
    ]);
}


public function slaughterRemittance(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate = $request->query('end_date');

    $payments = SlaughterPayment::with([
        'animal',
        'customer',
        'collector',
        'inspector',
        'remittanceables.remittance.receivedBy',
    ])
    ->where('status', 'remitted')
    ->where('is_remitted', 1)
    ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
        $query->whereDate('payment_date', '>=', $startDate)
              ->whereDate('payment_date', '<=', $endDate);
    })
    ->orderBy('payment_date', 'desc')
    ->get();

    $entries = [];
    foreach ($payments as $payment) {
        $mainCollector = optional($payment->remittanceables->first()?->remittance?->receivedBy)->fullname ?? 'N/A';

        $entries[] = [
            'animal_type' => optional($payment->animal)->animal_type ?? 'N/A',
            'customer_name' => optional($payment->customer)->fullname ?? 'N/A',
            'payment_date' => optional($payment->payment_date)
                                ? Carbon::parse($payment->payment_date)
                                    ->timezone('Asia/Manila')
                                    ->toDateTimeString()
                                : null,
            'collector' => optional($payment->collector)->fullname ?? 'N/A',
            'inspector' => optional($payment->inspector)->fullname ?? 'N/A',
            'received_by' => $mainCollector,
            'amount' => (float) $payment->total_amount,
            'breakdown' => [
                'slaughter_fee' => $payment->slaughter_fee,
                'ante_mortem' => $payment->ante_mortem,
                'post_mortem' => $payment->post_mortem,
                'coral_fee' => $payment->coral_fee,
                'permit_to_slh' => $payment->permit_to_slh,
                'quantity' => $payment->quantity,
                'total_kilos' => $payment->total_kilos,
                'per_kilos' => is_array($payment->per_kilos) ? $payment->per_kilos : [$payment->per_kilos],
            ],
        ];
    }

    // Group by month -> day
    $grouped = [];
    foreach ($entries as $entry) {
        $paymentDate = Carbon::parse($entry['payment_date'])->timezone('Asia/Manila');
        $monthName = $paymentDate->format('F');
        $dayKey = $paymentDate->format('Y-m-d');
        $dayLabel = '(' . strtoupper($paymentDate->format('D')) . ') ' . $paymentDate->format('M j');

        if (!isset($grouped[$monthName])) $grouped[$monthName] = [];
        if (!isset($grouped[$monthName][$dayKey])) {
            $grouped[$monthName][$dayKey] = [
                'day_label' => $dayLabel,
                'total_amount' => 0,
                'details' => [],
            ];
        }

        $grouped[$monthName][$dayKey]['total_amount'] += $entry['amount'];
        $grouped[$monthName][$dayKey]['details'][] = $entry;
    }

    // Ensure months are ordered
    $monthOrder = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    $finalData = [];
    foreach ($monthOrder as $monthName) {
        if (!isset($grouped[$monthName])) continue;
        $finalData[] = [
            'month' => $monthName,
            'days' => array_values($grouped[$monthName]),
        ];
    }

    return response()->json([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'months' => $finalData,
    ]);
}




public function MarketRemittance(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate = $request->query('end_date');

    $payments = Payments::with([
            'rented.stall',
            'rented.application.vendor',
            'rented.application.section',
            'remittances.receivedBy',
            'collector'
        ])
        ->where('status', 'remitted')
        ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        })
        ->orderBy('payment_date', 'desc') // 🔹 latest first from DB
        ->get();

    $entries = [];
    foreach ($payments as $payment) {
        $rented = $payment->rented;
        $vendor = $rented?->application?->vendor;
        $section = $rented?->application?->section;
        $stall = $rented?->stall;
        $receivedByNames = $payment->remittances
            ->pluck('receivedBy.fullname')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $entries[] = [
            'vendor_name'    => $vendor?->fullname ?? 'Unknown Vendor',
            'vendor_contact' => $vendor?->contact_number ?? 'N/A',
            'section_name'   => $section?->name ?? 'Unknown Section',
            'stall_number'   => $stall?->stall_number ?? 'N/A',
            'stall_size'     => $stall?->size ?? 'N/A',
            'daily_rent'     => (float) ($rented?->daily_rent ?? 0),
            'monthly_rent'   => (float) ($rented?->monthly_rent ?? 0),
            'payment_date'   => Carbon::parse($payment->payment_date)->timezone('Asia/Manila'),
            'collector'      => $payment->collector?->fullname ?? 'Unknown',
            'received_by'    => implode(', ', $receivedByNames) ?: 'N/A',
            'payment_type'   => $payment->payment_type ?? 'Unknown',
            'amount'         => (float) $payment->amount,
        ];
    }

    // 🔹 Ensure entries are also sorted desc by date
    $entries = collect($entries)
        ->sortByDesc('payment_date')
        ->values()
        ->all();

    // ============================
    // GROUP BY MONTH + DAY
    // ============================
    $grouped = [];
    $grandTotal = 0;

    foreach ($entries as $entry) {
        $monthName = $entry['payment_date']->format('F');
        $dayKey = $entry['payment_date']->format('Y-m-d');
        $dayLabel = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($grouped[$monthName])) $grouped[$monthName] = [];
        if (!isset($grouped[$monthName][$dayKey])) {
            $grouped[$monthName][$dayKey] = [
                'day_label'    => $dayLabel,
                'total_amount' => 0,
                'details'      => [],
            ];
        }

        $grouped[$monthName][$dayKey]['total_amount'] += $entry['amount'];
        $grandTotal += $entry['amount'];

        $grouped[$monthName][$dayKey]['details'][] = $entry;
    }

    // Format final structure (days in each month also desc)
    $finalData = [];
    foreach ($grouped as $monthName => $days) {
        // 🔹 sort day keys (Y-m-d) descending
        krsort($days);

        $finalData[] = [
            'month' => $monthName,
            'days'  => array_values($days),
        ];
    }

    return response()->json([
        'start_date'  => $startDate,
        'end_date'    => $endDate,
        'grand_total' => $grandTotal,
        'months'      => $finalData,
    ]);
}


public function marketReport(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate = $request->query('end_date');

    $payments = Payments::with([
        'rented.stall',
        'rented.application.vendor',
        'rented.application.section',
        'remittances.receivedBy',
        'collector'
    ])
    ->where('status', 'remitted')
    ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
        $query->whereBetween('payment_date', [$startDate, $endDate]);
    })
    ->orderBy('payment_date', 'asc')
    ->get();

    $entries = [];
    foreach ($payments as $payment) {
        $rented = $payment->rented;
        $vendor = $rented?->application?->vendor;
        $section = $rented?->application?->section;
        $stall = $rented?->stall;
        $receivedByNames = $payment->remittances->pluck('receivedBy.fullname')->filter()->unique()->values()->toArray();

        $entries[] = [
            'vendor_name' => $vendor?->fullname ?? 'Unknown Vendor',
            'vendor_contact' => $vendor?->contact_number ?? 'N/A',
            'section_name' => $section?->name ?? 'Unknown Section',
            'stall_number' => $stall?->stall_number ?? 'N/A',
            'stall_size' => $stall?->size ?? 'N/A',
            'daily_rent' => (float) ($rented?->daily_rent ?? 0),
            'monthly_rent' => (float) ($rented?->monthly_rent ?? 0),
            'payment_date' => Carbon::parse($payment->payment_date)->timezone('Asia/Manila'),
            'collector' => $payment->collector?->fullname ?? 'Unknown',
            'received_by' => implode(', ', $receivedByNames) ?: 'N/A',
            'payment_type' => $payment->payment_type ?? 'Unknown',
            'amount' => (float) $payment->amount,
        ];
    }

    $entries = collect($entries)->sortBy('payment_date')->values()->all();

    // Grouping logic remains the same
    $grouped = [];
    foreach ($entries as $entry) {
        $monthName = $entry['payment_date']->format('F');
        $dayKey = $entry['payment_date']->format('Y-m-d');
        $dayLabel = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($grouped[$monthName])) $grouped[$monthName] = [];
        if (!isset($grouped[$monthName][$dayKey])) {
            $grouped[$monthName][$dayKey] = [
                'day_label' => $dayLabel,
                'total_amount' => 0,
                'details' => [],
            ];
        }

        $grouped[$monthName][$dayKey]['total_amount'] += $entry['amount'];
        $grouped[$monthName][$dayKey]['details'][] = $entry;
    }

    $finalData = [];
    foreach ($grouped as $monthName => $days) {
        $finalData[] = [
            'month' => $monthName,
            'days' => array_values($days),
        ];
    }

    return response()->json([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'months' => $finalData,
    ]);
}






public function DisplayDetails($vendorId, $paymentType, $paymentDate)
{
    $payments = Payments::with('rented.stall', 'rented.application.vendor', 'remittances')
        ->whereHas('rented.application.vendor', fn($q) => $q->where('id', $vendorId))
        ->where('payment_type', $paymentType)
        ->whereDate('payment_date', $paymentDate)
        ->get();

    $stalls = $payments->flatMap(function($payment) {
        return $payment->rented ? [[
            'id' => $payment->rented->stall?->id,
            'stall_number' => $payment->rented->stall?->stall_number,
            'section_name' => $payment->rented->stall?->section?->name ?? 'N/A',
            'daily_rent' => $payment->rented->daily_rent,
            'amount_paid' => $payment->amount,
            'remit_date' => optional($payment->remittances->first())->remit_date,
        ]] : [];
    });

    return response()->json([
        'vendor_id' => $vendorId,
        'vendor_name' => $payments->first()?->rented->application->vendor?->fullname ?? 'Unknown',
        'payment_type' => $paymentType,
        'payment_date' => $paymentDate,
        'stalls' => $stalls,
    ]);
}



public function vendorsWithMissedPayments()
{
    $today = Carbon::today();

    $rentedData = Rented::with(['application.vendor', 'stall', 'payments'])
        ->get()
        ->groupBy('application.vendor.id')
        ->map(function ($rents) use ($today) {
            $vendor = $rents->first()->application->vendor;
            $stalls = [];
            $totalMissed = 0;

            foreach ($rents as $rented) {
                $missedDates = [];

                $lastPayment = $rented->payments->sortByDesc('payment_date')->first();
                $advanceDays = $lastPayment ? intval($lastPayment->advance_days) : 0;
                $lastPaymentDate = $lastPayment ? Carbon::parse($lastPayment->payment_date) : null;

                if ($lastPaymentDate) {
                    $nextDue = $lastPaymentDate->copy()->addDays($advanceDays)->addDay();
                } else {
                    $nextDue = Carbon::parse($rented->created_at)->addDay();
                }

                if (Carbon::parse($rented->created_at)->isSameDay($today)) {
                    $stalls[] = [
                        'stall_number' => $rented->stall->stall_number,
                        'missed_days'  => 0,
                        'status'       => 'Occupied',
                        'next_due'     => $nextDue->toDateString(),
                    ];
                    continue;
                }

                $hasPaymentToday = $rented->payments->contains(function ($p) use ($today) {
                    return Carbon::parse($p->payment_date)->isSameDay($today);
                });

                if ($nextDue->lt($today)) {
                    $date = $nextDue->copy();
                    while ($date->lt($today)) {
                        $missedDates[] = $date->toDateString();
                        $date->addDay();
                    }
                }

                if ($nextDue->isSameDay($today) && !$hasPaymentToday) {
                    $missedDates[] = $today->toDateString();
                }

                $status = 'Occupied';
                if (!empty($missedDates)) {
                    if (count($missedDates) === 1 && in_array($today->toDateString(), $missedDates)) {
                        $status = 'Unpaid Today';
                    } else {
                        $status = 'Missed';
                    }
                }

                $stalls[] = [
                    'stall_number' => $rented->stall->stall_number,
                    'missed_days'  => count($missedDates),
                    'missed_dates' => $missedDates,
                    'next_due'     => $nextDue->toDateString(),
                    'status'       => $status,
                ];

                $totalMissed += count($missedDates);
            }

            if ($totalMissed === 0) return null;

            // ✅ Get the latest "Missed Payment" notification
            $lastNotification = Notification::where('vendor_id', $vendor->id)
                ->where('title', 'Missed Payment')
                ->latest('created_at')
                ->first();

            return [
                'vendor_id'          => $vendor->id,
                'vendor_name'        => $vendor->fullname,
                'contact_number'     => $vendor->contact_number,
                'stalls'             => $stalls,
                'days_missed'        => $totalMissed,
                'last_notified_date' => $lastNotification ? $lastNotification->created_at->toDateString() : null,
            ];
        })
        ->filter()
        ->values();

    return response()->json($rentedData);
}


public function notifyVendor(Request $request)
{
    $request->validate([
        'vendor_id' => 'required|exists:vendor_details,id',
    ]);

    $vendorId = $request->vendor_id;
    $today = now()->toDateString();

    // ✅ Fetch vendor rented stalls with payments, stalls, and application
    $rentedStalls = Rented::with(['stall', 'application.vendor', 'payments'])
        ->whereHas('application', fn($q) => $q->where('vendor_id', $vendorId))
        ->get();

    if ($rentedStalls->isEmpty()) {
        return response()->json([
            'status' => 'info',
            'message' => 'No rented stalls found for this vendor.',
        ]);
    }

    $notifications = [];
    $totalMissedDays = 0;

    foreach ($rentedStalls as $rented) {
        $missedDates = [];

        $lastPayment = $rented->payments->sortByDesc('payment_date')->first();
        $advanceDays = $lastPayment ? intval($lastPayment->advance_days) : 0;
        $lastPaymentDate = $lastPayment ? Carbon::parse($lastPayment->payment_date) : null;

        if ($lastPaymentDate) {
            $nextDue = $lastPaymentDate->copy()->addDays($advanceDays)->addDay();
        } else {
            $nextDue = Carbon::parse($rented->created_at)->addDay();
        }

        $todayDate = Carbon::today();

        if ($nextDue->lt($todayDate)) {
            $date = $nextDue->copy();
            while ($date->lt($todayDate)) {
                $missedDates[] = $date->toDateString();
                $date->addDay();
            }
        }

        if ($nextDue->isSameDay($todayDate) &&
            !$rented->payments->contains(fn($p) => Carbon::parse($p->payment_date)->isSameDay($todayDate))) {
            $missedDates[] = $todayDate->toDateString();
        }

        $missedDays = count($missedDates);
        $totalMissedDays += $missedDays;

        if ($missedDays > 0) {
            $stallNumber = optional($rented->stall)->stall_number ?? 'Unknown';

            // ✅ Avoid duplicate notification for same stall & day
            $alreadyNotifiedToday = Notification::where('vendor_id', $vendorId)
                ->where('title', 'Missed Payment')
                ->where('message', 'like', "%Stall #{$stallNumber}%")
                ->whereDate('created_at', $today)
                ->exists();

            if (!$alreadyNotifiedToday) {
                $notification = Notification::create([
                    'vendor_id' => $vendorId,
                    'title'     => 'Missed Payment',
                    'message'   => "You have missed {$missedDays} payment(s) for Stall #{$stallNumber}. Please settle as soon as possible.",
                    'is_read'   => 0,
                ]);

                $notifications[] = $notification;
            }
        }
    }

    if (empty($notifications)) {
        return response()->json([
            'status' => 'info',
            'message' => 'No new missed payments found or vendor already notified today.',
        ]);
    }

    return response()->json([
        'status' => 'success',
        'message' => "Vendor notified successfully with correct missed days ({$totalMissedDays} total).",
        'notifications' => $notifications,
    ]);
}

public function vendornotification(Request $request)
{
    // Get the vendor's ID
    $vendorId = VendorDetails::where('user_id', auth()->id())->value('id');

    if (!$vendorId) {
        return response()->json([
            'status' => 'error',
            'message' => 'Vendor profile not found',
            'notifications' => [],
            'unread_count' => 0
        ], 404);
    }

    $today = now()->startOfDay();

    // ✅ Create daily notification even if no payment exists
    $exists = Notification::where('vendor_id', $vendorId)
        ->where('title', 'Missed Payment')
        ->whereDate('created_at', $today)
        ->exists();



    // Fetch all notifications
    $notifications = Notification::where('vendor_id', $vendorId)
        ->orderBy('created_at', 'desc')
        ->get();

    $unreadCount = $notifications->where('is_read', 0)->count();

    return response()->json([
        'status' => 'success',
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
    ]);
}


    /**
     * Mark a notification as read
     */
    public function markAsRead($id)
    {
        // Get vendor details ID for the logged-in user
        $vendorId = VendorDetails::where('user_id', auth()->id())->value('id');

        if (!$vendorId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vendor profile not found',
            ], 404);
        }

        $notification = Notification::where('vendor_id', $vendorId)
            ->where('id', $id)
            ->firstOrFail();

        $notification->update(['is_read' => 1]);

        return response()->json([
            'status' => 'success',
            'message' => 'Notification marked as read',
            'notification' => $notification
        ]);
    }

public function stallHistory($stallId)
{
    $stall = Stalls::with([
        'rentals.application.vendor',
        'rentals.application.section',
        'rentals.payments'
    ])->findOrFail($stallId);

    return response()->json([
        'stall_number' => $stall->stall_number,
        'section' => $stall->rented->application->section->name ?? null, 
        'history' => $stall->rentals->map(function ($rental) {
            $latestPayment = $rental->payments()->latest('payment_date')->first();

            $paymentType = 'N/A';
            $advanceDays = null;
            $amount = null;

            if ($latestPayment) {
                if ($latestPayment->payment_type === 'advance') {
                    $paymentType = 'Advance';
                    $advanceDays = $latestPayment->advance_days;
                } elseif ($latestPayment->payment_type === 'daily') {
                    $paymentType = 'Daily';
                } else {
                    $paymentType = ucfirst($latestPayment->payment_type);
                }
                $amount = $latestPayment->amount;
            }

            return [
                'application_id'    => $rental->application_id,
                'vendor'            => [
                    'fullname' => $rental->application->vendor->fullname ?? 'N/A',
                ],
                'section' => [
                    'name' => $rental->application->section->name ?? 'N/A',
                ],
                'monthly_rent'      => $rental->monthly_rent,
                'daily_rent'        => $rental->daily_rent,
                'last_payment_date' => $rental->last_payment_date?->format('Y-m-d'),
                'start_date'        => $rental->start_date?->format('Y-m-d'),
                'end_date'          => $rental->end_date?->format('Y-m-d'),
                'payment_type'      => $paymentType,
                'advance_days'      => $advanceDays,
                'amount'            => $amount,
            ];
        }),
    ]);
}




  public function sidebarData()
{
    // Count only pending vendors
    $vendorCount = VendorDetails::where('Status', 'pending')->count();

    // Count only pending main collectors
    $mainCollectorCount = MainCollector::where('Status', 'pending')->count();

    // Count only pending incharge collectors
    $inchargeCount = InchargeCollector::where('Status', 'pending')->count();

    // Count only pending meat inspectors
    $meatInspectorCount = MeatInspector::where('Status', 'pending')->count();

    return response()->json([
        'vendorCount' => $vendorCount,
        'mainCollectorCount' => $mainCollectorCount,
        'inchargeCount' => $inchargeCount,
        'meatInspectorCount' => $meatInspectorCount,
    ]);
}

public function getRemittanceDetails($vendorId)
{
    $payments = Payments::with([
        'rented.stall', 
        'rented.application.section', 
        'vendor', 
        'collector', 
        'remittances.receivedBy'
    ])
    ->where('vendor_id', $vendorId)
    ->where('status', 'remitted') // optional filter
    ->get();

    if ($payments->isEmpty()) {
        return response()->json(['message' => 'No payments found'], 404);
    }

    $data = $payments->map(function ($payment) {
        $stall = $payment->rented->stall;
        $application = $payment->rented->application;
        $section = $application?->section;

        $dailyRent = $payment->rented->daily_rent ?? 0;
        $advanceDays = $payment->advance_days ?? 0;

        $amountPaid = $payment->payment_type === 'advance'
            ? $dailyRent * $advanceDays
            : $payment->amount;

        $receivedByName = $payment->remittances->first()?->receivedBy?->fullname ?? 'N/A';

        $missedCount = $payment->missed_days ?? 0;
        $missedDays = [];
        if($missedCount > 0){
            for($i=0; $i<$missedCount; $i++){
                $missedDays[] = [
                    'missed_day_number' => $i+1,
                    'missed_amount' => $dailyRent
                ];
            }
        }

        return [
            'vendor_id'     => $payment->vendor->id,
            'vendor_name'   => $payment->vendor->fullname,
            'payment_date'  => $payment->payment_date->toDateString(),
            'section_name'  => $section?->name ?? 'Unknown',
            'payment_type'  => $payment->payment_type,
            'stall_number'  => $stall?->stall_number ?? 'N/A',
            'daily_rent'    => $dailyRent,
            'advance_days'  => $advanceDays,
            'amount_paid'   => $amountPaid,
            'collected_by'  => $payment->collector->fullname,
            'received_by'   => $receivedByName,
            'missed_days'   => $missedDays,
        ];
    });

    return response()->json($data);
}





}
