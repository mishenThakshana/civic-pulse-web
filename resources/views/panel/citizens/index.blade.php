@extends('layouts.panel')

@section('title', 'Citizens')
@section('page-label', 'Citizens')

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Citizens</h1>
    <p class="page-subtitle">Review NIC uploads, approve or reject registrations, and manage citizen accounts.</p>
  </div>
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

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">Pending Review</div>
    <div class="stat-value" style="color:var(--accent);">{{ $pendingCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Approved</div>
    <div class="stat-value" style="color:var(--accent);">{{ $approvedCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Rejected</div>
    <div class="stat-value" style="color:var(--accent);">{{ $rejectedCount }}</div>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-header">
    <span class="table-title">All Citizens <span class="table-count">({{ $citizens->total() }})</span></span>
  </div>

  <form method="GET" action="{{ route('citizens.index') }}" class="list-filter">
    <div class="ff ff--grow">
      <label>Search</label>
      <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email, or NIC number…" />
    </div>
    <div class="ff">
      <label>Status</label>
      <select name="status">
        <option value="">All statuses</option>
        @foreach ($statuses as $status)
          <option value="{{ $status->value }}" {{ request('status') === $status->value ? 'selected' : '' }}>{{ $status->label() }}</option>
        @endforeach
      </select>
    </div>
    <div class="actions">
      <button type="submit" class="btn-primary">Apply</button>
      @if (request()->anyFilled(['search', 'status']))
        <a href="{{ route('citizens.index') }}" class="btn-ghost btn-ghost--sm">Clear</a>
      @endif
    </div>
  </form>

  <table class="data-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Phone</th>
        <th>NIC Number</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @forelse ($citizens as $citizen)
        <tr>
          <td style="font-weight:500;">{{ $citizen->name }}</td>
          <td style="color:var(--text-muted); font-size:12px;">{{ $citizen->email }}</td>
          <td style="font-size:12px;">{{ $citizen->phone }}</td>
          <td style="font-size:12px;">{{ $citizen->nic_number }}</td>
          <td>
            @if ($citizen->approval_status->value === 'approved')
              <span class="badge badge--green">Approved</span>
            @elseif ($citizen->approval_status->value === 'rejected')
              <span class="badge badge--red">Rejected</span>
            @else
              <span class="badge badge--amber">Pending</span>
            @endif
          </td>
          <td>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              <button type="button" class="btn-ghost btn-ghost--sm" data-modal-open="viewModal{{ $citizen->id }}">View</button>
              <button type="button" class="btn-ghost btn-ghost--sm" data-modal-open="editModal{{ $citizen->id }}">Edit</button>
              @if (auth()->user()->isAdmin())
                <form method="POST" action="{{ route('citizens.destroy', $citizen) }}" onsubmit="return confirm('Permanently delete {{ addslashes($citizen->name) }}\'s account?')">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn-ghost btn-ghost--sm">Delete</button>
                </form>
              @endif
            </div>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="6" style="text-align:center; color:var(--text-muted); padding:40px 16px;">No citizens match these filters.</td>
        </tr>
      @endforelse
    </tbody>
  </table>

  @if ($citizens->hasPages())
    <div class="pagination-bar">
      @if ($citizens->onFirstPage())
        <span class="page-btn page-btn--disabled">&lsaquo;</span>
      @else
        <a href="{{ $citizens->previousPageUrl() }}" class="page-btn">&lsaquo;</a>
      @endif
      @foreach ($citizens->getUrlRange(1, $citizens->lastPage()) as $page => $url)
        @if ($page == $citizens->currentPage())
          <span class="page-btn page-btn--active">{{ $page }}</span>
        @else
          <a href="{{ $url }}" class="page-btn">{{ $page }}</a>
        @endif
      @endforeach
      @if ($citizens->hasMorePages())
        <a href="{{ $citizens->nextPageUrl() }}" class="page-btn">&rsaquo;</a>
      @else
        <span class="page-btn page-btn--disabled">&rsaquo;</span>
      @endif
    </div>
  @endif
</div>

{{-- View Modals --}}
@foreach ($citizens as $citizen)
<div class="modal-overlay" id="viewModal{{ $citizen->id }}">
  <div class="modal modal--lg">
    <div class="modal-header">
      <h2 class="modal-title">{{ $citizen->name }}</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
        <div>
          <div class="detail-label" style="margin-bottom:6px;">NIC Front</div>
          @if ($citizen->nic_photo_front_path)
            @if (strtolower(pathinfo($citizen->nic_photo_front_path, PATHINFO_EXTENSION)) === 'pdf')
              <a href="{{ asset('storage/'.$citizen->nic_photo_front_path) }}" target="_blank" rel="noopener" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; width:100%; height:140px; border-radius:6px; border:1px solid var(--border); background:var(--bg); color:var(--accent); text-decoration:none;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span style="font-size:12px; font-weight:500;">View PDF</span>
              </a>
            @else
              <a href="{{ asset('storage/'.$citizen->nic_photo_front_path) }}" target="_blank" rel="noopener">
                <img src="{{ asset('storage/'.$citizen->nic_photo_front_path) }}" alt="NIC front for {{ $citizen->name }}" style="width:100%; height:140px; object-fit:cover; border-radius:6px; border:1px solid var(--border);" />
              </a>
            @endif
          @else
            <span class="badge">No photo</span>
          @endif
        </div>
        <div>
          <div class="detail-label" style="margin-bottom:6px;">NIC Back</div>
          @if ($citizen->nic_photo_back_path)
            @if (strtolower(pathinfo($citizen->nic_photo_back_path, PATHINFO_EXTENSION)) === 'pdf')
              <a href="{{ asset('storage/'.$citizen->nic_photo_back_path) }}" target="_blank" rel="noopener" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; width:100%; height:140px; border-radius:6px; border:1px solid var(--border); background:var(--bg); color:var(--accent); text-decoration:none;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span style="font-size:12px; font-weight:500;">View PDF</span>
              </a>
            @else
              <a href="{{ asset('storage/'.$citizen->nic_photo_back_path) }}" target="_blank" rel="noopener">
                <img src="{{ asset('storage/'.$citizen->nic_photo_back_path) }}" alt="NIC back for {{ $citizen->name }}" style="width:100%; height:140px; object-fit:cover; border-radius:6px; border:1px solid var(--border);" />
              </a>
            @endif
          @else
            <span class="badge">No photo</span>
          @endif
        </div>
      </div>

      <div class="detail-row"><span class="detail-label">Email</span><span class="detail-value">{{ $citizen->email }}</span></div>
      <div class="detail-row"><span class="detail-label">Phone</span><span class="detail-value">{{ $citizen->phone }}</span></div>
      <div class="detail-row"><span class="detail-label">NIC Number</span><span class="detail-value">{{ $citizen->nic_number }}</span></div>
      <div class="detail-row">
        <span class="detail-label">Approval Status</span>
        <span class="detail-value">
          @if ($citizen->approval_status->value === 'approved')
            <span class="badge badge--green">Approved</span>
          @elseif ($citizen->approval_status->value === 'rejected')
            <span class="badge badge--red">Rejected</span>
          @else
            <span class="badge badge--amber">Pending</span>
          @endif
        </span>
      </div>
      @if ($citizen->approval_status->value === 'rejected' && $citizen->rejection_reason)
        <div class="detail-row"><span class="detail-label">Rejection Reason</span><span class="detail-value">{{ $citizen->rejection_reason }}</span></div>
      @endif
      @if ($citizen->approvedBy)
        <div class="detail-row"><span class="detail-label">Reviewed By</span><span class="detail-value">{{ $citizen->approvedBy->name }}</span></div>
      @endif
      <div class="detail-row">
        <span class="detail-label">Account</span>
        <span class="detail-value">
          @if ($citizen->is_active)
            <span class="badge badge--green">Active</span>
          @else
            <span class="badge">Disabled</span>
          @endif
        </span>
      </div>
      <div class="detail-row"><span class="detail-label">Registered</span><span class="detail-value">{{ $citizen->created_at->format('d M Y, h:i A') }}</span></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-ghost btn-ghost--close" data-modal-close>Close</button>
      <form method="POST" action="{{ route('citizens.toggle-active', $citizen) }}">
        @csrf
        @method('PATCH')
        <button type="submit" class="btn-ghost">{{ $citizen->is_active ? 'Deactivate' : 'Activate' }}</button>
      </form>
      @if ($citizen->approval_status->value === 'pending')
        <button type="button" class="btn-ghost btn-ghost--danger" data-modal-close data-modal-open="rejectModal{{ $citizen->id }}">Reject</button>
        <form method="POST" action="{{ route('citizens.approve', $citizen) }}">
          @csrf
          <button type="submit" class="btn-primary">Approve</button>
        </form>
      @endif
    </div>
  </div>
</div>
@endforeach

{{-- Reject Modals --}}
@foreach ($citizens as $citizen)
  @if ($citizen->approval_status->value === 'pending')
  <div class="modal-overlay" id="rejectModal{{ $citizen->id }}">
    <div class="modal">
      <div class="modal-header">
        <h2 class="modal-title">Reject {{ $citizen->name }}</h2>
        <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
      </div>
      <form method="POST" action="{{ route('citizens.reject', $citizen) }}">
        @csrf
        <div class="modal-body">
          <div class="modal-info-note">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>This reason is required and will be shown to {{ $citizen->name }} when they try to sign in.</span>
          </div>
          <div class="field" style="margin-top:14px;">
            <label for="reason{{ $citizen->id }}">Reason for rejection</label>
            <textarea id="reason{{ $citizen->id }}" name="reason" required placeholder="e.g. NIC photo is blurry / details don't match / duplicate account…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-ghost" data-modal-close>Cancel</button>
          <button type="submit" class="btn-danger">Reject Registration</button>
        </div>
      </form>
    </div>
  </div>
  @endif
@endforeach

{{-- Edit Modals --}}
@foreach ($citizens as $citizen)
<div class="modal-overlay" id="editModal{{ $citizen->id }}">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Edit {{ $citizen->name }}</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('citizens.update', $citizen) }}">
      @csrf
      @method('PATCH')
      <div class="modal-body">
        <div class="field">
          <label for="name{{ $citizen->id }}">Full name</label>
          <input type="text" id="name{{ $citizen->id }}" name="name" value="{{ $citizen->name }}" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="email{{ $citizen->id }}">Email</label>
          <input type="email" id="email{{ $citizen->id }}" name="email" value="{{ $citizen->email }}" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="phone{{ $citizen->id }}">Phone</label>
          <input type="text" id="phone{{ $citizen->id }}" name="phone" value="{{ $citizen->phone }}" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="nic_number{{ $citizen->id }}">NIC number</label>
          <input type="text" id="nic_number{{ $citizen->id }}" name="nic_number" value="{{ $citizen->nic_number }}" required />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
@endforeach

@endsection
