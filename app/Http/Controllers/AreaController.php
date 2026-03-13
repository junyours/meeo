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

                $rented = $stall->rented;

                // 🔹 1. INACTIVE STALL (highest priority)
                if (!$stall->is_active) {

                    $status = 'inactive';
                    $color = '#64748B';
                    $missedDays = $rented ? ($rented->missed_days ?? 0) : 0;

                }
                // 🔹 2. RENTED STALL
                elseif ($rented && $stall->status !== 'vacant') {

                    $today = now();

                    // Determine next due date
                    if ($rented->next_due_date) {
                        $nextDueDate = Carbon::parse($rented->next_due_date);
                    } else {
                        $baseDate = $rented->last_payment_date
                            ? Carbon::parse($rented->last_payment_date)
                            : Carbon::parse($rented->created_at);

                        $nextDueDate = $baseDate->copy()->addDay();
                    }

                    // 🔹 Check if paid today
                    $paidToday = $rented->last_payment_date &&
                        Carbon::parse($rented->last_payment_date)->isSameDay($today);

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
                    // 🔹 PARTIAL (keep stored missed days)
                    elseif ($rented->status === 'partial') {

                        $status = 'partial';
                        $missedDays = (int) ($rented->missed_days ?? 0);
                        $color = '#00f7ffff';

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
                        // 🔥 OVERDUE (this is where missed days continue increasing)
                        else {

                            $missedDays = $today->diffInDays($nextDueDate);

                            // Always update DB
                            if ($rented->missed_days != $missedDays) {
                                $rented->missed_days = $missedDays;
                                $rented->save();
                            }

                            // 🔥 Auto temp close at 4 but KEEP incrementing
                            if ($missedDays >= 4) {

                                if ($rented->status !== 'temp_closed') {
                                    $rented->status = 'temp_closed';
                                    $rented->save();
                                }

                                $status = 'temp_closed';
                                $color = '#ff4d00ff';

                            } else {

                                $status = 'missed';
                                $color = '#eb7114ff';

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
