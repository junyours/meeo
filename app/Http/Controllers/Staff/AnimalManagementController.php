<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Animal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnimalManagementController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeStaff($request);

        return Animal::query()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'slaughter_fee_per_kilo',
                'ante_mortem_fee_per_head',
                'post_mortem_fee_per_kilo',
                'coral_fee_per_head',
                'permit_to_slh_fee_per_head',
                'minimum_weight_kg',
                'minimum_weight_fee',
                'hides_fee_per_head',
                'is_active',
            ]);
    }

    public function store(Request $request)
    {
        $this->authorizeStaff($request);

        $validated = $request->validate($this->rules());
        $animal = Animal::create($this->attributes($validated));

        return response()->json([
            'message' => 'Animal added successfully.',
            'animal' => $animal,
        ], 201);
    }

    public function update(Request $request, int $animalId)
    {
        $this->authorizeStaff($request);
        $animal = Animal::query()->findOrFail($animalId);
        $validated = $request->validate($this->rules($animal));

        $animal->update($this->attributes($validated, $animal));

        return response()->json([
            'message' => 'Animal updated successfully.',
            'animal' => $animal->refresh(),
        ]);
    }

    public function destroy(Request $request, int $animalId)
    {
        $this->authorizeStaff($request);
        $animal = Animal::query()->findOrFail($animalId);
        $animal->delete();

        return response()->json([
            'message' => 'Animal removed from the catalog.',
        ]);
    }

    private function rules(?Animal $animal = null): array
    {
        $feeFields = [
            'slaughter_fee_per_kilo',
            'ante_mortem_fee_per_head',
            'post_mortem_fee_per_kilo',
            'coral_fee_per_head',
            'permit_to_slh_fee_per_head',
            'minimum_weight_kg',
            'minimum_weight_fee',
            'hides_fee_per_head',
        ];

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('animals', 'name')->ignore($animal?->id),
            ],
            ...array_fill_keys($feeFields, ['sometimes', 'numeric', 'min:0']),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function attributes(array $validated, ?Animal $animal = null): array
    {
        $feeFields = [
            'slaughter_fee_per_kilo',
            'ante_mortem_fee_per_head',
            'post_mortem_fee_per_kilo',
            'coral_fee_per_head',
            'permit_to_slh_fee_per_head',
            'minimum_weight_kg',
            'minimum_weight_fee',
            'hides_fee_per_head',
        ];

        $attributes = [
            'name' => trim($validated['name']),
        ];

        foreach ($feeFields as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            } elseif ($animal === null) {
                $attributes[$field] = 0;
            }
        }

        if (array_key_exists('is_active', $validated)) {
            $attributes['is_active'] = $validated['is_active'];
        } elseif ($animal === null) {
            $attributes['is_active'] = true;
        }

        return $attributes;
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->role === 'staff', 403, 'Staff access is required.');
    }
}
