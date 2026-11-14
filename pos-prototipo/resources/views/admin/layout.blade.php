<!DOCTYPE html>
<html lang="es-CL">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', 'Panel') · {{ $negocioPanel->nombre ?? 'POS Multisede' }}</title>
    <link rel="stylesheet" href="{{ asset('pos/css/admin.css') }}?v={{ filemtime(public_path('pos/css/admin.css')) }}">
</head>
<body>
<header class="adm-header">
    <div class="adm-brand">POS Multisede <small>{{ $negocioPanel->nombre ?? '' }}</small></div>
    <nav class="adm-nav">
        <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'on' : '' }}">Ventas</a>
        <a href="{{ route('admin.cierres') }}" class="{{ request()->routeIs('admin.cierres*') ? 'on' : '' }}">Cierres de caja</a>
        <a href="{{ route('admin.inventario') }}" class="{{ request()->routeIs('admin.inventario*') ? 'on' : '' }}">Inventario</a>
        @if ($negocioPanel)
            <a href="{{ url('/pos/' . $negocioPanel->slug) }}" target="_blank">Abrir POS ↗</a>
        @endif
    </nav>
    <div class="adm-user">
        <span>{{ $usuarioPanel->name }} · {{ $usuarioPanel->rol === 'dueno' ? 'Dueña/o' : 'Encargado/a' }}</span>
        <form method="POST" action="{{ route('admin.logout') }}">@csrf<button type="submit">Salir</button></form>
    </div>
</header>
<main class="adm-main">
    @if (session('ok'))
        <div class="alerta alerta--ok">✓ {{ session('ok') }}</div>
    @endif
    @if ($errors->any())
        <div class="alerta alerta--err">{{ $errors->first() }}</div>
    @endif
    @yield('contenido')
</main>
</body>
</html>
