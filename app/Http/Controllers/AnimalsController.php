<?php

namespace App\Http\Controllers;

use App\Models\Animals;
use Illuminate\Http\Request;

class AnimalsController extends Controller
{
    public function index()
    {
        return Animals::all();
    }

    // 🔹 Store a new animal
    public function store(Request $request)
    {
        $validated = $request->validate([
            'animal_type'        => 'required|string|max:255',
            'fixed_rate'         => 'nullable|numeric|min:0',
            'ante_mortem_rate'   => 'nullable|numeric|min:0',
            'post_mortem_rate'   => 'nullable|numeric|min:0',
            'coral_fee_rate'     => 'nullable|numeric|min:0',
            'permit_to_slh_rate' => 'nullable|numeric|min:0',
            'slaughter_fee_rate' => 'nullable|numeric|min:0',
            'excess_kilo_limit'  => 'nullable|numeric|min:0',
        ]);

        return Animals::create($validated);
    }

    // 🔹 Show one animal
    public function show($id)
    {
        return Animals::findOrFail($id);
    }

    // 🔹 Update an animal
    public function update(Request $request, $id)
    {
        $animal = Animals::findOrFail($id);

        $validated = $request->validate([
            'animal_type'        => 'sometimes|string|max:255',
            'fixed_rate'         => 'nullable|numeric|min:0',
            'ante_mortem_rate'   => 'nullable|numeric|min:0',
            'post_mortem_rate'   => 'nullable|numeric|min:0',
            'coral_fee_rate'     => 'nullable|numeric|min:0',
            'permit_to_slh_rate' => 'nullable|numeric|min:0',
            'slaughter_fee_rate' => 'nullable|numeric|min:0',
            'excess_kilo_limit'  => 'nullable|numeric|min:0',
        ]);

        $animal->update($validated);
        return $animal;
    }

    // 🔹 Delete an animal
    public function destroy($id)
    {
        $animal = Animals::findOrFail($id);
        $animal->delete();
        return response()->json(['message' => 'Animal deleted successfully']);
    }

}
