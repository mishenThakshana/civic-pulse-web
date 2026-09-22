@extends('layouts.panel')

@section('title', 'Reports')
@section('page-label', 'Reports')

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Reports</h1>
    <p class="page-subtitle">Every report, ranked by how many people it affects and how long it has waited.</p>
  </div>
</div>

@if (session('status'))
  <div class="alert alert--success">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    {{ session('status') }}
  </div>
@endif

<div class="table-wrapper">
  <div class="table-header" style="border-bottom:none; padding-bottom:0;">
    <div style="display:flex; gap:2px;">
      <a href="{{ route('reports.index', array_merge(request()->except(['status', 'page']), [])) }}" class="fm-tab {{ ! request('status') ? 'is-active' : '' }}">
        All <span class="fm-tab-count">{{ $allCount }}</span>
      </a>
      @foreach ($statuses as $status)
        <a href="{{ route('reports.index', array_merge(request()->except(['status', 'page']), ['status' => $status->value])) }}" class="fm-tab {{ request('status') === $status->value ? 'is-active' : '' }}">
          {{ $status->label() }}
          <span class="fm-tab-count">
            {{ match($status->value) { 'open' => $openCount, 'in_progress' => $inProgressCount, 'resolved' => $resolvedCount } }}
          </span>
        </a>
      @endforeach
    </div>
  </div>

  <form method="GET" action="{{ route('reports.index') }}" class="list-filter">
    <input type="hidden" name="status" value="{{ request('status') }}" />
    <div class="ff ff--grow">
      <label>Search</label>
      <input type="text" name="search" value="{{ request('search') }}" placeholder="Reference, title, or description…" />
    </div>
    <div class="ff">
      <label>Category</label>
      <select name="category_id">
        <option value="">All categories</option>
        @foreach ($categories as $category)
          <option value="{{ $category->id }}" {{ (string) request('category_id') === (string) $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="ff">
      <label>Department</label>
      <select name="department_id">
        <option value="">All departments</option>
        @foreach ($departments as $department)
          <option value="{{ $department->id }}" {{ (string) request('department_id') === (string) $department->id ? 'selected' : '' }}>{{ $department->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="actions">
      <button type="submit" class="btn-primary">Apply</button>
      @if (request()->anyFilled(['search', 'category_id', 'department_id']))
        <a href="{{ route('reports.index', ['status' => request('status')]) }}" class="btn-ghost btn-ghost--sm">Clear</a>
      @endif
    </div>
  </form>

  <table class="data-table">
    <thead>
      <tr>
        <th>Ref</th>
        <th>Issue</th>
        <th>Category</th>
        <th>Votes</th>
        <th>Department</th>
        <th>Status</th>
        <th>Age</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @forelse ($reports as $report)
        <tr>
          <td style="color:var(--text-muted); font-size:12px;">#{{ $report->id }}</td>
          <td style="font-weight:500; max-width:260px;">{{ $report->title }}</td>
          <td><span class="badge">{{ $report->category?->name ?? 'Uncategorized' }}</span></td>
          <td>
            <span class="vote-pill vote-pill--up">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="M5 12l7-7 7 7"/></svg>
              {{ $report->upvotes_count }}
            </span>
          </td>
          <td style="font-size:12px;">{{ $report->department?->name ?? 'Unassigned' }}</td>
          <td>
            @if ($report->status->value === 'resolved')
              <span class="badge badge--green">{{ $report->status->label() }}</span>
            @elseif ($report->status->value === 'in_progress')
              <span class="badge badge--blue">{{ $report->status->label() }}</span>
            @else
              <span class="badge badge--amber">{{ $report->status->label() }}</span>
            @endif
          </td>
          <td style="font-size:12px; color:var(--text-muted);">{{ $report->created_at->diffForHumans(null, true) }}</td>
          <td><a href="{{ route('reports.show', $report) }}" class="btn-ghost btn-ghost--sm">View</a></td>
        </tr>
      @empty
        <tr>
          <td colspan="8" style="text-align:center; color:var(--text-muted); padding:40px 16px;">No reports match these filters.</td>
        </tr>
      @endforelse
    </tbody>
  </table>

  @if ($reports->hasPages())
    <div class="pagination-bar">
      @if ($reports->onFirstPage())
        <span class="page-btn page-btn--disabled">&lsaquo;</span>
      @else
        <a href="{{ $reports->previousPageUrl() }}" class="page-btn">&lsaquo;</a>
      @endif
      @foreach ($reports->getUrlRange(1, $reports->lastPage()) as $page => $url)
        @if ($page == $reports->currentPage())
          <span class="page-btn page-btn--active">{{ $page }}</span>
        @else
          <a href="{{ $url }}" class="page-btn">{{ $page }}</a>
        @endif
      @endforeach
      @if ($reports->hasMorePages())
        <a href="{{ $reports->nextPageUrl() }}" class="page-btn">&rsaquo;</a>
      @else
        <span class="page-btn page-btn--disabled">&rsaquo;</span>
      @endif
    </div>
  @endif
</div>

@endsection
