<?php

namespace App\Http\Controllers;

use App\Models\CashTicket;
use App\Models\CashTicketsPayment;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

class CashTicketTypeController extends Controller
{
    /**
     * Display a listing of cash ticket types.
     */
    public function index()
    {
        $cashTickets = CashTicket::with('payments')->get();
        return response()->json([
            'success' => true,
            'data' => $cashTickets
        ]);
    }

    /**
     * Store a newly created cash ticket type.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:255|unique:cash_tickets,type',
            'quantity' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $cashTicket = CashTicket::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Cash ticket type created successfully',
            'data' => $cashTicket
        ], 201);
    }

    /**
     * Display the specified cash ticket type.
     */
    public function show($id)
    {
        $cashTicket = CashTicket::with('payments')->find($id);
        
        if (!$cashTicket) {
            return response()->json([
                'success' => false,
                'message' => 'Cash ticket type not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $cashTicket
        ]);
    }

    /**
     * Update the specified cash ticket type.
     */
    public function update(Request $request, $id)
    {
        $cashTicket = CashTicket::find($id);
        
        if (!$cashTicket) {
            return response()->json([
                'success' => false,
                'message' => 'Cash ticket type not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|max:255|unique:cash_tickets,type,' . $id,
            'quantity' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $cashTicket->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Cash ticket type updated successfully',
            'data' => $cashTicket
        ]);
    }

    /**
     * Remove the specified cash ticket type.
     */
    public function destroy($id)
    {
        $cashTicket = CashTicket::find($id);
        
        if (!$cashTicket) {
            return response()->json([
                'success' => false,
                'message' => 'Cash ticket type not found'
            ], 404);
        }

        // Check if there are payments associated with this cash ticket
        if ($cashTicket->payments()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete cash ticket type with existing payments'
            ], 422);
        }

        $cashTicket->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cash ticket type deleted successfully'
        ]);
    }

    /**
     * Get daily collections for a specific month
     */
    public function getDailyCollections(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|integer|min:1|max:12',
            'year' => 'required|integer|min:2020|max:2030'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $month = $request->month;
        $year = $request->year;
        
        // Get all cash ticket types
        $cashTicketTypes = CashTicket::all();
        
        // Get all payments for the specified month
        $payments = CashTicketsPayment::with('cashTicket')
            ->whereMonth('payment_date', $month)
            ->whereYear('payment_date', $year)
            ->get()
            ->groupBy(function($payment) {
                return Carbon::parse($payment->payment_date)->format('Y-m-d');
            });

        // Build daily data structure
        $dailyData = [];
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;
        
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::create($year, $month, $day)->format('Y-m-d');
            $dailyData[$date] = [
                'date' => $date,
                'types' => [],
                'total' => 0
            ];
            
            // Initialize all types with 0
            foreach ($cashTicketTypes as $type) {
                $dailyData[$date]['types'][$type->id] = [
                    'type_name' => $type->type,
                    'amount' => 0,
                    'quantity' => 0
                ];
            }
        }

        // Fill in actual payment data
        foreach ($payments as $date => $dayPayments) {
            foreach ($dayPayments as $payment) {
                $dailyData[$date]['types'][$payment->cash_ticket_id]['amount'] = $payment->amount_paid;
                $dailyData[$date]['total'] += $payment->amount_paid;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'cash_ticket_types' => $cashTicketTypes,
                'daily_data' => array_values($dailyData),
                'month' => $month,
                'year' => $year
            ]
        ]);
    }

    /**
     * Get monthly collections for a specific year
     */
    public function getMonthlyCollections(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|min:2020|max:2030'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $year = $request->year;
        
        // Get all cash ticket types
        $cashTicketTypes = CashTicket::all();
        
        // Get all payments for the specified year
        $payments = CashTicketsPayment::with('cashTicket')
            ->whereYear('payment_date', $year)
            ->get()
            ->groupBy(function($payment) {
                return Carbon::parse($payment->payment_date)->format('Y-m');
            });

        // Build monthly data structure
        $monthlyData = [];
        
        for ($month = 1; $month <= 12; $month++) {
            $monthKey = Carbon::create($year, $month)->format('Y-m');
            $monthlyData[$monthKey] = [
                'month' => $month,
                'month_name' => Carbon::create($year, $month)->format('F'),
                'types' => [],
                'total' => 0
            ];
            
            // Initialize all types with 0
            foreach ($cashTicketTypes as $type) {
                $monthlyData[$monthKey]['types'][$type->id] = [
                    'type_name' => $type->type,
                    'amount' => 0
                ];
            }
        }

        // Fill in actual payment data
        foreach ($payments as $monthKey => $monthPayments) {
            foreach ($monthPayments as $payment) {
                $monthlyData[$monthKey]['types'][$payment->cash_ticket_id]['amount'] += $payment->amount_paid;
                $monthlyData[$monthKey]['total'] += $payment->amount_paid;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'cash_ticket_types' => $cashTicketTypes,
                'monthly_data' => array_values($monthlyData),
                'year' => $year
            ]
        ]);
    }

    /**
     * Save daily payment data
     */
    public function saveDailyPayments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'payments' => 'required|array',
            'payments.*.cash_ticket_id' => 'required|integer|exists:cash_tickets,id',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = Carbon::parse($request->date);
        
        // Check if date is today or in the past (not future)
        if ($date->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot edit payments for future dates'
            ], 422);
        }

        // Delete existing payments for this date
        CashTicketsPayment::whereDate('payment_date', $date)->delete();

        // Create new payments
        foreach ($request->payments as $paymentData) {
            if ($paymentData['amount'] > 0) {
                CashTicketsPayment::create([
                    'cash_ticket_id' => $paymentData['cash_ticket_id'],
                    'amount_paid' => $paymentData['amount'],
                    'payment_date' => $date,
                    'notes' => $paymentData['notes'] ?? null
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Daily payments saved successfully'
        ]);
    }

    /**
     * Get analytics data
     */
    public function getAnalytics(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // Get total collections by type
        $collectionsByType = CashTicketsPayment::with('cashTicket')
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->get()
            ->groupBy('cash_ticket.type')
            ->map(function ($payments) {
                return $payments->sum('amount_paid');
            });

        // Get daily totals
        $dailyTotals = CashTicketsPayment::whereBetween('payment_date', [$startDate, $endDate])
            ->get()
            ->groupBy(function($payment) {
                return Carbon::parse($payment->payment_date)->format('Y-m-d');
            })
            ->map(function ($payments) {
                return $payments->sum('amount_paid');
            });

        // Get total amount
        $totalAmount = $dailyTotals->sum();

        return response()->json([
            'success' => true,
            'data' => [
                'collections_by_type' => $collectionsByType,
                'daily_totals' => $dailyTotals,
                'total_amount' => $totalAmount,
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d')
            ]
        ]);
    }
}
