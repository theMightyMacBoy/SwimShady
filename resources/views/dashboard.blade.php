@extends('layouts.app')

@section('body-attrs')
data-csrf="{{ csrf_token() }}"
data-poll-ms="{{ (int) config('swimshady.ui_poll_ms') }}"
data-device-id="{{ $current?->id }}"
data-can-control="{{ $canControl ? '1' : '0' }}"
@endsection

@section('content')
  @php
    $leading = '';
    if ($devices->count() > 1) {
        $leading .= '<select id="deviceSelect" class="device-select">';
        foreach ($devices as $device) {
            $selected = $current && $device->id === $current->id ? ' selected' : '';
            $leading .= '<option value="'.e($device->id).'"'.$selected.'>'.e($device->name).'</option>';
        }
        $leading .= '</select>';
    }
    if ($current) {
        $leading .= '<button id="refreshBtn" class="btn-secondary" type="button">↻ Refresh</button>';
    }
  @endphp
  @include('partials.topbar', ['heading' => config('app.name'), 'nav' => 'app', 'leading' => $leading])

  @if (! $current)
    <main class="grid">
      <section class="card">
        <div class="label">No device available</div>
        <p class="muted">
          @if (auth()->user()->isAdmin())
            Register your ESP32 on the <a href="{{ route('devices.index') }}">Devices</a> page, then flash its token.
          @else
            No devices are assigned to your account. Ask an admin to assign one.
          @endif
        </p>
      </section>
    </main>
  @else
    @if (! $canControl)
      <p class="readonly-note">Read-only access — you can view this device but not control it.</p>
    @endif
    <main class="grid">
      <section class="card temp-card">
        <div class="label">Water Temperature</div>
        <div id="waterTemp" class="big-temp">--<span class="unit">°F</span></div>
        <div id="updated" class="muted">Connecting…</div>
      </section>

      <section class="card">
        <div class="label">Target Temperature</div>
        <div class="target-row">
          <input type="range" id="targetSlider" min="70" max="104" step="1" value="82" @disabled(! $canControl)>
          <output id="targetValue" class="target-value">82°F</output>
        </div>
        <div class="muted">Heater holds the pool at this temperature (70–104 °F).</div>
      </section>

      <section class="card">
        <div class="label">Pool Heat System</div>
        <button id="systemToggle" class="toggle" data-on="false" @disabled(! $canControl)>OFF</button>
        <div class="muted">Master enable for heating.</div>
      </section>

      <section class="card">
        <div class="label">Pool Pump</div>
        <button id="pumpToggle" class="toggle" data-on="false" @disabled(! $canControl)>OFF</button>
        <div class="muted">Turning off starts the 5-minute cool-down.</div>
      </section>

      <section class="card status-card">
        <div class="label">Pump Status</div>
        <div id="pumpStatus" class="status">—</div>
        <div id="cooldownWrap" class="cooldown hidden">
          Cool-down remaining: <span id="cooldown" class="mono">--:--</span>
          <span class="tip" tabindex="0" role="img" aria-label="Why is the pump still running?"
            data-tip="After the heater shuts off, the pump keeps circulating for 5 minutes so the heat exchanger fully cools before the water stops moving. This protects the heater from residual heat.">i</span>
          @if ($canControl)
            <button id="cancelCooldown" class="btn-secondary">Cancel cool-down</button>
          @endif
        </div>
      </section>

      <section class="card">
        <div class="label">Heater</div>
        <div id="heaterIndicator" class="indicator">
          <span class="dot"></span><span id="heaterText">OFF</span>
          <span id="heaterTip" class="tip hidden" tabindex="0" role="img" aria-label="Why isn't the heater firing?"
            data-tip="The heater ran within the last few minutes. To avoid rapid on/off cycling, it waits a short time before firing again.">i</span>
        </div>
        <div class="muted">Lit while the burner is firing (read-only).</div>
      </section>
    </main>

    <p class="footer">
      <span class="muted">{{ $current->name }} · firmware {{ $current->firmware_version ?? '—' }} · branch {{ $current->channel ?? 'stable' }}</span><br>
      <a href="{{ route('history', ['device' => $current->id]) }}">View temperature history →</a>
    </p>
  @endif

  <div id="toast" class="toast hidden"></div>
@endsection

@push('scripts')
  <script src="{{ asset('assets/app.js') }}?v=9"></script>
@endpush
