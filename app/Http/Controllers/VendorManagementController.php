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

    public function assignToStall(Request $request, VendorDetails $vendor)
    {
        $validated = $request->validate([
            'stall_id' => 'required|exists:stall,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'monthly_rate' => 'required|numeric|min:0',
            'daily_rate' => 'nullable|numeric|min:0',
        ]);

        $stall = Stalls::findOrFail($validated['stall_id']);

        if ($stall->status === 'occupied') {
            return response()->json(['message' => 'Stall is already occupied'], 422);
        }

        DB::transaction(function() use ($vendor, $validated, $stall) {
            $rental = Rented::create([
                'vendor_id' => $vendor->id,
                'stall_id' => $validated['stall_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'] ?? null,
                'monthly_rate' => $validated['monthly_rate'],
                'daily_rate' => $validated['daily_rate'] ?? 0,
                'status' => 'occupied',
            ]);

            $stall->update(['status' => 'occupied']);

            AdminActivity::log(
                auth()->id(),
                'assign',
                'vendor_management',
                "Assigned vendor {$vendor->fullname} to stall {$stall->stall_number}",
                null,
                [
                    'vendor_id' => $vendor->id,
                    'stall_id' => $stall->id,
                    'rental_id' => $rental->id
                ]
            );
        });

        return response()->json(['message' => 'Vendor assigned to stall successfully']);
    }

    public function removeFromStall(Request $request, VendorDetails $vendor)
    {
        $validated = $request->validate([
            'stall_id' => 'required|exists:stall,id',
            'removal_reason' => 'nullable|string|max:1000',
        ]);

        $stall = Stalls::findOrFail($validated['stall_id']);
        $rental = Rented::where('vendor_id', $vendor->id)
                       ->where('stall_id', $stall->id)
                       ->where('status', 'active')
                       ->first();

        if (!$rental) {
            return response()->json(['message' => 'No active rental found for this vendor and stall'], 422);
        }

        DB::transaction(function() use ($vendor, $stall, $rental, $validated) {
            $rental->update([
                'status' => 'terminated',
                'end_date' => now(),
                'removal_reason' => $validated['removal_reason'] ?? null,
            ]);

            $stall->update(['status' => 'available']);

            AdminActivity::log(
                auth()->id(),
                'remove',
                'vendor_management',
                "Removed vendor {$vendor->fullname} from stall {$stall->stall_number}",
                null,
                [
                    'vendor_id' => $vendor->id,
                    'stall_id' => $stall->id,
                    'rental_id' => $rental->id,
                    'reason' => $validated['removal_reason'] ?? null
                ]
            );
        });

        return response()->json(['message' => 'Vendor removed from stall successfully']);
    }

    public function createAndAssignVendor(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'contact_number' => 'required|string|max:20',
            'stall_id' => 'required|exists:stall,id',
            'monthly_rate' => 'required|numeric|min:0',
            'daily_rate' => 'required|numeric|min:0',
            'payment_type' => 'required|in:daily,monthly',
        ]);

        try {
            DB::beginTransaction();

            // Create the vendor
            $vendor = VendorDetails::create([
                'first_name' => ucfirst(strtolower($validated['first_name'])),
                'middle_name' => ucfirst(strtolower($validated['middle_name'] ?? '')),
                'last_name' => ucfirst(strtolower($validated['last_name'])),
                'contact_number' => $validated['contact_number'],
                'Status' => 'active',
            ]);

            // Get the stall
            $stall = Stalls::findOrFail($validated['stall_id']);

            // Create rental record
            $rental = Rented::create([
                'vendor_id' => $vendor->id,
                'stall_id' => $stall->id,
                'monthly_rate' => $validated['monthly_rate'],
                'daily_rate' => $validated['daily_rate'],
                'payment_type' => $validated['payment_type'],
                'status' => 'active',
                'start_date' => now(),
            ]);

            // Update stall status
            $stall->update(['status' => 'occupied']);

            AdminActivity::log(
                auth()->id(),
                'created_and_assigned',
                'vendor_management',
                "Created and assigned vendor {$vendor->full_name} to stall {$stall->stall_number}",
                null,
                [
                    'vendor' => $vendor->toArray(),
                    'stall' => $stall->toArray(),
                    'rental' => $rental->toArray()
                ]
            );

            DB::commit();

            return response()->json([
                'message' => 'Vendor created and assigned successfully',
                'vendor' => $vendor,
                'stall' => $stall,
                'rental' => $rental
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create and assign vendor: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getStallHistory(VendorDetails $vendor)
    {
        $rentals = Rented::with(['stall.section.area'])
                        ->where('vendor_id', $vendor->id)
                        ->orderBy('created_at', 'desc')
                        ->get();

        return response()->json($rentals);
    }

    public function getAvailableStalls()
    {
        $stalls = Stalls::with(['section.area'])
                       ->where('status', 'available')
                       ->where('is_active', true)
                       ->orderBy('stall_number')
                       ->get();

        return response()->json($stalls);
    }
}
