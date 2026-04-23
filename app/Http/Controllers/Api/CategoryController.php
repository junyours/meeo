<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Generate full URL for storage images
     */
    private function getStorageUrl($path)
    {
        return asset('storage/' . $path);
    }

    /**
     * Get base64 encoded image for fallback
     */
    private function getBase64Image($path)
    {
        $fullPath = storage_path('app/public/' . $path);
        if (file_exists($fullPath)) {
            $imageData = file_get_contents($fullPath);
            $mimeType = mime_content_type($fullPath);
            return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
        }
        return null;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categories = Category::with('products')->get();
        return response()->json($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string',
            'icon' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120'
        ]);

        $data = $request->except('image');
        
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageData = file_get_contents($image->getPathname());
            $mimeType = $image->getMimeType();
            $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            $data['image'] = $base64Image;
        }

        $category = Category::create($data);
        return response()->json($category, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        $category->load('products');
        return response()->json($category);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string',
            'icon' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,avif,webp|max:2048',
            'existing_image' => 'nullable|string'
        ]);

        $data = $request->except(['image', 'existing_image']);
        
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageData = file_get_contents($image->getPathname());
            $mimeType = $image->getMimeType();
            $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            $data['image'] = $base64Image;
        } elseif ($request->has('existing_image')) {
            // Keep existing image
            $data['image'] = $request->existing_image;
        } else {
            $data['image'] = null;
        }

        $category->update($data);
        
        return response()->json($category);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $category = Category::findOrFail($id);
        $category->delete();
        return response()->json(['message' => 'Category deleted successfully'], 200);
    }
}
