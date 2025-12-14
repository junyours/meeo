<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Wharf;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Payments;
use App\Models\MotorPool;
use Illuminate\Http\Request;
use App\Models\SlaughterPayment;

class ReportsController extends Controller
{
public function detailedCollections(Request $request)
{
    $year = $request->query('year', now()->year);
    $monthName = $request->query('month'); // e.g., "January"

    $payments = Payments::with([
        'collector.user',
        'vendor',
        'rented.stall.section.area',
        'remittances.receivedBy'
    ])
    ->whereYear('payment_date', $year);

    // Filter by month if provided
    if ($monthName && $monthName !== "All") {
        $monthNumber = date('m', strtotime($monthName));
        $payments->whereMonth('payment_date', $monthNumber);
    }

    $payments = $payments->orderBy('payment_date', 'desc')
                         ->orderBy('created_at', 'desc')
                         ->get();

    if ($payments->isEmpty()) {
        return response()->json([
            'status' => 'success',
            'months' => []
        ]);
    }

    // Group payments by month and day
    $grouped = $payments->groupBy(function ($p) {
        return $p->payment_date ? $p->payment_date->format('F Y') : 'No Date';
    })->map(function ($monthGroup) {
        return $monthGroup->groupBy(function ($p) {
            return $p->payment_date ? $p->payment_date->format('F j, Y') : 'No Date';
        })->map(function ($dayGroup, $day) {
            $details = $dayGroup->map(function ($p) {
                return [
                    'collector_name' => $p->collector->fullname ?? 'N/A',
                    'vendor_name'    => $p->vendor->fullname ?? 'N/A',
                    'stall_number'   => $p->rented->stall->stall_number ?? 'N/A',
                    'section_name'   => $p->rented->stall->section->name ?? 'N/A',
                    'area_name'      => $p->rented->stall->section->area->name ?? 'N/A',
                    'payment_type'   => ucfirst($p->payment_type ?? 'N/A'),
                    'amount'         => floatval($p->amount),
                    'missed_days'    => $p->missed_days ?? 0,
                    'advance_days'   => $p->advance_days ?? 0,
                    'remarks'        => $p->remarks ?? 'None',
                    'status'         => ucfirst($p->status ?? 'N/A'),
                    'remitted_to'    => optional($p->remittances->first()?->receivedBy)->fullname ?? 'N/A',
                    'payment_time'   => $p->created_at ? $p->created_at->format('h:i A') : 'N/A',
                ];
            });

            $total = $details->sum('amount');

            return [
                'day_label'    => $day,
                'total_amount' => $total,
                'details'      => $details
            ];
        });
    });

    $months = $grouped->map(function ($days, $month) {
        return [
            'month' => $month,
            'days'  => array_values($days->toArray()),
        ];
    })->values();

    return response()->json([
        'status' => 'success',
        'year'   => $year,
        'months' => $months
    ]);
}

public function collectorTotals(Request $request)
{
    $year = $request->query('year', now()->year);
    $monthName = $request->query('month');
    $monthNumber = $monthName && $monthName !== "All" ? date('m', strtotime($monthName)) : null;

    // Helper function to fetch payments from a model with relationships
    $fetchPayments = function($model) use ($year, $monthNumber) {
        $query = $model::with(['collector', 'remittanceables.remittance']);
        $query->whereYear('payment_date', $year) ->whereIn('status', ['collected', 'remitted']);;
        if ($monthNumber) $query->whereMonth('payment_date', $monthNumber);
        return $query->get();
    };

    // Market payments
    $marketPayments = $fetchPayments(Payments::class)->map(function($p) {
        $stall = $p->rented?->stall;
        $section = $stall?->section;
        $vendor = $p->vendor;
        $receivedBy = $p->remittanceables->first()?->remittance?->receivedBy?->fullname ?? null;

        return [
            'source' => 'market',
            'collector_id' => $p->collector_id,
            'collector_name' => $p->collector?->fullname ?? 'N/A',
            'assigned' => $p->collector?->area ?? 'N/A',
            'vendor_name' => $vendor?->fullname ?? 'N/A',
            'stall_number' => $stall?->stall_number ?? 'N/A',
            'section_name' => $section?->name ?? 'N/A',
            'payment_type' => $p->payment_type,
            'amount' => floatval($p->amount),
            'payment_date' => $p->payment_date,
            'missed_days' => $p->missed_days,
            'advance_days' => $p->advance_days,
            'status' => $p->status,
            'received_by' => $receivedBy,
            'time_remitted' => optional($p->updated_at)->format('h:i A'),
        ];
    });

    // Wharf payments
    $wharfPayments = $fetchPayments(Wharf::class)->map(function($p) {
        $receivedBy = $p->remittanceables->first()?->remittance?->receivedBy?->fullname ?? null;
        return [
            'source' => 'wharf',
            'collector_id' => $p->collector_id,
            'collector_name' => $p->collector?->fullname ?? 'N/A',
            'assigned' => $p->collector?->area ?? 'N/A',
            'amount' => floatval($p->amount),
            'payment_date' => $p->payment_date,
            'status' => $p->status,
            'received_by' => $receivedBy,
            'time_remitted' => optional($p->updated_at)->format('h:i A'),
        ];
    });

    // MotorPool payments
    $motorPoolPayments = $fetchPayments(MotorPool::class)->map(function($p) {
        $receivedBy = $p->remittanceables->first()?->remittance?->receivedBy?->fullname ?? null;
        return [
            'source' => 'motor_pool',
            'collector_id' => $p->collector_id,
            'collector_name' => $p->collector?->fullname ?? 'N/A',
            'assigned' => $p->collector?->area ?? 'N/A',
            'amount' => floatval($p->amount),
            'payment_date' => $p->payment_date,
            'status' => $p->status,
            'received_by' => $receivedBy,
            'time_remitted' => optional($p->updated_at)->format('h:i A'),
        ];
    });

    // Slaughter payments
    $slaughterPayments = $fetchPayments(SlaughterPayment::class)->map(function($p) {
        $receivedBy = $p->remittanceables->first()?->remittance?->receivedBy?->fullname ?? null;
        $customerName = $p->customer?->fullname ?? 'N/A';
        $animalName = $p->animal?->animal_type ?? 'N/A';
        $inspectorName = $p->inspector?->fullname ?? 'N/A';

        return [
            'source' => 'slaughter',
            'collector_id' => $p->collector_id,
            'collector_name' => $p->collector?->fullname ?? 'N/A',
            'assigned' => $p->collector?->area ?? 'N/A',
            'customer_name' => $customerName,
            'animals_name' => $animalName,
            'inspector_name' => $inspectorName,
            'slaughter_fee' => $p->slaughter_fee,
            'ante_mortem' => $p->ante_mortem,
            'post_mortem' => $p->post_mortem,
            'coral_fee' => $p->coral_fee,
            'permit_to_slh' => $p->permit_to_slh,
            'quantity' => $p->quantity,
            'total_kilos' => $p->total_kilos,
            'per_kilos' => $p->per_kilos,
            'total_amount' => $p->total_amount,
            'payment_date' => $p->payment_date,
            'status' => $p->status,
            'received_by' => $receivedBy,
            'time_remitted' => optional($p->updated_at)->format('h:i A'),
        ];
    });

    // Merge all
    $allPayments = $marketPayments
        ->merge($wharfPayments)
        ->merge($motorPoolPayments)
        ->merge($slaughterPayments);

    // Group by collector, then group by source and by vendor/received_by/customer
    $collectors = $allPayments->groupBy('collector_name')->map(function($group, $collectorName) {
        $collectorDetails = $group->groupBy('source')->map(function($sourceGroup, $source) {
            if ($source === 'market') {
                return $sourceGroup->groupBy('vendor_name')->map(function($vendorGroup, $vendor) {
                    return [
                        'vendor_name' => $vendor,
                        'total_amount' => $vendorGroup->sum('amount'),
                        'records' => $vendorGroup->values(),
                    ];
                })->values();
            }

            if (in_array($source, ['wharf', 'motor_pool'])) {
                return $sourceGroup->groupBy('received_by')->map(function($recGroup, $receiver) {
                    return [
                        'received_by' => $receiver ?? 'N/A',
                        'total_amount' => $recGroup->sum('amount'),
                        'records' => $recGroup->values(),
                    ];
                })->values();
            }

            if ($source === 'slaughter') {
                return $sourceGroup->groupBy('customer_name')->map(function($custGroup, $customer) {
                    return [
                        'customer_name' => $customer,
                        'total_amount' => $custGroup->sum('total_amount'),
                        'records' => $custGroup->values(),
                    ];
                })->values();
            }

            return [];
        });

        return [
            'collector_name' => $collectorName,
            'assigned' => $group->first()['assigned'] ?? 'N/A',
            'total_collections' => $group->count(),
            'total_amount' => $group->sum(fn($p) => $p['amount'] ?? $p['total_amount'] ?? 0),
            'details' => $collectorDetails,
        ];
    })->values();

    return response()->json([
        'status' => 'success',
        'year' => $year,
        'collectors' => $collectors,
    ]);
}



public function index(Request $request)
{

    $section = $request->query('section');
    $vendor = $request->query('vendor');

    $baseQuery = Rented::with(['stall.section', 'application.vendor'])
        ->when($section, fn($q) =>
            $q->whereHas('stall.section', fn($s) => $s->where('name', $section))
        )
        ->when($vendor, fn($q) =>
            $q->whereHas('application.vendor', fn($v) => $v->where('fullname', $vendor))
        );

    // 🔹 Rented but not paid
    $rentedNotPaidRaw = (clone $baseQuery)
        ->whereNull('last_payment_date')
        ->get();

    $rentedNotPaidGrouped = $rentedNotPaidRaw->groupBy(fn($r) => $r->application->vendor->fullname ?? 'Unknown Vendor');

    $rentedNotPaid = $rentedNotPaidGrouped->map(function ($group, $vendorName) {
       $details = $group->map(function ($r) {
  $startDate = Carbon::parse($r->created_at)->addDay(); // start counting from next day
$endDate = Carbon::today();

$missedDays = $startDate->diffInDays($endDate) + 1; // inclusive of startDate

$missedDates = [];
for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
    $missedDates[] = $date->format('Y-m-d');
}

$dailyRent = $r->daily_rent ?? 0;
$totalMissed = $dailyRent * $missedDays;

return [
    'stall_number'  => $r->stall->stall_number ?? 'N/A',
    'section_name'  => $r->stall->section->name ?? 'N/A',
    'created_at'    => $r->created_at->format('Y-m-d'),
    'daily_rent'    => $dailyRent,
    'missed_days'   => $missedDays,
    'missed_dates'  => $missedDates,
    'total_missed'  => $totalMissed,
];
});


        return [
            'vendor_name'       => $vendorName,
            'stall_count'       => $group->count(),
            'total_missed_days' => $details->sum('missed_days'),
            'details'           => $details,
        ];
    })->values();

    // 🔹 Newly rented (last 7 days)
 // 🔹 Newly rented (last 7 days)
$newRentedRaw = (clone $baseQuery)
    ->where('created_at', '>=', Carbon::today()->subDays(7))
    ->get();

// Group by vendor name
$newRentedGrouped = $newRentedRaw->groupBy(fn($r) => $r->application->vendor->fullname ?? 'Unknown Vendor');

$newRented = $newRentedGrouped->map(function ($group, $vendorName) {
    $details = $group->map(function ($r) {
        return [
            'stall_number' => $r->stall->stall_number ?? 'N/A',
            'section_name' => $r->stall->section->name ?? 'N/A',
            'vendor_name'  => $r->application->vendor->fullname ?? 'N/A',
            'created_at'   => $r->created_at->format('Y-m-d'),
            'daily_rent'   => $r->daily_rent ?? 0,
            'monthly_rent' => $r->monthly_rent ?? 0,
        ];
    });

    return [
        'vendor_name' => $vendorName,
        'stall_count' => $group->count(),
        'details'     => $details,
    ];
})->values();



    
    // 🔹 Paid grouped by vendor (with payment details)
    
    $today = Carbon::today();
$paidRaw = (clone $baseQuery)
    ->whereHas('payments', fn($q) => $q->whereDate('payment_date', $today)) // only today's payments
    ->with(['payments' => fn($q) => $q->whereDate('payment_date', $today)]) // eager load only today's payments
    ->get();


$paidGrouped = $paidRaw->groupBy(fn($r) => $r->application->vendor->fullname ?? 'Unknown Vendor');

$paid = $paidGrouped->map(function ($group, $vendorName) {
    $details = $group->map(function ($r) {
        // Take the last payment within the range
        $payment = $r->payments->sortByDesc('payment_date')->first();

        return [
            'stall_number'  => $r->stall->stall_number ?? 'N/A',
            'section_name'  => $r->stall->section->name ?? 'N/A',
            'daily_rent'    => number_format($r->daily_rent ?? 0, 2),
            'monthly_rent'  => number_format($r->monthly_rent ?? 0, 2),
            'last_payment'  => $payment?->payment_date
                ? Carbon::parse($payment->payment_date)->format('F d, Y')  // ← Change here
                : 'Never Paid',
            'amount_paid'   => $payment?->amount ?? 0,
            'payment_type'  => $payment?->payment_type ?? null,
            'missed_days'   => $payment?->missed_days ?? 0,
            'advance_days'  => $payment && $payment->payment_type === 'advance' ? $payment->advance_days : null,
            'status'        => 'Paid',
        ];
    });

    return [
        'vendor_name' => $vendorName,
        'stall_count' => $group->count(),
        'total_paid'  => $details->sum('amount_paid'),
        'details'     => $details,
    ];
})->values();



    // 🔹 Never rented grouped by section
    $neverRented = Stalls::with('section')
        ->when($section, fn($q) =>
            $q->whereHas('section', fn($s) => $s->where('name', $section))
        )
        ->whereDoesntHave('rented')
        ->get()
        ->groupBy(fn($stall) => $stall->section->name ?? 'Unknown Section')
        ->map(function ($group, $sectionName) {
            return [
                'section_name' => $sectionName,
                'stall_count'  => $group->count(),
                'status'       => 'Never Rented', // For table
                'details'      => $group->map(fn($stall) => [
                    'stall_number' => $stall->stall_number,
                    'status'       => 'Available', // For modal
                ])->values(),
            ];
        })
        ->values();

    // 🔹 Missed payments grouped by vendor (full details)
    $missedPaymentsRaw = (clone $baseQuery)
        ->get()
        ->filter(fn($r) => !$r->last_payment_date || Carbon::parse($r->last_payment_date)->lt(Carbon::today()));

    $missedPaymentsGrouped = $missedPaymentsRaw->groupBy(fn($r) => $r->application->vendor->fullname ?? 'Unknown Vendor');

    $missedPayments = $missedPaymentsGrouped->map(function ($group, $vendorName) {
       $details = $group->map(function ($r) {
    $lastPayment = $r->last_payment_date
        ? Carbon::parse($r->last_payment_date)
        : Carbon::parse($r->created_at)->startOfDay();

    // Exclude today from missed dates
    $endDate = Carbon::yesterday(); // only count up to yesterday
    $missedDays = $lastPayment->diffInDays($endDate);

    $missedDates = [];
    for ($date = $lastPayment->copy()->addDay(); $date->lte($endDate); $date->addDay()) {
        $missedDates[] = $date->format('Y-m-d');
    }

    return [
        'stall_number' => $r->stall->stall_number ?? 'N/A',
        'section_name' => $r->stall->section->name ?? 'N/A',
        'created_at'   => $r->created_at->format('Y-m-d'),
        'last_payment' => $r->last_payment_date ? Carbon::parse($r->last_payment_date)->format('Y-m-d') : null,
        'missed_days'  => $missedDays,
        'missed_dates' => $missedDates,
    ];
});
        return [
            'vendor_name' => $vendorName,
            'stall_count' => $group->count(),
            'details'     => $details,
        ];
    })->values();

    return response()->json([
        'rented_not_paid' => $rentedNotPaid,
                'new_rented'      => $newRented,
        'paid'            => $paid,
        'never_rented'    => $neverRented,
        'missed_payments' => $missedPayments, // Updated with detailed grouping
    ]);
}




public function DepartmentsReport(Request $request)
{
    $startDate = $request->query('start_date');
    $endDate   = $request->query('end_date');

    // =================== WHARF ===================
    $wharfPayments = Wharf::with(['collector', 'approvedRemittances.remittance.receivedBy'])
        ->whereHas('approvedRemittances')
        ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        })
        ->get();

    $wharfEntries = [];
    foreach ($wharfPayments as $pmt) {
        $approvedRemittance = $pmt->approvedRemittances->first()?->remittance;
        $wharfEntries[] = [
            'id'           => $pmt->id,
            'amount'       => (float) $pmt->amount,
            'payment_date' => Carbon::parse($pmt->payment_date)->timezone('Asia/Manila'),
            'collector'    => $pmt->collector?->fullname ?? 'Unknown',
            'received_by'  => $approvedRemittance?->receivedBy?->fullname ?? 'Unknown',
            'status'       => $pmt->status ?? 'Pending',
        ];
    }

    $wharfGrouped = [];
    foreach ($wharfEntries as $entry) {
        $month   = $entry['payment_date']->format('F');
        $dayKey  = $entry['payment_date']->format('Y-m-d');
        $dayLabel = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($wharfGrouped[$month])) $wharfGrouped[$month] = [];
        if (!isset($wharfGrouped[$month][$dayKey])) {
            $wharfGrouped[$month][$dayKey] = [
                'day_label'    => $dayLabel,
                'total_amount' => 0,
                'details'      => [],
            ];
        }

        $wharfGrouped[$month][$dayKey]['total_amount'] += $entry['amount'];
        $wharfGrouped[$month][$dayKey]['details'][]     = $entry;
    }

    $wharfFinal = [];
    foreach ($wharfGrouped as $month => $days) {
        $wharfFinal[] = [
            'month' => $month,
            'days'  => array_values($days),
        ];
    }

    // =================== MOTORPOOL ===================
    $motorPayments = MotorPool::with(['collector', 'approvedRemittances.remittance.receivedBy'])
        ->whereHas('approvedRemittances')
        ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
            $query->whereBetween('payment_date', [$startDate, $endDate]);
        })
        ->get();

    $motorEntries = [];
    foreach ($motorPayments as $pmt) {
        $approvedRemittance = $pmt->approvedRemittances->first()?->remittance;
        $motorEntries[] = [
            'id'           => $pmt->id,
            'amount'       => (float) $pmt->amount,
            'payment_date' => Carbon::parse($pmt->payment_date)->timezone('Asia/Manila'),
            'collector'    => $pmt->collector?->fullname ?? 'Unknown',
            'received_by'  => $approvedRemittance?->receivedBy?->fullname ?? 'Unknown',
            'status'       => $pmt->status ?? 'Pending',
        ];
    }

    $motorGrouped = [];
    foreach ($motorEntries as $entry) {
        $month   = $entry['payment_date']->format('F');
        $dayKey  = $entry['payment_date']->format('Y-m-d');
        $dayLabel = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($motorGrouped[$month])) $motorGrouped[$month] = [];
        if (!isset($motorGrouped[$month][$dayKey])) {
            $motorGrouped[$month][$dayKey] = [
                'day_label'    => $dayLabel,
                'total_amount' => 0,
                'details'      => [],
            ];
        }

        $motorGrouped[$month][$dayKey]['total_amount'] += $entry['amount'];
        $motorGrouped[$month][$dayKey]['details'][]     = $entry;
    }

    $motorFinal = [];
    foreach ($motorGrouped as $month => $days) {
        $motorFinal[] = [
            'month' => $month,
            'days'  => array_values($days),
        ];
    }

    // =================== MARKET ===================
    $marketPayments = Payments::with([
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

    $marketEntries = [];
    foreach ($marketPayments as $payment) {
        $rented   = $payment->rented;
        $vendor   = $rented?->application?->vendor;
        $section  = $rented?->application?->section;
        $stall    = $rented?->stall;
        $receivedByNames = $payment->remittances
            ->pluck('receivedBy.fullname')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $marketEntries[] = [
            'vendor_name'     => $vendor?->fullname ?? 'Unknown Vendor',
            'vendor_contact'  => $vendor?->contact_number ?? 'N/A',
            'section_name'    => $section?->name ?? 'Unknown Section',
            'stall_number'    => $stall?->stall_number ?? 'N/A',
            'stall_size'      => $stall?->size ?? 'N/A',
            'daily_rent'      => (float) ($rented?->daily_rent ?? 0),
            'monthly_rent'    => (float) ($rented?->monthly_rent ?? 0),
            'payment_date'    => Carbon::parse($payment->payment_date)->timezone('Asia/Manila'),
            'collector'       => $payment->collector?->fullname ?? 'Unknown',
            'received_by'     => implode(', ', $receivedByNames) ?: 'N/A',
            'payment_type'    => $payment->payment_type ?? 'Unknown',
            'amount'          => (float) $payment->amount,
        ];
    }

    $marketEntries = collect($marketEntries)->sortBy('payment_date')->values()->all();

    $marketGrouped = [];
    foreach ($marketEntries as $entry) {
        $monthName = $entry['payment_date']->format('F');
        $dayKey    = $entry['payment_date']->format('Y-m-d');
        $dayLabel  = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($marketGrouped[$monthName])) $marketGrouped[$monthName] = [];
        if (!isset($marketGrouped[$monthName][$dayKey])) {
            $marketGrouped[$monthName][$dayKey] = [
                'day_label'    => $dayLabel,
                'total_amount' => 0,
                'details'      => [],
            ];
        }

        $marketGrouped[$monthName][$dayKey]['total_amount'] += $entry['amount'];
        $marketGrouped[$monthName][$dayKey]['details'][]     = $entry;
    }

    $marketFinal = [];
    foreach ($marketGrouped as $monthName => $days) {
        $marketFinal[] = [
            'month' => $monthName,
            'days'  => array_values($days),
        ];
    }

    // =================== SLAUGHTER ===================
    $slaughterPayments = SlaughterPayment::with([
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

    $slaughterEntries = [];
    foreach ($slaughterPayments as $payment) {
        $mainCollector = optional($payment->remittanceables->first()?->remittance?->receivedBy)->fullname;

        $slaughterEntries[] = [
            'animal_type'   => optional($payment->animal)->animal_type ?? 'N/A',
            'customer_name' => optional($payment->customer)->fullname ?? 'N/A',
            'payment_date'  => Carbon::parse($payment->payment_date)->timezone('Asia/Manila'),
            'collector'     => optional($payment->collector)->fullname ?? 'N/A',
            'inspector'     => optional($payment->inspector)->fullname ?? 'N/A',
            'received_by'   => $mainCollector ?? 'N/A',
            'amount'        => (float) $payment->total_amount,
            'breakdown'     => [
                'slaughter_fee' => $payment->slaughter_fee,
                'ante_mortem'   => $payment->ante_mortem,
                'post_mortem'   => $payment->post_mortem,
                'coral_fee'     => $payment->coral_fee,
                'permit_to_slh' => $payment->permit_to_slh,
                'quantity'      => $payment->quantity,
                'total_kilos'   => $payment->total_kilos,
                'per_kilos'     => $payment->per_kilos,
            ],
        ];
    }

    $slaughterGrouped = [];
    foreach ($slaughterEntries as $entry) {
        $monthName = $entry['payment_date']->format('F');
        $dayKey    = $entry['payment_date']->format('Y-m-d');
        $dayLabel  = '(' . strtoupper($entry['payment_date']->format('D')) . ') ' . $entry['payment_date']->format('M j');

        if (!isset($slaughterGrouped[$monthName])) $slaughterGrouped[$monthName] = [];
        if (!isset($slaughterGrouped[$monthName][$dayKey])) {
            $slaughterGrouped[$monthName][$dayKey] = [
                'day_label'    => $dayLabel,
                'total_amount' => 0,
                'details'      => [],
            ];
        }

        $slaughterGrouped[$monthName][$dayKey]['total_amount'] += $entry['amount'];
        $slaughterGrouped[$monthName][$dayKey]['details'][]     = $entry;
    }

    $slaughterFinal = [];
    foreach ($slaughterGrouped as $monthName => $days) {
        $slaughterFinal[] = [
            'month' => $monthName,
            'days'  => array_values($days),
        ];
    }

    // =================== FINAL RESPONSE ===================
    return response()->json([
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'wharf'      => ['months' => $wharfFinal],
        'motorpool'  => ['months' => $motorFinal],
        'market'     => ['months' => $marketFinal],
        'slaughter'  => ['months' => $slaughterFinal],
    ]);
}
}
