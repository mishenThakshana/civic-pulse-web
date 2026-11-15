<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Align existing categories with the ten AI issue categories.
     *
     * Only runs on databases that already have categories (an existing
     * deployment): legacy categories are renamed or merged and the missing
     * issue categories are added. A fresh database gets them from
     * CategorySeeder instead.
     */
    public function up(): void
    {
        if (Category::query()->exists()) {
            Category::syncWithIssueCategories();
        }
    }

    /**
     * The merge cannot be undone without losing information, so this is a no-op.
     */
    public function down(): void
    {
        //
    }
};
