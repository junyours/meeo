<?php

namespace App\Http\Controllers;

use App\Models\ActivitySalesReport;
use App\Models\EventActivity;
use App\Models\EventStall;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ActivitySalesReportController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivitySalesReport::with([
            'activity', 'stall', 'vendor', 'reportedBy', 'verifiedBy'
        ]);

        if ($request->has('activity_id')) {
            $query->where('activity_id', $request->activity_id);
        }

        if ($request->has('stall_id')) {
            $query->where('stall_id', $request->stall_id);
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->has('verified')) {
            $query->where('verified', $request->boolean('verified'));
        }

        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('report_date', [$request->date_from, $request->date_to]);
        }

        $reports = $query->orderBy('report_date', 'desc')
            ->orderBy('stall_number', 'asc')
            ->paginate(20);

        return response()->json([
            'reports' => $reports,
            'status' => 'success'
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'activity_id' => 'required|exists:event_activities,id',
            'stall_id' => 'required|exists:event_stalls,id',
            'report_date' => 'required|date',
            'total_sales' => 'required|numeric|min:0',
            'cash_sales' => 'nullable|numeric|min:0',
            'credit_sales' => 'nullable|numeric|min:0',
            'other_sales' => 'nullable|numeric|min:0',
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
            $stall = EventStall::findOrFail($request->stall_id);
            $activity = EventActivity::findOrFail($request->activity_id);

            // Calculate day number based on activity start date
            $reportDate = \Carbon\Carbon::parse($request->report_date);
            $dayNumber = $activity->start_date->diffInDays($reportDate) + 1;

            // Check if report already exists for this stall and date
            $existingReport = ActivitySalesReport::where('stall_id', $request->stall_id)
                ->where('report_date', $request->report_date)
                ->first();

            if ($existingReport) {
                return response()->json([
                    'message' => 'Sales report already exists for this stall and date',
                    'status' => 'error'
                ], 422);
            }

            $report = ActivitySalesReport::create([
                'activity_id' => $request->activity_id,
                'stall_id' => $request->stall_id,
                'assignment_id' => $stall->currentAssignment?->id,
                'vendor_id' => $stall->assigned_vendor_id,
                'report_date' => $request->report_date,
                'day_number' => $dayNumber,
                'total_sales' => $request->total_sales,
                'cash_sales' => $request->cash_sales ?? 0,
                'credit_sales' => $request->credit_sales ?? 0,
                'other_sales' => $request->other_sales ?? 0,
                'notes' => $request->notes,
                'reported_by' => Auth::id(),
                'verified' => false,
            ]);

            AdminActivity::log(
                Auth::id(),
                'create',
                'ActivitySalesReport',
                "Created sales report for stall: {$stall->stall_number}",
                null,
                $report->toArray()
            );

            return response()->json([
                'message' => 'Sales report created successfully',
                'report' => $report->load(['activity', 'stall', 'vendor', 'reportedBy']),
                'status' => 'success'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create sales report',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function show($id)
    {
        $report = ActivitySalesReport::with([
            'activity',
            'stall',
            'assignment',
            'vendor',
            'reportedBy',
            'verifiedBy'
        ])->findOrFail($id);

        return response()->json([
            'report' => $report,
            'status' => 'success'
        ]);
    }

    public function update(Request $request, $id)
    {
        $report = ActivitySalesReport::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'total_sales' => 'sometimes|required|numeric|min:0',
            'cash_sales' => 'nullable|numeric|min:0',
            'credit_sales' => 'nullable|numeric|min:0',
            'other_sales' => 'nullable|numeric|min:0',
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
            $oldValues = $report->toArray();
            $updateData = $request->only([
                'total_sales', 'cash_sales', 'credit_sales', 'other_sales', 'notes'
            ]);

            $report->update($updateData);

            AdminActivity::log(
                Auth::id(),
                'update',
                'ActivitySalesReport',
                "Updated sales report for stall: {$report->stall->stall_number}",
                $oldValues,
                $report->toArray()
            );

            return response()->json([
                'message' => 'Sales report updated successfully',
                'report' => $report->fresh(),
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update sales report',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function destroy($id)
    {
        $report = ActivitySalesReport::findOrFail($id);

        try {
            $oldValues = $report->toArray();
            $stallNumber = $report->stall->stall_number;

            $report->delete();

            AdminActivity::log(
                Auth::id(),
                'delete',
                'ActivitySalesReport',
                "Deleted sales report for stall: {$stallNumber}",
                $oldValues,
                null
            );

            return response()->json([
                'message' => 'Sales report deleted successfully',
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete sales report',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function verify($id)
    {
        $report = ActivitySalesReport::findOrFail($id);

        try {
            $report->verify(Auth::id());

            AdminActivity::log(
                Auth::id(),
                'verify',
                'ActivitySalesReport',
                "Verified sales report for stall: {$report->stall->stall_number}",
                null,
                $report->toArray()
            );

            return response()->json([
                'message' => 'Sales report verified successfully',
                'report' => $report->fresh(['verifiedBy']),
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to verify sales report',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function unverify($id)
    {
        $report = ActivitySalesReport::findOrFail($id);

        try {
            $report->unverify();

            AdminActivity::log(
                Auth::id(),
                'unverify',
                'ActivitySalesReport',
                "Unverified sales report for stall: {$report->stall->stall_number}",
                null,
                $report->toArray()
            );

            return response()->json([
                'message' => 'Sales report unverified successfully',
                'report' => $report->fresh(),
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to unverify sales report',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function getActivityReport($activityId, Request $request)
    {
        $activity = EventActivity::findOrFail($activityId);
        
        $sortOrder = $request->get('sort_order', 'desc'); // desc or asc
        
        $salesData = ActivitySalesReport::byActivity($activityId)
            ->with(['stall', 'vendor'])
            ->selectRaw('
                stall_id,
                vendor_id,
                SUM(total_sales) as total_sales,
                SUM(cash_sales) as total_cash_sales,
                SUM(credit_sales) as total_credit_sales,
                SUM(other_sales) as total_other_sales,
                COUNT(*) as report_count,
                AVG(total_sales) as average_daily_sales
            ')
            ->groupBy('stall_id', 'vendor_id')
            ->orderBy("total_sales", $sortOrder)
            ->get()
            ->map(function ($item, $index) use ($sortOrder) {
                $rank = $sortOrder === 'desc' ? $index + 1 : null;
                return [
                    'rank' => $rank,
                    'stall_id' => $item->stall_id,
                    'stall_number' => $item->stall->stall_number,
                    'stall_name' => $item->stall->stall_name,
                    'vendor_id' => $item->vendor_id,
                    'vendor_name' => $item->vendor->full_name,
                    'total_sales' => (float) $item->total_sales,
                    'total_cash_sales' => (float) $item->total_cash_sales,
                    'total_credit_sales' => (float) $item->total_credit_sales,
                    'total_other_sales' => (float) $item->total_other_sales,
                    'report_count' => $item->report_count,
                    'average_daily_sales' => (float) $item->average_daily_sales,
                ];
            });

        $summary = [
            'activity' => $activity,
            'total_stalls' => $salesData->count(),
            'total_sales' => $salesData->sum('total_sales'),
            'average_sales_per_stall' => $salesData->count() > 0 ? $salesData->sum('total_sales') / $salesData->count() : 0,
            'highest_sales' => $salesData->max('total_sales'),
            'lowest_sales' => $salesData->min('total_sales'),
        ];

        return response()->json([
            'summary' => $summary,
            'sales_data' => $salesData,
            'sort_order' => $sortOrder,
            'status' => 'success'
        ]);
    }

    public function getStallSalesHistory($stallId)
    {
        $stall = EventStall::findOrFail($stallId);
        
        $reports = ActivitySalesReport::byStall($stallId)
            ->with(['activity', 'reportedBy', 'verifiedBy'])
            ->orderBy('report_date', 'asc')
            ->get();

        return response()->json([
            'stall' => $stall,
            'reports' => $reports,
            'total_sales' => $reports->sum('total_sales'),
            'average_daily_sales' => $reports->count() > 0 ? $reports->sum('total_sales') / $reports->count() : 0,
            'status' => 'success'
        ]);
    }
}
