@extends('layouts.panel')

@section('title', $title)
@section('page-label', $title)

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">{{ $title }}</h1>
    <p class="page-subtitle">This section is under construction.</p>
  </div>
</div>

<div class="table-wrapper" style="padding:56px 24px; text-align:center;">
  <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 14px;">
    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
  </svg>
  <p style="font-size:14px; font-weight:600; color:var(--text);">Work in progress</p>
  <p style="font-size:13px; color:var(--text-muted); margin-top:4px;">{{ $title }} will be available in a future update.</p>
</div>

@endsection
