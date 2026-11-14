@extends('admin.layout')
@section('titulo', 'Ventas')
@use('App\Support\Fmt')
@section('contenido')
<div class="adm-title">
    <div>
        <h1>Ventas</h1>
        <p>{{ $periodo->etiqueta() }} · {{ $periodo->desdeLocal->format('d/m') }} al {{ $periodo->hastaLocal->format('d/m/Y') }}
           · {{ $sedeId ? $sedes->firstWhere('id', $sedeId)->nombre : 'Todas las sedes' }}</p>
    </div>
    <form class="filtros" method="GET">
        <select name="periodo" onchange="this.form.submit()">
            @foreach (\App\Support\Periodo::OPCIONES as $k => $v)
                <option value="{{ $k }}" @selected($periodo->clave === (string) $k)>{{ $v }}</option>
            @endforeach
        </select>
        <select name="sede" onchange="this.form.submit()">
            <option value="0">Todas las sedes</option>
            @foreach ($sedes as $s)
                <option value="{{ $s->id }}" @selected($sedeId === $s->id)>{{ $s->nombre }}</option>
            @endforeach
        </select>
        <noscript><button class="btn">Ver</button></noscript>
    </form>
</div>

@if ($cajasAbiertas->isNotEmpty())
    <div class="alerta alerta--warn">
        Caja abierta en: {{ $cajasAbiertas->map(fn ($c) => $c->sede . ' (desde ' . Fmt::fecha($c->abierta_en, 'd/m H:i') . ')')->join(', ') }}
    </div>
@endif

<div class="grid grid--kpi" style="margin-bottom:16px">
    <div class="card"><div class="kpi-label">Total vendido</div><div class="kpi-value">{{ Fmt::clp($kpi['total']) }}</div>
        <div class="kpi-sub">Descuentos: {{ Fmt::clp($kpi['descuentos']) }}</div></div>
    <div class="card"><div class="kpi-label">N° de ventas</div><div class="kpi-value">{{ Fmt::num($kpi['n']) }}</div>
        <div class="kpi-sub">Ticket promedio {{ Fmt::clp($kpi['ticket']) }}</div></div>
    <div class="card"><div class="kpi-label">Descuadre de caja</div>
        <div class="kpi-value" style="color: {{ $descuadre->neto < 0 ? 'var(--red)' : ($descuadre->neto > 0 ? 'var(--amber)' : 'inherit') }}">{{ Fmt::clp($descuadre->neto) }}</div>
        <div class="kpi-sub">{{ (int) $descuadre->con_descuadre }} de {{ (int) $descuadre->cierres }} cierres con diferencia ·
            <a href="{{ route('admin.cierres', ['periodo' => $periodo->clave, 'sede' => $sedeId ?: null]) }}">ver</a></div></div>
    <div class="card"><div class="kpi-label">Productos con stock bajo</div>
        <div class="kpi-value" style="color: {{ $stockBajo ? 'var(--red)' : 'inherit' }}">{{ Fmt::num($stockBajo) }}</div>
        <div class="kpi-sub">≤ {{ \App\Http\Controllers\Admin\InventarioController::UMBRAL_BAJO }} unidades ·
            <a href="{{ route('admin.inventario', ['bajo' => 1, 'sede' => $sedeId ?: null]) }}">ver</a></div></div>
</div>

<div class="card" style="margin-bottom:16px">
    <h2>Ventas por día</h2>
    @php($maxDia = max(1, collect($porDia)->max('total')))
    <div class="dias">
        @foreach ($porDia as $dia => $d)
            <div class="dia" title="{{ \Illuminate\Support\Carbon::parse($dia)->format('d/m') }}: {{ Fmt::clp($d['total']) }} en {{ $d['n'] }} ventas">
                @if (count($porDia) <= 10)<div class="dia-val">{{ $d['total'] ? Fmt::clp($d['total']) : '' }}</div>@endif
                <div class="dia-col" style="height: {{ round(100 * $d['total'] / $maxDia, 1) }}%"></div>
                <div class="dia-lbl">{{ count($porDia) > 10 ? \Illuminate\Support\Carbon::parse($dia)->format('d') : \Illuminate\Support\Carbon::parse($dia)->locale('es')->isoFormat('ddd D/M') }}</div>
            </div>
        @endforeach
    </div>
</div>

<div class="grid grid--2" style="margin-bottom:16px">
    <div class="card"><h2>Por sede</h2>@include('admin._barras', ['filas' => $porSede])</div>
    <div class="card"><h2>Por medio de pago</h2>@include('admin._barras', ['filas' => $porMetodo])</div>
</div>

<div class="grid grid--3">
    <div class="card">
        <h2>Productos más vendidos</h2>
        @if ($topProductos->isEmpty())
            <p class="muted">Sin ventas en el periodo.</p>
        @else
        <table class="t">
            <thead><tr><th>Producto</th><th class="num">Unidades</th><th class="num">Venta</th></tr></thead>
            <tbody>
            @foreach ($topProductos as $p)
                <tr><td>{{ $p->nombre }} <span class="mono">{{ $p->sku }}</span></td>
                    <td class="num">{{ Fmt::num($p->unidades) }}</td><td class="num">{{ Fmt::clp($p->total) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>
    <div class="card"><h2>Por cajera</h2>@include('admin._barras', ['filas' => $porCajera])</div>
</div>
@endsection
