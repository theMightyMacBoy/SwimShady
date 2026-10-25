<header class="topbar">
  <h1>{{ $heading }}</h1>
  <div class="topbar-right">
    @isset($leading)
      {!! $leading !!}
    @endisset
    @if (($nav ?? 'app') === 'app')
      <span id="conn" class="conn conn-unknown" title="Connection">●</span>
      <span class="user">{{ auth()->user()->username }} · {{ auth()->user()->role }}</span>
      @if (auth()->user()->isAdmin())
        @include('partials.admin-links')
      @endif
      <a class="logout" href="{{ route('account') }}">Account</a>
    @elseif (($nav ?? '') === 'admin')
      @include('partials.admin-links')
      <a class="logout" href="{{ route('dashboard') }}">← Dashboard</a>
    @else
      <a class="logout" href="{{ $backUrl ?? route('dashboard') }}">{{ $backLabel ?? '← Dashboard' }}</a>
    @endif
    @if (($nav ?? 'app') !== 'bare')
      <form method="post" action="{{ route('logout') }}" style="display:inline">
        @csrf
        <button type="submit" class="logout">Sign out</button>
      </form>
    @endif
  </div>
</header>
