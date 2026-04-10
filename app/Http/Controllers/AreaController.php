<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Area;
use Illuminate\Http\Request;

class AreaController extends Controller
{
public function index()
{
    $areas = Area::with(['sections.stalls.rented'])->get();

    $areas->transform(function ($area) {
        $area->sections->transform(function ($section) {
            $section->stalls->transform(function ($stall) {

                $status = $stall->status;
                $color = '#09ff00ff';
                $missedDays = 0;
                $nextDueDate = null;

                $rented = $stall->currentRental;

                // 🔹 1. INACTIVE STALL (highest priority)
                if (!$stall->is_active) {

                    $status = 'inactive';
                    $color = '#64748B';
                    $missedDays = $rented ? ($rented->missed_days ?? 0) : 0;

                }
                // 🔹 2. RENTED STALL
                elseif ($rented && $stall->status !== 'vacant') {

                    $today = now()->startOfDay();

                    // Use created_at as rental start date for missed days calculation
                    $rentalStart = Carbon::parse($rented->created_at)->startOfDay();

                    // Determine next due date based on payment history
                    $latestPayment = $rented->payments()->latest('payment_date')->first();
                    
                    // Check if stall is monthly
                    $isMonthlyStall = $stall->is_monthly ?? false;
                    
                    if ($latestPayment) {
                        // Check if there's a payment for today
                        $paidToday = $latestPayment && Carbon::parse($latestPayment->payment_date)->isSameDay($today);
                        
                        if ($isMonthlyStall) {
                            // Monthly stall: calculate missed months
                            $missedMonths = 0;
                            $expectedPaymentMonth = $rentalStart->copy();
                            
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
                            
                            // Store missed months in missed_days field (reuse column)
                            $missedDays = $missedMonths;
                        } else {
                            // Daily stall: calculate missed days (existing logic)
                            $expectedPaymentDate = $rentalStart->copy();
                            $calculatedMissedDays = 0;
                            
                            while ($expectedPaymentDate->lt($today)) {
                                $hasPaymentForDay = $rented->payments()
                                    ->whereDate('payment_date', $expectedPaymentDate)
                                    ->whereIn('status', ['collected', 'remitted'])
                                    ->exists();
                                
                                if (!$hasPaymentForDay) {
                                    $calculatedMissedDays++;
                                }
                                
                                $expectedPaymentDate->addDay();
                            }
                            
                            $missedDays = $calculatedMissedDays;
                        }
                        
                        if ($latestPayment->payment_date) {
                            // Handle advance payments like StallController does
                            $advanceDays = $latestPayment->advance_days ?? 1;
                            $nextDueDate = Carbon::parse($latestPayment->payment_date)->addDays($advanceDays);
                        } else {
                            $nextDueDate = $rentalStart->copy()->addDay();
                        }
                    } else {
                        // No payments yet
                        $paidToday = false;
                        
                        if ($isMonthlyStall) {
                            // Monthly stall: calculate missed months from rental start
                            $missedMonths = 0;
                            $expectedPaymentMonth = $rentalStart->copy();
                            
                            while ($expectedPaymentMonth->lt($today)) {
                                $missedMonths++;
                                $expectedPaymentMonth->addMonth();
                            }
                            
                            $missedDays = $missedMonths;
                        } else {
                            // Daily stall: calculate missed days from rental start (same logic as above)
                            $calculatedMissedDays = 0;
                            $expectedPaymentDate = $rentalStart->copy();
                            
                            while ($expectedPaymentDate->lt($today)) {
                                $calculatedMissedDays++;
                                $expectedPaymentDate->addDay();
                            }
                            
                            $missedDays = $calculatedMissedDays;
                        }
                        $nextDueDate = $rentalStart->copy()->addDay();
                    }

                    // 🔹 Check if paid today
                    $paidToday = $latestPayment && Carbon::parse($latestPayment->payment_date)->isSameDay($today);

                    // 🔹 ADVANCE (still covered)
                    if ($rented->status === 'advance' && $today->lte($nextDueDate)) {

                        $status = 'advance';
                        $missedDays = 0;
                        $color = '#3b82f6ff';

                    }
                    // 🔹 FULLY PAID (only valid on payment day)
                    elseif ($rented->status === 'fully paid' && $paidToday) {

                        $status = 'fully_paid';
                        $missedDays = 0;
                        $color = '#074c00ff';

                    }
                    // 🔹 PARTIAL (check missed days first)
                    elseif ($rented->status === 'partial') {
                        
                        $missedDays = (int) ($rented->missed_days ?? 0);
                        
                        // 🔥 Auto temp close at 4 (days or months) but KEEP incrementing
                        if ($missedDays >= 4) {
                            
                            if ($rented->status !== 'temp_closed' && $rented->status !== 'unoccupied') {
                                $rented->status = 'temp_closed';
                                $rented->save();
                            }
                            
                            $status = 'temp_closed';
                            $color = '#ff4d00ff';
                            
                        } else {
                            
                            $status = 'partial';
                            $color = '#00f7ffff';
                            
                        }
                    }
                    else {

                        // 🔹 If paid today
                        if ($paidToday) {

                            $status = 'paid_today';
                            $color = 'blue';
                            $missedDays = 0;

                        }
                        // 🔹 If still within due date
                        elseif ($today->lte($nextDueDate)) {

                            $status = 'occupied';
                            $color = 'red';
                            $missedDays = 0;

                        }
                        // 🔥 OVERDUE (this is where missed days/months continue increasing)
                        else {

                            // Always update DB (but not for unoccupied rentals)
                            if ($rented->missed_days != $missedDays && $rented->status !== 'unoccupied') {
                                $rented->missed_days = $missedDays;
                                $rented->save();
                            }

                            // 🔥 For monthly stalls: red for 1-3 months, temp_closed at 4+ months
                            // 🔥 For daily stalls: red for 1-3 days, temp_closed at 4+ days
                            if ($missedDays >= 4) {

                                if ($rented->status !== 'temp_closed' && $rented->status !== 'unoccupied') {
                                    $rented->status = 'temp_closed';
                                    $rented->save();
                                }

                                $status = 'temp_closed';
                                $color = '#ff4d00ff';

                            } else {

                                $status = 'missed';
                                $color = '#eb1414ff'; // Red color for 1-3 missed days/months

                            }
                        }
                    }
                }
                // 🔹 3. VACANT
                elseif ($stall->status === 'vacant') {

                    $status = 'vacant';
                    $color = '#09ff00ff';
                }

                return [
                    'id'              => $stall->id,
                    'stall_number'    => $stall->stall_number,
                    'status'          => $status,
                    'color'           => $color,
                    'missed_days'     => $missedDays,
                    'next_due_date'   => $nextDueDate ? $nextDueDate->toDateString() : null,
                    'row_position'    => $stall->row_position,
                    'column_position' => $stall->column_position,
                    'size'            => $stall->size,
                    'section_id'      => $stall->section_id,
                    'created_at'      => $stall->created_at,
                    'updated_at'      => $stall->updated_at,
                    'is_active'       => $stall->is_active,
                    'message'         => $stall->message,
                    'is_monthly'      => $stall->is_monthly,
                ];
            });

            return [
                'id'            => $section->id,
                'name'          => $section->name,
                'area_id'       => $section->area_id,
                'rate_type'     => $section->rate_type,
                'rate'          => $section->rate,
                'monthly_rate'  => $section->monthly_rate,
                'daily_rate'    => $section->daily_rate,
                'column_index'  => $section->column_index,
                'row_index'     => $section->row_index,
                'stalls'        => $section->stalls,
                'created_at'    => $section->created_at,
                'updated_at'    => $section->updated_at,
            ];
        });

        return $area;
    });

    return response()->json([
        'status' => 'success',
        'data'   => $areas,
    ]);
}


    



   public function store(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'column_count' => 'required|integer|min:1',
        'rows_per_column' => 'required|array',
        'rows_per_column.*' => 'integer|min:1',
        'position_x' => 'nullable|integer',
        'position_y' => 'nullable|integer',
    ]);

    $area = Area::create([
        'name' => $request->name,
        'column_count' => $request->column_count,
        'rows_per_column' => $request->rows_per_column, // save JSON
        'row_count' => null, // not needed anymore
        'position_x' => $request->position_x ?? 0,
        'position_y' => $request->position_y ?? 0,
    ]);

    return response()->json(['message' => 'Area created', 'data' => $area], 201);
}


    public function update(Request $request, $id)
    {
        $area = Area::findOrFail($id);
        $area->update($request->only('name'));

        return response()->json(['message' => 'Area updated', 'data' => $area]);
    }

    public function destroy($id)
    {
        Area::destroy($id);
        return response()->json(['message' => 'Area deleted']);
    }
}
