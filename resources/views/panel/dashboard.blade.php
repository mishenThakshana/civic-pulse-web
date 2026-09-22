@extends('layouts.panel')

@section('title', 'Dashboard')
@section('page-label', 'Dashboard')

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">A live picture of what the city has reported and how it is being handled.</p>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">Open Reports</div>
    <div class="stat-value">{{ $openCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">In Progress</div>
    <div class="stat-value">{{ $inProgressCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Resolved</div>
    <div class="stat-value">{{ $resolvedCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Avg Resolution Time</div>
    <div class="stat-value">{{ $avgResolutionDays !== null ? $avgResolutionDays.'d' : '—' }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Pending Citizens</div>
    <div class="stat-value">{{ $pendingCitizens }}</div>
  </div>
</div>

<div class="report-grid">
  <div class="report-card">
    <div class="report-card-title">Needs Attention (top {{ $needsAttention->count() }} by votes)</div>
    <table class="data-table">
      <thead>
        <tr><th>Issue</th><th>Category</th><th>Votes</th><th>Age</th></tr>
      </thead>
      <tbody>
        @forelse ($needsAttention as $report)
          <tr>
            <td><a href="{{ route('reports.show', $report) }}" style="color:var(--text); font-weight:500; text-decoration:none;">{{ $report->title }}</a></td>
            <td><span class="badge">{{ $report->category?->name ?? 'Uncategorized' }}</span></td>
            <td>
              <span class="vote-pill vote-pill--up">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="M5 12l7-7 7 7"/></svg>
                {{ $report->upvotes_count }}
              </span>
            </td>
            <td style="font-size:12px; color:var(--text-muted);">{{ $report->created_at->diffForHumans(null, true) }}</td>
          </tr>
        @empty
          <tr><td colspan="4" style="text-align:center; color:var(--text-muted); padding:24px;">No open reports right now.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="report-card">
    <div class="report-card-title">Department Workload</div>
    @forelse ($departmentWorkload as $department)
      <div class="detail-row">
        <span class="detail-label">{{ $department->name }}</span>
        <span class="detail-value">{{ $department->open_count }} open</span>
      </div>
    @empty
      <p style="font-size:13px; color:var(--text-muted);">No departments yet.</p>
    @endforelse
  </div>
</div>

@endsection
