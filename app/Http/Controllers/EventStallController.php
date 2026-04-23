<?php

namespace App\Http\Controllers;

use App\Models\EventStall;
use App\Models\EventActivity;
use App\Models\StallAssignment;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class EventStallController extends Controller
{
    public function index(Request $request)
    {
        $query = EventStall::with(['activity', 'assignedVendor']);

        if ($request->has('activity_id')) {
            $query->where('activity_id', $request->activity_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('vendor_id')) {
            $query->where('assigned_vendor_id', $request->vendor_id);
        }

        $stalls = $query->orderBy('stall_number', 'asc')
            ->get();

        // Transform the stalls to include formatted dates and duration
        $stalls->transform(function ($stall) {
            if ($stall->activity) {
                $stall->activity->formatted_start_date = $stall->activity->formatted_start_date;
                $stall->activity->formatted_end_date = $stall->activity->formatted_end_date;
                $stall->activity->duration = $stall->activity->duration;
            }
            
            // Ensure assigned_vendor is properly set from assignedVendor relationship
            if ($stall->assignedVendor) {
                $stall->assigned_vendor = $stall->assignedVendor;
            }
            
            return $stall;
        });

        return response()->json([
            'stalls' => $stalls,
            'status' => 'success'
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'activity_id' => 'required|exists:event_activities,id',
            'stall_number' => 'required_without:is_ambulant|nullable|string|max:50',
            'is_ambulant' => 'boolean',
            'stall_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'size' => 'required|string|max:100',
            'location' => 'nullable|string|max:255',
            'daily_rate' => 'required|numeric|min:0',
            'row_position' => 'nullable|integer|min:1',
            'column_position' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], 422);
        }

        try {
            $activity = EventActivity::findOrFail($request->activity_id);
            
            // Check if stall number already exists for this activity (only for fixed stalls)
            if (!$request->is_ambulant && $request->stall_number) {
                if (EventStall::where('activity_id', $request->activity_id)
                    ->where('stall_number', $request->stall_number)
                    ->exists()) {
                    return response()->json([
                        'message' => 'Stall number already exists for this activity',
                        'status' => 'error'
                    ], 422);
                }
            }

            $totalDays = $activity->getTotalDaysAttribute();
            $totalRent = $request->daily_rate * $totalDays;

            $stall = EventStall::create([
                'activity_id' => $request->activity_id,
                'stall_number' => $request->is_ambulant ? null : $request->stall_number,
                'is_ambulant' => $request->is_ambulant ?? false,
                'stall_name' => $request->stall_name,
                'description' => $request->description,
                'size' => $request->size,
                'location' => $request->location,
                'daily_rate' => $request->daily_rate,
                'total_days' => $totalDays,
                'total_rent' => $totalRent,
                'status' => 'available',
                'row_position' => $request->row_position,
                'column_position' => $request->column_position,
            ]);

            AdminActivity::log(
                Auth::id(),
                'create',
                'EventStall',
                "Created new stall: {$stall->stall_number} for activity: {$activity->name}",
                null,
                $stall->toArray()
            );

            return response()->json([
                'message' => 'Stall created successfully',
                'stall' => $stall->load(['activity', 'assignedVendor']),
                'status' => 'success'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create stall',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function show($id)
    {
        $stall = EventStall::with([
            'activity',
            'assignedVendor',
            'assignments' => function($query) {
                $query->with('vendor')->orderBy('created_at', 'desc');
            },
            'payments',
            'salesReports' => function($query) {
                $query->with(['reportedBy', 'verifiedBy'])->orderBy('report_date', 'desc');
            }
        ])->findOrFail($id);

        return response()->json([
            'stall' => $stall,
            'status' => 'success'
        ]);
    }

    public function update(Request $request, $id)
    {
        $stall = EventStall::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'stall_number' => 'sometimes|required_without:is_ambulant|nullable|string|max:50',
            'is_ambulant' => 'boolean',
            'stall_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'size' => 'sometimes|required|string|max:100',
            'location' => 'nullable|string|max:255',
            'daily_rate' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:available,occupied,maintenance,reserved',
            'row_position' => 'nullable|integer|min:1',
            'column_position' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], 422);
        }

        try {
            $oldValues = $stall->toArray();
            $updateData = $request->except(['activity_id']);

            // Handle ambulant stall logic
            if ($request->has('is_ambulant')) {
                $updateData['is_ambulant'] = $request->is_ambulant;
                if ($request->is_ambulant) {
                    $updateData['stall_number'] = null;
                } else {
                    // If switching from ambulant to fixed, stall_number is required
                    if (!$request->stall_number) {
                        return response()->json([
                            'message' => 'Stall number is required for fixed stalls',
                            'status' => 'error'
                        ], 422);
                    }
                }
            }

            // Check stall number uniqueness for fixed stalls
            if (!$request->is_ambulant && $request->stall_number) {
                if (EventStall::where('activity_id', $stall->activity_id)
                    ->where('stall_number', $request->stall_number)
                    ->where('id', '!=', $stall->id)
                    ->exists()) {
                    return response()->json([
                        'message' => 'Stall number already exists for this activity',
                        'status' => 'error'
                    ], 422);
                }
            }

            if ($request->has('daily_rate')) {
                $activity = $stall->activity;
                $totalDays = $activity->total_days;
                $updateData['total_rent'] = $request->daily_rate * $totalDays;
            }

            $stall->update($updateData);

            AdminActivity::log(
                Auth::id(),
                'update',
                'EventStall',
                "Updated stall: {$stall->stall_number}",
                $oldValues,
                $stall->toArray()
            );

            return response()->json([
                'message' => 'Stall updated successfully',
                'stall' => $stall->fresh(),
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update stall',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function destroy($id)
    {
        $stall = EventStall::findOrFail($id);

        try {
            $oldValues = $stall->toArray();
            $stallNumber = $stall->stall_number;

            // Check if stall has assignments or payments
            if ($stall->assignments()->count() > 0 || $stall->payments()->count() > 0) {
                return response()->json([
                    'message' => 'Cannot delete stall with assignments or payments',
                    'status' => 'error'
                ], 422);
            }

            $stall->delete();

            AdminActivity::log(
                Auth::id(),
                'delete',
                'EventStall',
                "Deleted stall: {$stallNumber}",
                $oldValues,
                null
            );

            return response()->json([
                'message' => 'Stall deleted successfully',
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete stall',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function assignVendor(Request $request, $id)
    {
        $stall = EventStall::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'vendor_id' => 'required|exists:event_vendors,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], 422);
        }

        try {
            if ($stall->status !== 'available') {
                return response()->json([
                    'message' => 'Stall is not available for assignment',
                    'status' => 'error'
                ], 422);
            }

            $assignment = $stall->assignToVendor(
                $request->vendor_id,
                $request->start_date,
                $request->end_date
            );

            if ($request->has('notes')) {
                $assignment->notes = $request->notes;
                $assignment->save();
            }

            $stall->fresh();

            AdminActivity::log(
                Auth::id(),
                'assign',
                'EventStall',
                "Assigned vendor to stall: {$stall->stall_number}",
                null,
                ['vendor_id' => $request->vendor_id, 'stall_id' => $stall->id]
            );

            return response()->json([
                'message' => 'Vendor assigned successfully',
                'stall' => $stall->load(['assignedVendor', 'assignments']),
                'assignment' => $assignment->load('vendor'),
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to assign vendor',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function releaseStall($id)
    {
        $stall = EventStall::findOrFail($id);

        try {
            if ($stall->status !== 'occupied') {
                return response()->json([
                    'message' => 'Stall is not currently occupied',
                    'status' => 'error'
                ], 422);
            }

            $vendorName = $stall->assignedVendor->full_name ?? 'Unknown';
            $stall->releaseStall();

            AdminActivity::log(
                Auth::id(),
                'release',
                'EventStall',
                "Released stall: {$stall->stall_number} from vendor: {$vendorName}",
                null,
                ['stall_id' => $stall->id]
            );

            return response()->json([
                'message' => 'Stall released successfully',
                'stall' => $stall->fresh(),
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to release stall',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function getAvailableStalls($activityId)
    {
        $stalls = EventStall::where('activity_id', $activityId)
            ->available()
            ->orderBy('stall_number', 'asc')
            ->get();

        return response()->json([
            'stalls' => $stalls,
            'status' => 'success'
        ]);
    }
}
