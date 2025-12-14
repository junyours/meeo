<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Tenant;
use App\Models\Payments;
use App\Models\Sections;
use Illuminate\Http\Request;
use App\Models\StallStatusLogs;
use App\Models\InchargeCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StallController extends Controller
{

      public function removeVendor($stallId)
    {
        return DB::transaction(function () use ($stallId) {
            // 1. Find the stall
            $stall = Stalls::findOrFail($stallId);

            // 2. Get the latest rented record for this stall that is still active
            // Adjust "status" condition based on your actual values (e.g., "occupied", "active", etc.)
            $rented = Rented::where('stall_id', $stall->id)
                ->orderBy('created_at', 'desc')
                ->first();

            // 3. If there is a rented record, mark it as unoccupied
            if ($rented) {
                $rented->status = 'unoccupied';   // <--- as you requested
                $rented->save();
            }

            // 4. Update stall status to vacant
            $stall->status = 'vacant';
            $stall->save();

            return response()->json([
                'message' => 'Vendor removed and stall marked as vacant.',
                'stall'   => $stall,
                'rented'  => $rented,
            ]);
        });
    }
   public function toggleActive(Request $request, Stalls $stall)
    {
        $request->validate([
            'is_active' => 'required|boolean',
            'message'   => 'nullable|string',
        ]);

        $stall->is_active = $request->is_active;
        $stall->message   = $request->message;
        $stall->save();

        // Save log entry
        StallStatusLogs::create([
            'stall_id'  => $stall->id,
            'is_active' => $request->is_active,
            'message'   => $request->message,
        ]);

        return response()->json([
            'success' => true,
            'stall'   => $stall,
        ]);
    }

    public function statusLogs(Stalls $stall)
    {
        return response()->json(
            $stall->statusLogs()->get()
        );
    }


  public function addStall(Request $request)
{
    $validated = $request->validate([
        'section_id'      => 'required|exists:section,id',
        'stall_number'    => 'required|string|max:50',
        'row_position'    => 'required|integer|min:1',
        'column_position' => 'required|integer|min:1',
        'size'            => 'nullable|string|max:50',
        // ❌ Remove status from validation
    ]);

    // ✅ Automatically set default status to "vacant"
    $validated['status'] = 'vacant';

    // Prevent duplicate stall position in the same section
    $exists = Stalls::where('section_id', $validated['section_id'])
        ->where('row_position', $validated['row_position'])
        ->where('column_position', $validated['column_position'])
        ->exists();

    if ($exists) {
        return response()->json([
            'status'  => 'error',
            'message' => 'A stall already exists at this position in the section.'
        ], 422);
    }

    $stall = Stalls::create($validated);

    return response()->json([
        'status'  => 'success',
        'message' => 'Stall created successfully.',
        'data'    => $stall
    ], 201);
}


public function store(Request $request)
{
    // Your validation and logic here

    // Save the tenant data
    $tenant = Tenant::create([
        'stall_id' => $request->stall_id,
        'fullname' => $request->fullname,
        'age' => $request->age,
        'gender' => $request->gender,
        'contact_number' => $request->contact_number,
        'address' => $request->address,
        'emergency_contact' => $request->emergency_contact,
        'business_name' => $request->business_name,
        'years_in_operation' => $request->years_in_operation,
        'product_type' => $request->product_type,
        'estimated_sales' => $request->estimated_sales,
        'peak_time' => $request->peak_time,
        'business' => $request->business,
        'sanitary' => $request->sanitary,
        'registration' => $request->registration,
        'dti' => $request->dti,
        'remarks' => $request->remarks,
        'week1' => $request->week1,
        'week2' => $request->week2,
        'week3' => $request->week3,
        'week4' => $request->week4,
        'total_sales' => $request->total_sales,
        'source' => $request->source,
        'purchase_location' => $request->purchase_location,
        'purchase_frequency' => $request->purchase_frequency,
        'transport_mode' => $request->transport_mode,
    ]);

    return response()->json(['message' => 'Tenant created successfully', 'tenant' => $tenant]);
}


     public function update(Request $request, $id)
    {
        $request->validate([
            'size'   => 'required|string|max:50',
            'status' => 'required|in:available,occupied,reserved',
        ]);

        $stall = Stalls::findOrFail($id);

        $stall->update([
            'size'   => $request->size,
            'status' => $request->status,
        ]);

        return response()->json([
            'success' => true,
            'data' => $stall->load('section') // return with section for frontend update
        ]);
    }

    public function destroy($id)
    {
        Stalls::destroy($id);
        return response()->json(['message' => 'Stall deleted']);
    }

    
    public function index()
{
    return Stalls::all(); // returns array of stall records with their ids
}

public function stallsForCollector(Request $request)
{
    // Get the currently authenticated user
    $user = $request->user();

    // Check if the user has an assignment as an in-charge collector
    $assignment = InchargeCollector::where('user_id', $user->id)->first();
    if (!$assignment) {
        return response()->json(['stalls' => [], 'already_remitted' => false]);
    }

    // Get the area assigned to the collector
    $area = $assignment->area;
    $today = now()->startOfDay();

    // Check if payments have already been remitted for today
    $alreadyRemitted = Payments::where('collector_id', $assignment->id)
        ->whereDate('created_at', $today)
        ->where('status', 'remitted')
        ->exists();

    // Build the query to fetch stalls that are occupied and rented
    $query = Stalls::with([
        'rented.application', 
        'rented.application.vendor', 
        'rented.payments', 
        'section'
    ])->whereHas('rented', fn($q) => $q->where('status', 'occupied'));

    // Filter stalls by section if area is not 'market'
    if ($area !== 'market') {
        $query->whereHas('section', fn($q) => $q->where('name', $area));
    }

    $stalls = $query->get();

    // Process each stall
    $stalls->each(function ($stall) use ($today, $alreadyRemitted) {
        if (!$stall->rented) return;

        $rented = $stall->rented;

        // Get the latest payment by creation date
        $lastPayment = $rented->payments()->orderByDesc('created_at')->first();

        // Flag for pending confirmation
        $rented->is_pending_confirmation = $lastPayment ? $lastPayment->status === 'pending_confirmation' : false;

        // Flag for paid today
        $rented->is_paid_today = $lastPayment 
            ? ($lastPayment->status === 'collected' && Carbon::parse($lastPayment->payment_date)->isSameDay($today))
            : false;

        // Base is_collectable
        $rented->is_collectable = !$alreadyRemitted && !$rented->is_pending_confirmation;

        $rented->missedDaysCount = 0;
        $rented->missedAmount = 0;
        $rented->is_advance_active = false;
        $rented->advance_days = 0;

        $rentedStart = Carbon::parse($rented->rented_date ?? $rented->created_at)->startOfDay();

        // First day handling
        if ($today->equalTo($rentedStart)) {
            $rented->is_collectable = false;
            $rented->status_label = 'First Day';
            $rented->status_color = 'gray';
            $rented->next_due_date = $rentedStart->copy()->addDay()->toDateString();
            return;
        }

        $paymentType = $rented->application?->payment_type ?? 'daily';
        $baseDate = $lastPayment ? Carbon::parse($lastPayment->payment_date)->startOfDay() : $rentedStart;

        // --- ADVANCE PAYMENT HANDLING (ALL STATUSES EXCEPT CANCELLED) ---
        $latestAdvancePayment = $rented->payments()
            ->where('payment_type', 'advance')
            ->orderByDesc('payment_date')
            ->first();

        if ($latestAdvancePayment && $latestAdvancePayment->advance_days > 0) {
            $advanceStart = Carbon::parse($latestAdvancePayment->payment_date)->addDay()->startOfDay();
            $advanceEnd = $advanceStart->copy()->addDays($latestAdvancePayment->advance_days - 1);

            if ($today->between($advanceStart, $advanceEnd)) {
                $rented->is_advance_active = true;
                $rented->advance_days = $latestAdvancePayment->advance_days;
                $baseDate = $advanceEnd->copy();

                // Adjust status_label based on advance payment status
                if ($latestAdvancePayment->status === 'pending') {
                    $rented->status_label = 'Pending To be Collected';
                    $rented->status_color = 'orange';
                } elseif ($latestAdvancePayment->status === 'collected' || $latestAdvancePayment->status === 'remitted') {
                    $rented->status_label = 'Advance Active';
                    $rented->status_color = 'blue';
                }
            } else {
                $baseDate = $advanceEnd->copy();
            }
        }

        // Missed days & amount calculation
        if ($rented->is_paid_today || $alreadyRemitted || $rented->is_pending_confirmation) {
            $rented->missedDaysCount = 0;
            $rented->missedAmount = 0;
            $rented->is_collectable = false;
        } elseif ($paymentType === 'daily') {
            $missedDays = $today->greaterThan($baseDate) ? $today->copy()->subDay()->diffInDays($baseDate) : 0;
            $rented->missedDaysCount = $missedDays;
            $rented->missedAmount = $missedDays * $rented->daily_rent;
            $rented->is_collectable = $missedDays > 0;
        } else {
            if ($rented->is_advance_active) {
                $rented->missedDaysCount = 0;
                $rented->missedAmount = 0;
                $rented->is_collectable = false;
            } else {
                $nextDueDate = $baseDate->copy()->addDay();
                $missedDays = $today->greaterThan($nextDueDate) ? $today->copy()->subDay()->diffInDays($nextDueDate) : 0;
                $rented->missedDaysCount = $missedDays;
                $rented->missedAmount = $missedDays * $rented->daily_rent;
                $rented->is_collectable = $missedDays > 0;
            }
        }

        // Status label fallback
        if (!isset($rented->status_label)) {
            if ($rented->is_pending_confirmation) {
                $rented->status_label = 'Pending Confirmation';
                $rented->status_color = 'orange';
            } elseif ($rented->is_paid_today) {
                $rented->status_label = 'Paid Today';
                $rented->status_color = 'green';
            } elseif ($rented->is_advance_active) {
                $rented->status_label = 'Advance Active';
                $rented->status_color = 'blue';
            } elseif ($rented->is_collectable) {
                $rented->status_label = 'Collectable';
                $rented->status_color = 'orange';
            } elseif ($rented->missedDaysCount > 0) {
                $rented->status_label = 'Missed';
                $rented->status_color = 'red';
            } else {
                $rented->status_label = 'No Payment';
                $rented->status_color = 'gray';
            }
        }

        $rented->next_due_date = $today->copy()->addDay()->toDateString();
        $rented->last_payment_date = $lastPayment?->payment_date;

        if ($alreadyRemitted) {
            $rented->is_collectable = false;
        }
    });

    return response()->json([
        'stalls' => $stalls,
        'already_remitted' => $alreadyRemitted
    ]);
}


public function getTenant($id)
{
    $stall = Stalls::with([
        'rented.application.vendor',
        'rented.application.section',
        'rented.payments'
    ])->findOrFail($id);

    // 🧩 If stall is inactive (under maintenance)
    if ($stall->is_active == false) {
        return response()->json([
            'stall_number' => $stall->stall_number,
            'stall_id'     => $stall->id,
            'is_active'    => false,
            'message'      => $stall->message ?? 'Under Maintenance',
        ]);
    }

    // 🧩 If no tenant found
    if (
        !$stall->rented ||
        !$stall->rented->application ||
        !$stall->rented->application->vendor
    ) {
        return response()->json([
            'stall_number'  => $stall->stall_number,
            'stall_id'      => $stall->id,
            'vendor'        => null,
            'application'   => null,
            'rented'        => null,
            'section'       => null,
            'payment_type'  => null,
            'advance_days'  => null,
            'amount'        => null,
            'next_due_date' => null,
            'missed_days'   => 0,
            'is_active'     => $stall->is_active,
        ]);
    }

    $latestPayment = $stall->rented->payments()->latest('payment_date')->first();

    $paymentType = 'N/A';
    $advanceDays = null;
    $amount      = null;
    $nextDueDate = null;
    $missedDays  = 0;
    $today       = now();

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

        // Original due date based on payment
        $dueDate = $latestPayment->payment_date->copy()->addDays($advanceDays ?? 1);

        // Compute missed days based on original due date
        if ($today->gt($dueDate)) {
            $missedDays = $today->diffInDays($dueDate);
        }

        // 🔹 Adjust the next due date:
        // if original due date is today or in the past -> set to tomorrow
        if ($dueDate->lte($today)) {
            $nextDueDate = $today->copy()->addDay(); // tomorrow
        } else {
            $nextDueDate = $dueDate; // still in the future
        }

    } else {
        // No payments yet → base on rental start
        $startDate  = $stall->rented->created_at->copy();
        $originalDue = $startDate->copy()->addDay();

        if ($today->gt($startDate)) {
            $missedDays = $today->diffInDays($startDate);
        }

        // 🔹 Same rule: if due already passed or today, set to tomorrow
        if ($originalDue->lte($today)) {
            $nextDueDate = $today->copy()->addDay();
        } else {
            $nextDueDate = $originalDue;
        }
    }

    return response()->json([
        'stall_number'   => $stall->stall_number,
        'stall_id'       => $stall->id,
        'vendor'         => $stall->rented->application->vendor,
        'application'    => $stall->rented->application,
        'rented'         => $stall->rented,
        'section'        => $stall->rented->application->section,
        'payment_type'   => $paymentType,
        'advance_days'   => $advanceDays,
        'amount'         => $amount,
        'next_due_date'  => $nextDueDate ? $nextDueDate->toDateString() : null,
        'missed_days'    => $missedDays,
        'is_active'      => $stall->is_active,
    ]);
}



public function getTenantHistory($id)
{
    $stall = Stalls::with([
        'rented.application.vendor',
        'rented.application.section'
    ])->findOrFail($id);

    $history = $stall->rentals()
        ->with(['application.vendor', 'payments']) // ⬅ make sure payments relation exists
        ->orderByDesc('created_at')
        ->get();

    $today = now();

    $formatted = $history->map(function ($r) use ($today) {
        // 🔹 Date range for this rental
        $startDate = $r->created_at->format('F d, Y');
        $endDate = ($r->status === 'unoccupied' && $r->updated_at)
            ? $r->updated_at->format('F d, Y')
            : 'Present';

        // 🔹 Compute missed days & related info using SAME LOGIC as getTenant
        $latestPayment = $r->payments()->latest('payment_date')->first();

        $paymentType  = 'N/A';
        $advanceDays  = null;
        $amount       = null;
        $nextDueDate  = null;
        $missedDays   = 0;

        if ($latestPayment) {
            if ($latestPayment->payment_type === 'advance') {
                $paymentType = 'Advance';
                $advanceDays = $latestPayment->advance_days;
            } elseif ($latestPayment->payment_type === 'daily') {
                $paymentType = 'Daily';
            } else {
                $paymentType = ucfirst($latestPayment->payment_type);
            }

            $amount      = $latestPayment->amount;
            $nextDueDate = $latestPayment->payment_date->copy()->addDays($advanceDays ?? 1);

            if ($today->gt($nextDueDate)) {
                $missedDays = $today->diffInDays($nextDueDate);
            }
        } else {
            // If no payment for this rental, base on rental start
            $start = $r->created_at->copy();
            $nextDueDate = $start->copy()->addDay();

            if ($today->gt($start)) {
                $missedDays = $today->diffInDays($start);
            }
        }

        // 🔹 Remaining balance = missed_days * daily_rent
        $dailyRent         = $r->daily_rent ?? 0;
        $remainingBalance  = $missedDays * $dailyRent;

        return [
            'vendor_name'        => $r->application->vendor->fullname ?? '—',
            'start_date'         => $startDate,
            'end_date'           => $endDate,
            'id'                 => $r->id,
            'daily_rent'         => $r->daily_rent,
            'monthly_rent'       => $r->monthly_rent,
            'payment_type'       => $paymentType,
            'advance_days'       => $advanceDays,
            'amount'             => $amount,
            'next_due_date'      => $nextDueDate ? $nextDueDate->toDateString() : null,
            'missed_days'        => $missedDays,
            'remaining_balance'  => $remainingBalance,
        ];
    });

    return response()->json([
        'stall_id' => $stall->id,
        'history'  => $formatted,
    ]);
}



}
