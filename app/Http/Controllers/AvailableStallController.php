<?php

namespace App\Http\Controllers;

use App\Models\Sections;
use Illuminate\Http\JsonResponse;

class AvailableStallController extends Controller
{
    public function index(): JsonResponse
    {
        $sections = Sections::query()
            ->select('id', 'name', 'area_id')
            ->with([
                'area:id,name',
                'stalls' => function ($query) {
                    $query->select('id', 'section_id', 'stall_number')
                        ->where('status', 'vacant')
                        ->where('is_active', true)
                        ->orderBy('stall_number');
                },
            ])->get();

        $availableSections = $sections
            ->map(function ($section) {
                return [
                    'section_id' => $section->id,
                    'section_name' => $section->name,
                    'area' => ['name' => $section->area?->name],
                    'available_stalls_count' => $section->stalls->count(),
                    'available_stalls' => $section->stalls->values(),
                ];
            })
            ->filter(fn ($section) => $section['available_stalls_count'] > 0)
            ->values();

        return response()->json([
            'status' => 'success',
            'message' => 'Available stalls grouped by section fetched successfully.',
            'data' => $availableSections,
        ]);
    }
}
