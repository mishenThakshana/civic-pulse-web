<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('panel.categories.index', [
            'categories' => Category::withCount('reports')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
        ]);

        Category::create($data);

        return back()->with('status', "\"{$data['name']}\" has been added.");
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name,'.$category->id],
        ]);

        $category->update($data);

        return back()->with('status', "\"{$category->name}\" has been updated.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->reports()->exists()) {
            return back()->withErrors(['category' => "\"{$category->name}\" has reports against it and cannot be deleted."]);
        }

        $name = $category->name;
        $category->delete();

        return back()->with('status', "\"{$name}\" has been deleted.");
    }
}
