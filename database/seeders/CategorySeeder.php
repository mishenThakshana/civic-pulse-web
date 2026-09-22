<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Safe to re-run (including in production): existing categories are
     * matched by name and left untouched, so this only ever adds what's missing.
     */
    public function run(): void
    {
        foreach (['Road damage', 'Drainage', 'Lighting', 'Waste Management', 'Public Safety', 'Water Supply', 'Other'] as $name) {
            Category::firstOrCreate(['name' => $name]);
        }
    }
}
