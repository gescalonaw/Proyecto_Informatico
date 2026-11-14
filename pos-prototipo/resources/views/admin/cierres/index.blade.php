@extends('admin.layout')
@section('titulo', 'Cierres de caja')
@use('App\Support\Fmt')
@section('contenido')
<div class="adm-title">
    <div>
        <h1>Cierres de caja</h1>
        <p>Arqueo ciego: la cajera cuenta sin ver lo esperado; aquí se ve la diferencia.</p>
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
        <label class="chk"><input type="checkbox" name="descuadre" value="1" @checked($soloDescuadre) onchange="this.form.submit()"> Solo con descuadre</label>
    </form>
</div>

<div class="card">
    @if ($turnos->isEmpty())
        <p class="muted">No hay cajas en este periodo.</p>
    @else
    <table class="t">
        <thead><tr>
            <th>Sede</th><th>Apertura</th><th>Cierre</th><th class="num">Ventas</th><th class="num">Vendido</th>
            <th class="num">Efectivo esperado</th><th class="num">Contado</th><th class="num">Descuadre</th><th></th>
        </tr></thead>
        <tbody>
        @foreach ($turnos as $t)
            @php($r = $resumen[$t->id] ?? null)
            <tr>
                <td><strong>{{ $t->sede }}</strong></td>
                <td>{{ Fmt::fecha($t->abierta_en) }}<br><span class="muted">{{ $t->abrio }}</span></td>
                <td>
                    @if ($t->estado === 'abierta')
                        <span class="pill pill--open">Abierta</span>
                    @else
                        {{ Fmt::fecha($t->cerrada_en) }}<br><span class="muted">{{ $t->cerro }}</span>
                    @endif
                </td>
                <td class="num">{{ $r->n ?? 0 }}</td>
                <td class="num">{{ Fmt::clp($r->total ?? 0) }}</td>
                <td class="num">{{ $t->estado === 'cerrada' ? Fmt::clp($t->esperado_efectivo_clp) : '—' }}</td>
                <td class="num">{{ $t->estado === 'cerrada' ? Fmt::clp($t->real_efectivo_clp) : '—' }}</td>
                <td class="num">
                    @if ($t->estado === 'cerrada')
                        @php($d = (int) $t->descuadre_efectivo_clp)
                        <span class="pill {{ $d === 0 ? 'pill--ok' : ($d < 0 ? 'pill--neg' : 'pill--pos') }}">
                            {{ $d === 0 ? 'Cuadrada' : ($d < 0 ? 'Falta ' . Fmt::clp(-$d) : 'Sobra ' . Fmt::clp($d)) }}
                        </span>
                    @else — @endif
                </td>
                <td class="num"><a href="{{ route('admin.cierres.show', $t->id) }}">Detalle →</a></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="paginacion">
        <span>{{ $turnos->firstItem() }}–{{ $turnos->lastItem() }} de {{ $turnos->total() }}</span>
        @if ($turnos->previousPageUrl())<a class="btn btn--ghost btn--sm" href="{{ $turnos->previousPageUrl() }}" style="display:inline-flex;align-items:center">← Anterior</a>@endif
        @if ($turnos->nextPageUrl())<a class="btn btn--ghost btn--sm" href="{{ $turnos->nextPageUrl() }}" style="display:inline-flex;align-items:center">Siguiente →</a>@endif
    </div>
    @endif
</div>
@endsection
