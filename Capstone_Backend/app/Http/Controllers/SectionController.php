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
        'rate' => 'nullable|numeric',
        'monthly_rate' => 'nullable|numeric',
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


     public function update(Request $request, $id)
    {
        $request->validate([
        
            'rate_type'   => 'nullable|in:per_sqm,fixed',
            'rate'        => 'nullable|numeric',
            'monthly_rate'=> 'nullable|numeric',
        ]);

        $section = Sections::findOrFail($id);

        $section->update([
       
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
