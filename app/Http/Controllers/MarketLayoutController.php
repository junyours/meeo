<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Sections;
use App\Models\Stalls;
use App\Models\VendorDetails;
use App\Models\Rented;
use App\Models\AdminActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MarketLayoutController extends Controller
{
    public function index()
    {
        $areas = Area::with(['sections' => function($query) {
                $query->select('id', 'name', 'area_id');
            }, 'stalls'])->orderBy('sort_order')->get();
        
        // Add area_name to each section
        $areasWithAreaName = $areas->map(function($area) {
            $area->sections = $area->sections->map(function($section) use ($area) {
                $section->area_name = $area->name;
                return $section;
            });
            return $area;
        });
        
        return response()->json($areasWithAreaName);
    }

    public function storeArea(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'column_count' => 'required|integer|min:1',
            'position_x' => 'nullable|integer',
            'position_y' => 'nullable|integer',
            'rows_per_column' => 'nullable|array',
        ]);

        $area = Area::create($validated);
        
        AdminActivity::log(
            auth()->id(),
            'create',
            'market_layout',
            "Created area: {$area->name}",
            null,
            $area->toArray()
        );

        return response()->json($area->load('sections'), 201);
    }

    public function updateArea(Request $request, Area $area)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'column_count' => 'required|integer|min:1',
            'position_x' => 'nullable|integer',
            'position_y' => 'nullable|integer',
            'rows_per_column' => 'nullable|array',
        ]);

        $oldValues = $area->toArray();
        $area->update($validated);
        
        AdminActivity::log(
            auth()->id(),
            'update',
            'market_layout',
            "Updated area: {$area->name}",
            $oldValues,
            $area->toArray()
        );

        return response()->json($area->load('sections'));
    }

    public function destroyArea(Area $area)
    {
        $areaName = $area->name;
        $area->delete();
        
        AdminActivity::log(
            auth()->id(),
            'delete',
            'market_layout',
            "Deleted area: {$areaName}",
            $area->toArray(),
            null
        );

        return response()->json(null, 204);
    }

    public function reorderAreas(Request $request)
    {
        $validated = $request->validate([
            'areas' => 'required|array',
            'areas.*.id' => 'required|exists:areas,id',
            'areas.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['areas'] as $areaData) {
            Area::where('id', $areaData['id'])
                ->update(['sort_order' => $areaData['sort_order']]);
        }

        AdminActivity::log(
            auth()->id(),
            'reorder',
            'market_layout',
            'Reordered areas',
            null,
            $validated['areas']
        );

        return response()->json(['message' => 'Areas reordered successfully']);
    }

    public function storeSection(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'area_id' => 'required|exists:areas,id',
            'rate_type' => 'required|in:daily,monthly',
            'rate' => 'required|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'daily_rate' => 'nullable|numeric|min:0',
            'column_index' => 'nullable|integer',
            'row_index' => 'nullable|integer',
        ]);

        $section = Sections::create($validated);
        
        AdminActivity::log(
            auth()->id(),
            'create',
            'market_layout',
            "Created section: {$section->name}",
            null,
            $section->toArray()
        );

        return response()->json($section->load(['area', 'stalls']), 201);
    }

    public function updateSection(Request $request, Sections $section)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'area_id' => 'required|exists:areas,id',
            'rate_type' => 'required|in:daily,monthly',
            'rate' => 'required|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'daily_rate' => 'nullable|numeric|min:0',
            'column_index' => 'nullable|integer',
            'row_index' => 'nullable|integer',
        ]);

        $oldValues = $section->toArray();
        $section->update($validated);
        
        AdminActivity::log(
            auth()->id(),
            'update',
            'market_layout',
            "Updated section: {$section->name}",
            $oldValues,
            $section->toArray()
        );

        return response()->json($section->load(['area', 'stalls']));
    }

    public function destroySection(Sections $section)
    {
        $sectionName = $section->name;
        $section->delete();
        
        AdminActivity::log(
            auth()->id(),
            'delete',
            'market_layout',
            "Deleted section: {$sectionName}",
            $section->toArray(),
            null
        );

        return response()->json(null, 204);
    }

    public function reorderSections(Request $request)
    {
        $validated = $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => 'required|exists:section,id',
            'sections.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['sections'] as $sectionData) {
            Sections::where('id', $sectionData['id'])
                ->update(['sort_order' => $sectionData['sort_order']]);
        }

        AdminActivity::log(
            auth()->id(),
            'reorder',
            'market_layout',
            'Reordered sections',
            null,
            $validated['sections']
        );

        return response()->json(['message' => 'Sections reordered successfully']);
    }

    public function storeStall(Request $request)
    {
        $validated = $request->validate([
            'section_id' => 'required|exists:section,id',
            'stall_number' => 'required|string|max:255',
            'row_position' => 'nullable|integer',
            'column_position' => 'nullable|integer',
            'size' => 'nullable|string|max:255',
            'status' => ['required', Rule::in(['available', 'occupied', 'inactive'])],
        ]);

        $stall = Stalls::create($validated);
        
        AdminActivity::log(
            auth()->id(),
            'create',
            'market_layout',
            "Created stall: {$stall->stall_number}",
            null,
            $stall->toArray()
        );

        return response()->json($stall->load(['section', 'rented']), 201);
    }

    public function updateStall(Request $request, Stalls $stall)
    {
        $validated = $request->validate([
            'section_id' => 'required|exists:section,id',
            'stall_number' => 'required|string|max:255',
            'row_position' => 'nullable|integer',
            'column_position' => 'nullable|integer',
            'size' => 'nullable|string|max:255',
            'status' => ['required', Rule::in(['available', 'occupied', 'inactive'])],
            'is_active' => 'nullable|boolean',
            'message' => 'nullable|string',
        ]);

        $oldValues = $stall->toArray();
        $stall->update($validated);
        
        AdminActivity::log(
            auth()->id(),
            'update',
            'market_layout',
            "Updated stall: {$stall->stall_number}",
            $oldValues,
            $stall->toArray()
        );

        return response()->json($stall->load(['section', 'rented']));
    }

    public function destroyStall(Stalls $stall)
    {
        $stallNumber = $stall->stall_number;
        $stall->delete();
        
        AdminActivity::log(
            auth()->id(),
            'delete',
            'market_layout',
            "Deleted stall: {$stallNumber}",
            $stall->toArray(),
            null
        );

        return response()->json(null, 204);
    }

    public function reorderStalls(Request $request)
    {
        $validated = $request->validate([
            'stalls' => 'required|array',
            'stalls.*.id' => 'required|exists:stall,id',
            'stalls.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['stalls'] as $stallData) {
            Stalls::where('id', $stallData['id'])
                ->update(['sort_order' => $stallData['sort_order']]);
        }

        AdminActivity::log(
            auth()->id(),
            'reorder',
            'market_layout',
            'Reordered stalls',
            null,
            $validated['stalls']
        );

        return response()->json(['message' => 'Stalls reordered successfully']);
    }

    /**
     * Assign a vendor to a stall
     */
    public function assignVendorToStall(Request $request, Stalls $stall)
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendor_details,id',
        ]);

        try {
            DB::beginTransaction();

            // Load the stall with section data
            $stall->load('section');

            // Get the vendor
            $vendor = VendorDetails::findOrFail($validated['vendor_id']);

            // Check if vendor is active
            if ($vendor->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only active vendors can be assigned to stalls'
                ], 422);
            }

            // First, close any existing rental records for this stall (except already unoccupied ones)
            Log::info('Starting vendor assignment - checking for existing rentals', [
                'stall_id' => $stall->id,
                'new_vendor_id' => $vendor->id,
            ]);

            $allRentals = Rented::where('stall_id', $stall->id)->get();
            
            Log::info('All rentals for this stall before assignment', [
                'stall_id' => $stall->id,
                'total_rentals' => $allRentals->count(),
                'rentals' => $allRentals->map(function($r) {
                    return [
                        'id' => $r->id,
                        'vendor_id' => $r->vendor_id,
                        'status' => $r->status,
                        'updated_at' => $r->updated_at->toDateTimeString(),
                    ];
                })
            ]);

            $existingRentals = Rented::where('stall_id', $stall->id)
                ->where('status', '!=', 'unoccupied')
                ->get();

            Log::info('Found rentals to close', [
                'stall_id' => $stall->id,
                'rentals_to_close' => $existingRentals->count(),
            ]);

            foreach ($existingRentals as $existingRented) {
                Log::info('Processing rental for closure', [
                    'rental_id' => $existingRented->id,
                    'vendor_id' => $existingRented->vendor_id,
                    'current_status' => $existingRented->status,
                ]);
                
                // Close the existing rental record with proper end date
                $currentTime = now();
                $existingRented->status = 'unoccupied';
                $existingRented->updated_at = $currentTime; // Explicitly set updated_at
                $existingRented->save(); 
                
                Log::info('Closed existing rental record', [
                    'stall_id' => $stall->id,
                    'rented_id' => $existingRented->id,
                    'vendor_id' => $existingRented->vendor_id,
                    'old_status' => 'occupied',
                    'new_status' => $existingRented->status,
                    'updated_at' => $existingRented->updated_at->toDateTimeString(),
                    'current_time' => $currentTime->toDateTimeString(),
                ]);
            }

            // Update stall with vendor information
            $stall->update([
                'status' => 'occupied',
                'is_active' => true,
            ]);

            // Calculate rent using same logic as StallController
            $section = $stall->section;
            $dailyRent = $this->computeDailyRate($stall, $section);
            $monthlyRent = $this->computeMonthlyRate($stall, $section);

            // Create a new rented record for this assignment
            $rented = Rented::create([
                'vendor_id' => $vendor->id,
                'stall_id' => $stall->id,
                'monthly_rent' => $monthlyRent,
                'daily_rent' => $dailyRent,
                'status' => 'occupied',
                'missed_days' => 0,
                'remaining_balance' => 0,
                'next_due_date' => now()->addDay()->toDateString(), // Set to tomorrow
            ]);

            // Log the activity
            AdminActivity::log(
                auth()->id(),
                'assign_vendor',
                'stall',
                "Assigned vendor {$vendor->first_name} {$vendor->last_name} to stall {$stall->stall_number}",
                null,
                [
                    'stall_id' => $stall->id,
                    'stall_number' => $stall->stall_number,
                    'vendor_id' => $vendor->id,
                    'vendor_name' => "{$vendor->first_name} {$vendor->last_name}",
                    'rented_id' => $rented->id,
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor assigned to stall successfully',
                'data' => $stall->load(['section', 'currentRental'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign vendor to stall: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove vendor from a stall
     */
    public function removeVendorFromStall(Request $request, Stalls $stall)
    {
        try {
            DB::beginTransaction();

            // Check if stall has a vendor
            if (!$stall->vendor_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stall has no vendor assigned'
                ], 422);
            }

            $vendorName = $stall->vendor ? 
                "{$stall->vendor->first_name} {$stall->vendor->last_name}" : 
                'Unknown vendor';

            // Update stall to remove vendor
            $stall->update([
                'status' => 'vacant',
                'is_active' => false,
            ]);

            // End or update rented record
            $rented = Rented::where('stall_id', $stall->id)
                ->where('vendor_id', $stall->vendor_id)
                ->where('status', 'active')
                ->first();

            if ($rented) {
                $rented->update([
                    'status' => 'ended',
                    'next_due_date' => null,
                ]);
            }

            // Log the activity
            AdminActivity::log(
                auth()->id(),
                'remove_vendor',
                'market_layout',
                "Removed vendor {$vendorName} from stall {$stall->stall_number}",
                null,
                null
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor removed from stall successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove vendor from stall: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendors for multi-stall assignment
     */
    public function getVendorsForAssignment()
    {
        try {
            $vendors = VendorDetails::where('status', 'active')
                ->select('id', 'first_name', 'last_name', 'contact_number')
                ->orderBy('first_name')
                ->get();

            return response()->json([
                'success' => true,
                'vendors' => $vendors
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch vendors: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vacant stalls by section
     */
    public function getVacantStallsBySection($sectionId)
    {
        try {
            $section = Sections::findOrFail($sectionId);
            
            $vacantStalls = Stalls::where('section_id', $sectionId)
                ->where('status', 'vacant')
                ->select('id', 'stall_number', 'size', 'daily_rate', 'monthly_rate')
                ->orderBy('stall_number')
                ->get();

            // Calculate rates using same logic as StallController
            $vacantStallsWithRates = $vacantStalls->map(function ($stall) use ($section) {
                $dailyRate = $this->computeDailyRate($stall, $section);
                $monthlyRate = $this->computeMonthlyRate($stall, $section);
                
                return [
                    'id' => $stall->id,
                    'stall_number' => $stall->stall_number,
                    'daily_rate' => $dailyRate,
                    'monthly_rate' => $monthlyRate,
                    'size' => $stall->size,
                    'section' => $section
                ];
            });

            return response()->json([
                'success' => true,
                'section' => $section,
                'vacant_stalls' => $vacantStallsWithRates
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch vacant stalls: ' . $e->getMessage()
            ], 500);
        }
    }

    private function computeDailyRate($stall, $section)
    {
        // If stall has individual daily rate, use it
        if ($stall->daily_rate) {
            return $stall->daily_rate;
        }
        
        // Fall back to section rates
        if (!$section) {
            return 0;
        }
        
        if ($section->rate_type === 'per_sqm' && $stall->size) {
            return $section->rate * $stall->size;
        }
        
        return $section->daily_rate ?? 0;
    }

    private function computeMonthlyRate($stall, $section)
    {
        // If stall has individual monthly rate, use it
        if ($stall->monthly_rate) {
            return $stall->monthly_rate;
        }
        
        // Fall back to section rates
        if (!$section) {
            return 0;
        }
        
        if ($section->rate_type === 'per_sqm' && $stall->size) {
            $dailyRate = $section->rate * $stall->size;
            return $dailyRate * 31; // Monthly rate = daily rate * 31
        }
        
        return $section->monthly_rate ?? 0;
    }

    /**
     * Multi-stall assignment
     */
    public function multiAssignStalls(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_id' => 'required|exists:vendor_details,id',
                'stall_ids' => 'required|array|min:1',
                'stall_ids.*' => 'exists:stall,id',
                'payment_type' => 'required|in:daily,monthly,both',
                'daily_rate' => 'nullable|numeric|min:0',
                'monthly_rate' => 'nullable|numeric|min:0',
            ]);

            DB::beginTransaction();

            $vendor = VendorDetails::findOrFail($validated['vendor_id']);
            $assignedStalls = [];
            $totalDaily = 0;
            $totalMonthly = 0;

            foreach ($validated['stall_ids'] as $stallId) {
                $stall = Stalls::with('section')->findOrFail($stallId);
                
                if ($stall->status !== 'vacant') {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Stall {$stall->stall_number} is not vacant"
                    ], 422);
                }

                // Calculate rates using same logic as StallController
                $section = $stall->section;
                $dailyRate = $validated['daily_rate'] ?? null;
                $monthlyRate = $validated['monthly_rate'] ?? null;
                
                // If custom rates not provided, use computed rates
                if (!$dailyRate) {
                    $dailyRate = $this->computeDailyRate($stall, $section);
                }
                
                if (!$monthlyRate) {
                    $monthlyRate = $this->computeMonthlyRate($stall, $section);
                }

                // Update stall
                $stall->update([
                  
                    'status' => 'occupied',
                    'is_active' => true,
                ]);

                // Create rental record
                Rented::create([
                    'vendor_id' => $vendor->id,
                    'stall_id' => $stall->id,
                    'daily_rent' => $dailyRate,
                    'monthly_rent' => $monthlyRate,
                    'status' => 'occupied',
                    'next_due_date' => $validated['payment_type'] === 'monthly' 
                        ? date('Y-m-d', strtotime('+1 month'))
                        : date('Y-m-d', strtotime('+1 day')),
                ]);

                $assignedStalls[] = [
                    'stall_number' => $stall->stall_number,
                    'section_name' => $stall->section->name,
                    'daily_rate' => $dailyRate,
                    'monthly_rate' => $monthlyRate,
                ];

                $totalDaily += $dailyRate;
                $totalMonthly += $monthlyRate;
            }

            // Log activity
            AdminActivity::log(
                auth()->id(),
                'multi_assign_stalls',
                'market_layout',
                "Assigned vendor {$vendor->first_name} {$vendor->last_name} to " . count($validated['stall_ids']) . " stalls",
                null,
                [
                    'vendor_id' => $vendor->id,
                    'stall_count' => count($validated['stall_ids']),
                    'stalls' => $assignedStalls
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor assigned to stalls successfully',
                'assigned_stalls' => $assignedStalls,
                'payment_breakdown' => [
                    'total_daily' => $totalDaily,
                    'total_monthly' => $totalMonthly,
                    'payment_type' => $validated['payment_type'],
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign stalls: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sections by area type (Market vs Open Space)
     */
    public function getSectionsByAreaType(Request $request)
    {
        try {
            $areaType = $request->query('area_type'); // 'market' or 'open_space'
            
            if (!$areaType || !in_array($areaType, ['market', 'open_space'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid area type. Must be "market" or "open_space"'
                ], 400);
            }

            // Filter areas based on type
            if ($areaType === 'market') {
                // Get both wet and dry areas for market
                $areas = Area::whereIn('name', ['wet', 'dry'])
                    ->with(['sections' => function($query) {
                        $query->select('id', 'name', 'area_id');
                    }])
                    ->get();
            } else {
                // Get open space areas
                $areas = Area::where('name', 'like', '%Open Space%')
                    ->with(['sections' => function($query) {
                        $query->select('id', 'name', 'area_id');
                    }])
                    ->get();
            }

            $sections = $areas->flatMap(function($area) {
                return $area->sections->map(function($section) use ($area) {
                    return [
                        'id' => $section->id,
                        'name' => $section->name,
                        'area_name' => $area->name,
                        'area_type' => in_array($area->name, ['wet', 'dry']) ? 'market' : 'open_space'
                    ];
                });
            });

            return response()->json([
                'success' => true,
                'sections' => $sections
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch sections: ' . $e->getMessage()
            ], 500);
        }
    }
}
