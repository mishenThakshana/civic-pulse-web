@extends('layouts.panel')

@section('title', 'Analytics')
@section('page-label', 'Analytics')

@php
  $maxCategoryCount = $categoryCounts->max('reports_count') ?: 1;
  $maxMonthCount = collect($months)->flatMap(fn ($m) => [$m['submitted'], $m['resolved']])->max() ?: 1;
  $maxResolutionDays = $resolutionByDepartment->max('days') ?: 1;
@endphp

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Analytics</h1>
    <p class="page-subtitle">How reports are distributed, how quickly they close, and where the pressure is.</p>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">Total Reports</div>
    <div class="stat-value">{{ $totalReports }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Resolution Rate</div>
    <div class="stat-value">{{ $resolutionRate }}%</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Median First Response</div>
    <div class="stat-value">{{ $medianFirstResponseHours !== null ? $medianFirstResponseHours.'h' : '—' }}</div>
  </div>
</div>

<div class="report-grid">
  <div class="report-card">
    <div class="report-card-title">Reports by Category</div>
    @forelse ($categoryCounts as $category)
      <div class="bar-row">
        <span class="bar-row-label">{{ $category->name }}</span>
        <div class="bar-track"><div class="bar-fill" style="width: {{ $category->reports_count / $maxCategoryCount * 100 }}%;"></div></div>
        <span class="bar-row-value">{{ $category->reports_count }}</span>
      </div>
    @empty
      <p style="font-size:13px; color:var(--text-muted);">No reports yet.</p>
    @endforelse
  </div>

  <div class="report-card">
    <div class="report-card-title">Submitted vs Resolved, Last 6 Months</div>
    <div class="month-bars">
      @foreach ($months as $month)
        <div class="month-bar-group">
          <div class="month-bar-pair">
            <div class="month-bar month-bar--submitted" style="height: {{ max($month['submitted'] / $maxMonthCount * 100, 2) }}%;" title="{{ $month['submitted'] }} submitted"></div>
            <div class="month-bar month-bar--resolved" style="height: {{ max($month['resolved'] / $maxMonthCount * 100, 2) }}%;" title="{{ $month['resolved'] }} resolved"></div>
          </div>
          <span class="month-bar-label">{{ $month['label'] }}</span>
        </div>
      @endforeach
    </div>
    <div style="display:flex; gap:16px; margin-top:10px; font-size:11px; color:var(--text-muted);">
      <span><span style="display:inline-block; width:8px; height:8px; background:var(--border); border-radius:2px; margin-right:4px;"></span>Submitted</span>
      <span><span style="display:inline-block; width:8px; height:8px; background:var(--accent); border-radius:2px; margin-right:4px;"></span>Resolved</span>
    </div>
  </div>
</div>

<div class="report-card">
  <div class="report-card-title">Average Resolution Time by Department</div>
  @forelse ($resolutionByDepartment as $department)
    <div class="bar-row">
      <span class="bar-row-label">{{ $department['name'] }}</span>
      <div class="bar-track"><div class="bar-fill" style="width: {{ $department['days'] !== null ? $department['days'] / $maxResolutionDays * 100 : 0 }}%;"></div></div>
      <span class="bar-row-value">{{ $department['days'] !== null ? $department['days'].'d' : '—' }}</span>
    </div>
  @empty
    <p style="font-size:13px; color:var(--text-muted);">No departments yet.</p>
  @endforelse
</div>

@endsection
