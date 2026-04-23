<?php

namespace App\Http\Controllers;

use App\Models\EventVendor;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class EventVendorController extends Controller
{
    /**
     * Display a listing of event vendors.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = EventVendor::query();

            // Search functionality
            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('middle_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('contact_number', 'like', "%{$search}%")
                      ->orWhere('address', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('status') && $request->get('status') !== 'all') {
                $query->where('status', $request->get('status'));
            }

            $vendors = $query->orderBy('created_at', 'desc')->paginate(10);

            return response()->json([
                'vendors' => $vendors,
                'message' => 'Event vendors retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving event vendors: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created event vendor.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'last_name' => 'required|string|max:255',
                'contact_number' => 'required|string|max:20',
                'address' => 'nullable|string|max:1000',
                'status' => 'required|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $vendor = EventVendor::create([
                'first_name' => $request->first_name,
                'middle_name' => $request->middle_name,
                'last_name' => $request->last_name,
                'contact_number' => $request->contact_number,
                'address' => $request->address,
                'status' => $request->status,
                'created_by' => Auth::id(),
            ]);

            // Log admin activity
            AdminActivity::log(
                Auth::id(),
                'created',
                'event_vendor',
                "Created event vendor: {$vendor->full_name}",
                null,
                $vendor->toArray()
            );

            return response()->json([
                'vendor' => $vendor,
                'message' => 'Event vendor created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error creating event vendor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified event vendor.
     */
    public function show($id): JsonResponse
    {
        try {
            $vendor = EventVendor::findOrFail($id);
            return response()->json([
                'vendor' => $vendor,
                'message' => 'Event vendor retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving event vendor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified event vendor.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $vendor = EventVendor::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'middle_name' => 'nullable|string|max:255',
                'last_name' => 'required|string|max:255',
                'contact_number' => 'required|string|max:20',
                'address' => 'nullable|string|max:1000',
                'status' => 'required|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $oldValues = $vendor->toArray();

            $vendor->update([
                'first_name' => $request->first_name,
                'middle_name' => $request->middle_name,
                'last_name' => $request->last_name,
                'contact_number' => $request->contact_number,
                'address' => $request->address,
                'status' => $request->status,
            ]);

            // Log admin activity
            AdminActivity::log(
                Auth::id(),
                'updated',
                'event_vendor',
                "Updated event vendor: {$vendor->full_name}",
                $oldValues,
                $vendor->toArray()
            );

            return response()->json([
                'vendor' => $vendor,
                'message' => 'Event vendor updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating event vendor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified event vendor.
     */
    public function destroy($id): JsonResponse
    {
        try {
            $vendor = EventVendor::findOrFail($id);
            $vendorName = $vendor->full_name;
            
            $vendor->delete();

            // Log admin activity
            AdminActivity::log(
                Auth::id(),
                'deleted',
                'event_vendor',
                "Deleted event vendor: {$vendorName}",
                $vendor->toArray(),
                null
            );

            return response()->json([
                'message' => 'Event vendor deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting event vendor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update vendor status.
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            $vendor = EventVendor::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $oldStatus = $vendor->status;
            $vendor->update(['status' => $request->status]);

            // Log admin activity
            AdminActivity::log(
                Auth::id(),
                'status_changed',
                'event_vendor',
                "Changed event vendor status from {$oldStatus} to {$request->status} for {$vendor->full_name}",
                ['status' => $oldStatus],
                ['status' => $request->status]
            );

            return response()->json([
                'vendor' => $vendor,
                'message' => 'Event vendor status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error updating event vendor status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available vendors for dropdown.
     */
    public function getAvailableVendors(): JsonResponse
    {
        try {
            $vendors = EventVendor::active()
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'contact_number']);

            return response()->json([
                'vendors' => $vendors,
                'message' => 'Available event vendors retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error retrieving available event vendors: ' . $e->getMessage()
            ], 500);
        }
    }
}
