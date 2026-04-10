<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MarketProduct;
use Illuminate\Http\Request;

class AvailableProductsController extends Controller
{
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
