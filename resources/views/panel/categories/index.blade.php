@extends('layouts.panel')

@section('title', 'Categories')
@section('page-label', 'Categories')

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Categories</h1>
    <p class="page-subtitle">The issue types citizens can report against, and how many reports each one has.</p>
  </div>
  <button type="button" class="btn-primary" data-modal-open="addModal">Add category</button>
</div>

@if (session('status'))
  <div class="alert alert--success">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    {{ session('status') }}
  </div>
@endif

@if ($errors->any())
  <div class="alert alert--error">{{ $errors->first() }}</div>
@endif

<div class="table-wrapper">
  <div class="table-header">
    <span class="table-title">All Categories <span class="table-count">({{ $categories->count() }})</span></span>
  </div>

  <table class="data-table">
    <thead>
      <tr><th>Name</th><th>Slug</th><th>Reports</th><th></th></tr>
    </thead>
    <tbody>
      @forelse ($categories as $category)
        <tr>
          <td style="font-weight:500;">{{ $category->name }}</td>
          <td style="color:var(--text-muted); font-size:12px;">{{ $category->slug }}</td>
          <td>{{ $category->reports_count }}</td>
          <td>
            <div style="display:flex; align-items:center; gap:8px;">
              <button type="button" class="btn-ghost btn-ghost--sm" data-modal-open="editModal{{ $category->id }}">Edit</button>
              <form method="POST" action="{{ route('categories.destroy', $category) }}" onsubmit="return confirm('Delete the \'{{ addslashes($category->name) }}\' category?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-ghost btn-ghost--sm">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:40px 16px;">No categories yet.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

{{-- Add Modal --}}
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Add category</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('categories.store') }}">
      @csrf
      <div class="modal-body">
        <div class="field">
          <label for="name">Name</label>
          <input type="text" id="name" name="name" placeholder="e.g. Road damage" required />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn-primary">Add category</button>
      </div>
    </form>
  </div>
</div>

{{-- Edit Modals --}}
@foreach ($categories as $category)
<div class="modal-overlay" id="editModal{{ $category->id }}">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Edit {{ $category->name }}</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('categories.update', $category) }}">
      @csrf
      @method('PATCH')
      <div class="modal-body">
        <div class="field">
          <label for="name{{ $category->id }}">Name</label>
          <input type="text" id="name{{ $category->id }}" name="name" value="{{ $category->name }}" required />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn-primary">Save changes</button>
      </div>
    </form>
  </div>
</div>
@endforeach

@endsection
