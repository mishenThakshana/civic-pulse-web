<?php

use App\Enums\IssueCategory;
use App\Models\Category;
use App\Models\Report;
use Database\Seeders\CategorySeeder;

test('the seeder creates the ten categories the AI service is trained on', function () {
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(10);
    expect(Category::pluck('slug')->sort()->values()->all())
        ->toBe(collect(IssueCategory::cases())->map->value->sort()->values()->all());
    expect(Category::where('slug', 'blocked-drain')->value('name'))->toBe('Blocked drain');
});

test('seeding twice does not duplicate categories', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(10);
});

test('legacy categories are renamed to their issue category and keep their reports', function () {
    $drainage = Category::create(['name' => 'Drainage', 'slug' => 'drainage']);
    $report = Report::factory()->create(['category_id' => $drainage->id]);

    Category::syncWithIssueCategories();

    expect($drainage->refresh()->slug)->toBe('blocked-drain');
    expect($drainage->name)->toBe('Blocked drain');
    expect($report->refresh()->category_id)->toBe($drainage->id);
    expect(Category::count())->toBe(10);
});

test('legacy categories without an issue category are merged into Other', function () {
    $other = Category::create(['name' => 'Other', 'slug' => 'other']);
    $water = Category::create(['name' => 'Water Supply', 'slug' => 'water-supply']);
    $safety = Category::create(['name' => 'Public Safety', 'slug' => 'public-safety']);
    $first = Report::factory()->create(['category_id' => $water->id]);
    $second = Report::factory()->create(['category_id' => $safety->id]);

    Category::syncWithIssueCategories();

    expect(Category::whereIn('slug', ['water-supply', 'public-safety'])->exists())->toBeFalse();
    expect($first->refresh()->category_id)->toBe($other->id);
    expect($second->refresh()->category_id)->toBe($other->id);
});

test('an AI category label maps to its database category', function () {
    $this->seed(CategorySeeder::class);

    $category = IssueCategory::fromAiKey('broken_street_light');

    expect($category)->toBe(IssueCategory::BrokenStreetLight);
    expect($category->aiKey())->toBe('broken_street_light');
    expect(Category::where('slug', $category->value)->value('name'))->toBe('Broken street light');
    expect(IssueCategory::fromAiKey('not_a_category'))->toBeNull();
});
