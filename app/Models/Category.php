<?php

namespace App\Models;

use App\Enums\IssueCategory;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            if (! $category->slug) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * The issue category this row represents, if it is one of the AI categories.
     */
    public function issueCategory(): ?IssueCategory
    {
        return IssueCategory::tryFrom($this->slug);
    }

    /**
     * Make the table hold the ten AI issue categories. Legacy categories are
     * renamed to their issue category, or merged into it when it already
     * exists, so every report keeps a category. Safe to run more than once.
     */
    public static function syncWithIssueCategories(): void
    {
        DB::transaction(function (): void {
            foreach (IssueCategory::legacy() as $legacySlug => $target) {
                $legacy = static::where('slug', $legacySlug)->first();

                if (! $legacy) {
                    continue;
                }

                $existing = static::where('slug', $target->value)->first();

                if ($existing) {
                    Report::where('category_id', $legacy->id)->update(['category_id' => $existing->id]);
                    $legacy->delete();
                } else {
                    $legacy->update(['name' => $target->label(), 'slug' => $target->value]);
                }
            }

            foreach (IssueCategory::cases() as $category) {
                static::firstOrCreate(['slug' => $category->value], ['name' => $category->label()]);
            }
        });
    }
}
