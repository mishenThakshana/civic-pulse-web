@extends('layouts.panel')

@section('title', 'Staff Accounts')
@section('page-label', 'Staff Accounts')

@section('content')

<div class="page-header">
  <div>
    <h1 class="page-title">Staff Accounts</h1>
    <p class="page-subtitle">Everyone who can sign in to this panel. Staff handle citizens and reports; administrators also manage settings and accounts.</p>
  </div>
  <button type="button" class="btn-primary" data-modal-open="addModal">Add account</button>
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
    <div class="stat-label">Administrators</div>
    <div class="stat-value" style="color:var(--accent);">{{ $adminCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Staff</div>
    <div class="stat-value" style="color:var(--accent);">{{ $staffCount }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Deactivated</div>
    <div class="stat-value" style="color:var(--accent);">{{ $inactiveCount }}</div>
  </div>
</div>

<div class="table-wrapper">
  <div class="table-header">
    <span class="table-title">All Accounts <span class="table-count">({{ $staffMembers->total() }})</span></span>
  </div>

  <form method="GET" action="{{ route('staff.index') }}" class="list-filter">
    <div class="ff ff--grow">
      <label>Search</label>
      <input type="text" name="search" value="{{ request('search') }}" placeholder="Name or email…" />
    </div>
    <div class="ff">
      <label>Role</label>
      <select name="role">
        <option value="">All roles</option>
        @foreach ($roles as $role)
          <option value="{{ $role->value }}" {{ request('role') === $role->value ? 'selected' : '' }}>{{ $role->label() }}</option>
        @endforeach
      </select>
    </div>
    <div class="actions">
      <button type="submit" class="btn-primary">Apply</button>
      @if (request()->anyFilled(['search', 'role']))
        <a href="{{ route('staff.index') }}" class="btn-ghost btn-ghost--sm">Clear</a>
      @endif
    </div>
  </form>

  <table class="data-table">
    <thead>
      <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last Active</th><th>Actions Logged</th><th></th></tr>
    </thead>
    <tbody>
      @forelse ($staffMembers as $member)
        <tr>
          <td style="font-weight:500;">
            {{ $member->name }}
            @if ($member->is(auth()->user()))
              <span class="badge" style="margin-left:4px;">You</span>
            @endif
          </td>
          <td style="color:var(--text-muted); font-size:12px;">{{ $member->email }}</td>
          <td><span class="role-badge role-badge--{{ $member->role->value }}">{{ $member->role->label() }}</span></td>
          <td>
            @if ($member->is_active)
              <span class="badge badge--green">Active</span>
            @else
              <span class="badge">Deactivated</span>
            @endif
          </td>
          <td style="font-size:12px; color:var(--text-muted);">{{ $member->last_active_at ? \Illuminate\Support\Carbon::parse($member->last_active_at)->diffForHumans() : 'Never' }}</td>
          <td>
            <a href="{{ route('activity-log.index', ['user_id' => $member->id]) }}" style="color:var(--accent); text-decoration:none; font-size:12px; font-weight:500;">{{ $member->activity_logs_count }}</a>
          </td>
          <td>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
              <button type="button" class="btn-ghost btn-ghost--sm" data-modal-open="editModal{{ $member->id }}">Edit</button>
              @unless ($member->is(auth()->user()))
                <form method="POST" action="{{ route('staff.toggle-active', $member) }}">
                  @csrf
                  @method('PATCH')
                  <button type="submit" class="btn-ghost btn-ghost--sm">{{ $member->is_active ? 'Deactivate' : 'Activate' }}</button>
                </form>
                <form method="POST" action="{{ route('staff.destroy', $member) }}" onsubmit="return confirm('Permanently delete {{ addslashes($member->name) }}\'s account? Their activity history is kept.')">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn-ghost btn-ghost--sm">Delete</button>
                </form>
              @endunless
            </div>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" style="text-align:center; color:var(--text-muted); padding:40px 16px;">No accounts match these filters.</td></tr>
      @endforelse
    </tbody>
  </table>

  @if ($staffMembers->hasPages())
    <div class="pagination-bar">
      @if ($staffMembers->onFirstPage())
        <span class="page-btn page-btn--disabled">&lsaquo;</span>
      @else
        <a href="{{ $staffMembers->previousPageUrl() }}" class="page-btn">&lsaquo;</a>
      @endif
      @foreach ($staffMembers->getUrlRange(1, $staffMembers->lastPage()) as $page => $url)
        @if ($page == $staffMembers->currentPage())
          <span class="page-btn page-btn--active">{{ $page }}</span>
        @else
          <a href="{{ $url }}" class="page-btn">{{ $page }}</a>
        @endif
      @endforeach
      @if ($staffMembers->hasMorePages())
        <a href="{{ $staffMembers->nextPageUrl() }}" class="page-btn">&rsaquo;</a>
      @else
        <span class="page-btn page-btn--disabled">&rsaquo;</span>
      @endif
    </div>
  @endif
</div>

{{-- Add Modal --}}
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Add account</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('staff.store') }}">
      @csrf
      <div class="modal-body">
        <div class="field">
          <label for="name">Full name</label>
          <input type="text" id="name" name="name" value="{{ old('name') }}" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" value="{{ old('email') }}" placeholder="name@council.lk" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="role">Role</label>
          <select id="role" name="role">
            @foreach ($roles as $role)
              <option value="{{ $role->value }}" {{ old('role', 'staff') === $role->value ? 'selected' : '' }}>{{ $role->label() }}</option>
            @endforeach
          </select>
          <span class="field-hint">Staff can manage citizens and reports. Administrators can also manage categories, departments, accounts, and view the activity log.</span>
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" autocomplete="new-password" minlength="8" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="password_confirmation">Confirm password</label>
          <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" minlength="8" required />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn-primary">Create account</button>
      </div>
    </form>
  </div>
</div>

{{-- Edit Modals --}}
@foreach ($staffMembers as $member)
<div class="modal-overlay" id="editModal{{ $member->id }}">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Edit {{ $member->name }}</h2>
      <button class="modal-close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('staff.update', $member) }}">
      @csrf
      @method('PATCH')
      <div class="modal-body">
        <div class="field">
          <label for="name{{ $member->id }}">Full name</label>
          <input type="text" id="name{{ $member->id }}" name="name" value="{{ $member->name }}" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="email{{ $member->id }}">Email</label>
          <input type="email" id="email{{ $member->id }}" name="email" value="{{ $member->email }}" required />
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="role{{ $member->id }}">Role</label>
          <select id="role{{ $member->id }}" name="role">
            @foreach ($roles as $role)
              <option value="{{ $role->value }}" {{ $member->role === $role ? 'selected' : '' }}>{{ $role->label() }}</option>
            @endforeach
          </select>
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="password{{ $member->id }}">New password</label>
          <input type="password" id="password{{ $member->id }}" name="password" autocomplete="new-password" minlength="8" />
          <span class="field-hint">Leave blank to keep the current password.</span>
        </div>
        <div class="field" style="margin-top:14px;">
          <label for="password_confirmation{{ $member->id }}">Confirm new password</label>
          <input type="password" id="password_confirmation{{ $member->id }}" name="password_confirmation" autocomplete="new-password" minlength="8" />
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
