<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds the ten issue categories the AI screening service is trained on
     * (App\Enums\IssueCategory). Safe to re-run, including in production:
     * existing categories are matched by slug and left as they are.
     */
    public function run(): void
    {
        Category::syncWithIssueCategories();
    }
}
