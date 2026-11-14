@extends('admin.layout')
@section('titulo', 'Caja ' . $turno->id)
@use('App\Support\Fmt')
@section('contenido')
<div class="adm-title">
    <div>
        <h1>Caja #{{ $turno->id }} · {{ $sede->nombre }}</h1>
        <p>Abierta {{ Fmt::fecha($turno->abierta_en) }} por {{ $nombres[$turno->abierta_por] ?? '—' }}
           @if ($turno->estado === 'cerrada') · cerrada {{ Fmt::fecha($turno->cerrada_en) }} por {{ $nombres[$turno->cerrada_por] ?? '—' }}
           @else · <span class="pill pill--open">Abierta</span> @endif</p>
    </div>
    <a class="btn btn--ghost" href="{{ route('admin.cierres') }}" style="display:inline-flex;align-items:center">← Volver</a>
</div>

@php($d = (int) $turno->descuadre_efectivo_clp)
<div class="grid grid--kpi" style="margin-bottom:16px">
    <div class="card"><div class="kpi-label">Efectivo inicial</div><div class="kpi-value">{{ Fmt::clp($turno->efectivo_inicial_clp) }}</div></div>
    <div class="card"><div class="kpi-label">Efectivo esperado</div>
        <div class="kpi-value">{{ $turno->estado === 'cerrada' ? Fmt::clp($turno->esperado_efectivo_clp) : Fmt::clp($turno->efectivo_inicial_clp + ($porMetodo['efectivo'] ?? 0)) }}</div>
        <div class="kpi-sub">Inicial + ventas en efectivo</div></div>
    <div class="card"><div class="kpi-label">Efectivo contado</div><div class="kpi-value">{{ $turno->estado === 'cerrada' ? Fmt::clp($turno->real_efectivo_clp) : '—' }}</div></div>
    <div class="card"><div class="kpi-label">Descuadre</div>
        <div class="kpi-value" style="color: {{ $d < 0 ? 'var(--red)' : ($d > 0 ? 'var(--amber)' : 'var(--green)') }}">
            {{ $turno->estado === 'cerrada' ? Fmt::clp($d) : '—' }}</div>
        <div class="kpi-sub">{{ $turno->estado !== 'cerrada' ? 'Se calcula al cerrar' : ($d === 0 ? 'Cuadrada' : ($d < 0 ? 'Falta dinero' : 'Sobra dinero')) }}</div></div>
</div>

<div class="grid grid--2" style="margin-bottom:16px">
    <div class="card">
        <h2>Por medio de pago</h2>
        <table class="t">
            <thead><tr><th>Medio</th><th class="num">Vendido</th><th class="num">Contado</th></tr></thead>
            <tbody>
                <tr><td>Efectivo</td><td class="num">{{ Fmt::clp($porMetodo['efectivo'] ?? 0) }}</td><td class="num">{{ $turno->real_efectivo_clp !== null ? Fmt::clp($turno->real_efectivo_clp - $turno->efectivo_inicial_clp) . ' *' : '—' }}</td></tr>
                <tr><td>Tarjetas (débito + crédito)</td><td class="num">{{ Fmt::clp(($porMetodo['debito'] ?? 0) + ($porMetodo['credito'] ?? 0)) }}</td><td class="num">{{ $turno->real_tarjeta_clp !== null ? Fmt::clp($turno->real_tarjeta_clp) : '—' }}</td></tr>
                <tr><td>Transferencia</td><td class="num">{{ Fmt::clp($porMetodo['transferencia'] ?? 0) }}</td><td class="num">{{ $turno->real_transferencia_clp !== null ? Fmt::clp($turno->real_transferencia_clp) : '—' }}</td></tr>
            </tbody>
        </table>
        <p class="muted" style="margin:8px 0 0;font-size:12px">* Contado menos el efectivo inicial.</p>
    </div>
    <div class="card">
        <h2>Por cajera (caja compartida)</h2>
        <table class="t">
            <thead><tr><th>Cajera</th><th class="num">Ventas</th><th class="num">Total</th></tr></thead>
            <tbody>
            @forelse ($porCajera as $c)
                <tr><td>{{ $c->nombre }}</td><td class="num">{{ $c->n }}</td><td class="num">{{ Fmt::clp($c->total) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">Sin ventas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2>Ventas del turno ({{ $ventas->count() }})</h2>
    @if ($ventas->isEmpty())
        <p class="muted">Sin ventas.</p>
    @else
    <table class="t">
        <thead><tr><th>Hora</th><th>Referencia</th><th>Cajera</th><th>Productos</th><th>Pago</th><th class="num">Descuento</th><th class="num">Total</th></tr></thead>
        <tbody>
        @foreach ($ventas as $v)
            <tr>
                <td>{{ Fmt::fecha($v->created_at, 'H:i') }}</td>
                <td class="mono">{{ $v->referencia }}</td>
                <td>{{ $nombres[$v->usuario_id] ?? '—' }}</td>
                <td>{{ ($lineas[$v->id] ?? collect())->map(fn ($l) => $l->cantidad . '× ' . $l->nombre)->join(', ') }}</td>
                <td>{{ ($pagos[$v->id] ?? collect())->map(fn ($p) => Fmt::metodo($p->metodo) . ' ' . Fmt::clp($p->monto_clp))->join(' + ') }}</td>
                <td class="num">{{ $v->descuento_clp ? Fmt::clp($v->descuento_clp) : '—' }}</td>
                <td class="num"><strong>{{ Fmt::clp($v->total_clp) }}</strong></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection
