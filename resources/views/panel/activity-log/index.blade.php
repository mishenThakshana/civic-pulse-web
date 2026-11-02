@extends('layouts.panel')

@section('title', 'Activity Log')
@section('page-label', 'Activity Log')

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Activity Log</h1>
    <p class="page-subtitle">An audit trail of every action taken in the panel, and who took it.</p>
  </div>
</div>

@if ($errors->any())
  <div class="alert alert--error">{{ $errors->first() }}</div>
@endif

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">Actions Today</div>
    <div class="stat-value" style="color:var(--accent);">{{ $todayCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Last 7 Days</div>
    <div class="stat-value" style="color:var(--accent);">{{ $weekCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Active Accounts (7d)</div>
    <div class="stat-value" style="color:var(--accent);">{{ $activeStaffCount }}</div>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-header">
    <span class="table-title">Entries <span class="table-count">({{ $entries->total() }})</span></span>
  </div>

  <form method="GET" action="{{ route('activity-log.index') }}" class="list-filter">
    <div class="ff ff--grow">
      <label>Search</label>
      <input type="text" name="search" value="{{ request('search') }}" placeholder="Search descriptions…" />
    </div>
    <div class="ff">
      <label>Account</label>
      <select name="user_id">
        <option value="">Everyone</option>
        @foreach ($staffMembers as $member)
          <option value="{{ $member->id }}" {{ (string) request('user_id') === (string) $member->id ? 'selected' : '' }}>{{ $member->name }}</option>
        @endforeach
      </select>
    </div>
    <div class="ff">
      <label>Action</label>
      <select name="action">
        <option value="">All actions</option>
        @foreach ($actions as $action)
          <option value="{{ $action->value }}" {{ request('action') === $action->value ? 'selected' : '' }}>{{ $action->label() }}</option>
        @endforeach
      </select>
    </div>
    <div class="ff">
      <label>From</label>
      <input type="date" name="from" value="{{ request('from') }}" />
    </div>
    <div class="ff">
      <label>To</label>
      <input type="date" name="to" value="{{ request('to') }}" />
    </div>
    <div class="actions">
      <button type="submit" class="btn-primary">Apply</button>
      @if (request()->anyFilled(['search', 'user_id', 'action', 'from', 'to']))
        <a href="{{ route('activity-log.index') }}" class="btn-ghost btn-ghost--sm">Clear</a>
      @endif
    </div>
  </form>

  <table class="data-table">
    <thead>
      <tr><th>When</th><th>Account</th><th>Action</th><th>Details</th><th>IP Address</th></tr>
    </thead>
    <tbody>
      @forelse ($entries as $entry)
        <tr>
          <td style="font-size:12px; white-space:nowrap;" title="{{ $entry->created_at->format('d M Y, h:i:s A') }}">
            {{ $entry->created_at->format('d M Y, h:i A') }}
            <div style="color:var(--text-muted);">{{ $entry->created_at->diffForHumans() }}</div>
          </td>
          <td style="font-weight:500;">
            @if ($entry->user)
              {{ $entry->user->name }}
              <div style="font-size:11px; font-weight:400; color:var(--text-muted);">{{ $entry->user->role->label() }}</div>
            @else
              <span style="color:var(--text-muted);">Deleted account</span>
            @endif
          </td>
          <td><span class="badge {{ $entry->action->badge() ? 'badge--'.$entry->action->badge() : '' }}">{{ $entry->action->label() }}</span></td>
          <td style="font-size:13px;">
            @if ($entry->subject_type === (new \App\Models\Report)->getMorphClass() && $entry->subject_id)
              <a href="{{ route('reports.show', $entry->subject_id) }}" style="color:var(--text); text-decoration:none;">{{ $entry->description }}</a>
            @else
              {{ $entry->description }}
            @endif
            @if (! empty($entry->properties['reason']))
              <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Reason: {{ $entry->properties['reason'] }}</div>
            @endif
            @if (! empty($entry->properties['changed']))
              <div style="font-size:12px; color:var(--text-muted); margin-top:2px;">Changed: {{ implode(', ', array_map(fn ($field) => str_replace('_', ' ', $field), $entry->properties['changed'])) }}</div>
            @endif
          </td>
          <td style="font-size:12px; color:var(--text-muted);">{{ $entry->ip_address ?? '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="5" style="text-align:center; color:var(--text-muted); padding:40px 16px;">No activity matches these filters.</td></tr>
      @endforelse
    </tbody>
  </table>

  @if ($entries->hasPages())
    <div class="pagination-bar">
      @if ($entries->onFirstPage())
        <span class="page-btn page-btn--disabled">&lsaquo;</span>
      @else
        <a href="{{ $entries->previousPageUrl() }}" class="page-btn">&lsaquo;</a>
      @endif
      @foreach ($entries->getUrlRange(max(1, $entries->currentPage() - 4), min($entries->lastPage(), $entries->currentPage() + 4)) as $page => $url)
        @if ($page == $entries->currentPage())
          <span class="page-btn page-btn--active">{{ $page }}</span>
        @else
          <a href="{{ $url }}" class="page-btn">{{ $page }}</a>
        @endif
      @endforeach
      @if ($entries->hasMorePages())
        <a href="{{ $entries->nextPageUrl() }}" class="page-btn">&rsaquo;</a>
      @else
        <span class="page-btn page-btn--disabled">&rsaquo;</span>
      @endif
    </div>
  @endif
</div>

@endsection
