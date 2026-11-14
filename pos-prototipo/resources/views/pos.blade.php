{{--
    Pantalla POS reciclada del plugin "POS Sistema de Inventario Multisede" (frontend/pos-template.php).
    Cambios respecto del plugin:
      - POS_CONFIG sale de Laravel y del negocio de la URL, no de WordPress/WooCommerce.
      - El selector de sedes solo muestra las del negocio de la URL (multi-tenant).
      - Cambio, devolución, reimpresión y favoritos quedan fuera de esta iteración (Should).
--}}
<!DOCTYPE html>
<html lang="es-CL" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1e3a5f">
    <meta name="mobile-web-app-capable" content="yes">
    <title>POS — {{ $tenant->nombre }}</title>

    <link rel="stylesheet" href="{{ asset('pos/css/pos-style.css') }}?v={{ filemtime(public_path('pos/css/pos-style.css')) }}">
    <style>
        /* Módulos del plugin que no entran en esta iteración */
        #pos-btn-cambio, #pos-btn-reimpresion { display: none !important; }
        /* Datos de prueba sin fotos: tarjeta compacta en vez de un recuadro gris vacío */
        .pos-product-img-wrap:has(.pos-product-no-img) { height: 34px; min-height: 34px; aspect-ratio: auto; }
        .pos-product-no-img { display: none; }
    </style>

    <script>
    window.POS_CONFIG = {
        apiBase      : @json(url('/api/pos/v1')),
        siteUrl      : @json(url('/')),
        posUrl       : @json(url('/pos/' . $tenant->slug)),
        loginUrl     : @json(url('/pos/' . $tenant->slug)),
        negocio      : @json($tenant->slug),
        version      : 'laravel-e3',
        currency     : '$',
        currencyPos  : 'left',
        decimals     : 0,
        decimalSep   : ',',
        thousandSep  : '.',
        nombreNegocio: @json($tenant->nombre),
    };
    </script>
</head>
<body class="pos-app-body" id="pos-app">
    <div id="pos-root">
        <div id="pos-boot-screen" class="pos-boot">
            <div class="pos-boot-inner">
                <div class="pos-boot-logo">
                    <span class="pos-boot-icon"></span>
                    <h1>POS</h1>
                    <p>{{ $tenant->nombre }}</p>
                </div>
                <div class="pos-boot-spinner">
                    <div class="pos-spinner-ring"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Orden importa: auth → productos → carrito → pago → main --}}
    @foreach (['pos-auth', 'pos-productos', 'pos-carrito', 'pos-pago', 'pos-main'] as $js)
        <script src="{{ asset("pos/js/$js.js") }}?v={{ filemtime(public_path("pos/js/$js.js")) }}"></script>
    @endforeach
</body>
</html>
