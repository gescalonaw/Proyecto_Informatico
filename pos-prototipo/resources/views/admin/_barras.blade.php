{{-- Barras horizontales: $filas con ->etiqueta, ->total, ->n --}}
@php($max = max(1, (int) $filas->max('total')))
@forelse ($filas as $f)
    <div class="barra">
        <div class="barra-lbl" title="{{ $f->etiqueta }}">{{ $f->etiqueta }}</div>
        <div class="barra-track"><div class="barra-fill" style="width: {{ round(100 * $f->total / $max, 1) }}%"></div></div>
        <div class="barra-val">{{ \App\Support\Fmt::clp($f->total) }} <span class="muted">· {{ $f->n }}</span></div>
    </div>
@empty
    <p class="muted">Sin ventas en el periodo.</p>
@endforelse
