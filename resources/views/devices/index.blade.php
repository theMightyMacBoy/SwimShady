@extends('layouts.app')
@section('title', config('app.name').' — Devices')

@section('content')
  @include('partials.topbar', ['heading' => 'Devices', 'nav' => 'admin'])

  <main class="history-main">
    @include('partials.flashes')

    @if (session('new_token'))
      <div class="card token-banner">
        <div class="label">Device token for {{ session('new_token_uid') }}</div>
        <p class="muted">Copy this now — shown only once. Put it in the firmware (<code>secrets.yaml</code> → <code>swimshady_token</code>).</p>
        <code class="token">{{ session('new_token') }}</code>
      </div>
    @endif

    <div class="card">
      <div class="label">Registered devices</div>
      @if ($devices->isEmpty())
        <p class="muted">No devices yet. Add one below, then flash its token into the firmware.</p>
      @else
        <table class="devices">
          <thead><tr><th>Name</th><th>UID</th><th>Status</th><th>Firmware</th><th>Channel</th><th>Last seen</th><th></th></tr></thead>
          <tbody>
          @foreach ($devices as $device)
            <tr>
              <td>{{ $device->name }}</td>
              <td><code>{{ $device->uid }}</code></td>
              <td>
                @if ($device->connectionStatus() === 'online')
                  <span class="badge badge-on">online</span>
                @elseif ($device->connectionStatus() === 'never')
                  <span class="badge badge-off">Never Connected</span>
                @else
                  <span class="badge badge-off">offline</span>
                @endif
              </td>
              <td class="muted">{{ $device->firmware_version ?? '—' }}</td>
              <td>
                <form method="post" action="{{ route('devices.channel', $device) }}">
                  @csrf
                  <select name="channel" class="device-select" onchange="this.form.submit()">
                    <option value="stable" @selected($device->channel === 'stable')>stable</option>
                    <option value="dev" @selected($device->channel === 'dev')>dev</option>
                  </select>
                </form>
              </td>
              <td class="muted">{{ $device->last_seen_at ? \App\Support\Display::time($device->last_seen_at) : 'never' }}</td>
              <td class="row-actions">
                <form method="post" action="{{ route('devices.token', $device) }}" onsubmit="return confirm('Regenerate token? The old one stops working.');">
                  @csrf
                  <button class="btn-secondary" type="submit">New token</button>
                </form>
                <form method="post" action="{{ route('devices.destroy', $device) }}" onsubmit="return confirm('Delete this device and all its history?');">
                  @csrf
                  <button class="btn-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      @endif
    </div>

    <div class="card">
      <div class="label">Add a device</div>
      <form method="post" action="{{ route('devices.store') }}" class="add-device">
        @csrf
        <label>Name <input type="text" name="name" value="{{ old('name') }}" placeholder="Pool ESP32" required></label>
        <label>UID <input type="text" name="uid" value="{{ old('uid') }}" placeholder="pool-esp32" required></label>
        <button type="submit">Add device</button>
      </form>
    </div>
  </main>
@endsection
