<?php

namespace App\Http\Controllers;

use App\Models\Payments;
use App\Models\VendorDetails;
use Illuminate\Http\Request;

class PaymentManagementController extends Controller
{
    /**
     * Get all payments for payment management screen
     * Returns payments with vendor and stall relationships
     */
    public function index()
    {
        $payments = Payments::with(['vendor', 'rented.stall'])
            ->orderBy('payment_date', 'desc')
            ->get()
            ->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'or_number' => $payment->or_number,
                    'vendor_id' => $payment->vendor_id,
                    'payment_type' => $payment->payment_type,
                    'amount' => $payment->amount,
                    'payment_date' => $payment->payment_date,
                    'missed_days' => $payment->missed_days,
                    'advance_days' => $payment->advance_days,
                    'status' => $payment->status,
                    'vendor' => $payment->vendor ? [
                        'id' => $payment->vendor->id,
                        'first_name' => $payment->vendor->first_name,
                        'last_name' => $payment->vendor->last_name,
                        'contact_number' => $payment->vendor->contact_number,
                    ] : null,
                    'rented' => $payment->rented ? [
                        'id' => $payment->rented->id,
                        'stall' => $payment->rented->stall ? [
                            'stall_number' => $payment->rented->stall->stall_number,
                        ] : null,
                    ] : null,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->updated_at,
                ];
            });

        return response()->json($payments);
    }

    /**
     * Update a specific payment
     * Handles all payment field updates with validation
     */
    public function update(Request $request, $id)
    {
        try {
            $payment = Payments::findOrFail($id);
            
            $validated = $request->validate([
                'or_number' => 'nullable|string|max:255',
                'payment_type' => 'nullable|in:daily,monthly,advance,penalty',
                'amount' => 'nullable|numeric|min:0',
                'payment_date' => 'nullable|date',
                'missed_days' => 'nullable|integer|min:0',
                'advance_days' => 'nullable|integer|min:0',
                'status' => 'nullable|in:paid,pending,overdue,partial',
            ]);

            // Only update fields that are provided
            $updateData = [];
            foreach ($validated as $key => $value) {
                if ($value !== null) {
                    $updateData[$key] = $value;
                }
            }

            if (empty($updateData)) {
                return response()->json([
                    'message' => 'No valid fields to update',
                ], 400);
            }

            $payment->update($updateData);

            // Return updated payment with relationships
            $updatedPayment = Payments::with(['vendor', 'rented.stall'])
                ->find($id);

            return response()->json([
                'message' => 'Payment updated successfully',
                'payment' => [
                    'id' => $updatedPayment->id,
                    'or_number' => $updatedPayment->or_number,
                    'vendor_id' => $updatedPayment->vendor_id,
                    'payment_type' => $updatedPayment->payment_type,
                    'amount' => $updatedPayment->amount,
                    'payment_date' => $updatedPayment->payment_date,
                    'missed_days' => $updatedPayment->missed_days,
                    'advance_days' => $updatedPayment->advance_days,
                    'status' => $updatedPayment->status,
                    'vendor' => $updatedPayment->vendor ? [
                        'id' => $updatedPayment->vendor->id,
                        'first_name' => $updatedPayment->vendor->first_name,
                        'last_name' => $updatedPayment->vendor->last_name,
                        'contact_number' => $updatedPayment->vendor->contact_number,
                    ] : null,
                    'rented' => $updatedPayment->rented ? [
                        'id' => $updatedPayment->rented->id,
                        'stall' => $updatedPayment->rented->stall ? [
                            'stall_number' => $updatedPayment->rented->stall->stall_number,
                        ] : null,
                    ] : null,
                    'created_at' => $updatedPayment->created_at,
                    'updated_at' => $updatedPayment->updated_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendors for dropdown/selection in payment management
     * Returns only active vendors ordered by name
     */
    public function getVendors()
    {
        $vendors = VendorDetails::select('id', 'first_name', 'last_name', 'contact_number', 'status')
            ->where('status', 'active')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return response()->json($vendors);
    }

    /**
     * Get payment statistics for dashboard
     * Returns summary data for payment management overview
     */
    public function getStats()
    {
        $today = now()->toDateString();
        
        // Total collected amount
        $totalCollected = Payments::sum('amount');
        
        // Today's collection
        $todayCollected = Payments::whereDate('payment_date', $today)->sum('amount');
        
        // Today's payment count
        $todayPaymentCount = Payments::whereDate('payment_date', $today)->count();
        
        // Total payment count
        $totalPaymentCount = Payments::count();

        return response()->json([
            'total_collected' => $totalCollected,
            'today_collected' => $todayCollected,
            'today_payment_count' => $todayPaymentCount,
            'total_payment_count' => $totalPaymentCount,
        ]);
    }

    /**
     * Delete a specific payment
     * Removes payment record with proper validation
     */
    public function destroy($id)
    {
        try {
            $payment = Payments::findOrFail($id);
            
            // Additional validation can be added here
            // For example, check if payment can be deleted (not too old, etc.)
            
            $payment->delete();

            return response()->json([
                'message' => 'Payment deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payments for a specific vendor
     * Can be filtered by month/year
     */
    public function getVendorPayments($vendorId, Request $request)
    {
        $query = Payments::with(['rented.stall'])
            ->where('vendor_id', $vendorId)
            ->orderBy('payment_date', 'desc');

        // Filter by month if provided
        if ($request->has('month') && $request->has('year')) {
            $query->whereMonth('payment_date', $request->month)
                  ->whereYear('payment_date', $request->year);
        }

        $payments = $query->get()->map(function ($payment) {
            return [
                'id' => $payment->id,
                'or_number' => $payment->or_number,
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
                'payment_date' => $payment->payment_date,
                'missed_days' => $payment->missed_days,
                'advance_days' => $payment->advance_days,
                'status' => $payment->status,
                'stall' => $payment->rented?->stall ? [
                    'stall_number' => $payment->rented->stall->stall_number,
                ] : null,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ];
        });

        return response()->json($payments);
    }
}
