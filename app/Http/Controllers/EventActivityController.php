<?php

namespace App\Http\Controllers;

use App\Models\EventActivity;
use App\Models\EventStall;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class EventActivityController extends Controller
{
    public function index()
    {
        $activities = EventActivity::with(['creator', 'stalls'])
            ->withCount(['stalls', 'stallAssignments'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // Transform activities to include formatted dates and duration
        $activities->getCollection()->transform(function ($activity) {
            $activity->formatted_start_date = $activity->formatted_start_date;
            $activity->formatted_end_date = $activity->formatted_end_date;
            $activity->duration = $activity->duration;
            return $activity;
        });

        return response()->json([
            'activities' => $activities,
            'status' => 'success'
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'location' => 'required|string|max:255',
            'daily_rental_rate' => 'required|numeric|min:0',
            'status' => 'required|in:active,inactive,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], 422);
        }

        try {
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $totalDays = $startDate->diffInDays($endDate) + 1;

            $activity = EventActivity::create([
                'name' => $request->name,
                'description' => $request->description,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'location' => $request->location,
                'status' => $request->status,
                'total_days' => $totalDays,
                'daily_rental_rate' => $request->daily_rental_rate,
                'created_by' => Auth::id(),
            ]);

            AdminActivity::log(
                Auth::id(),
                'create',
                'EventActivity',
                "Created new activity: {$activity->name}",
                null,
                $activity->toArray()
            );

            return response()->json([
                'message' => 'Activity created successfully',
                'activity' => $activity->load('creator'),
                'status' => 'success'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create activity',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function show($id)
    {
        $activity = EventActivity::with([
            'creator',
            'stalls' => function($query) {
                $query->with('assignedVendor');
            },
            'stallAssignments' => function($query) {
                $query->with(['vendor', 'stall']);
            },
            'payments',
            'salesReports' => function($query) {
                $query->with(['stall', 'vendor']);
            }
        ])->findOrFail($id);

        return response()->json([
            'activity' => $activity,
            'status' => 'success'
        ]);
    }

    public function update(Request $request, $id)
    {
        $activity = EventActivity::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'location' => 'sometimes|required|string|max:255',
            'daily_rental_rate' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:active,inactive,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], 422);
        }

        try {
            $oldValues = $activity->toArray();
            
            $updateData = $request->only([
                'name', 'description', 'location', 'daily_rental_rate', 'status'
            ]);

            if ($request->has(['start_date', 'end_date'])) {
                $startDate = Carbon::parse($request->start_date);
                $endDate = Carbon::parse($request->end_date);
                $totalDays = $startDate->diffInDays($endDate) + 1;
                
                $updateData['start_date'] = $startDate;
                $updateData['end_date'] = $endDate;
                $updateData['total_days'] = $totalDays;
            }

            $activity->update($updateData);

            AdminActivity::log(
                Auth::id(),
                'update',
                'EventActivity',
                "Updated activity: {$activity->name}",
                $oldValues,
                $activity->toArray()
            );

            return response()->json([
                'message' => 'Activity updated successfully',
                'activity' => $activity->fresh(),
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update activity',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function destroy($id)
    {
        $activity = EventActivity::findOrFail($id);

        try {
            $oldValues = $activity->toArray();
            $activityName = $activity->name;

            // Check if activity has related records
            if ($activity->stalls()->count() > 0 || $activity->payments()->count() > 0) {
                return response()->json([
                    'message' => 'Cannot delete activity with associated stalls or payments',
                    'status' => 'error'
                ], 422);
            }

            $activity->delete();

            AdminActivity::log(
                Auth::id(),
                'delete',
                'EventActivity',
                "Deleted activity: {$activityName}",
                $oldValues,
                null
            );

            return response()->json([
                'message' => 'Activity deleted successfully',
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete activity',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function getActiveActivities()
    {
        $activities = EventActivity::active()
            ->with(['stalls' => function($query) {
                $query->available();
            }])
            ->orderBy('start_date', 'asc')
            ->get();

        return response()->json([
            'activities' => $activities,
            'status' => 'success'
        ]);
    }

    public function bulkCreateStalls(Request $request, $activityId)
    {
        $activity = EventActivity::findOrFail($activityId);
        
        $validator = Validator::make($request->all(), [
            'stall_count' => 'required|integer|min:1|max:50',
            'is_ambulant' => 'required|boolean',
            'stall_name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'size' => 'required|string|max:100',
            'location' => 'nullable|string|max:255',
            'daily_rate' => 'required|numeric|min:0',
            'row_count' => 'nullable|integer|min:1|max:20',
            'column_count' => 'nullable|integer|min:1|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], 422);
        }

        try {
            $stallCount = $request->stall_count;
            $isAmbulant = $request->is_ambulant;
            $rowCount = $request->row_count ?? 1;
            $columnCount = $request->column_count ?? 1;
            
            $totalDays = $activity->getTotalDaysAttribute();
            $totalRent = $request->daily_rate * $totalDays;
            
            $createdStalls = [];
            
            if ($isAmbulant) {
                // Log current stall counts for debugging
                \Log::info('BulkCreateStalls - Ambulant stalls creation', [
                    'activity_id' => $activityId,
                    'requested_count' => $stallCount,
                    'current_total_stalls' => EventStall::where('activity_id', $activityId)->count(),
                    'current_fixed_stalls' => EventStall::where('activity_id', $activityId)->where('is_ambulant', false)->count(),
                    'current_ambulant_stalls' => EventStall::where('activity_id', $activityId)->where('is_ambulant', true)->count()
                ]);
                
                // Create ambulant stalls (no stall numbers, no positions)
                for ($i = 1; $i <= $stallCount; $i++) {
                    $stall = EventStall::create([
                        'activity_id' => $activityId,
                        'stall_number' => null,
                        'is_ambulant' => true,
                        'stall_name' => $request->stall_name,
                        'description' => $request->description,
                        'size' => $request->size,
                        'location' => $request->location,
                        'daily_rate' => $request->daily_rate,
                        'total_days' => $totalDays,
                        'total_rent' => $totalRent,
                        'status' => 'available',
                        'row_position' => null,
                        'column_position' => null,
                    ]);
                    $createdStalls[] = $stall;
                }
            } else {
                // Create fixed stalls with automatic numbering and grid positioning
                $stallNumber = 1;
                
                // Log current stall counts for debugging
                \Log::info('BulkCreateStalls - Fixed stalls creation', [
                    'activity_id' => $activityId,
                    'requested_count' => $stallCount,
                    'current_total_stalls' => EventStall::where('activity_id', $activityId)->count(),
                    'current_fixed_stalls' => EventStall::where('activity_id', $activityId)->where('is_ambulant', false)->count(),
                    'current_ambulant_stalls' => EventStall::where('activity_id', $activityId)->where('is_ambulant', true)->count()
                ]);
                
                $existingMax = EventStall::where('activity_id', $activityId)
                    ->where('is_ambulant', false)
                    ->whereNotNull('stall_number')
                    ->where('stall_number', 'REGEXP', '^[0-9]+$')
                    ->max('stall_number');
                
                \Log::info('BulkCreateStalls - Existing max stall number', [
                    'existing_max' => $existingMax,
                    'starting_stall_number' => $stallNumber
                ]);
                
                if ($existingMax) {
                    $stallNumber = (int)$existingMax + 1;
                }
                
                \Log::info('BulkCreateStalls - Final starting stall number', [
                    'final_stall_number' => $stallNumber
                ]);
                
                for ($i = 1; $i <= $stallCount; $i++) {
                    $row = ceil($i / $columnCount);
                    $col = (($i - 1) % $columnCount) + 1;
                    
                    $stall = EventStall::create([
                        'activity_id' => $activityId,
                        'stall_number' => (string)$stallNumber,
                        'is_ambulant' => false,
                        'stall_name' => $request->stall_name,
                        'description' => $request->description,
                        'size' => $request->size,
                        'location' => $request->location,
                        'daily_rate' => $request->daily_rate,
                        'total_days' => $totalDays,
                        'total_rent' => $totalRent,
                        'status' => 'available',
                        'row_position' => $row,
                        'column_position' => $col,
                    ]);
                    $createdStalls[] = $stall;
                    $stallNumber++;
                }
            }

            // Log final stall counts after creation
            \Log::info('BulkCreateStalls - After creation', [
                'activity_id' => $activityId,
                'created_count' => count($createdStalls),
                'final_total_stalls' => EventStall::where('activity_id', $activityId)->count(),
                'final_fixed_stalls' => EventStall::where('activity_id', $activityId)->where('is_ambulant', false)->count(),
                'final_ambulant_stalls' => EventStall::where('activity_id', $activityId)->where('is_ambulant', true)->count()
            ]);

            AdminActivity::log(
                Auth::id(),
                'create',
                'EventStall',
                "Bulk created {$stallCount} " . ($isAmbulant ? 'ambulant' : 'fixed') . " stalls for activity: {$activity->name}",
                null,
                ['stall_count' => $stallCount, 'is_ambulant' => $isAmbulant]
            );

            return response()->json([
                'message' => "Successfully created {$stallCount} " . ($isAmbulant ? 'ambulant' : 'fixed') . " stalls",
                'stalls' => $createdStalls,
                'status' => 'success'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create stalls',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function getActivityStats($id)
    {
        $activity = EventActivity::findOrFail($id);
        
        $stats = [
            'total_stalls' => $activity->stalls()->count(),
            'occupied_stalls' => $activity->stalls()->occupied()->count(),
            'available_stalls' => $activity->stalls()->available()->count(),
            'total_revenue' => $activity->total_revenue,
            'total_sales' => $activity->salesReports()->sum('total_sales'),
            'days_remaining' => $activity->end_date->diffInDays(now()) + 1,
            'is_active' => $activity->is_active,
        ];

        return response()->json([
            'stats' => $stats,
            'status' => 'success'
        ]);
    }
}
