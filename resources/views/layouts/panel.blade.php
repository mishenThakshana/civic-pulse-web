<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>@yield('title', 'Panel') — CivicPulse</title>
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

<div class="panel-root">

  {{-- Top Header --}}
  <header class="panel-header">
    <div class="panel-header-left">
      <a href="{{ route('dashboard') }}" class="panel-logo">
        <div class="panel-logo-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="2 14 8 14 11 20 14 4 17 14 22 14"/>
          </svg>
        </div>
        <span class="panel-logo-name">CivicPulse</span>
      </a>

      <div class="panel-header-sep"></div>

      <span style="font-size:13px; color:var(--text-muted);">@yield('page-label', 'Dashboard')</span>
    </div>

    <div class="panel-header-right">

      <span class="role-badge role-badge--{{ auth()->user()->role->value }}">{{ auth()->user()->role->label() }}</span>

      <div class="panel-user">
        <span class="panel-username">{{ auth()->user()->name }}</span>
      </div>

      <form method="POST" action="{{ route('logout') }}" style="display:inline;">
        @csrf
        <button type="submit" class="panel-signout">Sign out</button>
      </form>

    </div>
  </header>

  {{-- Body: Sidebar + Content --}}
  <div class="panel-body">

    <aside class="panel-sidebar">
      <div class="sidebar-role-bar">
        <div class="sidebar-role-label">Signed in as</div>
        <div class="sidebar-role-name">{{ auth()->user()->role->label() }}</div>
      </div>

      <nav class="sidebar-nav">

        <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
          <span class="nav-item-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
          </span>
          <span class="nav-item-text">Dashboard</span>
        </a>

        <a href="{{ route('citizens.index') }}" class="nav-item {{ request()->routeIs('citizens.*') ? 'is-active' : '' }}">
          <span class="nav-item-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="4" width="20" height="16" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M13 9h6"/><path d="M13 13h4"/><path d="M5 17h6"/>
            </svg>
          </span>
          <span class="nav-item-text">Citizens</span>
        </a>

        <a href="{{ route('reports.index') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'is-active' : '' }}">
          <span class="nav-item-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
            </svg>
          </span>
          <span class="nav-item-text">Reports</span>
        </a>

        <a href="{{ route('analytics.index') }}" class="nav-item {{ request()->routeIs('analytics.*') ? 'is-active' : '' }}">
          <span class="nav-item-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
          </span>
          <span class="nav-item-text">Analytics</span>
        </a>

        <div class="sidebar-section-label">Administration</div>

        <a href="{{ route('categories.index') }}" class="nav-item {{ request()->routeIs('categories.*') ? 'is-active' : '' }}">
          <span class="nav-item-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20.59 13.41 11 3.83V3H3v8h.83l9.58 9.59a2 2 0 0 0 2.83 0l4.35-4.35a2 2 0 0 0 0-2.83z"/><circle cx="6.5" cy="6.5" r="1.5"/>
            </svg>
          </span>
          <span class="nav-item-text">Categories</span>
        </a>

        <a href="{{ route('departments.index') }}" class="nav-item {{ request()->routeIs('departments.*') ? 'is-active' : '' }}">
          <span class="nav-item-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 9h1"/><path d="M14 9h1"/><path d="M9 13h1"/><path d="M14 13h1"/><path d="M9 21v-4h6v4"/>
            </svg>
          </span>
          <span class="nav-item-text">Departments</span>
        </a>

        <a href="{{ route('staff.index') }}" class="nav-item {{ request()->routeIs('staff.*') ? 'is-active' : '' }}">
          <span class="nav-item-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
          </span>
          <span class="nav-item-text">Staff Accounts</span>
        </a>

        <a href="{{ route('activity-log.index') }}" class="nav-item {{ request()->routeIs('activity-log.*') ? 'is-active' : '' }}">
          <span class="nav-item-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
            </svg>
          </span>
          <span class="nav-item-text">Activity Log</span>
        </a>

      </nav>
    </aside>

    <main class="panel-content">
      @yield('content')
    </main>

  </div>
</div>


<script>
  document.addEventListener('submit', function (e) {
    const btn = e.target.querySelector('[type="submit"]');
    if (btn) {
      btn.disabled = true;
      btn.style.opacity = '0.55';
      btn.style.cursor = 'not-allowed';
    }
  });

  document.addEventListener('click', function (e) {
    const opener = e.target.closest('[data-modal-open]');
    if (opener) {
      const modal = document.getElementById(opener.getAttribute('data-modal-open'));
      if (modal) modal.classList.add('is-open');
    }

    const closer = e.target.closest('[data-modal-close]');
    if (closer) {
      const modal = closer.closest('.modal-overlay');
      if (modal) modal.classList.remove('is-open');
    }
  });
</script>
</body>
</html>
