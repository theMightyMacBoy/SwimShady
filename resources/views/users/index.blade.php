@extends('layouts.app')
@section('title', config('app.name').' — Users')

@section('content')
  @include('partials.topbar', ['heading' => 'Users', 'nav' => 'admin'])

  <main class="history-main">
    @include('partials.flashes')

    <div class="card">
      <div class="label">Existing users</div>
      <p class="muted">Admins see and control every device. Standard users control, and read-only users only view, the devices checked below.</p>

      @foreach ($users as $user)
        @php $assigned = $user->devices->pluck('id')->all(); @endphp
        <form method="post" action="{{ route('users.update', $user) }}" class="user-row">
          @csrf
          <div class="user-head">
            <strong>{{ $user->username }}</strong>
            <select name="role" class="device-select">
              @foreach ($roles as $role)
                <option value="{{ $role }}" @selected($user->role === $role)>{{ $role }}</option>
              @endforeach
            </select>
            <input type="password" name="password" class="inline-input" placeholder="reset password (optional)" autocomplete="new-password">
            <span class="muted">last login: {{ $user->last_login_at ? \App\Support\Display::time($user->last_login_at) : 'never' }}</span>
          </div>
          <div class="device-assign">
            @forelse ($devices as $device)
              <label class="chk">
                <input type="checkbox" name="devices[]" value="{{ $device->id }}" @checked(in_array($device->id, $assigned, true))>
                {{ $device->name }}
              </label>
            @empty
              <span class="muted">No devices registered yet.</span>
            @endforelse
            <span class="muted assign-note">(admins see all devices regardless)</span>
          </div>
          <div class="row-actions">
            <button type="submit" class="btn-secondary">Save</button>
          </div>
          <button type="submit" form="del-{{ $user->id }}" class="btn-danger">Delete</button>
        </form>
        <form id="del-{{ $user->id }}" method="post" action="{{ route('users.destroy', $user) }}"
              onsubmit="return confirm('Delete user {{ $user->username }}?');" hidden>
          @csrf
        </form>
      @endforeach
    </div>

    <div class="card">
      <div class="label">Add user</div>
      <form method="post" action="{{ route('users.store') }}" class="user-row">
        @csrf
        <div class="user-head">
          <input type="text" name="username" class="inline-input" placeholder="username" required>
          <input type="password" name="password" class="inline-input" placeholder="password (min 8)" required minlength="8" autocomplete="new-password">
          <select name="role" class="device-select">
            <option value="standard">standard</option>
            <option value="readonly">readonly</option>
            <option value="admin">admin</option>
          </select>
        </div>
        <div class="device-assign">
          @foreach ($devices as $device)
            <label class="chk"><input type="checkbox" name="devices[]" value="{{ $device->id }}"> {{ $device->name }}</label>
          @endforeach
        </div>
        <div class="row-actions"><button type="submit">Add user</button></div>
      </form>
    </div>
  </main>
@endsection
