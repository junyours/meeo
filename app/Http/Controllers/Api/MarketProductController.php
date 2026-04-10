<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketProduct;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $products = MarketProduct::with('category')->get();
        return response()->json($products);
    }

    /**
     * Store a newly created resource in storage.
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

        $data = $request->except('image');
        $data['available'] = $request->boolean('available', true);
        
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageData = file_get_contents($image->getPathname());
            $mimeType = $image->getMimeType();
            $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            $data['image'] = $base64Image;
        }

        $product = MarketProduct::create($data);
        return response()->json($product, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(MarketProduct $product)
    {
        $product->load('category');
        return response()->json($product);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $product = MarketProduct::findOrFail($id);
        
        \Log::info('Product update request received', [
            'product_id' => $product->id,
            'request_data' => $request->all(),
            'has_file' => $request->hasFile('image'),
            'has_existing_image' => $request->has('existing_image'),
            'existing_image_value' => $request->input('existing_image'),
            'current_product_image' => $product->image
        ]);

        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric|min:0',
            'unit' => 'required|string|max:50',
            'description' => 'nullable|string',
            'available' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,avif,webp|max:2048',
            'existing_image' => 'nullable|string'
        ]);

        $data = $request->except(['image', 'existing_image']);
        
        if ($request->has('available')) {
            $data['available'] = $request->boolean('available');
        }
        
        if ($request->hasFile('image')) {
            \Log::info('Processing new image upload for product');
            
            $image = $request->file('image');
            $imageData = file_get_contents($image->getPathname());
            $mimeType = $image->getMimeType();
            $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            $data['image'] = $base64Image;
            
            \Log::info('New product image stored as base64', ['mimeType' => $mimeType]);
        } elseif ($request->has('existing_image')) {
            // Keep existing image
            $data['image'] = $request->existing_image;
            \Log::info('Keeping existing product image', ['url' => $data['image']]);
        } else {
            \Log::info('No product image provided, image will be removed');
            $data['image'] = null;
        }

        \Log::info('Final data for product update', ['data' => $data]);
        
        $product->update($data);
        
        \Log::info('Product updated successfully', ['updated_product' => $product->fresh()]);
        
        return response()->json($product);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MarketProduct $product)
    {
        $product->delete();
        return response()->json(null, 204);
    }

    /**
     * Get products by category.
     */
    public function getByCategory($categoryId)
    {
        $products = MarketProduct::with('category')
            ->where('category_id', $categoryId)
            ->where('available', true)
            ->get();
        
        return response()->json($products);
    }
}
