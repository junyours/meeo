<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MarketProduct;
use App\Models\MarketProductPriceHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AvailableProductsController extends Controller
{
    /**
     * Get the public catalog with only fields used by the market landing page.
     */
    public function getCatalog()
    {
        $categories = Category::query()
            ->select('id', 'name', 'description', 'color', 'icon', 'image')
            ->with(['products' => function ($query) {
                $query->select('id', 'category_id', 'name', 'price', 'unit', 'available', 'image')
                    ->where('available', true);
            }])
            ->get()
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'color' => $category->color,
                'icon' => $category->icon,
                'image' => $category->image,
                'products' => $category->products->map(fn ($product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'unit' => $product->unit,
                    'available' => $product->available,
                    'image' => $product->image,
                ])->values(),
            ]);

        return response()->json($categories);
    }

    /**
     * Get available products with price changes for a calendar period.
     */
    public function getPriceHistory(Request $request)
    {
        $validated = $request->validate([
            'period' => 'required|in:day,week,month,year',
        ]);

        $period = $validated['period'];
        $now = Carbon::now();

        switch ($period) {
            case 'day':
                $currentStart = $now->copy()->startOfDay();
                $currentEnd = $now->copy()->endOfDay();
                $previousStart = $currentStart->copy()->subDay();
                break;
            case 'week':
                $currentStart = $now->copy()->startOfWeek();
                $currentEnd = $now->copy()->endOfWeek();
                $previousStart = $currentStart->copy()->subWeek();
                break;
            case 'month':
                $currentStart = $now->copy()->startOfMonth();
                $currentEnd = $now->copy()->endOfMonth();
                $previousStart = $currentStart->copy()->subMonth();
                break;
            default:
                $currentStart = $now->copy()->startOfYear();
                $currentEnd = $now->copy()->endOfYear();
                $previousStart = $currentStart->copy()->subYear();
                break;
        }

        $previousEnd = $currentStart->copy()->subSecond();

        $priceSnapshotAt = function (Carbon $date) {
            return MarketProductPriceHistory::query()
                ->select('new_price')
                ->whereColumn('market_product_id', 'market_products.id')
                ->where('effective_date', '<=', $date)
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->limit(1);
        };

        $hasPriceHistory = MarketProductPriceHistory::query()
            ->selectRaw('1')
            ->whereColumn('market_product_id', 'market_products.id')
            ->limit(1);

        $products = MarketProduct::query()
            ->select('id', 'category_id', 'name', 'description', 'price', 'unit')
            ->selectSub($priceSnapshotAt($previousEnd), 'past_price')
            ->selectSub($priceSnapshotAt($currentEnd), 'present_price')
            ->selectSub($hasPriceHistory, 'has_price_history')
            ->with('category:id,name')
            ->where('available', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'period' => $period,
            'previous_start_date' => $previousStart->toDateString(),
            'previous_end_date' => $previousEnd->toDateString(),
            'current_start_date' => $currentStart->toDateString(),
            'current_end_date' => $currentEnd->toDateString(),
            'products' => $products->map(function ($product) {
                $hasNoHistory = !(bool) $product->has_price_history;
                $pastPrice = $product->past_price ?? ($hasNoHistory ? $product->price : null);
                $presentPrice = $product->present_price ?? ($hasNoHistory ? $product->price : null);

                return [
                    'id' => $product->id,
                    'category_name' => $product->category?->name ?? 'Uncategorized',
                    'name' => $product->name,
                    'description' => $product->description,
                    'unit' => $product->unit,
                    'past_price' => $pastPrice,
                    'present_price' => $presentPrice,
                    'remarks' => match (true) {
                        $pastPrice === null || $presentPrice === null => 'no_data',
                        (float) $presentPrice > (float) $pastPrice => 'increased',
                        (float) $presentPrice < (float) $pastPrice => 'decreased',
                        default => 'stable',
                    },
                ];
            })->values(),
        ]);
    }

    /**
     * Get all categories for public view
     */
    public function getCategories()
    {
        $categories = Category::with('products')->get();
        return response()->json($categories);
    }

    /**
     * Get all available products for public view
     */
    public function getAvailableProducts()
    {
        $products = MarketProduct::with('category')
            ->where('available', true)
            ->get();
            
        return response()->json($products);
    }

    /**
     * Get all products for public view (including unavailable)
     */
    public function getAllProducts()
    {
        $products = MarketProduct::with('category')->get();
        return response()->json($products);
    }

    /**
     * Get products by category for public view
     */
    public function getProductsByCategory($categoryId)
    {
        $category = Category::with(['products' => function($query) {
            $query->where('available', true);
        }])->find($categoryId);
        
        if (!$category) {
            return response()->json(['message' => 'Category not found'], 404);
        }
        
        return response()->json($category);
    }

    /**
     * Get single product for public view
     */
    public function getProduct($id)
    {
        $product = MarketProduct::with('category')->find($id);
        
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }
        
        return response()->json($product);
    }
}
