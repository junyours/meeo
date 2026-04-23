<?php

namespace App\Http\Controllers;

use App\Models\Payments;
use App\Models\EventActivity;
use App\Models\EventStall;
use App\Models\StallAssignment;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class EventPaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payments::with([
            'eventVendor', 
            'activity',
            'stall',
        ])->where('payment_type', 'event');

        if ($request->has('activity_id')) {
            $query->where('activity_id', $request->activity_id);
        }

        if ($request->has('stall_id')) {
            $query->where('stall_id', $request->stall_id);
        }

        if ($request->has('event_vendor_id')) {
            $query->where('event_vendor_id', $request->event_vendor_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('payment_date', [$request->date_from, $request->date_to]);
        }

        $payments = $query->orderBy('payment_date', 'desc')
            ->paginate(20);

        return response()->json([
            'payments' => $payments,
            'status' => 'success'
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'activity_id' => 'required|exists:event_activities,id',
            'stall_id' => 'required|exists:event_stalls,id',
            'event_vendor_id' => 'required|exists:event_vendors,id',
            'amount' => 'required|numeric|min:0',
            'payment_date' => 'required|date',
            'or_number' => 'nullable|string|max:100',
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
            $stall = EventStall::findOrFail($request->stall_id);
            
            // Create payment directly with activity and stall references
            $payment = Payments::create([
                'rented_id' => null, // No rental record needed for event payments
                'vendor_id' => null, // Keep null for event payments
                'event_vendor_id' => $request->event_vendor_id,
                'payment_type' => 'event',
                'amount' => $request->amount,
                'or_number' => $request->or_number,
                'payment_date' => $request->payment_date,
                'status' => 'paid',
                'missed_days' => 0,
                'advance_days' => 0,
                'activity_id' => $request->activity_id,
                'stall_id' => $request->stall_id,
            ]);

            AdminActivity::log(
                Auth::id(),
                'create',
                'EventPayment',
                "Created event payment for stall: {$stall->stall_number}, amount: {$request->amount}",
                null,
                $payment->toArray()
            );

            return response()->json([
                'message' => 'Event payment created successfully',
                'payment' => $payment->load(['eventVendor', 'activity', 'stall']),
                'status' => 'success'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create event payment',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function show($id)
    {
        $payment = Payments::with([
            'vendor',
            'rented.eventStall.activity',
            'rented.eventStall.assignedVendor',
            'remittances'
        ])->findOrFail($id);

        return response()->json([
            'payment' => $payment,
            'status' => 'success'
        ]);
    }

    public function update(Request $request, $id)
    {
        $payment = Payments::with(['stall'])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'amount' => 'sometimes|required|numeric|min:0',
            'payment_date' => 'sometimes|required|date',
            'or_number' => 'nullable|string|max:100',
            'status' => 'sometimes|required|in:paid,pending,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'status' => 'error'
            ], 422);
        }

        try {
            $oldValues = $payment->toArray();
            $updateData = $request->only([
                'amount', 'payment_date', 'or_number', 'status'
            ]);

            $payment->update($updateData);

            // For event payments, get stall info from stall relationship, not rented
            $stallNumber = 'Unknown';
            if ($payment->stall_id && $payment->stall) {
                $stallNumber = $payment->stall->stall_number ?? $payment->stall->stall_name ?? 'Unknown';
            }

            AdminActivity::log(
                Auth::id(),
                'update',
                'EventPayment',
                "Updated event payment for stall: {$stallNumber}",
                $oldValues,
                $payment->toArray()
            );

            return response()->json([
                'message' => 'Event payment updated successfully',
                'payment' => $payment->fresh(),
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update event payment',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function destroy($id)
    {
        $payment = Payments::with(['stall'])->findOrFail($id);

        try {
            $oldValues = $payment->toArray();
            
            // For event payments, get stall info from stall relationship, not rented
            $stallNumber = 'Unknown';
            if ($payment->stall_id && $payment->stall) {
                $stallNumber = $payment->stall->stall_number ?? $payment->stall->stall_name ?? 'Unknown';
            }

            // Check if payment has remittances
            if ($payment->remittances()->count() > 0) {
                return response()->json([
                    'message' => 'Cannot delete payment with remittances',
                    'status' => 'error'
                ], 422);
            }

            $payment->delete();

            AdminActivity::log(
                Auth::id(),
                'delete',
                'EventPayment',
                "Deleted event payment for stall: {$stallNumber}",
                $oldValues,
                null
            );

            return response()->json([
                'message' => 'Event payment deleted successfully',
                'status' => 'success'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete event payment',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function getActivityPayments($activityId)
    {
        $activity = EventActivity::findOrFail($activityId);
        
        $payments = Payments::where('payment_type', 'event')
            ->whereHas('rented.eventStall.activity', function($q) use ($activityId) {
                $q->where('id', $activityId);
            })
            ->with(['vendor', 'rented.eventStall'])
            ->orderBy('payment_date', 'desc')
            ->get();

        $summary = [
            'total_payments' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'paid_amount' => $payments->where('status', 'paid')->sum('amount'),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
        ];

        return response()->json([
            'activity' => $activity,
            'payments' => $payments,
            'summary' => $summary,
            'status' => 'success'
        ]);
    }

    public function getStallPayments($stallId)
    {
        $stall = EventStall::findOrFail($stallId);
        
        $payments = Payments::where('payment_type', 'event')
            ->whereHas('rented.eventStall', function($q) use ($stallId) {
                $q->where('id', $stallId);
            })
            ->with(['vendor', 'rented'])
            ->orderBy('payment_date', 'desc')
            ->get();

        return response()->json([
            'stall' => $stall,
            'payments' => $payments,
            'total_paid' => $payments->where('status', 'paid')->sum('amount'),
            'total_pending' => $payments->where('status', 'pending')->sum('amount'),
            'status' => 'success'
        ]);
    }

    public function getVendorPayments($vendorId)
    {
        $payments = Payments::where('payment_type', 'event')
            ->where('vendor_id', $vendorId)
            ->with(['vendor', 'rented.eventStall.activity'])
            ->orderBy('payment_date', 'desc')
            ->get();

        return response()->json([
            'payments' => $payments,
            'total_paid' => $payments->where('status', 'paid')->sum('amount'),
            'total_pending' => $payments->where('status', 'pending')->sum('amount'),
            'status' => 'success'
        ]);
    }

    public function getPaymentSummary(Request $request)
    {
        $query = Payments::where('payment_type', 'event');

        if ($request->has('activity_id')) {
            $query->whereHas('rented.eventStall.activity', function($q) use ($request) {
                $q->where('id', $request->activity_id);
            });
        }

        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('payment_date', [$request->date_from, $request->date_to]);
        }

        $payments = $query->get();

        $summary = [
            'total_payments' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'paid_amount' => $payments->where('status', 'paid')->sum('amount'),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            'cancelled_amount' => $payments->where('status', 'cancelled')->sum('amount'),
            'average_payment' => $payments->count() > 0 ? $payments->sum('amount') / $payments->count() : 0,
        ];

        return response()->json([
            'summary' => $summary,
            'status' => 'success'
        ]);
    }

    public function getVendorsByActivity($activityId)
    {
        try {
            $activity = EventActivity::findOrFail($activityId);
            
            // Get vendors who have stall assignments for this activity
            $vendors = StallAssignment::where('activity_id', $activityId)
                ->where('status', 'active')
                ->with('vendor')
                ->get()
                ->pluck('vendor')
                ->filter()
                ->unique('id')
                ->values();

            return response()->json([
                'vendors' => $vendors,
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch vendors for activity',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }

    public function getStallsByVendorAndActivity($activityId, $vendorId)
    {
        try {
            $activity = EventActivity::findOrFail($activityId);
            
            // Get stalls assigned to this vendor for this activity
            $stalls = StallAssignment::where('activity_id', $activityId)
                ->where('vendor_id', $vendorId)
                ->where('status', 'active')
                ->with('stall')
                ->get()
                ->pluck('stall')
                ->filter()
                ->values();

            return response()->json([
                'stalls' => $stalls,
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch stalls for vendor',
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    }
}
