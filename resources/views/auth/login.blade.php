@extends('layouts.app')
@section('title', config('app.name').' — Sign in')

@section('body-attrs')
class="login-body"
@endsection

@section('content')
  <form class="login-card" method="post" action="{{ route('login.attempt') }}">
    @csrf
    <h1>{{ config('app.name') }}</h1>
    @if (session('error'))
      <p class="error">{{ session('error') }}</p>
    @endif
    <label>Username
      <input type="text" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit">Sign in</button>
    <button type="button" id="tryDemo" class="btn-secondary">Try the live demo</button>
    <p class="muted demo-hint">The demo signs in as <strong>demo</strong> / <strong>demo</strong> and opens a simulated pool you can fully control.</p>
  </form>
@endsection

@push('scripts')
<script>
  document.getElementById('tryDemo').addEventListener('click', function () {
    var f = this.form;
    f.username.value = 'demo';
    f.password.value = 'demo';
    f.submit();
  });
</script>
@endpush
