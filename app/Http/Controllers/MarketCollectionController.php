<?php

namespace App\Http\Controllers;

use App\Models\Payments;
use Illuminate\Http\Request;
use App\Models\MarketCollection;

class MarketCollectionController extends Controller
{
    

    public function store(Request $request)
{
    // 1. Save payment
    $payment = Payments::create([
        'rented_id'   => $request->rented_id,
        'amount'      => $request->amount,
        'payment_date'=> $request->payment_date,
    ]);

    // 2. Save market collection with collector id
    MarketCollection::create([
        'payment_id'   => $payment->id,
        'collector_id' => auth()->id(), // ✅ auto-assign current logged-in collector
    ]);

    return response()->json([
        'message' => 'Payment and collection recorded successfully',
        'payment' => $payment
    ]);
}

}
