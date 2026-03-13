<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Department;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('departments')->delete();

        $departments = [
            [
                'name' => 'Market',
                'code' => 'MARKET',
                'description' => 'Market stall rentals and collections',
                'is_active' => true,
            ],
            [
                'name' => 'Wharf',
                'code' => 'WHARF',
                'description' => 'Wharf fees and collections',
                'is_active' => true,
            ],
            [
                'name' => 'Slaughter',
                'code' => 'SLAUGHTER',
                'description' => 'Slaughterhouse fees and inspections',
                'is_active' => true,
            ],
            [
                'name' => 'Motor Pool',
                'code' => 'MOTORPOOL',
                'description' => 'Motor pool vehicle fees and collections',
                'is_active' => true,
            ],
        ];

        foreach ($departments as $department) {
            Department::create($department);
        }

        $this->command->info('Default departments seeded successfully.');
    }
}
