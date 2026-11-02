@extends('layouts.panel')

@section('title', 'Departments')
@section('page-label', 'Departments')

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Departments</h1>
    <p class="page-subtitle">The municipal teams reports are routed to, and how each one is performing.</p>
  </div>
  <button type="button" class="btn-primary" data-modal-open="addModal">Add department</button>
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

@if (session('new_api_key'))
  <div class="api-key-reveal">
    <div class="api-key-reveal-title">New API key for {{ session('new_api_key_department') }}</div>
    <p class="api-key-reveal-text">Copy this key now and send it to the department securely. For security it is stored hashed and <strong>will not be shown again</strong>.</p>
    <div class="api-key-reveal-row">
      <code id="newApiKey">{{ session('new_api_key') }}</code>
      <button type="button" class="btn-ghost btn-ghost--sm" data-copy-target="newApiKey">Copy</button>
    </div>
    <p class="api-key-reveal-text" style="margin-top:8px;">Share the <a href="{{ route('developers') }}" target="_blank" rel="noopener">integration API documentation</a> with their developers.</p>
  </div>
@endif

<div class="table-wrapper">
  <div class="table-header">
    <span class="table-title">All Departments <span class="table-count">({{ $departments->count() }})</span></span>
  </div>

  <table class="data-table">
    <thead>
      <tr><th>Department</th><th>Contact Email</th><th>Open</th><th>In Progress</th><th>Resolved</th><th>Avg Resolution</th><th>API Keys</th><th></th></tr>
    </thead>
    <tbody>
      @forelse ($departments as $department)
        <tr>
          <td style="font-weight:500;">{{ $department->name }}</td>
          <td style="color:var(--text-muted); font-size:12px;">{{ $department->contact_email }}</td>
          <td>{{ $department->open_count }}</td>
          <td>{{ $department->in_progress_count }}</td>
          <td>{{ $department->resolved_count }}</td>
          <td>{{ $department->avg_resolution_days !== null ? $department->avg_resolution_days.'d' : '—' }}</td>
          <td>{{ $department->apiKeys->count() ?: '—' }}</td>
          <td>
            <div style="display:flex; align-items:center; gap:8px;">
              <button type="button" class="btn-ghost btn-ghost--sm" data-modal-open="apiKeysModal{{ $department->id }}">API access</button>
              <button type="button" class="btn-ghost btn-ghost--sm" data-modal-open="editModal{{ $department->id }}">Edit</button>
              <form method="POST" action="{{ route('departments.destroy', $department) }}" onsubmit="return confirm('Delete the \'{{ addslashes($department->name) }}\' department? Its reports will become unassigned.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-ghost btn-ghost--sm">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="8" style="text-align:center; color:var(--text-muted); padding:40px 16px;">No departments yet.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

{{-- Add Modal --}}
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Add department</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('departments.store') }}">
      @csrf
      <div class="modal-body">
        <div class="field">
          <label for="name">Name</label>
          <input type="text" id="name" name="name" placeholder="e.g. Roads and Highways" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="contact_email">Contact email</label>
          <input type="email" id="contact_email" name="contact_email" placeholder="roads@council.lk" required />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn-primary">Add department</button>
      </div>
    </form>
  </div>
</div>

{{-- API Key Modals --}}
@foreach ($departments as $department)
<div class="modal-overlay" id="apiKeysModal{{ $department->id }}">
  <div class="modal modal--lg">
    <div class="modal-header">
      <h2 class="modal-title">API access — {{ $department->name }}</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-info-note">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>Keys let this department's own systems read the reports assigned to it and, if allowed, post status updates. See the <a href="{{ route('developers') }}" target="_blank" rel="noopener">API documentation</a>.</span>
      </div>

      @if ($department->apiKeys->isNotEmpty())
        <table class="data-table" style="margin-top:14px;">
          <thead><tr><th>Name</th><th>Key</th><th>Access</th><th>Last Used</th><th></th></tr></thead>
          <tbody>
            @foreach ($department->apiKeys as $apiKey)
              <tr>
                <td style="font-weight:500;">
                  {{ $apiKey->name }}
                  <div style="font-size:11px; font-weight:400; color:var(--text-muted);">Issued {{ $apiKey->created_at->format('d M Y') }}{{ $apiKey->createdBy ? ' by '.$apiKey->createdBy->name : '' }}</div>
                </td>
                <td><code style="font-size:12px;">{{ $apiKey->key_prefix }}…</code></td>
                <td>
                  @if ($apiKey->can_update_reports)
                    <span class="badge badge--blue">Read &amp; update</span>
                  @else
                    <span class="badge">Read only</span>
                  @endif
                </td>
                <td style="font-size:12px; color:var(--text-muted);">{{ $apiKey->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                <td>
                  <form method="POST" action="{{ route('departments.api-keys.destroy', [$department, $apiKey]) }}" onsubmit="return confirm('Revoke the \'{{ addslashes($apiKey->name) }}\' key? Any system using it will stop working immediately.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-ghost btn-ghost--sm btn-ghost--danger">Revoke</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @else
        <p style="font-size:13px; color:var(--text-muted); margin-top:14px;">No API keys have been issued to this department.</p>
      @endif

      <form method="POST" action="{{ route('departments.api-keys.store', $department) }}" style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border);">
        @csrf
        <div class="report-card-title" style="margin-bottom:10px;">Issue a new key</div>
        <div class="field">
          <label for="key_name{{ $department->id }}">Key name</label>
          <input type="text" id="key_name{{ $department->id }}" name="name" placeholder="e.g. Works management system" required />
          <span class="field-hint">Name it after the system that will use it, so it's clear what breaks if it's revoked.</span>
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="access{{ $department->id }}">Access</label>
          <select id="access{{ $department->id }}" name="access">
            <option value="read">Read only — fetch assigned reports</option>
            <option value="read_write">Read &amp; update — also post status updates to citizens</option>
          </select>
        </div>
        <div style="display:flex; justify-content:flex-end; margin-top:16px;">
          <button type="submit" class="btn-primary">Issue key</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endforeach

{{-- Edit Modals --}}
@foreach ($departments as $department)
<div class="modal-overlay" id="editModal{{ $department->id }}">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Edit {{ $department->name }}</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('departments.update', $department) }}">
      @csrf
      @method('PATCH')
      <div class="modal-body">
        <div class="field">
          <label for="name{{ $department->id }}">Name</label>
          <input type="text" id="name{{ $department->id }}" name="name" value="{{ $department->name }}" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="contact_email{{ $department->id }}">Contact email</label>
          <input type="email" id="contact_email{{ $department->id }}" name="contact_email" value="{{ $department->contact_email }}" required />
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
