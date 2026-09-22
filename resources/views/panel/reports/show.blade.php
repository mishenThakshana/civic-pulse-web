@extends('layouts.panel')

@section('title', 'Report #'.$report->id)
@section('page-label', 'Reports')

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Report #{{ $report->id }}</h1>
    <p class="page-subtitle">{{ $report->title }}</p>
  </div>
  <a href="{{ route('reports.index') }}" class="btn-ghost btn-ghost--sm">Back to queue</a>
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

<div class="report-grid">
  <div>
    <div class="report-card">
      <div class="report-card-title">Reported Issue</div>
      <img src="{{ asset('storage/'.$report->photo_path) }}" alt="Photo for report #{{ $report->id }}" class="report-photo" />
      <p style="font-size:13px; color:var(--text); line-height:1.6;">{{ $report->description }}</p>
    </div>

    <div class="report-card">
      <div class="report-card-title">Location</div>
      <div class="detail-row"><span class="detail-label">Coordinates</span><span class="detail-value">{{ number_format($report->latitude, 5) }}, {{ number_format($report->longitude, 5) }}</span></div>
      <div class="detail-row">
        <span class="detail-label">Map</span>
        <span class="detail-value">
          <a href="https://www.openstreetmap.org/?mlat={{ $report->latitude }}&mlon={{ $report->longitude }}#map=17/{{ $report->latitude }}/{{ $report->longitude }}" target="_blank" rel="noopener" style="color:var(--accent);">Open in OpenStreetMap</a>
        </span>
      </div>
    </div>

    <div class="report-card">
      <div class="report-card-title">Status History</div>
      <div class="timeline">
        @foreach ($report->statusHistory as $entry)
          <div class="timeline-item">
            <div class="timeline-dot"></div>
            <div class="timeline-header">
              @if ($entry->to_status->value === 'resolved')
                <span class="badge badge--green">{{ $entry->to_status->label() }}</span>
              @elseif ($entry->to_status->value === 'in_progress')
                <span class="badge badge--blue">{{ $entry->to_status->label() }}</span>
              @else
                <span class="badge badge--amber">{{ $entry->to_status->label() }}</span>
              @endif
              <span style="font-size:12px; font-weight:500;">{{ $entry->changedBy?->name ?? 'System' }}</span>
            </div>
            @if ($entry->note)
              <p class="timeline-note">{{ $entry->note }}</p>
            @endif
            @if ($entry->photo_path)
              <img src="{{ asset('storage/'.$entry->photo_path) }}" alt="Progress photo" class="timeline-photo" />
            @endif
            <div class="timeline-meta">{{ $entry->created_at->format('d M Y, h:i A') }}</div>
          </div>
        @endforeach
      </div>
    </div>
  </div>

  <div>
    <div class="report-card">
      <div class="report-card-title">Details</div>
      <div class="detail-row"><span class="detail-label">Reference</span><span class="detail-value">#{{ $report->id }}</span></div>
      <div class="detail-row"><span class="detail-label">Reported by</span><span class="detail-value">{{ $report->user->name }}</span></div>
      <div class="detail-row"><span class="detail-label">Submitted</span><span class="detail-value">{{ $report->created_at->format('d M Y, h:i A') }}</span></div>
      <div class="detail-row"><span class="detail-label">Category</span><span class="detail-value">{{ $report->category?->name ?? 'Uncategorized' }}</span></div>
      <div class="detail-row"><span class="detail-label">Votes</span><span class="detail-value">{{ $report->upvotes_count }} citizens</span></div>
      <div class="detail-row"><span class="detail-label">Department</span><span class="detail-value">{{ $report->department?->name ?? 'Unassigned' }}</span></div>
      <div class="detail-row">
        <span class="detail-label">Status</span>
        <span class="detail-value">
          @if ($report->status->value === 'resolved')
            <span class="badge badge--green">{{ $report->status->label() }}</span>
          @elseif ($report->status->value === 'in_progress')
            <span class="badge badge--blue">{{ $report->status->label() }}</span>
          @else
            <span class="badge badge--amber">{{ $report->status->label() }}</span>
          @endif
        </span>
      </div>
    </div>

    <div class="report-card">
      <div class="report-card-title">Update This Report</div>
      <form method="POST" action="{{ route('reports.update', $report) }}" enctype="multipart/form-data">
        @csrf
        @method('PATCH')
        <div class="field">
          <label for="department_id">Assign to department</label>
          <select id="department_id" name="department_id">
            <option value="">Unassigned</option>
            @foreach ($departments as $department)
              <option value="{{ $department->id }}" {{ (string) old('department_id', $report->department_id) === (string) $department->id ? 'selected' : '' }}>{{ $department->name }}</option>
            @endforeach
          </select>
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="status">Change status</label>
          <select id="status" name="status">
            @foreach ($statuses as $status)
              <option value="{{ $status->value }}" {{ old('status', $report->status->value) === $status->value ? 'selected' : '' }}>{{ $status->label() }}</option>
            @endforeach
          </select>
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="note">Note for the citizen</label>
          <textarea id="note" name="note" placeholder="This note is shown to everyone following the report…">{{ old('note') }}</textarea>
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="photo">Attach a progress photo</label>
          <input type="file" id="photo" name="photo" accept="image/*" />
        </div>
        <button type="submit" class="btn-primary" style="width:100%; margin-top:16px; padding:10px;">Save update and notify</button>
      </form>
    </div>
  </div>
</div>

@endsection
