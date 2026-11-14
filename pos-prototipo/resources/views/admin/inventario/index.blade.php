@extends('admin.layout')
@section('titulo', 'Inventario')
@use('App\Support\Fmt')
@section('contenido')
<div class="adm-title">
    <div>
        <h1>Inventario · {{ $sede->nombre }}</h1>
        <p>{{ Fmt::num($totales->productos) }} productos · {{ Fmt::num($totales->unidades) }} unidades ·
           valor a precio de venta {{ Fmt::clp($totales->valor_venta) }} ·
           <span style="color:var(--red)">{{ Fmt::num($totales->bajos) }} con stock bajo (≤ {{ $umbral }})</span></p>
    </div>
    <form class="filtros" method="GET">
        <select name="sede" onchange="this.form.submit()">
            @foreach ($sedes as $s)
                <option value="{{ $s->id }}" @selected($sede->id === $s->id)>{{ $s->nombre }}{{ $s->tipo === 'bodega' ? ' (bodega)' : '' }}</option>
            @endforeach
        </select>
        <input type="search" name="q" value="{{ $q }}" placeholder="Nombre, SKU o código">
        <label class="chk"><input type="checkbox" name="bajo" value="1" @checked($soloBajo) onchange="this.form.submit()"> Solo stock bajo</label>
        <button class="btn" type="submit">Buscar</button>
    </form>
</div>

<div class="card" style="margin-bottom:16px">
    @if ($productos->isEmpty())
        <p class="muted">No hay productos que coincidan.</p>
    @else
    <table class="t">
        <thead><tr>
            <th>Producto</th><th>Código</th><th class="num">Precio</th>
            @if ($verCosto)<th class="num">Costo</th><th class="num">Margen</th>@endif
            <th class="num">Stock</th><th>Ajustar stock</th>
        </tr></thead>
        <tbody>
        @foreach ($productos as $p)
            <tr>
                <td>{{ $p->nombre }}<br><span class="mono">{{ $p->sku }}</span></td>
                <td class="mono">{{ $p->codigo_barras }}</td>
                <td class="num">{{ Fmt::clp($p->precio_clp) }}</td>
                @if ($verCosto)
                    <td class="num">{{ Fmt::clp($p->costo_clp) }}</td>
                    <td class="num">{{ $p->precio_clp ? round(100 * ($p->precio_clp - $p->costo_clp) / $p->precio_clp) . ' %' : '—' }}</td>
                @endif
                <td class="num">
                    @if ($p->stock <= $umbral)<span class="pill pill--low">{{ Fmt::num($p->stock) }}</span>
                    @else<strong>{{ Fmt::num($p->stock) }}</strong>@endif
                </td>
                <td>
                    <form class="ajuste" method="POST" action="{{ route('admin.inventario.ajustar') }}">
                        @csrf
                        <input type="hidden" name="sede_id" value="{{ $sede->id }}">
                        <input type="hidden" name="producto_id" value="{{ $p->id }}">
                        <select name="tipo" aria-label="Tipo de ajuste">
                            <option value="entrada">+ Entrada</option>
                            <option value="salida">− Salida</option>
                            <option value="fijar">= Conteo</option>
                        </select>
                        <input type="number" name="cantidad" min="0" required placeholder="Cant." aria-label="Cantidad">
                        <input type="text" name="motivo" required minlength="3" maxlength="160" placeholder="Motivo (ej. merma, recepción)" aria-label="Motivo">
                        <button class="btn btn--sm" type="submit">Aplicar</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="paginacion">
        <span>{{ $productos->firstItem() }}–{{ $productos->lastItem() }} de {{ Fmt::num($productos->total()) }}</span>
        @if ($productos->previousPageUrl())<a class="btn btn--ghost btn--sm" href="{{ $productos->previousPageUrl() }}" style="display:inline-flex;align-items:center">← Anterior</a>@endif
        @if ($productos->nextPageUrl())<a class="btn btn--ghost btn--sm" href="{{ $productos->nextPageUrl() }}" style="display:inline-flex;align-items:center">Siguiente →</a>@endif
    </div>
    @endif
</div>

<div class="card">
    <h2>Últimos ajustes en {{ $sede->nombre }}</h2>
    @if ($movimientos->isEmpty())
        <p class="muted">Todavía no hay ajustes manuales en esta sede. Las ventas descuentan stock solas.</p>
    @else
    <table class="t">
        <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th class="num">Antes</th><th class="num">Después</th><th class="num">Diferencia</th><th>Motivo</th><th>Quién</th></tr></thead>
        <tbody>
        @foreach ($movimientos as $m)
            <tr>
                <td>{{ Fmt::fecha($m->created_at) }}</td>
                <td>{{ $m->producto }}</td>
                <td>{{ ['entrada' => 'Entrada', 'salida' => 'Salida', 'fijar' => 'Conteo'][$m->tipo] }}</td>
                <td class="num">{{ Fmt::num($m->cantidad_anterior) }}</td>
                <td class="num">{{ Fmt::num($m->cantidad_nueva) }}</td>
                <td class="num"><span class="pill {{ $m->diferencia < 0 ? 'pill--neg' : 'pill--ok' }}">{{ $m->diferencia > 0 ? '+' : '' }}{{ Fmt::num($m->diferencia) }}</span></td>
                <td>{{ $m->motivo }}</td>
                <td>{{ $m->usuario }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
@endsection
