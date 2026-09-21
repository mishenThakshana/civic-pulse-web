<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Mishen Mendis',
            'email' => 'admin@civicpulse.test',
        ]);

        foreach (['Road damage', 'Drainage', 'Lighting', 'Waste Management', 'Public Safety', 'Water Supply', 'Other'] as $name) {
            Category::firstOrCreate(['name' => $name]);
        }

        foreach ([
            ['name' => 'Roads and Highways', 'contact_email' => 'roads@council.lk'],
            ['name' => 'Drainage', 'contact_email' => 'drainage@council.lk'],
            ['name' => 'Electrical', 'contact_email' => 'electrical@council.lk'],
            ['name' => 'Waste Management', 'contact_email' => 'waste@council.lk'],
            ['name' => 'Parks and Recreation', 'contact_email' => 'parks@council.lk'],
            ['name' => 'Water Board Liaison', 'contact_email' => 'water@council.lk'],
        ] as $department) {
            Department::firstOrCreate(['name' => $department['name']], $department);
        }
    }
}
