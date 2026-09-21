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

<div class="table-wrapper">
  <div class="table-header">
    <span class="table-title">All Departments <span class="table-count">({{ $departments->count() }})</span></span>
  </div>

  <table class="data-table">
    <thead>
      <tr><th>Department</th><th>Contact Email</th><th>Open</th><th>In Progress</th><th>Resolved</th><th>Avg Resolution</th><th></th></tr>
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
          <td>
            <div style="display:flex; align-items:center; gap:8px;">
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
        <tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:40px 16px;">No departments yet.</td></tr>
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
