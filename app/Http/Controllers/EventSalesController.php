<?php

namespace App\Http\Controllers;

use App\Models\ActivitySalesReport;
use App\Models\EventActivity;
use App\Models\EventPayment;
use App\Models\EventStall;
use App\Models\EventVendor;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class EventSalesController extends Controller
{
    public function getActivitySalesReport($activityId, Request $request)
    {
        $activity = EventActivity::findOrFail($activityId);
        
        // Log activity dates for debugging
        \Log::info('=== ACTIVITY DATES DEBUG ===');
        \Log::info('Activity ID', ['activity_id' => $activityId]);
        \Log::info('Activity Name', ['name' => $activity->name]);
        \Log::info('Activity Start Date (raw)', ['start_date_raw' => $activity->start_date]);
        \Log::info('Activity End Date (raw)', ['end_date_raw' => $activity->end_date]);
        \Log::info('Activity Start Date (type)', ['start_date_type' => gettype($activity->start_date)]);
        \Log::info('Activity End Date (type)', ['end_date_type' => gettype($activity->end_date)]);
        \Log::info('Activity Start Date (as string)', ['start_date_string' => (string)$activity->start_date]);
        \Log::info('Activity End Date (as string)', ['end_date_string' => (string)$activity->end_date]);
        \Log::info('Activity Location', ['location' => $activity->location]);
        \Log::info('==========================');
        
        $sortOrder = $request->get('sort_order', 'desc');
        
        // Get all occupied stalls for this activity with their sales reports
        $stalls = EventStall::where('activity_id', $activityId)
            ->where('status', 'occupied')
            ->with(['assignedVendor', 'salesReports'])
            ->get();
        
        $salesData = [];
        
        foreach ($stalls as $stall) {
            // Get all sales reports for this stall
            $reports = $stall->salesReports;
            
            // Calculate activity duration and initialize daily income
            $startDate = new \DateTime($activity->start_date);
            $endDate = new \DateTime($activity->end_date);
            $totalDays = $startDate->diff($endDate)->days + 1;
            
            $dailyIncome = [];
            $productNames = [];
            
            // Initialize all days with 0 income
            for ($day = 1; $day <= $totalDays; $day++) {
                $dailyIncome["day{$day}_income"] = 0;
            }
            
            // Calculate income by day and collect product names
            foreach ($reports as $report) {
                $dayNumber = $report->day_number;
                
                if (isset($dailyIncome["day{$dayNumber}_income"])) {
                    $dailyIncome["day{$dayNumber}_income"] += $report->total_sales;
                }
                
                // Collect product names from this report
                if ($report->products) {
                    foreach ($report->products as $product) {
                        if (!in_array($product['name'], $productNames)) {
                            $productNames[] = $product['name'];
                        }
                    }
                }
            }
            
            $totalSales = array_sum($dailyIncome);
            
            // Combine all data including daily income
            $stallData = [
                'stall_id' => $stall->id,
                'stall_number' => $stall->stall_number,
                'stall_name' => $stall->stall_name,
                'vendor_name' => $stall->assignedVendor ? $stall->assignedVendor->first_name . ' ' . $stall->assignedVendor->last_name : 'Unassigned',
                'vendor_id' => $stall->assignedVendor ? $stall->assignedVendor->id : null,
                'total_sales' => $totalSales,
                'product_services' => implode(', ', $productNames),
                'is_ambulant' => $stall->is_ambulant,
            ];
            
            // Add daily income data
            foreach ($dailyIncome as $dayKey => $income) {
                $stallData[$dayKey] = $income;
            }
            
            $salesData[] = $stallData;
        }
        
        // Sort by total sales
        usort($salesData, function($a, $b) use ($sortOrder) {
            if ($sortOrder === 'desc') {
                return $b['total_sales'] <=> $a['total_sales'];
            } else {
                return $a['total_sales'] <=> $b['total_sales'];
            }
        });
        
        // Add ranks
        foreach ($salesData as $index => &$data) {
            $data['rank'] = $index + 1;
        }
        
        // Calculate summary
        $totalStalls = $stalls->count();
        $totalSales = array_sum(array_column($salesData, 'total_sales'));
        $lowestSales = !empty($salesData) ? min(array_column($salesData, 'total_sales')) : 0;
        $highestSales = !empty($salesData) ? max(array_column($salesData, 'total_sales')) : 0;
        
        $summary = [
            'total_stalls' => $totalStalls,
            'total_sales' => $totalSales,
            'lowest_sales' => $lowestSales,
            'highest_sales' => $highestSales,
        ];
        
        // Format dates as YYYY-MM-DD to avoid timezone issues
        $formattedActivity = [
            'id' => $activity->id,
            'name' => $activity->name,
            'description' => $activity->description,
            'start_date' => $activity->start_date->format('Y-m-d'),
            'end_date' => $activity->end_date->format('Y-m-d'),
            'location' => $activity->location,
            'status' => $activity->status,
            'created_by' => $activity->created_by,
            'created_at' => $activity->created_at,
            'updated_at' => $activity->updated_at
        ];

      
        return response()->json([
            'sales_data' => $salesData,
            'summary' => $summary,
            'activity' => $formattedActivity
        ]);
    }
    
    public function showSalesReport($id)
    {
        $salesReport = ActivitySalesReport::with(['stall.assignedVendor', 'activity'])->find($id);
        
        if (!$salesReport) {
            return response()->json(['message' => 'Sales report not found'], 404);
        }
        
        return response()->json($salesReport);
    }
    
    public function updateSalesReport(Request $request, $id)
    {
        $salesReport = ActivitySalesReport::find($id);
        
        if (!$salesReport) {
            return response()->json(['message' => 'Sales report not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'products' => 'required|array',
            'products.*.name' => 'required|string|max:255',
            'products.*.unit_sold' => 'required|integer|min:0',
            'products.*.unit_price' => 'required|numeric|min:0',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $validated = $validator->validated();
        
        $validated = $validator->validated();
        
        // Calculate total sales from products
        $totalSales = 0;
        foreach ($validated['products'] as $index => $product) {
            $unitSold = isset($product['unit_sold']) ? floatval($product['unit_sold']) : 0;
            $unitPrice = isset($product['unit_price']) ? floatval($product['unit_price']) : 0;
            $productTotal = $unitSold * $unitPrice;
            $totalSales += $productTotal;
        }
                
        $salesReport->update([
            'products' => $validated['products'],
            'total_sales' => $totalSales,
        ]);
        
        return response()->json($salesReport);
    }
    
    public function destroySalesReport($id)
    {
        $salesReport = ActivitySalesReport::find($id);
        
        if (!$salesReport) {
            return response()->json(['message' => 'Sales report not found'], 404);
        }
        
        $salesReport->delete();
        
        return response()->json(['message' => 'Sales report deleted successfully']);
    }
    
    public function storeSalesReport(Request $request)
    {
        // Log incoming data for debugging
        \Log::info('=== BACKEND DATA RECEIVED ===');
        \Log::info('Full request data', ['data' => $request->all()]);
        \Log::info('report_day field', ['report_day' => $request->input('report_day')]);
        \Log::info('activity_id', ['activity_id' => $request->input('activity_id')]);
        \Log::info('stall_id', ['stall_id' => $request->input('stall_id')]);
        \Log::info('vendor_id', ['vendor_id' => $request->input('vendor_id')]);
        \Log::info('total_sales', ['total_sales' => $request->input('total_sales')]);
        \Log::info('products', ['products' => $request->input('products')]);
        \Log::info('============================');

        $validated = $request->validate([
            'activity_id' => 'required|exists:event_activities,id',
            'stall_id' => 'required|exists:event_stalls,id',
            'vendor_id' => 'required|exists:event_vendors,id',
            'report_day' => 'required|date',
            'total_sales' => 'required|numeric|min:0',
            'products' => 'required|array|min:1',
            'products.*.name' => 'required|string|max:255',
            'products.*.unit_sold' => 'required|numeric|min:0',
            'products.*.unit_price' => 'required|numeric|min:0',
            'products.*.total_sales' => 'required|numeric|min:0'
        ]);

        \Log::info('Validated data', ['validated' => $validated]);

        try {
            // Calculate the actual date for the selected day
            $activity = EventActivity::findOrFail($validated['activity_id']);
            $startDate = new \DateTime($activity->start_date);
            
            // The frontend sends the actual date for the selected day
            $reportDate = $validated['report_day'];
            $reportDateObj = new \DateTime($reportDate);
            
            // Use Carbon for better date handling
            $reportDateCarbon = \Carbon\Carbon::parse($reportDate);
            $startDateCarbon = \Carbon\Carbon::parse($activity->start_date);
            $dayNumber = $startDateCarbon->diffInDays($reportDateCarbon) + 1;

            \Log::info('=== DATE PROCESSING ===');
            \Log::info('Activity start_date', ['start_date' => $activity->start_date]);
            \Log::info('Activity end_date', ['end_date' => $activity->end_date]);
            \Log::info('Raw report_date from frontend', ['report_date' => $reportDate]);
            \Log::info('Parsed reportDateCarbon', ['parsed_date' => $reportDateCarbon->format('Y-m-d')]);
            \Log::info('Formatted for database', ['formatted_date' => $reportDateCarbon->format('Y-m-d')]);
            \Log::info('Day number calculated', ['day_number' => $dayNumber]);
            \Log::info('=====================');

            // Check if a report already exists for this stall on this date within this activity's date range
            $existingReport = ActivitySalesReport::where('stall_id', $validated['stall_id'])
                ->where('activity_id', $validated['activity_id'])
                ->where('report_date', $reportDateCarbon->format('Y-m-d'))
                ->first();

            \Log::info('=== DUPLICATE CHECK ===');
            \Log::info('Checking for existing report', [
                'stall_id' => $validated['stall_id'],
                'activity_id' => $validated['activity_id'],
                'report_date' => $reportDateCarbon->format('Y-m-d')
            ]);
            \Log::info('Existing report found', ['found' => $existingReport ? 'YES' : 'NO']);
            if ($existingReport) {
                \Log::info('Existing report details', ['details' => $existingReport->toArray()]);
            }
            \Log::info('=====================');

            if ($existingReport) {
                return response()->json([
                    'message' => 'A sales report already exists for this stall on ' . $reportDateCarbon->format('F d, Y') . ' for this activity. Please update the existing report instead.',
                    'existing_report' => $existingReport
                ], 422);
            }

            $report = ActivitySalesReport::create([
                'activity_id' => $validated['activity_id'],
                'stall_id' => $validated['stall_id'],
                'vendor_id' => $validated['vendor_id'],
                'report_date' => $reportDateCarbon->format('Y-m-d'),
                'day_number' => $dayNumber,
                'total_sales' => $validated['total_sales'],
                'products' => $validated['products']
            ]);

            return response()->json([
                'message' => 'Sales report created successfully',
                'report' => $report
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Error creating sales report: ' . $e->getMessage());
            \Log::error('Exception trace', ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'Error creating sales report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get existing report dates for a vendor in an activity
     */
    public function getVendorReportDates($activityId, $vendorId)
    {
        try {
            $reports = ActivitySalesReport::where('activity_id', $activityId)
                ->where('vendor_id', $vendorId)
                ->select('report_date', 'day_number', 'stall_id')
                ->get()
                ->map(function ($report) {
                    $report->report_date = \Carbon\Carbon::parse($report->report_date)->format('Y-m-d');
                    return $report;
                });

            return response()->json([
                'reports' => $reports,
                'message' => 'Vendor report dates retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving vendor report dates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete sales reports for a specific vendor, activity, and day
     */
    public function deleteSalesReportByDay(Request $request)
    {
        try {
            $validated = $request->validate([
                'activity_id' => 'required|exists:event_activities,id',
                'vendor_id' => 'required|exists:event_vendors,id',
                'report_date' => 'required|date'
            ]);

            $deletedCount = ActivitySalesReport::where('activity_id', $validated['activity_id'])
                ->where('vendor_id', $validated['vendor_id'])
                ->where('report_date', $validated['report_date'])
                ->delete();

            return response()->json([
                'message' => "Successfully deleted {$deletedCount} sales report(s) for the specified day",
                'deleted_count' => $deletedCount
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting sales reports: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function getStallSalesHistory($stallId)
    {
        $stall = EventStall::with(['activity', 'assignedVendor'])
            ->findOrFail($stallId);
        
        $salesHistory = ActivitySalesReport::where('stall_id', $stallId)
            ->with(['activity'])
            ->orderBy('report_date', 'desc')
            ->get();
        
        return response()->json([
            'stall' => $stall,
            'sales_history' => $salesHistory
        ]);
    }
}
