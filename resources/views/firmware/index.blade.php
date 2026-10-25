@extends('layouts.app')
@section('title', config('app.name').' — Firmware')

@section('content')
  @include('partials.topbar', ['heading' => 'Firmware', 'nav' => 'admin'])

  <main class="history-main">
    @include('partials.flashes')

    <div class="card">
      <div class="label">Upload firmware (.ota.bin)</div>
      <p class="muted">Build with <code>esphome compile ESP32/pool-temp.yaml</code> →
        upload <code>firmware.ota.bin</code>. The <strong>version</strong> must match the
        <code>firmware_version</code> substitution in that build. The latest upload on a
        channel becomes the target; idle devices on that channel self-update.</p>
      <form method="post" action="{{ route('firmware.store') }}" enctype="multipart/form-data" class="add-device" style="flex-direction:column;align-items:stretch;max-width:520px">
        @csrf
        <label>Version <input type="text" name="version" placeholder="2026.06.08.1" required></label>
        <label>Channel
          <select name="channel" class="device-select">
            <option value="stable">stable</option>
            <option value="dev">dev</option>
          </select>
        </label>
        <label>Notes (optional) <input type="text" name="notes" placeholder="what changed"></label>
        <label>File <input type="file" name="file" accept=".bin" required></label>
        <button type="submit">Upload</button>
      </form>
    </div>

    <div class="card">
      <div class="label">Uploaded builds <span class="muted">(newest per channel = target)</span></div>
      @if ($firmwares->isEmpty())
        <p class="muted">No firmware uploaded yet.</p>
      @else
        @php $badged = []; @endphp
        <table class="devices">
          <thead><tr><th>Version</th><th>Channel</th><th>Size</th><th>MD5</th><th>Uploaded</th><th>Notes</th><th></th></tr></thead>
          <tbody>
          @foreach ($firmwares as $firmware)
            @php $isTarget = empty($badged[$firmware->channel]); $badged[$firmware->channel] = true; @endphp
            <tr>
              <td>{{ $firmware->version }} @if ($isTarget)<span class="badge badge-on">target</span>@endif</td>
              <td class="muted">{{ $firmware->channel }}</td>
              <td class="muted">{{ number_format($firmware->size / 1024, 0) }} KB</td>
              <td class="muted"><code>{{ substr($firmware->md5, 0, 10) }}…</code></td>
              <td class="muted">{{ \App\Support\Display::time($firmware->created_at) }}</td>
              <td class="muted">{{ $firmware->notes }}</td>
              <td class="row-actions">
                <form method="post" action="{{ route('firmware.destroy', $firmware) }}" onsubmit="return confirm('Delete firmware {{ $firmware->version }}?');">
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
  </main>
@endsection
