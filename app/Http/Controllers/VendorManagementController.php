<?php

namespace App\Http\Controllers;

use App\Models\VendorDetails;
use App\Models\Stalls;
use App\Models\Rented;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VendorManagementController extends Controller
{
   public function index(Request $request)
{
    $query = VendorDetails::with(['certificates', 'activeCertificate'])
      ->orderBy('created_at', 'desc');

    if ($request->search) {
        $search = $request->search;
        $query->where(function($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
              ->orWhere('middle_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('contact_number', 'like', "%{$search}%")
              ->orWhere('address', 'like', "%{$search}%");
        });
    }

    if ($request->status) {
        $query->where('status', $request->status);
    }

    // ❌ REMOVE paginate()
    // ✅ RETURN ALL
    $vendors = $query->get();

    return response()->json($vendors);
}


    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'contact_number' => 'required|string|max:20',
            'address' => 'nullable|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            $vendor = VendorDetails::create([
                'first_name' => ucfirst(strtolower($validated['first_name'])),
                'middle_name' => ucfirst(strtolower($validated['middle_name'] ?? '')),
                'last_name' => ucfirst(strtolower($validated['last_name'])),
                'contact_number' => $validated['contact_number'],
                'address' => $validated['address'] ?? null,
                'Status' => 'active',
            ]);

            AdminActivity::log(
                auth()->id(),
                'created',
                'vendor',
                "Created new vendor: {$vendor->full_name}",
                null,
                $vendor->toArray()
            );

            DB::commit();

            return response()->json([
                'message' => 'Vendor created successfully',
                'vendor' => $vendor
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create vendor: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(VendorDetails $vendor)
    {
        return response()->json($vendor->load([
            'certificates',
            'activeCertificate'
        ]));
    }

    public function update(Request $request, VendorDetails $vendor)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'contact_number' => 'required|string|max:20',
            'address' => 'nullable|string|max:500',
        ]);

        $validated['fullname'] = trim("{$validated['first_name']} {$validated['middle_name']} {$validated['last_name']}");

        $oldValues = $vendor->toArray();
        $vendor->update($validated);
        
        AdminActivity::log(
            auth()->id(),
            'update',
            'vendor_management',
            "Updated vendor: {$vendor->fullname}",
            $oldValues,
            $vendor->toArray()
        );

        return response()->json($vendor->load(['certificates']));
    }

    public function destroy(VendorDetails $vendor)
    {
        $vendorName = $vendor->fullname;
        $vendor->delete();
        
        AdminActivity::log(
            auth()->id(),
            'delete',
            'vendor_management',
            "Deleted vendor: {$vendorName}",
            $vendor->toArray(),
            null
        );

        return response()->json(null, 204);
    }


}
