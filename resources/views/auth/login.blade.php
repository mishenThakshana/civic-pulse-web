@extends('layouts.guest')

@section('title', 'Sign in — CivicPulse')

@section('content')
<div class="guest-card">

  <div class="guest-logo">
    <div class="guest-logo-icon">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="2 14 8 14 11 20 14 4 17 14 22 14"/>
      </svg>
    </div>
    <div class="guest-logo-text">
      CivicPulse
      <span>Municipal Admin Panel</span>
    </div>
  </div>

  <h1 class="guest-heading">Sign in</h1>
  <p class="guest-subheading">Enter your credentials to access the panel.</p>

  @if ($errors->any())
    <div class="alert alert--error">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span>{{ $errors->first() }}</span>
    </div>
  @endif

  <form class="guest-form" method="POST" action="{{ route('login.submit') }}">
    @csrf

    <div class="field">
      <label for="email">Email address</label>
      <input
        type="email"
        id="email"
        name="email"
        value="{{ old('email') }}"
        placeholder="you@civicpulse.gov"
        autocomplete="email"
        autofocus
        required
      />
    </div>

    <div class="field">
      <label for="password">Password</label>
      <input
        type="password"
        id="password"
        name="password"
        placeholder="••••••••"
        autocomplete="current-password"
        required
      />
    </div>

    <button type="submit" class="btn-primary" style="margin-top:4px;">
      Sign in
    </button>

  </form>

</div>
@endsection
