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
        $request->validate([
            'settlement_amount' => 'nullable|numeric|min:0',
            'unoccupied_date' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($request, $stallId) {
            // 1. Find the stall
            $stall = Stalls::with('section')->findOrFail($stallId);

            // 2. Get the currently active rented record for this stall
            // Only get records that are currently active/occupied
            $rented = Rented::with('payments')->where('stall_id', $stall->id)
                ->whereIn('status', ['occupied', 'active', 'advance', 'temp_closed', 'partial', 'fully paid'])
                ->orderBy('created_at', 'desc')
                ->first();
            $settlementAmount = 0;

            // 3. If there is an active rented record, mark it as unoccupied
            if ($rented) {
                $unoccupiedAt = $request->filled('unoccupied_date')
                    ? Carbon::parse($request->unoccupied_date)
                    : now();
                $balanceAtExit = $this->calculateRentalBalanceAtExit($rented, $stall, $unoccupiedAt);
                $rate = $balanceAtExit['rate'];
                $remainingBalance = $this->calculateRentalAnalysisBalance($rented, $stall, $unoccupiedAt);

                $settlementAmount = round((float) $request->input('settlement_amount', 0), 2);
                if ($settlementAmount > $remainingBalance) {
                    return response()->json([
                        'message' => 'Settlement amount cannot exceed the outstanding balance.',
                    ], 422);
                }

                if ($settlementAmount > 0) {
                    Payments::create([
                        'rented_id' => $rented->id,
                        'vendor_id' => $rented->vendor_id,
                        'payment_type' => $settlementAmount >= $remainingBalance ? 'fully paid' : 'partial',
                        'amount' => $settlementAmount,
                        'payment_date' => now()->toDateString(),
                        'missed_days' => $rate > 0 ? min((int) floor($settlementAmount / $rate), (int) $rented->missed_days) : 0,
                        'advance_days' => 0,
                        'status' => 'collected',
                    ]);
                }

                $rented->remaining_balance = max(0, round($remainingBalance - $settlementAmount, 2));
                $rented->missed_days = $rate > 0
                    ? (int) ceil($rented->remaining_balance / $rate)
                    : 0;
                $rented->status = 'unoccupied';
                
                // Set the unoccupied date if provided, otherwise use current time
                if ($request->has('unoccupied_date') && $request->unoccupied_date) {
                    $rented->updated_at = $unoccupiedAt;
                }
                
                $rented->save();
                
                Log::info('Vendor removed from stall', [
                    'stall_id' => $stallId,
                    'rented_id' => $rented->id,
                    'vendor_id' => $rented->vendor_id,
                    'settlement_amount' => $settlementAmount,
                    'remaining_balance' => $rented->remaining_balance,
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
                'settlement_amount' => $rented ? $settlementAmount : 0,
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
            'effective_date'  => 'nullable|date|after_or_equal:today',
        ]);

        $validated['status'] = 'vacant';

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

        return DB::transaction(function () use ($validated) {
            $stall = Stalls::create($validated);

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
        'section',
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

    $formatted = $history->map(function ($r) use ($today, $stall) {
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

        // 🔹 Compute missed days & related info
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

        $isMonthly = (bool) ($r->stall->is_monthly ?? false);
        if ($r->status === 'unoccupied' && $r->updated_at) {
            $balanceAtExit = $this->calculateRentalBalanceAtExit($r, $r->stall, $r->updated_at);
            $remainingBalance = $this->calculateRentalAnalysisBalance($r, $stall);
            $missedDays = (int) ($r->missed_days ?? 0) > 0
                ? (int) $r->missed_days
                : $balanceAtExit['missed_periods'];
        } else {
            $rate = $isMonthly ? (float) ($r->monthly_rent ?? 0) : (float) ($r->daily_rent ?? 0);
            $remainingBalance = $r->remaining_balance !== null && (float) $r->remaining_balance > 0
                ? (float) $r->remaining_balance
                : $missedDays * $rate;
        }

        return [
            'vendor_name'        => $r->vendor->first_name ?? '—',
            'start_date'         => $startDate,
            'end_date'           => $endDate,
            'id'                 => $r->id,
            'status'             => $r->status,
            'is_monthly'         => $isMonthly,
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

private function calculateRentalAnalysisBalance(Rented $rented, Stalls $stall, ?Carbon $asOfDate = null): float
{
    $year = $asOfDate?->year ?? now()->year;
    $rentalStart = $rented->created_at->copy()->startOfDay();
    $rentalEnd = $asOfDate
        ? $asOfDate->copy()->endOfDay()
        : ($rented->status === 'unoccupied' && $rented->updated_at
            ? $rented->updated_at->copy()->endOfDay()
            : Carbon::create($year, 12, 31)->endOfDay());
    $section = $stall->section;
    $balance = 0;

    for ($month = 1; $month <= 12; $month++) {
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
        $historicalDailyRate = $this->rateHistoryService->getDailyRateForMonth($stall->id, $year, $month);
        $historicalMonthlyRate = $this->rateHistoryService->getMonthlyRateForMonth($stall->id, $year, $month);
        $hasStallDailyRate = !is_null($historicalDailyRate) && $historicalDailyRate > 0;
        $hasStallMonthlyRate = !is_null($historicalMonthlyRate) && $historicalMonthlyRate > 0;

        if ($hasStallDailyRate && $hasStallMonthlyRate) {
            if ($stall->is_monthly || ($stall->stall_number == 16 && strtolower($section->name) === 'meat & fish')) {
                $dailyRate = $historicalMonthlyRate / $daysInMonth;
            } else {
                $dailyRate = $historicalDailyRate;
            }
        } elseif ($section->rate_type === 'fixed') {
            $dailyRate = (float) ($section->monthly_rate ?? 0) / $daysInMonth;
        } else {
            $dailyRate = $hasStallDailyRate
                ? $historicalDailyRate
                : (float) ($rented->daily_rent ?? 0);
        }

        $monthlyRate = 0;
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $currentDay = Carbon::create($year, $month, $day)->startOfDay();
            if ($rentalStart->greaterThan($currentDay) || ($rentalEnd && $rentalEnd->lessThan($currentDay))) {
                continue;
            }

            $monthlyRate += $dailyRate;
        }

        $monthlyPayments = $rented->payments
            ->filter(function ($payment) use ($year, $month) {
                return in_array($payment->status, ['paid', 'collected'], true)
                    && $payment->payment_date->year === $year
                    && $payment->payment_date->month === $month;
            })
            ->sum('amount');

        $balance += max(0, $monthlyRate - $monthlyPayments);
    }

    return round($balance, 2);
}

public function getRentalBalanceAtDate(Request $request, $rentedId)
{
    $validated = $request->validate([
        'date' => 'required|date|before_or_equal:today',
    ]);

    $rented = Rented::with(['stall.section', 'payments'])->findOrFail($rentedId);
    if (!$rented->stall || !$rented->stall->section || $rented->status === 'unoccupied') {
        return response()->json(['message' => 'Balance quotes are only available for active rentals.'], 422);
    }

    $asOfDate = Carbon::parse($validated['date']);
    if ($asOfDate->lt($rented->created_at->copy()->startOfDay())) {
        return response()->json(['message' => 'The selected date cannot be before this rental started.'], 422);
    }

    return response()->json([
        'remaining_balance' => $this->calculateRentalAnalysisBalance($rented, $rented->stall, $asOfDate),
    ]);
}

private function calculateRentalBalanceAtExit(Rented $rented, Stalls $stall, Carbon $exitDate): array
{
    $startDate = $rented->created_at->copy()->startOfDay();
    $endDate = $exitDate->copy()->startOfDay();
    $isMonthly = (bool) $stall->is_monthly;
    $rate = $isMonthly
        ? (float) ($rented->monthly_rent ?? $stall->monthly_rate ?? 0)
        : (float) ($rented->daily_rent ?? $stall->daily_rate ?? 0);
    $periods = 0;

    if ($rate > 0 && $startDate->lt($endDate)) {
        if ($isMonthly) {
            $period = $startDate->copy()->startOfMonth();
            while ($period->lt($endDate->copy()->startOfMonth())) {
                $periods++;
                $period->addMonth();
            }
        } else {
            $period = $startDate->copy();
            while ($period->lt($endDate)) {
                $periods++;
                $period->addDay();
            }
        }
    }

    $paidAmount = (float) $rented->payments()
        ->whereIn('status', ['collected', 'remitted'])
        ->sum('amount');
    $balance = max(0, round(($periods * $rate) - $paidAmount, 2));

    return [
        'balance' => $balance,
        'rate' => $rate,
        'periods' => $periods,
        'missed_periods' => $rate > 0 ? (int) ceil($balance / $rate) : 0,
    ];
}

public function settleUnoccupiedBalance(Request $request, $rentedId)
{
    $validated = $request->validate([
        'amount' => 'required|numeric|min:0.01',
        'or_number' => 'nullable|digits_between:1,19',
        'payment_date' => 'nullable|date|before_or_equal:today',
    ]);

    return DB::transaction(function () use ($validated, $rentedId) {
        $rented = Rented::with('stall')->lockForUpdate()->findOrFail($rentedId);
        if ($rented->status !== 'unoccupied' || !$rented->stall || !$rented->updated_at) {
            return response()->json([
                'message' => 'Payments through this action are only available for removed vendors.',
            ], 422);
        }

        $balanceAtExit = $this->calculateRentalBalanceAtExit($rented, $rented->stall, $rented->updated_at);
        $balance = $this->calculateRentalAnalysisBalance($rented, $rented->stall);
        $amount = round((float) $validated['amount'], 2);
        if ($balance <= 0) {
            return response()->json(['message' => 'This rental has no remaining balance.'], 422);
        }
        if ($amount > $balance) {
            return response()->json([
                'message' => 'Payment cannot exceed the remaining balance of ₱' . number_format($balance, 2) . '.',
            ], 422);
        }

        $remainingBalance = max(0, round($balance - $amount, 2));
        Payments::create([
            'rented_id' => $rented->id,
            'vendor_id' => $rented->vendor_id,
            'payment_type' => $remainingBalance === 0 ? 'fully paid' : 'partial',
            'amount' => $amount,
            'or_number' => $validated['or_number'] ?? null,
            'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
            'missed_days' => $balanceAtExit['rate'] > 0
                ? min((int) floor($amount / $balanceAtExit['rate']), (int) $rented->missed_days)
                : 0,
            'advance_days' => 0,
            'status' => 'collected',
        ]);

        $exitDate = $rented->updated_at;
        $rented->remaining_balance = $remainingBalance;
        $rented->missed_days = $balanceAtExit['rate'] > 0
            ? (int) ceil($remainingBalance / $balanceAtExit['rate'])
            : 0;
        $rented->timestamps = false;
        $rented->save();
        $rented->timestamps = true;
        $rented->updated_at = $exitDate;

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded against the removed rental.',
            'remaining_balance' => $remainingBalance,
        ]);
    });
}

public function settleUnoccupiedBalances(Request $request)
{
    $validated = $request->validate([
        'rental_ids' => 'required|array|min:1',
        'rental_ids.*' => 'required|integer|distinct|exists:rented,id',
        'amount' => 'required|numeric|min:0.01',
        'or_number' => 'nullable|digits_between:1,19',
        'payment_date' => 'nullable|date|before_or_equal:today',
    ]);

    return DB::transaction(function () use ($validated) {
        $rentals = Rented::with('stall')
            ->whereIn('id', $validated['rental_ids'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $firstRental = $rentals->first();
        if (!$firstRental || $rentals->count() !== count($validated['rental_ids'])) {
            return response()->json(['message' => 'One or more rentals could not be found.'], 404);
        }

        $balances = [];
        foreach ($rentals as $rental) {
            if (
                $rental->status !== 'unoccupied'
                || !$rental->stall
                || !$rental->updated_at
                || $rental->vendor_id !== $firstRental->vendor_id
                || $rental->stall->section_id !== $firstRental->stall->section_id
                || (string) $rental->stall->stall_number !== (string) $firstRental->stall->stall_number
            ) {
                return response()->json([
                    'message' => 'Only removed rentals for the same vendor, section, and stall can be paid together.',
                ], 422);
            }

            $balanceAtExit = $this->calculateRentalBalanceAtExit($rental, $rental->stall, $rental->updated_at);
            $balances[$rental->id] = [
                'balance' => (float) ($rental->remaining_balance ?? 0) > 0
                    ? (float) $rental->remaining_balance
                    : $balanceAtExit['balance'],
                'rate' => $balanceAtExit['rate'],
            ];
        }

        $totalBalance = round(array_sum(array_column($balances, 'balance')), 2);
        $paymentTotal = round((float) $validated['amount'], 2);
        $amountToAllocate = $paymentTotal;
        if ($totalBalance <= 0) {
            return response()->json(['message' => 'These rentals have no remaining balance.'], 422);
        }
        if ($amountToAllocate > $totalBalance) {
            return response()->json([
                'message' => 'Payment cannot exceed the combined remaining balance of ₱' . number_format($totalBalance, 2) . '.',
            ], 422);
        }

        foreach ($rentals as $rental) {
            if ($amountToAllocate <= 0) {
                break;
            }

            $balanceAtExit = $balances[$rental->id];
            $balance = $balanceAtExit['balance'];
            $paymentAmount = min($balance, $amountToAllocate);
            if ($paymentAmount <= 0) {
                continue;
            }

            $remainingBalance = max(0, round($balance - $paymentAmount, 2));
            Payments::create([
                'rented_id' => $rental->id,
                'vendor_id' => $rental->vendor_id,
                'payment_type' => $remainingBalance === 0 ? 'fully paid' : 'partial',
                'amount' => $paymentAmount,
                'or_number' => $validated['or_number'] ?? null,
                'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
                'missed_days' => $balanceAtExit['rate'] > 0
                    ? min((int) floor($paymentAmount / $balanceAtExit['rate']), (int) $rental->missed_days)
                    : 0,
                'advance_days' => 0,
                'status' => 'collected',
            ]);

            $rental->remaining_balance = $remainingBalance;
            $rental->missed_days = $balanceAtExit['rate'] > 0
                ? (int) ceil($remainingBalance / $balanceAtExit['rate'])
                : 0;
            $exitDate = $rental->updated_at;
            $rental->timestamps = false;
            $rental->save();
            $rental->timestamps = true;
            $rental->updated_at = $exitDate;

            $amountToAllocate = round($amountToAllocate - $paymentAmount, 2);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded against the removed rentals.',
            'remaining_balance' => round($totalBalance - $paymentTotal, 2),
        ]);
    });
}


}
