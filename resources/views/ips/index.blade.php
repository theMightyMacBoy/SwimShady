@extends('layouts.app')
@section('title', config('app.name').' — IP Security')

@section('content')
  @include('partials.topbar', ['heading' => 'IP Security', 'nav' => 'admin'])

  <main class="history-main">
    @include('partials.flashes')

    <div class="card">
      <div class="label">Add rule</div>
      <p class="muted">A <strong>whitelist</strong> always wins and is never auto-banned. A <strong>block</strong> is a permanent manual ban. Both are kept forever. Auto-bans ({{ (int) config('swimshady.ban_threshold') }} failures in {{ (int) config('swimshady.ban_window_seconds') }}s) expire on their own. Whitelist the pool's public IP so a bad device token cannot ban the house.</p>
      <form method="post" action="{{ route('ips.store') }}" class="user-row">
        @csrf
        <div class="user-head">
          <input type="text" name="ip" class="inline-input" value="{{ old('ip') }}" placeholder="IP address (e.g. 203.0.113.7)" required>
          <select name="kind" class="device-select">
            <option value="allow">whitelist (allow)</option>
            <option value="deny">block (deny)</option>
          </select>
          <input type="text" name="note" class="inline-input" value="{{ old('note') }}" placeholder="note (optional)">
        </div>
        <div class="row-actions"><button type="submit">Add rule</button></div>
      </form>
    </div>

    <div class="card">
      <div class="label">Rules &amp; active bans</div>
      @if ($rules->isEmpty())
        <p class="muted">No rules or bans.</p>
      @else
        <table class="data-table">
          <thead><tr><th>IP</th><th>Kind</th><th>Source</th><th>Reason</th><th>Expires</th><th>Created</th><th></th></tr></thead>
          <tbody>
          @foreach ($rules as $rule)
            <tr>
              <td class="mono">{{ $rule->ip }}</td>
              <td>{{ $rule->kind === 'allow' ? '✅ allow' : '⛔ deny' }}</td>
              <td>{{ $rule->source }}</td>
              <td>{{ $rule->reason }}</td>
              <td class="mono">{{ \App\Support\Display::time($rule->expires_at) }}</td>
              <td class="mono">{{ \App\Support\Display::time($rule->created_at) }}</td>
              <td>
                <form method="post" action="{{ route('ips.destroy', $rule) }}" onsubmit="return confirm('Remove this rule?');">
                  @csrf
                  <button type="submit" class="btn-danger">Remove</button>
                </form>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>

    <div class="card">
      <div class="label">Recent logins (last {{ $hours }} h)</div>
      @if ($logins->isEmpty())
        <p class="muted">No login attempts in this window.</p>
      @else
        <table class="data-table">
          <thead><tr><th>When</th><th>IP</th><th>Username</th><th>Result</th></tr></thead>
          <tbody>
          @foreach ($logins as $login)
            <tr>
              <td class="mono">{{ \App\Support\Display::time($login->created_at) }}</td>
              <td class="mono">{{ $login->ip }}</td>
              <td>{{ $login->username }}</td>
              <td>{{ $login->success ? '✅ ok' : '❌ failed' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>

    <div class="card">
      <div class="label">Recent API requests (last {{ $hours }} h)</div>
      @if ($requests->isEmpty())
        <p class="muted">No API requests in this window.</p>
      @else
        <table class="data-table">
          <thead><tr><th>When</th><th>IP</th><th>Method</th><th>Path</th><th>Status</th></tr></thead>
          <tbody>
          @foreach ($requests as $row)
            <tr>
              <td class="mono">{{ \App\Support\Display::time($row->created_at) }}</td>
              <td class="mono">{{ $row->ip }}</td>
              <td>{{ $row->method }}</td>
              <td class="mono">{{ $row->path }}</td>
              <td>{{ $row->status_code }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </main>
@endsection
