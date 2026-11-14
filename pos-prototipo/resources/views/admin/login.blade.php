<!DOCTYPE html>
<html lang="es-CL">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel · POS Multisede</title>
    <link rel="stylesheet" href="{{ asset('pos/css/admin.css') }}?v={{ filemtime(public_path('pos/css/admin.css')) }}">
</head>
<body>
<div class="login-wrap">
    <form class="login-card" method="POST" action="{{ route('admin.login.post') }}">
        @csrf
        <div class="login-head">
            <h1>Panel de administración</h1>
            <p>Dueña/o y encargado/a</p>
        </div>
        <div class="login-body">
            @if ($errors->any())
                <div class="alerta alerta--err" style="margin:0">{{ $errors->first() }}</div>
            @endif
            <label>Correo
                <input class="inp" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </label>
            <label>Contraseña
                <input class="inp" type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn" type="submit">Ingresar</button>
        </div>
        <div class="login-foot">Las cajeras usan la pantalla POS de su negocio.</div>
    </form>
</div>
</body>
</html>
