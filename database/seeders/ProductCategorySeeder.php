<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\MarketProduct;

class ProductCategorySeeder extends Seeder
{
    public function run(): void
    {
        // Create categories
        $categories = [
            [
                'name' => 'Fresh Vegetables',
                'description' => 'Farm-fresh vegetables sourced directly from local farms',
                'color' => '#52c41a',
                'icon' => 'FaCarrot',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Fresh Seafood',
                'description' => 'Daily catch from local waters, delivered fresh',
                'color' => '#1890ff',
                'icon' => 'FaFish',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Premium Poultry',
                'description' => 'High-quality poultry products from trusted suppliers',
                'color' => '#faad14',
                'icon' => 'FaDrumstickBite',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Quality Pork',
                'description' => 'Premium cuts of pork from inspected sources',
                'color' => '#f5222d',
                'icon' => 'FaBacon',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Seasonal Fruits',
                'description' => 'Fresh, seasonal fruits from local orchards',
                'color' => '#eb2f96',
                'icon' => 'FaAppleAlt',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Local Delicacies',
                'description' => 'Traditional Filipino snacks and street food favorites',
                'color' => '#722ed1',
                'icon' => 'FaCookie',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Local Souvenirs',
                'description' => 'Handcrafted souvenirs and local products',
                'color' => '#13c2c2',
                'icon' => 'FaGift',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        $createdCategories = Category::insert($categories);
        
        // Get category IDs
        $vegetables = Category::where('name', 'Fresh Vegetables')->first();
        $seafood = Category::where('name', 'Fresh Seafood')->first();
        $poultry = Category::where('name', 'Premium Poultry')->first();
        $pork = Category::where('name', 'Quality Pork')->first();
        $fruits = Category::where('name', 'Seasonal Fruits')->first();
        $delicacies = Category::where('name', 'Local Delicacies')->first();
        $souvenirs = Category::where('name', 'Local Souvenirs')->first();

        // Create products
        $products = [
            // Vegetables
            ['name' => 'Premium Tomatoes', 'price' => 45, 'unit' => 'kg', 'category_id' => $vegetables->id, 'available' => true],
            ['name' => 'Red Onions', 'price' => 65, 'unit' => 'kg', 'category_id' => $vegetables->id, 'available' => true],
            ['name' => 'Organic Cabbage', 'price' => 40, 'unit' => 'kg', 'category_id' => $vegetables->id, 'available' => true],
            ['name' => 'Baby Carrots', 'price' => 55, 'unit' => 'kg', 'category_id' => $vegetables->id, 'available' => true],
            ['name' => 'Russet Potatoes', 'price' => 50, 'unit' => 'kg', 'category_id' => $vegetables->id, 'available' => true],
            ['name' => 'Bell Peppers', 'price' => 85, 'unit' => 'kg', 'category_id' => $vegetables->id, 'available' => true],
            ['name' => 'Fresh Spinach', 'price' => 30, 'unit' => 'bunch', 'category_id' => $vegetables->id, 'available' => true],
            ['name' => 'Asian Eggplant', 'price' => 60, 'unit' => 'kg', 'category_id' => $vegetables->id, 'available' => true],

            // Seafood
            ['name' => 'Bangus', 'price' => 130, 'unit' => 'kg', 'category_id' => $seafood->id, 'available' => true],
            ['name' => 'Galunggong', 'price' => 110, 'unit' => 'kg', 'category_id' => $seafood->id, 'available' => true],
            ['name' => 'Fresh Tilapia', 'price' => 95, 'unit' => 'kg', 'category_id' => $seafood->id, 'available' => true],
            ['name' => 'Tamarong', 'price' => 280, 'unit' => 'kg', 'category_id' => $seafood->id, 'available' => true],
            ['name' => 'Shrimp', 'price' => 320, 'unit' => 'kg', 'category_id' => $seafood->id, 'available' => true],
            ['name' => 'Tamban', 'price' => 220, 'unit' => 'kg', 'category_id' => $seafood->id, 'available' => true],
            ['name' => 'Blue Crabs', 'price' => 380, 'unit' => 'kg', 'category_id' => $seafood->id, 'available' => true],
            ['name' => 'Green Mussels', 'price' => 90, 'unit' => 'kg', 'category_id' => $seafood->id, 'available' => true],

            // Poultry
            ['name' => 'Whole Free-Range Chicken', 'price' => 165, 'unit' => 'kg', 'category_id' => $poultry->id, 'available' => true],
            ['name' => 'Chicken Breast', 'price' => 195, 'unit' => 'kg', 'category_id' => $poultry->id, 'available' => true],
            ['name' => 'Chicken Thighs', 'price' => 155, 'unit' => 'kg', 'category_id' => $poultry->id, 'available' => true],
            ['name' => 'Chicken Wings', 'price' => 175, 'unit' => 'kg', 'category_id' => $poultry->id, 'available' => true],
            ['name' => 'Farm Eggs', 'price' => 8, 'unit' => 'dozen', 'category_id' => $poultry->id, 'available' => true],
            ['name' => 'Chicken Liver', 'price' => 90, 'unit' => 'kg', 'category_id' => $poultry->id, 'available' => true],
            ['name' => 'Chicken Feet', 'price' => 80, 'unit' => 'kg', 'category_id' => $poultry->id, 'available' => true],

            // Pork
            ['name' => 'Pork Belly', 'price' => 240, 'unit' => 'kg', 'category_id' => $pork->id, 'available' => true],
            ['name' => 'Pork Loin', 'price' => 300, 'unit' => 'kg', 'category_id' => $pork->id, 'available' => true],
            ['name' => 'Pork Ribs', 'price' => 280, 'unit' => 'kg', 'category_id' => $pork->id, 'available' => true],
            ['name' => 'Ground Pork', 'price' => 220, 'unit' => 'kg', 'category_id' => $pork->id, 'available' => true],
            ['name' => 'Pork Chops', 'price' => 260, 'unit' => 'kg', 'category_id' => $pork->id, 'available' => true],
            ['name' => 'Pork Shoulder', 'price' => 195, 'unit' => 'kg', 'category_id' => $pork->id, 'available' => true],
            ['name' => 'Pork Liver', 'price' => 135, 'unit' => 'kg', 'category_id' => $pork->id, 'available' => true],

            // Fruits
            ['name' => 'Lakatan Bananas', 'price' => 55, 'unit' => 'bunch', 'category_id' => $fruits->id, 'available' => true],
            ['name' => 'Red Apples', 'price' => 135, 'unit' => 'kg', 'category_id' => $fruits->id, 'available' => true],
            ['name' => 'Navel Oranges', 'price' => 90, 'unit' => 'kg', 'category_id' => $fruits->id, 'available' => true],
            ['name' => 'Carabao Mangoes', 'price' => 120, 'unit' => 'kg', 'category_id' => $fruits->id, 'available' => true],
            ['name' => 'Pineapples', 'price' => 70, 'unit' => 'pc', 'category_id' => $fruits->id, 'available' => true],
            ['name' => 'Papaya', 'price' => 45, 'unit' => 'pc', 'category_id' => $fruits->id, 'available' => true],
            ['name' => 'Watermelon', 'price' => 35, 'unit' => 'kg', 'category_id' => $fruits->id, 'available' => true],
            ['name' => 'Grapes', 'price' => 165, 'unit' => 'kg', 'category_id' => $fruits->id, 'available' => true],

            // Delicacies
            ['name' => 'Sweet Banana Cue', 'price' => 18, 'unit' => 'pc', 'category_id' => $delicacies->id, 'available' => true],
            ['name' => 'Camote Cue', 'price' => 15, 'unit' => 'pc', 'category_id' => $delicacies->id, 'available' => true],
            ['name' => 'Fish Balls', 'price' => 6, 'unit' => 'pc', 'category_id' => $delicacies->id, 'available' => true],
            ['name' => 'Kikiam', 'price' => 10, 'unit' => 'pc', 'category_id' => $delicacies->id, 'available' => true],
            ['name' => 'Squid Balls', 'price' => 12, 'unit' => 'pc', 'category_id' => $delicacies->id, 'available' => true],
            ['name' => 'Bibingka', 'price' => 40, 'unit' => 'pc', 'category_id' => $delicacies->id, 'available' => true],
            ['name' => 'Puto', 'price' => 10, 'unit' => 'pc', 'category_id' => $delicacies->id, 'available' => true],
            ['name' => 'Kakanin', 'price' => 30, 'unit' => 'pc', 'category_id' => $delicacies->id, 'available' => true],

            // Souvenirs
            ['name' => 'Custom Keychains', 'price' => 45, 'unit' => 'pc', 'category_id' => $souvenirs->id, 'available' => true],
            ['name' => 'Local T-Shirts', 'price' => 220, 'unit' => 'pc', 'category_id' => $souvenirs->id, 'available' => true],
            ['name' => 'Ceramic Mugs', 'price' => 135, 'unit' => 'pc', 'category_id' => $souvenirs->id, 'available' => true],
            ['name' => 'Woven Bags', 'price' => 165, 'unit' => 'pc', 'category_id' => $souvenirs->id, 'available' => true],
            ['name' => 'Local Hats', 'price' => 95, 'unit' => 'pc', 'category_id' => $souvenirs->id, 'available' => true],
            ['name' => 'Wooden Crafts', 'price' => 275, 'unit' => 'pc', 'category_id' => $souvenirs->id, 'available' => true],
            ['name' => 'Postcards', 'price' => 20, 'unit' => 'pc', 'category_id' => $souvenirs->id, 'available' => true],
            ['name' => 'Ref Magnets', 'price' => 50, 'unit' => 'pc', 'category_id' => $souvenirs->id, 'available' => true],
        ];

        foreach ($products as $product) {
            $product['created_at'] = now();
            $product['updated_at'] = now();
        }

        MarketProduct::insert($products);
    }
}
