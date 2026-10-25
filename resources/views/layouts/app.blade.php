<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', config('app.name'))</title>
  <link rel="stylesheet" href="{{ asset('assets/style.css') }}">
</head>
<body @yield('body-attrs')>
  @yield('content')
  @stack('scripts')
</body>
</html>
