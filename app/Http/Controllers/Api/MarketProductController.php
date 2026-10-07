<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketProduct;
use App\Models\MarketProductPriceHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketProductController extends Controller
{
    /**
     * Display a listing of the products.
     */
    public function index()
    {
        $products = MarketProduct::query()
            ->select(
                'id',
                'name',
                'category_id',
                'price',
                'unit',
                'available',
                'image'
            )
            ->with('category:id,name,color')
            ->orderBy('name')
            ->get();

        return response()->json($products);
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric|min:0',
            'unit' => 'required|string|max:50',
            'description' => 'nullable|string',
            'available' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120'
        ]);

        DB::beginTransaction();

        try {
            $data = $request->except('image');

            $data['available'] = $request->boolean('available', true);

            /*
             * Convert uploaded image to Base64.
             */
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                $imageData = file_get_contents($image->getPathname());
                $mimeType = $image->getMimeType();

                $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

                $data['image'] = $base64Image;
            }

            /*
             * Create the product.
             */
            $product = MarketProduct::create($data);

            /*
             * Create the initial price history record.
             *
             * old_price is NULL because this is the
             * first recorded price of the product.
             */
            MarketProductPriceHistory::create([
                'market_product_id' => $product->id,
                'old_price' => null,
                'new_price' => $product->price,
                'change_amount' => null,
                'change_percentage' => null,
                'effective_date' => now(),
            ]);

            DB::commit();

            return response()->json(
                $product->load('category:id,name,color'),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified product.
     */
    public function show($id)
    {
        $product = MarketProduct::with('category:id,name,color')
            ->findOrFail($id);

        return response()->json($product);
    }

    /**
     * Update the specified product.
     *
     * If the price changes, a new price history record
     * will automatically be created.
     */
    public function update(Request $request, $id)
    {
        $product = MarketProduct::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric|min:0',
            'unit' => 'required|string|max:50',
            'description' => 'nullable|string',
            'available' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,avif,webp|max:2048',
            'existing_image' => 'nullable|string',
            'effective_date' => 'nullable|date'
        ]);

        DB::beginTransaction();

        try {
            /*
             * Store the old price before updating.
             */
            $oldPrice = (float) $product->price;
            $newPrice = (float) $request->price;

            /*
             * Determine whether the price actually changed.
             */
            $priceChanged = $oldPrice !== $newPrice;

            $data = $request->except([
                'image',
                'existing_image',
                'effective_date'
            ]);

            /*
             * Handle available field.
             */
            if ($request->has('available')) {
                $data['available'] = $request->boolean('available');
            }

            /*
             * Handle image upload.
             */
            if ($request->hasFile('image')) {
                $image = $request->file('image');

                $imageData = file_get_contents($image->getPathname());
                $mimeType = $image->getMimeType();

                $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

                $data['image'] = $base64Image;
            } elseif ($request->has('existing_image')) {
                /*
                 * Keep the existing image.
                 */
                $data['image'] = $request->existing_image;
            } else {
                /*
                 * Preserve the existing behavior of removing
                 * the image when no image is supplied.
                 */
                $data['image'] = null;
            }

            /*
             * Update the product.
             */
            $product->update($data);

            /*
             * Create a price history record ONLY when
             * the price actually changes.
             */
            if ($priceChanged) {

                $changeAmount = $newPrice - $oldPrice;

                $changePercentage = $oldPrice > 0
                    ? ($changeAmount / $oldPrice) * 100
                    : null;

                MarketProductPriceHistory::create([
                    'market_product_id' => $product->id,
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                    'change_amount' => $changeAmount,
                    'change_percentage' => $changePercentage,
                    'effective_date' => $request->input('effective_date', now()),
                ]);
            }

            DB::commit();

            return response()->json(
                $product->load('category:id,name,color')
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified product.
     */
    public function destroy($id)
    {
        try {
            $product = MarketProduct::findOrFail($id);

            $product->delete();

            return response()->json([
                'message' => 'Product deleted successfully'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            return response()->json([
                'message' => 'Product not found',
                'error' => 'The product with ID ' . $id . ' does not exist'
            ], 404);

        } catch (\Exception $e) {

            return response()->json([
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get products by category.
     */
    public function getByCategory($categoryId)
    {
        $products = MarketProduct::query()
            ->select(
                'id',
                'name',
                'category_id',
                'price',
                'unit',
                'available',
                'image'
            )
            ->with('category:id,name,color')
            ->where('category_id', $categoryId)
            ->orderBy('name')
            ->get();

        return response()->json($products);
    }

    /**
     * Get the complete price history of a product.
     */
    public function priceHistory($id)
    {
        $product = MarketProduct::findOrFail($id);

        $history = MarketProductPriceHistory::query()
            ->where('market_product_id', $product->id)
            ->orderByDesc('effective_date')
            ->get();

        return response()->json([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'current_price' => $product->price,
                'unit' => $product->unit,
            ],
            'history' => $history,
        ]);
    }

    /**
     * Get price history within a custom date range.
     *
     * Example:
     * /products/1/price-history/date-range?start_date=2026-09-01&end_date=2026-09-30
     */
    public function priceHistoryByDateRange(Request $request, $id)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $product = MarketProduct::findOrFail($id);

        $history = MarketProductPriceHistory::query()
            ->where('market_product_id', $product->id)
            ->whereBetween('effective_date', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59',
            ])
            ->orderBy('effective_date')
            ->get();

        return response()->json([
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'current_price' => $product->price,
                'unit' => $product->unit,
            ],
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'history' => $history,
        ]);
    }
}