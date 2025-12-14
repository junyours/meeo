<?php

namespace App\Http\Controllers;

use App\Models\Rented;
use App\Models\Stalls;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
        public function store(Request $request)
    {
    
        $request->validate([
            'stall_id' => 'required|exists:stall,id',
            'fullname' => 'required|string',
            'age' => 'required|integer',
            'gender' => 'required|string',
            'contact_number' => 'required|string',
            'address' => 'required|string',
            'emergency_contact' => 'nullable|string',
            'business_name' => 'nullable|string',
            'years_in_operation' => 'nullable|integer',
        ]);

        $tenant = Tenant::create($request->only([
            'stall_id', 'fullname', 'age', 'gender', 'contact_number',
            'address', 'emergency_contact', 'business_name', 'years_in_operation'
        ]));

        $stall = Stalls::find($request->stall_id);
        $stall->update(['status' => 'occupied']);

        return response()->json(['message' => 'Tenant added successfully']);
    }

    public function storeGroup(Request $request)
{
    $request->validate([
        'vendor_id' => 'required|exists:vendor_details,id',
        'stall_ids' => 'required|array|min:1',
        'stall_ids.*' => 'exists:stall,id',
    ]);

    DB::beginTransaction();

    try {
        foreach ($request->stall_ids as $stallId) {
            Rented::create([
                'vendor_id' => $request->vendor_id,
                'stall_id' => $stallId,
            ]);

            // Update the stall status to occupied
            Stalls::where('id', $stallId)->update(['status' => 'occupied']);
        }

        DB::commit();

        return response()->json(['message' => 'Vendor assigned to stalls successfully.']);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Failed to assign vendor to stalls.',
            'error' => $e->getMessage()
        ], 500);
    }
}


  public function showByStall($stallId)
{
    $rented = Rented::with('vendor')->where('stall_id', $stallId)->first();

    if (!$rented) {
        return response()->json(['message' => 'Tenant not found'], 404);
    }

    return response()->json([
        'stall_id' => $rented->stall_id,
        'vendor_id' => $rented->vendor_id,
        'vendor_details' => $rented->vendor, // includes fullname, age, gender, etc.
    ]);
}



    

}
