<?php

use App\Models\Category;
use App\Models\Report;
use App\Models\User;

test('admin can view the categories list with report counts', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create(['name' => 'Road damage']);
    Report::factory()->create(['category_id' => $category->id]);

    $this->actingAs($admin)->get('/categories')
        ->assertOk()
        ->assertSee('Road damage')
        ->assertSee('road-damage');
});

test('admin can add a category and its slug is generated automatically', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/categories', ['name' => 'Water Supply'])->assertRedirect();

    $category = Category::where('name', 'Water Supply')->first();
    expect($category)->not->toBeNull();
    expect($category->slug)->toBe('water-supply');
});

test('a category name must be unique', function () {
    $admin = User::factory()->admin()->create();
    Category::factory()->create(['name' => 'Drainage']);

    $this->actingAs($admin)->post('/categories', ['name' => 'Drainage'])->assertSessionHasErrors('name');
});

test('admin can rename a category', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create(['name' => 'Lighting']);

    $this->actingAs($admin)->patch("/categories/{$category->id}", ['name' => 'Street Lighting'])->assertRedirect();

    expect($category->refresh()->name)->toBe('Street Lighting');
});

test('a category with reports against it cannot be deleted', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    Report::factory()->create(['category_id' => $category->id]);

    $this->actingAs($admin)->delete("/categories/{$category->id}")->assertSessionHasErrors('category');

    expect(Category::find($category->id))->not->toBeNull();
});

test('an unused category can be deleted', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();

    $this->actingAs($admin)->delete("/categories/{$category->id}")->assertRedirect();

    expect(Category::find($category->id))->toBeNull();
});
