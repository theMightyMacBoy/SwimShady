@extends('layouts.app')
@section('title', config('app.name').' — Account')

@section('content')
  @include('partials.topbar', ['heading' => 'Account', 'nav' => 'bare'])

  <main class="history-main">
    <div class="card" style="max-width:420px">
      <div class="label">Change password — {{ $user->username }}</div>
      @include('partials.flashes')
      @if ($user->username === 'demo')
        <p class="muted">This is the shared public demo account — its password can't be changed.</p>
      @else
        <form method="post" action="{{ route('account.password') }}" class="add-device" style="flex-direction:column;align-items:stretch">
          @csrf
          <label>Current password
            <input type="password" name="current" autocomplete="current-password" required>
          </label>
          <label>New password (min 8 chars)
            <input type="password" name="new" autocomplete="new-password" required minlength="8">
          </label>
          <label>Confirm new password
            <input type="password" name="confirm" autocomplete="new-password" required minlength="8">
          </label>
          <button type="submit">Update password</button>
        </form>
      @endif
    </div>
  </main>
@endsection
