<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Animal;
use App\Models\SlaughterhouseCollection;
use App\Services\SlaughterCollectionCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MobileSlaughterCollectorController extends Controller
{
    public function __construct(
        private readonly SlaughterCollectionCalculator $calculator
    ) {
    }

    public function animals(Request $request)
    {
        $this->authorizeSlaughterCollector($request);

        return response()->json([
            'data' => Animal::query()
                ->where('is_active', true)
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
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeSlaughterCollector($request);

        $clientTransactionId = $request->validate([
            'client_transaction_id' => ['required', 'uuid'],
        ])['client_transaction_id'];
        $collectorId = $request->user()->id;
        $existing = SlaughterhouseCollection::withTrashed()
            ->where('client_transaction_id', $clientTransactionId)
            ->first();

        if ($existing) {
            abort_unless(
                (int) $existing->user_id === (int) $collectorId,
                409,
                'This transaction identifier is already in use.'
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction was already synchronized.',
                'collection' => [
                    'id' => $existing->id,
                    'grand_total' => (float) $existing->grand_total,
                ],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'collection_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'or_number' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.animal_id' => [
                'required',
                'integer',
                Rule::exists('animals', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.weights' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.weights.*' => ['required', 'numeric', 'min:0.001', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach ($request->input('items', []) as $index => $item) {
                if (
                    is_array($item)
                    &&
                    isset($item['quantity'], $item['weights'])
                    && is_array($item['weights'])
                    && count($item['weights']) !== (int) $item['quantity']
                ) {
                    $validator->errors()->add(
                        "items.{$index}.weights",
                        'Enter one weight for every animal in the quantity.'
                    );
                }
            }
        });

        $validated = $validator->validate();

        $animals = Animal::query()
            ->whereIn('id', collect($validated['items'])->pluck('animal_id'))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $collection = DB::transaction(function () use (
            $validated,
            $clientTransactionId,
            $collectorId,
            $animals
        ) {
            $collection = SlaughterhouseCollection::create([
                'client_transaction_id' => $clientTransactionId,
                'collection_date' => $validated['collection_date'],
                'or_number' => trim($validated['or_number']),
                'customer_name' => trim($validated['customer_name']),
                'user_id' => $collectorId,
                'grand_total' => 0,
            ]);

            foreach ($validated['items'] as $item) {
                $animal = $animals->get($item['animal_id']);
                abort_if(!$animal, 422, 'A selected animal is no longer available.');

                $weights = array_map('floatval', $item['weights']);
                $charges = [
                    'animal_id' => $animal->id,
                    'animal_type' => $animal->name,
                    ...$this->calculator->calculate($animal, $weights),
                ];

                $collection->items()->create([
                    ...$charges,
                ]);
            }

            $collection->grand_total = (float) $collection->items()->sum('total_amount');
            $collection->save();

            return $collection;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Slaughterhouse collection created successfully.',
            'collection' => [
                'id' => $collection->id,
                'grand_total' => (float) $collection->grand_total,
            ],
        ], 201);
    }

    private function authorizeSlaughterCollector(Request $request): void
    {
        abort_unless(
            strtolower((string) $request->user()?->role) === 'slaughter_collector',
            403,
            'Slaughter collector access is required.'
        );
    }
}
