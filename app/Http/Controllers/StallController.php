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
use App\Services\StallRateHistoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StallController extends Controller
{
    protected $rateHistoryService;

    public function __construct(StallRateHistoryService $rateHistoryService)
    {
        $this->rateHistoryService = $rateHistoryService;
    }

      public function removeVendor(Request $request, $stallId)
    {
        return DB::transaction(function () use ($request, $stallId) {
            // 1. Find the stall
            $stall = Stalls::findOrFail($stallId);

            // 2. Get the currently active rented record for this stall
            // Only get records that are currently active/occupied
            $rented = Rented::where('stall_id', $stall->id)
                ->whereIn('status', ['occupied', 'active', 'advance', 'temp_closed', 'partial', 'fully paid'])
                ->orderBy('created_at', 'desc')
                ->first();

            // 3. If there is an active rented record, mark it as unoccupied
            if ($rented) {
                $rented->status = 'unoccupied';
                
                // Set the unoccupied date if provided, otherwise use current time
                if ($request->has('unoccupied_date') && $request->unoccupied_date) {
                    $unoccupiedDate = \Carbon\Carbon::parse($request->unoccupied_date);
                    $rented->updated_at = $unoccupiedDate;
                }
                
                $rented->save();
                
                Log::info('Vendor removed from stall', [
                    'stall_id' => $stallId,
                    'rented_id' => $rented->id,
                    'vendor_id' => $rented->vendor_id,
                    'previous_status' => 'occupied',
                    'new_status' => 'unoccupied'
                ]);
            } else {
                Log::info('No active rental found for stall', [
                    'stall_id' => $stallId
                ]);
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
            'daily_rate'      => 'nullable|numeric|min:0',
            'monthly_rate'    => 'nullable|numeric|min:0',
            'is_monthly'      => 'nullable|boolean',
            'effective_date'   => 'nullable|date|after_or_equal:today',
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

        return DB::transaction(function () use ($validated, $request) {
            $stall = Stalls::create($validated);

            // Create initial rate history record if rates are provided
            if ($validated['daily_rate'] || $validated['monthly_rate']) {
                $effectiveDate = $validated['effective_date'] ?? now()->toDateString();
                $this->rateHistoryService->createRateHistory(
                    $stall->id,
                    $validated['daily_rate'],
                    $validated['monthly_rate'],
                    $effectiveDate
                );
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Stall created successfully.',
                'data'    => $stall->load('section')
            ], 201);
        });
    }

    public function updateStallRent(Request $request, $id)
    {
        $request->validate([
            'daily_rate'   => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'annual_rate'  => 'nullable|numeric|min:0',
            'is_monthly'   => 'nullable|boolean',
            'effective_date' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $stall = Stalls::findOrFail($id);
            
            // Debug logging
            Log::info('Updating stall rent', [
                'stall_id' => $id,
                'old_daily_rate' => $stall->daily_rate,
                'new_daily_rate' => $request->daily_rate,
                'old_monthly_rate' => $stall->monthly_rate,
                'new_monthly_rate' => $request->monthly_rate,
                'old_annual_rate' => $stall->annual_rate,
                'new_annual_rate' => $request->annual_rate,
                'effective_date' => $request->input('effective_date', now()->toDateString())
            ]);

            // Check if rates are actually changing
            $ratesChanged = (
                ($request->daily_rate != $stall->daily_rate) || 
                ($request->monthly_rate != $stall->monthly_rate) ||
                ($request->annual_rate != $stall->annual_rate) ||
                ($request->has('is_monthly') && $request->is_monthly != $stall->is_monthly)
            );
            
            Log::info('Rates changed check', ['ratesChanged' => $ratesChanged]);

            // Update stall rent rates
            $updateData = [
                'daily_rate'   => $request->daily_rate,
                'monthly_rate' => $request->monthly_rate,
                'annual_rate'  => $request->annual_rate,
            ];
            
            // Update is_monthly if provided
            if ($request->has('is_monthly')) {
                $updateData['is_monthly'] = $request->is_monthly;
            }
            
            $stall->update($updateData);

            // Create rate history record if rates changed AND effective date is provided
            $effectiveDate = $request->input('effective_date');
            if ($ratesChanged && !empty($effectiveDate)) {
                Log::info('Creating rate history', [
                    'stall_id' => $id,
                    'daily_rate' => $request->daily_rate,
                    'monthly_rate' => $request->monthly_rate,
                    'annual_rate' => $request->annual_rate,
                    'effective_date' => $effectiveDate
                ]);
                
                try {
                    $rateHistory = $this->rateHistoryService->createRateHistory(
                        $id,
                        $request->daily_rate,
                        $request->monthly_rate,
                        $effectiveDate,
                        $request->annual_rate
                    );
                    Log::info('Rate history created successfully', ['rate_history_id' => $rateHistory->id]);
                } catch (\Exception $e) {
                    Log::error('Failed to create rate history', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // Don't fail the whole transaction if rate history fails
                }
            } elseif ($ratesChanged && empty($effectiveDate)) {
                Log::info('Rates changed but no effective date provided, skipping rate history creation');
            } else {
                Log::info('Rates did not change, skipping history creation');
            }

            // Find and update active rented records for this stall
            // Only update if effective date is today or in the past
            $today = now()->toDateString();
            $effectiveDate = $request->input('effective_date', $today);
            $shouldUpdateRentedRecords = ($effectiveDate <= $today);
            
            $updatedRentedCount = 0;
            if ($shouldUpdateRentedRecords) {
                $activeRentedRecords = Rented::where('stall_id', $id)
                    ->where('status', 'occupied')
                    ->get();

                foreach ($activeRentedRecords as $rented) {
                    $rented->update([
                        'daily_rent'   => $request->daily_rate,
                        'monthly_rent' => $request->monthly_rate,
                    ]);
                    $updatedRentedCount++;
                }
                
                Log::info('Updated rented records with new rates', [
                    'stall_id' => $id,
                    'effective_date' => $effectiveDate,
                    'updated_count' => $updatedRentedCount
                ]);
            } else {
                Log::info('Skipping rented record update due to future effective date', [
                    'stall_id' => $id,
                    'effective_date' => $effectiveDate,
                    'today' => $today
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => "Stall rent rates updated successfully. Updated {$updatedRentedCount} active rental record(s)." . ($ratesChanged ? " Rate history recorded." : ""),
                'data'    => $stall->load('section'),
                'updated_rented_records' => $updatedRentedCount,
                'rate_history_created' => $ratesChanged,
                'effective_date' => $request->input('effective_date', now()->toDateString())
            ]);
        });
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
    $stalls = Stalls::with('section')->get();
    
    // Compute rent rates for each stall
    $stalls->each(function ($stall) {
        $stall->computed_daily_rate = $this->computeDailyRate($stall);
        $stall->computed_monthly_rate = $this->computeMonthlyRate($stall);
    });
    
    return $stalls;
}

private function computeDailyRate($stall)
{
    // If stall has individual daily rate, use it
    if ($stall->daily_rate) {
        return $stall->daily_rate;
    }
    
    // Fall back to section rates
    if (!$stall->section) {
        return 0;
    }
    
    if ($stall->section->rate_type === 'per_sqm' && $stall->size) {
        return $stall->section->rate * $stall->size;
    }
    
    return $stall->section->daily_rate ?? 0;
}

private function computeMonthlyRate($stall)
{
    // If stall has individual monthly rate, use it
    if ($stall->monthly_rate) {
        return $stall->monthly_rate;
    }
    
    // Fall back to section rates
    if (!$stall->section) {
        return 0;
    }
    
    if ($stall->section->rate_type === 'per_sqm' && $stall->size) {
        $dailyRate = $stall->section->rate * $stall->size;
        return $dailyRate * 31; // Monthly rate = daily rate * 31
    }
    
    return $stall->section->monthly_rate ?? 0;
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

        // Flag for paid today - check if payment exists for today
        $rented->is_paid_today = $lastPayment 
            ? ($lastPayment->status === 'collected' && Carbon::parse($lastPayment->payment_date)->isSameDay($today))
            : false;

        // Base is_collectable
        $rented->is_collectable = !$alreadyRemitted && !$rented->is_pending_confirmation;

        $rented->missedDaysCount = 0;
        $rented->missedAmount = 0;
        $rented->is_advance_active = false;
        $rented->advance_days = 0;

        // Use created_at as the rental start date for missed days calculation
        $rentalStart = Carbon::parse($rented->created_at)->startOfDay();

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
        } elseif ($stall->is_monthly) {
            // Monthly stall: calculate missed months
            $missedMonths = 0;
            $expectedPaymentMonth = $rentalStart->copy();
            
            // Count each month from rental start until yesterday that doesn't have a payment
            while ($expectedPaymentMonth->lt($today)) {
                $hasPaymentForMonth = $rented->payments()
                    ->whereYear('payment_date', $expectedPaymentMonth->year)
                    ->whereMonth('payment_date', $expectedPaymentMonth->month)
                    ->whereIn('status', ['collected', 'remitted'])
                    ->exists();
                
                if (!$hasPaymentForMonth) {
                    $missedMonths++;
                }
                
                $expectedPaymentMonth->addMonth();
            }
            
            $rented->missedDaysCount = $missedMonths; // Store months in missed_days field
            $rented->missedAmount = $missedMonths * ($rented->monthly_rent ?: $rented->daily_rent * 30);
            $rented->is_collectable = $missedMonths > 0;
        } elseif ($paymentType === 'daily') {
            // Calculate missed days from rental start date
            // If rental started on Jan 1 and today is Jan 2 with no payment for Jan 1, missed days = 1
            $expectedPaymentDate = $rentalStart->copy();
            $missedDays = 0;
            
            // Count each day from rental start until yesterday that doesn't have a payment
            while ($expectedPaymentDate->lt($today)) {
                $hasPaymentForDay = $rented->payments()
                    ->where('payment_type', 'daily')
                    ->whereDate('payment_date', $expectedPaymentDate)
                    ->whereIn('status', ['collected', 'remitted'])
                    ->exists();
                
                if (!$hasPaymentForDay) {
                    $missedDays++;
                }
                
                $expectedPaymentDate->addDay();
            }
            
            $rented->missedDaysCount = $missedDays;
            $rented->missedAmount = $missedDays * $rented->daily_rent;
            $rented->is_collectable = $missedDays > 0;
        } else {
            if ($rented->is_advance_active) {
                $rented->missedDaysCount = 0;
                $rented->missedAmount = 0;
                $rented->is_collectable = false;
            } else {
                // For monthly payments, calculate from the day after rental start
                $nextDueDate = $rentalStart->copy()->addDay();
                $missedDays = 0;
                
                // Count missed days from due date until yesterday
                $currentDate = $nextDueDate->copy();
                while ($currentDate->lt($today)) {
                    $hasPaymentForDay = $rented->payments()
                        ->whereDate('payment_date', $currentDate)
                        ->whereIn('status', ['collected', 'remitted'])
                        ->exists();
                    
                    if (!$hasPaymentForDay) {
                        $missedDays++;
                    }
                    
                    $currentDate->addDay();
                }
                
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
                // Check if missed days/months >= 4 for temp_closed color
                if ($rented->missedDaysCount >= 4) {
                    $rented->status_label = 'Temp Closed';
                    $rented->status_color = '#ff4d00ff';
                } else {
                    $rented->status_label = 'Collectable';
                    $rented->status_color = 'orange';
                }
            } elseif ($rented->missedDaysCount > 0) {
                // Check if missed days/months >= 4 for temp_closed color
                if ($rented->missedDaysCount >= 4) {
                    $rented->status_label = 'Temp Closed';
                    $rented->status_color = '#ff4d00ff';
                } else {
                    // Red color for 1-3 missed days/months
                    $rented->status_label = 'Missed';
                    $rented->status_color = '#eb1414ff';
                }
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
        'currentRental.vendor',
        'currentRental.payments',
        'section'
    ])->findOrFail($id);

    // Compute rent rates
    $computedDailyRate = $this->computeDailyRate($stall);
    $computedMonthlyRate = $this->computeMonthlyRate($stall);

    // 🧩 If stall is inactive (under maintenance)
    if ($stall->is_active == false) {
        return response()->json([
            'stall_number' => $stall->stall_number,
            'stall_id'     => $stall->id,
            'is_active'    => false,
            'message'      => $stall->message ?? 'Under Maintenance',
            'daily_rent'   => $computedDailyRate,
            'monthly_rent' => $computedMonthlyRate,
            'is_monthly'   => $stall->is_monthly,
        ]);
    }

    // 🧩 If no tenant found
    if (
        !$stall->currentRental ||
        !$stall->currentRental->vendor
    ) {
        return response()->json([
            'stall_number'  => $stall->stall_number,
            'stall_id'      => $stall->id,
            'vendor'        => null,
            'rented'        => null,
            'section'       => $stall->section,
            'payment_type'  => null,
            'advance_days'  => null,
            'amount'        => null,
            'status'        => $stall->status,
            'next_due_date' => null,
            'missed_days'   => 0,
            'is_active'     => $stall->is_active,
            'daily_rent'    => $computedDailyRate,
            'monthly_rent'  => $computedMonthlyRate,
            'is_monthly'    => $stall->is_monthly,
        ]);
    }

    $latestPayment = $stall->currentRental->payments()->latest('payment_date')->first();

    $paymentType      = '-';
    $advanceDays      = null;
    $amount           = null;
    $nextDueDate      = null;
    $missedDays       = 0;
    $remainingBalance = null;
    $today            = now()->startOfDay();

    // Use created_at as rental start date for missed days calculation
    $rentalStart = $stall->currentRental->created_at->copy()->startOfDay();

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

        // Calculate missed days based on payment history
        if ($stall->is_monthly) {
            // Monthly stall: calculate missed months
            $missedMonths = 0;
            $expectedPaymentMonth = $rentalStart->copy();
            
            while ($expectedPaymentMonth->lt($today)) {
                $hasPaymentForMonth = $stall->currentRental->payments()
                    ->whereYear('payment_date', $expectedPaymentMonth->year)
                    ->whereMonth('payment_date', $expectedPaymentMonth->month)
                    ->whereIn('status', ['collected', 'remitted'])
                    ->exists();
                
                if (!$hasPaymentForMonth) {
                    $missedMonths++;
                }
                
                $expectedPaymentMonth->addMonth();
            }
            
            $missedDays = $missedMonths; // Store months in missed_days field
        } elseif ($paymentType === 'Daily') {
            // Count unpaid days from rental start
            $expectedPaymentDate = $rentalStart->copy();
            $calculatedMissedDays = 0;
            
            while ($expectedPaymentDate->lt($today)) {
                $hasPaymentForDay = $stall->currentRental->payments()
                    ->where('payment_type', 'daily')
                    ->whereDate('payment_date', $expectedPaymentDate)
                    ->whereIn('status', ['collected', 'remitted'])
                    ->exists();
                
                if (!$hasPaymentForDay) {
                    $calculatedMissedDays++;
                }
                
                $expectedPaymentDate->addDay();
            }
            
            $missedDays = $calculatedMissedDays;
        } else {
            // For advance or other payment types
            $dueDate = $latestPayment->payment_date->copy()->addDays($advanceDays ?? 1);
            $missedDays = $today->gt($dueDate) ? $today->diffInDays($dueDate) : 0;
        }

        // Set next due date to tomorrow if there are missed days or if paid today
        if ($missedDays > 0 || Carbon::parse($latestPayment->payment_date)->isSameDay($today)) {
            $nextDueDate = $today->copy()->addDay();
        } else {
            $nextDueDate = $latestPayment->payment_date->copy()->addDays($advanceDays ?? 1);
        }

    } else {
        // No payments yet - calculate missed days/months from rental start
        if ($stall->is_monthly) {
            // Monthly stall: calculate missed months
            $missedMonths = 0;
            $expectedPaymentMonth = $rentalStart->copy();
            
            while ($expectedPaymentMonth->lt($today)) {
                $missedMonths++;
                $expectedPaymentMonth->addMonth();
            }
            
            $missedDays = $missedMonths;
        } else {
            // Daily stall: calculate missed days
            $calculatedMissedDays = 0;
            $expectedPaymentDate = $rentalStart->copy();
            
            while ($expectedPaymentDate->lt($today)) {
                $calculatedMissedDays++;
                $expectedPaymentDate->addDay();
            }
            
            $missedDays = $calculatedMissedDays;
        }
        $nextDueDate = $today->copy()->addDay();
    }

    // Don't override with stored missed_days - use calculated value like AreaController
    // This ensures consistency between SectionManager and individual stall views

    // Use stored remaining_balance when present, otherwise compute as rent * missedDays/Months
    if ($stall->currentRental) {
        if ($stall->currentRental->remaining_balance !== null && $stall->currentRental->remaining_balance > 0) {
            $remainingBalance = (float) $stall->currentRental->remaining_balance;
        } elseif ($missedDays > 0) {
            if ($stall->is_monthly) {
                // Monthly stall: use monthly rent
                $monthlyRent = (float) ($stall->currentRental->monthly_rent ?? $computedMonthlyRate ?? 0);
                $totalMissedAmount = $missedDays * $monthlyRent;
            } else {
                // Daily stall: use daily rent
                $dailyRent = (float) ($stall->currentRental->daily_rent ?? 0);
                $totalMissedAmount = $missedDays * $dailyRent;
            }

            if ($totalMissedAmount > 0) {
                $remainingBalance = $totalMissedAmount;
            }
        }
    }

    return response()->json([
        'stall_number'   => $stall->stall_number,
        'stall_id'       => $stall->id,
        'vendor'         => $stall->currentRental->vendor,
        'rented'         => $stall->currentRental,
        'status'         => $stall->status,

        'section'        => $stall->section,
        'payment_type'   => $paymentType,
        'advance_days'   => $advanceDays,
        'amount'         => $amount,
        'next_due_date'  => $nextDueDate ? $nextDueDate->toDateString() : null,
        'missed_days'    => $missedDays,
        'is_active'      => $stall->is_active,
        'rented_status'  => $stall->currentRental->status ?? null,
        'rented_id'      => $stall->currentRental->id ?? null,
        'vendor_id'      => $stall->currentRental->vendor->id ?? null,
        'remaining_balance' => $remainingBalance,
        'daily_rent'     => $computedDailyRate,
        'monthly_rent'   => $computedMonthlyRate,
        'is_monthly'     => $stall->is_monthly,
    ]);
}



public function getTenantHistory($id)
{
    $stall = Stalls::with([
        'rentals.vendor',
        'rentals.payments'
    ])->findOrFail($id);

    $history = $stall->rentals()
        ->with(['vendor', 'payments']) 
        ->orderByDesc('created_at')
        ->get();

    // Debug log to see what we're working with
    Log::info('Getting tenant history for stall', [
        'stall_id' => $id,
        'total_rentals' => $history->count(),
        'rentals' => $history->map(function($r) {
            return [
                'id' => $r->id,
                'vendor_id' => $r->vendor_id,
                'vendor_name' => $r->vendor ? $r->vendor->first_name : 'Unknown',
                'status' => $r->status,
                'created_at' => $r->created_at->toDateTimeString(),
                'updated_at' => $r->updated_at->toDateTimeString(),
            ];
        })
    ]);

    $today = now()->startOfDay();

    $formatted = $history->map(function ($r) use ($today) {
        // 🔹 Date range for this rental
        $startDate = $r->created_at->format('F d, Y');
        
        // Debug each rental record
        Log::info('Processing rental record', [
            'rental_id' => $r->id,
            'vendor_name' => $r->vendor ? $r->vendor->first_name : 'Unknown',
            'status' => $r->status,
            'updated_at' => $r->updated_at ? $r->updated_at->toDateTimeString() : null,
            'is_unoccupied' => $r->status === 'unoccupied',
            'has_updated_at' => !empty($r->updated_at)
        ]);
        
        $endDate = ($r->status === 'unoccupied' && $r->updated_at)
            ? $r->updated_at->format('F d, Y')
            : 'Present';

        // 🔹 Compute missed days & related info using SAME LOGIC as stallsForCollector
        $latestPayment = $r->payments()->latest('payment_date')->first();

        $paymentType  = 'N/A';
        $advanceDays  = null;
        $amount       = null;
        $nextDueDate  = null;
        $missedDays   = 0;

        // Use created_at as rental start date for missed days calculation
        $rentalStart = $r->created_at->copy()->startOfDay();

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

            // Calculate missed days based on payment history
            if ($paymentType === 'Daily') {
                // Count unpaid days from rental start
                $expectedPaymentDate = $rentalStart->copy();
                $calculatedMissedDays = 0;
                
                while ($expectedPaymentDate->lt($today)) {
                    $hasPaymentForDay = $r->payments()
                        ->where('payment_type', 'daily')
                        ->whereDate('payment_date', $expectedPaymentDate)
                        ->whereIn('status', ['collected', 'remitted'])
                        ->exists();
                    
                    if (!$hasPaymentForDay) {
                        $calculatedMissedDays++;
                    }
                    
                    $expectedPaymentDate->addDay();
                }
                
                $missedDays = $calculatedMissedDays;
            } else {
                // For advance or other payment types
                $dueDate = $latestPayment->payment_date->copy()->addDays($advanceDays ?? 1);
                $missedDays = $today->gt($dueDate) ? $today->diffInDays($dueDate) : 0;
            }
            
            $nextDueDate = $latestPayment->payment_date->copy()->addDays($advanceDays ?? 1);

        } else {
            // If no payment for this rental, base on rental start
            $calculatedMissedDays = 0;
            $expectedPaymentDate = $rentalStart->copy();
            
            while ($expectedPaymentDate->lt($today)) {
                $calculatedMissedDays++;
                $expectedPaymentDate->addDay();
            }
            
            $missedDays = $calculatedMissedDays;
            $nextDueDate = $rentalStart->copy()->addDay();
        }

        // 🔹 If rental is temp_closed and a stored missed_days exists, prefer it so partial payments
        // reflect the remaining days instead of always recomputing from dates.
        if ($r->status === 'temp_closed' && $r->missed_days !== null) {
            $missedDays = (int) $r->missed_days;
        }

        // 🔹 Remaining balance = missed_days * daily_rent
        $dailyRent         = $r->daily_rent ?? 0;
        $remainingBalance  = $missedDays * $dailyRent;

        return [
            'vendor_name'        => $r->vendor->first_name ?? '—',
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

/**
 * Process pending rate updates when they become effective
 * This method should be called periodically (e.g., daily cron job)
 */
public function processPendingRateUpdates()
{
    $today = now()->toDateString();
    $processedCount = 0;
    
    Log::info('Processing pending rate updates', ['date' => $today]);
    
    // Find all rate history records that become effective today
    $effectiveRateHistories = StallRateHistory::where('effective_from', $today)
        ->get();
        
    foreach ($effectiveRateHistories as $rateHistory) {
        $stallId = $rateHistory->stall_id;
        
        // Find active rented records for this stall
        $activeRentedRecords = Rented::where('stall_id', $stallId)
            ->where('status', 'occupied')
            ->get();
            
        foreach ($activeRentedRecords as $rented) {
            $rented->update([
                'daily_rent'   => $rateHistory->daily_rate,
                'monthly_rent' => $rateHistory->monthly_rate,
            ]);
            $processedCount++;
        }
        
        Log::info('Processed rate history', [
            'rate_history_id' => $rateHistory->id,
            'stall_id' => $stallId,
            'daily_rate' => $rateHistory->daily_rate,
            'monthly_rate' => $rateHistory->monthly_rate,
            'updated_rented_records' => count($activeRentedRecords)
        ]);
    }
    
    Log::info('Completed processing pending rate updates', [
        'processed_histories' => $effectiveRateHistories->count(),
        'updated_rented_records' => $processedCount
    ]);
    
    return response()->json([
        'success' => true,
        'message' => "Processed {$effectiveRateHistories->count()} rate histories, updated {$processedCount} rented records.",
        'processed_histories' => $effectiveRateHistories->count(),
        'updated_rented_records' => $processedCount
    ]);
}



}
