<?php

namespace App\Http\Controllers;

use App\Models\Sections;
use Illuminate\Http\Request;

class SectionController extends Controller
{
  public function index()
{
    $sections = Sections::with(['stalls' => function ($query) {
        $query->select('id', 'section_id', 'stall_number', 'size', 'status');
    }])->get();

    $sections = $sections->map(function ($section) {
        return [
            'id' => $section->id,
            'name' => $section->name,
            'rate_type' => $section->rate_type,
            'rate' => $section->rate, // for 'per_sqm'
                'monthly_rate' => $section->monthly_rate, // use DB value directly
        'daily_rate' => $section->daily_rate, 
            'stalls' => $section->stalls
        ];
    });

    return response()->json([
        'status' => 'success',
        'message' => 'Sections with stalls fetched successfully.',
        'data' => $sections
    ], 200);
}


  public function store(Request $request)
{
    $validated = $request->validate([
        'name' => 'required|string',
        'area_id' => 'required|exists:areas,id',
        'rate_type' => 'required|in:per_sqm,fixed',
        'rights_type' => 'required|in:space_right,stall_right',
        'rate' => 'nullable|numeric',
        'monthly_rate' => 'nullable|numeric',
        'space_right' => 'required_if:rights_type,space_right|numeric|min:0',
        'stall_right' => 'required_if:rights_type,stall_right|numeric|min:0',
        'column_index' => 'required|integer|min:0',
        'row_index' => 'required|integer|min:0',
    ]);

    if ($validated['rate_type'] === 'fixed') {
        $monthly = $validated['monthly_rate'] ?? 0;
        $validated['daily_rate'] = round($monthly / 30, 2);
    } else {
        $validated['monthly_rate'] = null;
        $validated['daily_rate'] = null;
    }

    $section = Sections::create($validated);

    return response()->json([
        'status' => 'success',
        'message' => 'Section created.',
        'data' => $section
    ], 201);
}

 
    public function destroy($id)
    {
        $section = Sections::findOrFail($id);
        $section->delete();
        return response()->json(['message' => 'Section deleted']);
    }


     public function availableStalls()
    {
        $sections = Sections::query()
            ->select('id', 'name', 'area_id')
            ->with('area:id,name')
            ->withCount([
                'stalls as available_stalls_count' => fn ($query) => $query->where('status', 'vacant'),
                'stalls as occupied_stalls_count' => fn ($query) => $query->where('status', 'occupied'),
                'stalls as total_stalls',
            ])
            ->get()
            ->map(fn ($section) => [
                'id' => $section->id,
                'name' => $section->name,
                'available_stalls_count' => $section->available_stalls_count,
                'occupied_stalls_count' => $section->occupied_stalls_count,
                'total_stalls' => $section->total_stalls,
                'area' => ['name' => $section->area?->name],
            ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Available and occupied stalls fetched successfully.',
            'data' => $sections
        ], 200);
    }   

        public function marketFees()
        {
            $sections = Sections::query()
                ->select('id', 'name', 'area_id', 'rate_type', 'rate', 'daily_rate', 'monthly_rate')
                ->with(['stalls' => function ($query) {
                $query->select('id', 'section_id', 'stall_number', 'size', 'daily_rate', 'monthly_rate')
                    ->where('is_active', true);
            }, 'area' => function ($query) {
                $query->select('id', 'name');
            }])->get();

            $data = $sections->map(function ($section) {
                $stalls = $section->stalls->map(function ($stall) use ($section) {
                    $dailyRate = $stall->daily_rate;
                    $monthlyRate = $stall->monthly_rate;

                    if ($dailyRate === null && $monthlyRate !== null) {
                        $dailyRate = $monthlyRate / 30;
                    }

                    if ($section->rate_type === 'per_sqm' && $section->rate !== null) {
                        $dailyRate = $dailyRate ?? ($section->rate * ($stall->size ?? 0));
                        $monthlyRate = $monthlyRate ?? ($dailyRate * 30);
                    } else {
                        $dailyRate = $dailyRate ?? $section->daily_rate;
                        $monthlyRate = $monthlyRate ?? $section->monthly_rate;
                    }

                    if ($monthlyRate === null && $dailyRate !== null) {
                        $monthlyRate = $dailyRate * 30;
                    }

                    return [
                        'id' => $stall->id,
                        'stall_number' => $stall->stall_number,
                        'daily_rate' => $dailyRate !== null ? round($dailyRate, 2) : null,
                        'monthly_rate' => $monthlyRate !== null ? round($monthlyRate, 2) : null,
                    ];
                })->values();

                return [
                    'id' => $section->id,
                    'name' => $section->name,
                    'rate_type' => $section->rate_type,
                    'area' => ['name' => $section->area?->name],
                    'stalls' => $stalls,
                ];
            })->values();

            return response()->json([
                'status' => 'success',
                'message' => 'Current market rental fees fetched successfully.',
                'data' => $data,
            ], 200);
        }

    public function update(Request $request, $id)
    {
        $request->validate([
        'name'        => 'nullable|string',
             'rate_type'   => 'nullable|in:per_sqm,fixed',
            'rate'        => 'nullable|numeric',
            'monthly_rate'=> 'nullable|numeric',
        ]);

        $section = Sections::findOrFail($id);

        $section->update([
             'name'   => $request->name,
            'rate_type'   => $request->rate_type,
            'rate'        => $request->rate_type === "per_sqm" ? $request->rate : null,
            'monthly_rate'=> $request->rate_type === "fixed" ? $request->monthly_rate : null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $section->load('stalls') // return stalls too for frontend sync
        ]);
    }
}
