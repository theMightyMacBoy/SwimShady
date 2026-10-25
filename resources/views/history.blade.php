@extends('layouts.app')
@section('title', config('app.name').' — History')

@section('body-attrs')
data-device-id="{{ $current?->id }}"
@endsection

@section('content')
  @include('partials.topbar', [
    'heading' => 'Temperature History'.($current ? ' — '.$current->name : ''),
    'nav' => 'bare',
    'backLabel' => '← Back',
    'backUrl' => route('dashboard', $current ? ['device' => $current->id] : []),
  ])

  <main class="history-main">
    <div class="range-tabs">
      <button class="range-btn active" data-hours="24">24h</button>
      <button class="range-btn" data-hours="72">3d</button>
      <button class="range-btn" data-hours="168">7d</button>
      <button class="range-btn" data-hours="720">30d</button>
    </div>
    <div class="card">
      <canvas id="chart" width="900" height="360"></canvas>
      <div id="chartEmpty" class="muted hidden">No history recorded yet.</div>
    </div>
    <p class="muted legend">
      <span class="key key-water"></span> Water temp
      <span class="key key-target"></span> Target
      <span class="key key-heat"></span> Heater on
    </p>
  </main>
@endsection

@push('scripts')
  <script src="{{ asset('assets/history.js') }}?v=3"></script>
@endpush
