/**
 * pos-pago.js – Modal de pago, comprobante y cierre de caja
 */

const PosPago = ( () => {
    'use strict';

    const fmt = ( n ) => PosProductos.formatPrice( n );

    // ── Modal pago ────────────────────────────────────────────
    function openPago() {
        if ( PosCarrito.isEmpty() ) return;

        const overlay = document.getElementById( 'pos-modal-pago-overlay' );
        if ( ! overlay ) return;

        // Limpiar inputs de pago.
        [ 'pos-pago-efectivo', 'pos-pago-debito', 'pos-pago-credito', 'pos-pago-transferencia' ].forEach( id => {
            const el = document.getElementById( id );
            if ( el ) el.value = '';
        } );

        const comprobanteOpen = document.getElementById( 'pos-pago-comprobante' );
        if ( comprobanteOpen ) comprobanteOpen.value = '';

        // Sincronizar el input de descuento del modal con el descuento actual del carrito.
        const descInput = document.getElementById( 'pos-pago-discount-input' );
        if ( descInput ) {
            const pct = PosCarrito.getDescuentoPct();
            descInput.value = pct > 0 ? pct : '';
        }

        // Bug 1: limpiar texto del vuelto y ocultarlo con style.display + hidden
        // (evita que CSS heredado lo deje visible con monto viejo).
        const vueltoRowOpen = document.getElementById( 'pos-pago-vuelto-row' );
        const vueltoAmtOpen = document.getElementById( 'pos-pago-vuelto-amount' );
        if ( vueltoRowOpen ) { vueltoRowOpen.style.display = 'none'; vueltoRowOpen.setAttribute( 'hidden', '' ); }
        if ( vueltoAmtOpen ) vueltoAmtOpen.textContent = '';

        actualizarTotalPago();
        recalcularPago();
        overlay.removeAttribute( 'hidden' );
        document.getElementById( 'pos-pago-efectivo' )?.focus();
    }

    // Refresca el total y el desglose del modal de pago según el descuento actual.
    function actualizarTotalPago() {
        const subtotal  = PosCarrito.getSubtotal();
        const descPct   = PosCarrito.getDescuentoPct();
        const descMonto = PosCarrito.getDescuentoMonto();
        const total     = PosCarrito.getTotal();

        const totalEl = document.getElementById( 'pos-pago-total-amount' );
        if ( totalEl ) totalEl.textContent = fmt( total );

        const breakdown = document.getElementById( 'pos-pago-breakdown' );
        if ( breakdown ) {
            if ( descPct > 0 ) {
                breakdown.removeAttribute( 'hidden' );
                breakdown.style.display = '';
                document.getElementById( 'pos-pago-subtotal' ).textContent       = fmt( subtotal );
                document.getElementById( 'pos-pago-discount-pct' ).textContent   = descPct;
                document.getElementById( 'pos-pago-discount-amount' ).textContent = '−' + fmt( descMonto );
            } else {
                breakdown.setAttribute( 'hidden', '' );
                breakdown.style.display = 'none';
            }
        }

        // Marcar visualmente el preset activo.
        document.querySelectorAll( '#pos-modal-pago-overlay .pos-discount-preset' ).forEach( btn => {
            const p = parseInt( btn.dataset.pct, 10 );
            btn.classList.toggle( 'pos-discount-preset--active', p === descPct );
        } );
    }

    function closePago() {
        document.getElementById( 'pos-modal-pago-overlay' )?.setAttribute( 'hidden', '' );
    }

    /**
     * Lee los 3 inputs de pago y devuelve los pagos no-cero.
     * @returns {Array<{metodo:string, monto:number}>}
     */
    function getPagosActuales() {
        const pagos = [];
        document.querySelectorAll( '.pos-pago-input' ).forEach( input => {
            const monto = parseFloat( input.value || 0 );
            if ( monto > 0 ) {
                pagos.push( { metodo: input.dataset.metodo, monto } );
            }
        } );
        return pagos;
    }

    /**
     * Recalcula:
     *   - Total ingresado (suma de los inputs)
     *   - Estado: Falta $X (rojo) / Vuelto $X (verde, solo si efectivo cubre el exceso) / OK
     *   - Habilitar/deshabilitar botón Pagar
     *   - Mostrar campo Referencia si hay débito o crédito
     */
    function recalcularPago() {
        const total    = PosCarrito.getTotal();
        const pagos    = getPagosActuales();
        const ingresado = pagos.reduce( ( s, p ) => s + p.monto, 0 );
        const diferencia = ingresado - total;

        const ingresadoEl = document.getElementById( 'pos-pago-ingresado' );
        const estadoLabel = document.getElementById( 'pos-pago-estado-label' );
        const estadoAmount = document.getElementById( 'pos-pago-estado-amount' );
        const estadoRow   = document.getElementById( 'pos-pago-estado-row' );
        const vueltoRow   = document.getElementById( 'pos-pago-vuelto-row' );
        const vueltoAmountEl = document.getElementById( 'pos-pago-vuelto-amount' );
        const submitBtn   = document.getElementById( 'pos-pago-submit' );

        if ( ingresadoEl ) ingresadoEl.textContent = fmt( ingresado );

        // Ocultar fila de vuelto y limpiar su texto al inicio de cada cálculo
        // (style.display + hidden para evitar que CSS heredado lo muestre).
        if ( vueltoRow ) { vueltoRow.style.display = 'none'; vueltoRow.setAttribute( 'hidden', '' ); }
        if ( vueltoAmountEl ) vueltoAmountEl.textContent = '';

        if ( ! estadoRow ) return;

        if ( ingresado <= 0 ) {
            estadoLabel.textContent  = 'Falta:';
            estadoAmount.textContent = fmt( total );
            estadoRow.className      = 'pos-pago-resumen-row pos-pago-resumen-row--main pos-pago-falta';
        } else if ( diferencia < 0 ) {
            estadoLabel.textContent  = 'Falta:';
            estadoAmount.textContent = fmt( Math.abs( diferencia ) );
            estadoRow.className      = 'pos-pago-resumen-row pos-pago-resumen-row--main pos-pago-falta';
        } else if ( Math.abs( diferencia ) < 0.01 ) {
            estadoLabel.textContent  = 'Cuadrado:';
            estadoAmount.textContent = '✓ ' + fmt( total );
            estadoRow.className      = 'pos-pago-resumen-row pos-pago-resumen-row--main pos-pago-ok';
        } else {
            // Sobrante: solo es vuelto si fue en efectivo y los demás métodos no exceden.
            const efectivo = pagos.find( p => p.metodo === 'efectivo' );
            const noEfectivo = pagos.filter( p => p.metodo !== 'efectivo' ).reduce( ( s, p ) => s + p.monto, 0 );

            if ( efectivo && noEfectivo <= total ) {
                // Pago completo + vuelto en efectivo.
                estadoLabel.textContent  = 'Cuadrado:';
                estadoAmount.textContent = '✓ ' + fmt( total );
                estadoRow.className      = 'pos-pago-resumen-row pos-pago-resumen-row--main pos-pago-ok';
                // Mostrar fila de vuelto separada.
                if ( vueltoRow ) { vueltoRow.removeAttribute( 'hidden' ); vueltoRow.style.display = ''; }
                if ( vueltoAmountEl ) vueltoAmountEl.textContent = fmt( diferencia );
            } else {
                // Tarjeta o transferencia exceden el total → no dan vuelto.
                estadoLabel.textContent  = 'Sobrepago (sin vuelto):';
                estadoAmount.textContent = fmt( diferencia );
                estadoRow.className      = 'pos-pago-resumen-row pos-pago-resumen-row--main pos-pago-falta';
            }
        }

        // El campo de comprobante solo tiene sentido si se está cobrando algo por
        // transferencia; en cualquier otro caso estorba.
        const filaComprobante = document.getElementById( 'pos-pago-comprobante-row' );
        if ( filaComprobante ) {
            const hayTransferencia = pagos.some( p => p.metodo === 'transferencia' );
            filaComprobante.hidden = ! hayTransferencia;
            if ( ! hayTransferencia ) {
                const inp = document.getElementById( 'pos-pago-comprobante' );
                if ( inp ) inp.value = '';
            }
        }

        // Habilitar botón pagar:
        //   - Hay al menos un pago
        //   - El efectivo puede sobrar (vuelto); el resto de los métodos (tarjetas
        //     y transferencia) no pueden exceder el total: no dan vuelto.
        const totalNoEfectivo = pagos.filter( p => p.metodo !== 'efectivo' ).reduce( ( s, p ) => s + p.monto, 0 );
        const cubreTotal = ingresado >= total - 0.01;
        const tarjetaOk  = totalNoEfectivo <= total + 0.01;
        if ( submitBtn ) {
            submitBtn.disabled = ! ( cubreTotal && tarjetaOk && pagos.length > 0 );
        }
    }

    // ── Completar venta ───────────────────────────────────────
    async function completarVenta() {
        const subtotal   = PosCarrito.getSubtotal();
        const descPct    = PosCarrito.getDescuentoPct();
        const descMonto  = PosCarrito.getDescuentoMonto();
        const total      = PosCarrito.getTotal();
        const items      = PosCarrito.getItems();
        const pagos      = getPagosActuales();

        if ( pagos.length === 0 ) {
            PosToast.show( 'Ingresa al menos un monto de pago.', 'error' );
            return;
        }

        const ingresado = pagos.reduce( ( s, p ) => s + p.monto, 0 );
        if ( ingresado < total - 0.01 ) {
            PosToast.show( 'El monto recibido es insuficiente.', 'error' );
            return;
        }

        // Si hay efectivo + sobrante, ajustar el monto del efectivo para que la suma cuadre exactamente.
        // (El backend valida que la suma = total con tolerancia $1).
        const sobrante = ingresado - total;
        let pagosNormalizados = pagos.map( p => ( { ...p } ) );
        if ( sobrante > 0.01 ) {
            const idxEf = pagosNormalizados.findIndex( p => p.metodo === 'efectivo' );
            if ( idxEf >= 0 ) {
                pagosNormalizados[ idxEf ].monto = Math.round( pagosNormalizados[ idxEf ].monto - sobrante );
            } else {
                PosToast.show( 'El monto en tarjeta o transferencia excede el total. Solo el efectivo da vuelto.', 'error' );
                return;
            }
        }

        const submitBtn = document.getElementById( 'pos-pago-submit' );
        if ( submitBtn ) { submitBtn.disabled = true; submitBtn.textContent = 'Procesando…'; }

        try {
            const payload = {
                ubicacion_id:    PosAuth.getUbicacionId(),
                items:           items.map( i => ( {
                    producto_id:    i.id,
                    cantidad:       i.cantidad,
                    precio_unitario: i.precio,
                } ) ),
                pagos:           pagosNormalizados,
                subtotal:         subtotal,
                descuento_pct:    descPct,
                descuento_monto:  descMonto,
                total:            total,
                referencia_pago:  '',
                comprobante_transferencia: ( document.getElementById( 'pos-pago-comprobante' )?.value || '' ).trim(),
                cajera_id:        ( window.PosMain && window.PosMain.getCajeraActivaId )
                                    ? window.PosMain.getCajeraActivaId()
                                    : PosAuth.getUserId(),
            };

            const json = await PosAuth.apiFetch( '/venta', {
                method:  'POST',
                body:    JSON.stringify( payload ),
            } );

            if ( ! json?.success ) {
                throw new Error( json?.message || 'Error al procesar la venta.' );
            }

            // Para el comprobante: si hay efectivo, vuelto = ingresado - total.
            const efectivoIngresado = pagos.find( p => p.metodo === 'efectivo' )?.monto || 0;
            const vuelto = sobrante > 0.01 ? sobrante : 0;

            showComprobante( json.data, items, subtotal, descPct, descMonto, total, pagos, efectivoIngresado, vuelto, '' );
            PosCarrito.vaciar();
            closePago();

            // Refrescar el stock en pantalla tras vender: mantiene esta caja al
            // día y acerca los números a lo que ve la otra caja.
            if ( 'undefined' !== typeof PosProductos && PosProductos.recargar ) {
                PosProductos.recargar();
            }

        } catch ( err ) {
            PosToast.show( err.message || 'Error al procesar la venta.', 'error' );
        } finally {
            if ( submitBtn ) {
                submitBtn.disabled   = false;
                submitBtn.textContent = '✓ Completar venta';
            }
        }
    }

    // ── Comprobante ───────────────────────────────────────────
    function showComprobante( data, items, subtotal, descPct, descMonto, total, pagos, efectivoIngresado, vuelto, referencia ) {
        const overlay = document.getElementById( 'pos-modal-comprobante-overlay' );
        if ( ! overlay ) return;

        const metodoLabels = {
            efectivo:      'Efectivo',
            debito:        'Tarjeta Débito',
            credito:       'Tarjeta Crédito',
            transferencia: 'Transferencia',
        };
        const now          = new Date();
        const fechaStr     = now.toLocaleDateString( 'es-CL' ) + ' ' + now.toLocaleTimeString( 'es-CL', { hour: '2-digit', minute: '2-digit' } );

        // Compatibilidad con llamadas antiguas: si 'pagos' viene como string, lo
        // convertimos a array de 1 elemento (legacy un solo método).
        if ( typeof pagos === 'string' ) {
            pagos = [ { metodo: pagos, monto: total } ];
            efectivoIngresado = efectivoIngresado || 0;
            vuelto = vuelto || 0;
        }

        // Texto para el método de pago: si es uno solo, su nombre; si son varios, "Pago mixto".
        let metodoTexto;
        if ( pagos.length === 1 ) {
            metodoTexto = metodoLabels[ pagos[0].metodo ] || pagos[0].metodo;
        } else {
            metodoTexto = 'Pago mixto: ' + pagos
                .map( p => ( metodoLabels[ p.metodo ] || p.metodo ) + ' ' + fmt( p.monto ) )
                .join( ' + ' );
        }

        document.getElementById( 'pos-comprobante-ref' ).textContent    = data.referencia_interna || '—';
        document.getElementById( 'pos-comprobante-fecha' ).textContent  = fechaStr;
        document.getElementById( 'pos-comprobante-metodo' ).textContent = metodoTexto;

        const itemsBody = document.getElementById( 'pos-comprobante-items-body' );
        if ( itemsBody ) {
            itemsBody.innerHTML = items.map( i => `
                <tr>
                    <td>${ i.nombre }</td>
                    <td style="text-align:center">${ i.cantidad }</td>
                    <td style="text-align:right">${ fmt( i.precio ) }</td>
                    <td style="text-align:right"><strong>${ fmt( i.precio * i.cantidad ) }</strong></td>
                </tr>
            ` ).join( '' );
        }

        // Subtotal y descuento (solo visibles si hay descuento aplicado).
        const subRow  = document.getElementById( 'pos-comprobante-subtotal-row' );
        const descRow = document.getElementById( 'pos-comprobante-discount-row' );
        if ( descPct > 0 ) {
            if ( subRow )  {
                subRow.style.display = '';
                document.getElementById( 'pos-comprobante-subtotal-value' ).textContent = fmt( subtotal );
            }
            if ( descRow ) {
                descRow.style.display = '';
                document.getElementById( 'pos-comprobante-discount-pct' ).textContent   = descPct;
                document.getElementById( 'pos-comprobante-discount-value' ).textContent = '−' + fmt( descMonto );
            }
        } else {
            if ( subRow )  subRow.style.display  = 'none';
            if ( descRow ) descRow.style.display = 'none';
        }

        document.getElementById( 'pos-comprobante-total-value' ).textContent = fmt( total );

        // Mostrar vuelto si hubo efectivo + sobrante.
        const vueltoRow = document.getElementById( 'pos-comprobante-vuelto-row' );
        if ( vueltoRow ) {
            if ( efectivoIngresado > 0 && vuelto > 0 ) {
                vueltoRow.style.display = '';
                document.getElementById( 'pos-comprobante-recibido' ).textContent = fmt( efectivoIngresado );
                document.getElementById( 'pos-comprobante-vuelto' ).textContent   = fmt( vuelto );
            } else {
                vueltoRow.style.display = 'none';
            }
        }

        overlay.removeAttribute( 'hidden' );
    }

    function closeComprobante() {
        document.getElementById( 'pos-modal-comprobante-overlay' )?.setAttribute( 'hidden', '' );
        // Refrescar el grid para que el stock refleje la venta recién hecha.
        // Esto aplica tanto al botón "Nueva venta" como a la "X" de cerrar.
        refrescarProductos();
    }

    // Refresca el grid de productos. Usa PosProductos.recargar() si existe
    // (mantiene página/búsqueda actual); si no, hace location.reload().
    function refrescarProductos() {
        if ( typeof PosProductos === 'undefined' ) { location.reload(); return; }
        const candidatos = [ 'recargar', 'refresh', 'reload', 'cargarProductos', 'fetchProductos', 'loadInitial' ];
        for ( const nombre of candidatos ) {
            if ( typeof PosProductos[ nombre ] === 'function' ) {
                try { PosProductos[ nombre ](); return; } catch ( e ) { /* probar siguiente */ }
            }
        }
        location.reload();
    }

    /**
     * Imprime el comprobante en formato de impresora térmica de 80 mm.
     *
     * Notas sobre impresoras térmicas (Utek y compatibles ESC/POS):
     * - El área imprimible real de un rollo de 80 mm es ~72 mm; el resto es
     *   margen físico del cabezal. Por eso el ancho se fija en 72mm.
     * - @page con size: 80mm auto evita que el navegador asuma tamaño carta y
     *   deje la boleta diminuta en una esquina o corte el contenido.
     * - 'auto' en el alto es clave: el papel es continuo, no hay páginas, así
     *   la boleta se corta justo donde termina el contenido y no desperdicia
     *   rollo.
     * - Fuente monoespaciada y tamaños chicos: el cabezal térmico tiene poca
     *   resolución y las fuentes finas salen borrosas.
     */
    /**
     * Logo para la boleta térmica, incrustado en base64.
     *
     * Va embebido (y no como <img src="...">) por dos razones:
     *  1. La ventana de impresión llama a print() a los pocos milisegundos de
     *     abrirse; una imagen cargada por red podría no alcanzar a estar lista
     *     y la boleta saldría sin logo.
     *  2. Evita depender de la URL del plugin, que cambia entre instalaciones.
     *
     * Está pre-binarizado a blanco y negro puro: las térmicas imprimen a 1 bit,
     * así que un logo a color se convertiría solo, con tramas y bordes sucios.
     */
    const LOGO_BOLETA = 'data:image/png;base64,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';

    /** Datos fijos del negocio que se imprimen en el encabezado. */
    const DIRECCION_BOLETA = 'Colocolo 366, Concepción';

    /**
     * Imprime una boleta. Sin argumentos, toma los datos del comprobante que
     * está en pantalla (venta recién hecha). Con un objeto `datos`, imprime esa
     * venta — así la misma plantilla sirve para reimprimir boletas antiguas.
     *
     * @param {Object|null} datos {
     *   referencia, fecha, metodoTexto, cajera, esCopia,
     *   items: [ { nombre, cantidad, precio_unitario, subtotal } ],
     *   subtotal, descuentoPct, descuentoMonto, total, recibido, vuelto
     * }
     */
    function imprimirComprobante( datos ) {
        // Modo "venta actual": leer del DOM del comprobante en pantalla.
        if ( ! datos ) {
            const comp = document.getElementById( 'pos-comprobante-print' );
            if ( ! comp ) return;
            datos = datosDesdeDOM();
        }

        const negocio = ( window.POS_CONFIG && POS_CONFIG.nombreNegocio ) || 'Boleta';
        const sede    = ( 'undefined' !== typeof PosAuth && PosAuth.getUbicacionNombre )
            ? ( PosAuth.getUbicacionNombre() || '' ) : '';
        const cajera  = datos.cajera || ( ( 'undefined' !== typeof PosAuth && PosAuth.getNombre )
            ? ( PosAuth.getNombre() || '' ) : '' );

        const win = window.open( '', '_blank', 'width=380,height=650' );
        if ( ! win ) {
            PosToast.show( 'El navegador bloqueó la ventana de impresión. Permite las ventanas emergentes.', 'error', 5000 );
            return;
        }

        win.document.write( `
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Boleta ${ negocio }</title>
<style>
    /* Rollo de 80 mm: el área imprimible efectiva es ~72 mm. */
    @page {
        size: 80mm auto;   /* auto = papel continuo, sin páginas fijas */
        margin: 0;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        padding: 0;
        width: 72mm;
        background: #fff;
    }

    body {
        font-family: 'Courier New', 'Consolas', monospace;
        font-size: 13px;
        /* TODO en negrita: el cabezal térmico marca poco, y una fuente fina
           sale gris y cuesta leerla (sobre todo cuando el rollo va gastado). */
        font-weight: 700;
        line-height: 1.4;
        color: #000;
        padding: 2mm 2mm 6mm;   /* el padding inferior deja aire antes del corte */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        /* Sin suavizado de bordes: el antialiasing genera píxeles grises que
           la térmica imprime tenues o directamente no imprime. */
        -webkit-font-smoothing: none;
        font-smooth: never;
        text-rendering: geometricPrecision;
    }

    /* Refuerzo: nada queda en peso normal aunque el navegador lo herede. */
    body, body * { font-weight: 700 !important; }

    /* ── Encabezado ── */
    .tk-head { text-align: center; margin-bottom: 4px; }
    .tk-logo {
        width: 34mm;          /* ~48% del ancho útil: visible sin gastar rollo */
        height: auto;
        display: block;
        margin: 0 auto 3px;
        /* Sin suavizado: en 1 bit el antialiasing ensucia los bordes. */
        image-rendering: pixelated;
    }
    .tk-negocio {
        font-size: 19px;
        font-weight: 900;
        letter-spacing: .5px;
        text-transform: uppercase;
    }
    .tk-sub { font-size: 12px; }

    .tk-sep {
        border: none;
        border-top: 2px dashed #000;   /* 2px: a 1px la térmica lo imprime débil */
        margin: 6px 0;
    }

    /* ── Datos de la venta ── */
    .tk-meta { font-size: 12px; }
    .tk-meta div {
        display: flex;
        justify-content: space-between;
        gap: 6px;
    }

    /* ── Ítems ── */
    table.tk-items {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    table.tk-items th {
        text-align: left;
        font-weight: 900;
        border-bottom: 2px solid #000;
        padding: 3px 0;
    }
    table.tk-items td {
        padding: 4px 0;
        vertical-align: top;
    }
    /* El nombre puede ser largo: se permite que corte en varias líneas. */
    table.tk-items td.tk-nom {
        word-break: break-word;
        padding-right: 3px;
    }
    table.tk-items small { font-size: 11px; }
    .tk-c { text-align: center; }
    .tk-r { text-align: right; white-space: nowrap; }

    /* ── Totales ── */
    .tk-tot {
        display: flex;
        justify-content: space-between;
        font-size: 20px;
        font-weight: 900;
        margin-top: 4px;
    }
    .tk-line {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
    }

    .tk-copia {
        font-size: 14px;
        font-weight: 900;
        margin-top: 2px;
        letter-spacing: 1px;
    }

    .tk-foot {
        text-align: center;
        font-size: 12px;
        margin-top: 10px;
    }

    /* Nada de esto debe salir en papel. */
    .no-print { display: none !important; }
</style>
</head>
<body>
    <div class="tk-head">
        <img class="tk-logo" src="${ LOGO_BOLETA }" alt="">
        <div class="tk-negocio">${ negocio }</div>
        <div class="tk-sub">${ DIRECCION_BOLETA }</div>
        ${ sede ? `<div class="tk-sub">${ sede }</div>` : '' }
        ${ datos.esCopia ? '<div class="tk-copia">— COPIA —</div>' : '' }
    </div>

    <hr class="tk-sep">

    <div class="tk-meta">
        <div><span>Boleta:</span><span>${ datos.referencia }</span></div>
        <div><span>Fecha:</span><span>${ datos.fecha }</span></div>
        <div><span>Pago:</span><span>${ datos.metodoTexto }</span></div>
        ${ cajera ? `<div><span>Atendió:</span><span>${ cajera }</span></div>` : '' }
    </div>

    <hr class="tk-sep">

    <table class="tk-items">
        <thead>
            <tr>
                <th>Producto</th>
                <th class="tk-c">Cant</th>
                <th class="tk-r">Total</th>
            </tr>
        </thead>
        <tbody>${ filasItems( datos.items ) }</tbody>
    </table>

    <hr class="tk-sep">

    ${ bloqueTotales( datos ) }

    <hr class="tk-sep">

    <div class="tk-foot">
        ¡Gracias por su compra!<br>
        @_pavariar<br>
        pavariar.cl
    </div>
</body>
</html>` );

        win.document.close();
        win.focus();

        // Esperar a que el logo esté decodificado antes de imprimir. Con un
        // setTimeout fijo, en equipos lentos la boleta podía salir sin logo:
        // print() es bloqueante y captura la página tal como esté en ese
        // instante. Aquí se espera al evento load de la imagen, con un tope de
        // seguridad por si algo falla (mejor imprimir sin logo que no imprimir).
        const imprimirYa = ( () => {
            let hecho = false;
            return () => {
                if ( hecho ) return;
                hecho = true;
                win.print();
                win.close();
            };
        } )();

        const logoEl = win.document.querySelector( '.tk-logo' );

        if ( logoEl && ! logoEl.complete ) {
            logoEl.addEventListener( 'load',  () => setTimeout( imprimirYa, 60 ) );
            logoEl.addEventListener( 'error', imprimirYa );
        } else {
            setTimeout( imprimirYa, 150 );
        }

        // Red de seguridad: pase lo que pase, se imprime.
        setTimeout( imprimirYa, 1500 );
    }

    /** Lee el texto de un elemento del comprobante en pantalla. */
    function txt( id ) {
        return document.getElementById( id )?.textContent?.trim() || '';
    }

    /** ¿Está visible una fila del comprobante? */
    function visible( id ) {
        const elx = document.getElementById( id );
        return !! elx && ! elx.hidden && 'none' !== elx.style.display;
    }

    /**
     * Arma el objeto de datos leyendo el comprobante que está en pantalla.
     * Se usa para la venta recién realizada.
     */
    function datosDesdeDOM() {
        const items = [];

        document.querySelectorAll( '#pos-comprobante-items-body tr' ).forEach( tr => {
            const c = tr.querySelectorAll( 'td' );
            if ( c.length < 4 ) return;
            items.push( {
                nombre  : c[0].textContent.trim(),
                cantidad: c[1].textContent.trim(),
                unit    : c[2].textContent.trim(),
                subtotal: c[3].textContent.trim(),
            } );
        } );

        return {
            referencia    : txt( 'pos-comprobante-ref' )    || '—',
            fecha         : txt( 'pos-comprobante-fecha' )  || '—',
            metodoTexto   : txt( 'pos-comprobante-metodo' ) || '—',
            esCopia       : false,
            items,
            subtotalTxt   : visible( 'pos-comprobante-subtotal-row' ) ? txt( 'pos-comprobante-subtotal-value' ) : '',
            descuentoPct  : visible( 'pos-comprobante-discount-row' ) ? txt( 'pos-comprobante-discount-pct' )   : '',
            descuentoTxt  : visible( 'pos-comprobante-discount-row' ) ? txt( 'pos-comprobante-discount-value' ) : '',
            totalTxt      : txt( 'pos-comprobante-total-value' ) || fmt( 0 ),
            recibidoTxt   : visible( 'pos-comprobante-vuelto-row' ) ? txt( 'pos-comprobante-recibido' ) : '',
            vueltoTxt     : visible( 'pos-comprobante-vuelto-row' ) ? txt( 'pos-comprobante-vuelto' )   : '',
        };
    }

    /**
     * Filas de ítems en formato ticket (3 columnas).
     * En 72 mm no caben Producto + Cant + P.Unit + Subtotal de forma legible,
     * así que el precio unitario va bajo el nombre cuando la cantidad es > 1.
     */
    function filasItems( items ) {
        return ( items || [] ).map( it => `
            <tr>
                <td class="tk-nom">
                    ${ it.nombre }
                    ${ parseInt( it.cantidad, 10 ) > 1 ? `<br><small>${ it.cantidad } x ${ it.unit }</small>` : '' }
                </td>
                <td class="tk-c">${ it.cantidad }</td>
                <td class="tk-r">${ it.subtotal }</td>
            </tr>` ).join( '' );
    }

    /** Subtotal, descuento, total y vuelto — solo los que correspondan. */
    function bloqueTotales( d ) {
        let html = '';

        if ( d.subtotalTxt ) {
            html += `<div class="tk-line"><span>Subtotal:</span><span>${ d.subtotalTxt }</span></div>`;
        }
        if ( d.descuentoTxt ) {
            html += `<div class="tk-line"><span>Descuento (${ d.descuentoPct }%):</span><span>${ d.descuentoTxt }</span></div>`;
        }

        html += `<div class="tk-tot"><span>TOTAL</span><span>${ d.totalTxt }</span></div>`;

        if ( d.recibidoTxt ) {
            html += `
                <div class="tk-line" style="margin-top:4px;"><span>Recibido:</span><span>${ d.recibidoTxt }</span></div>
                <div class="tk-line" style="font-weight:bold;"><span>Vuelto:</span><span>${ d.vueltoTxt }</span></div>`;
        }

        return html;
    }

    // ── Cierre de caja ────────────────────────────────────────
    async function openCierreCaja() {
        const overlay = document.getElementById( 'pos-modal-cierre-overlay' );
        if ( ! overlay ) return;

        // Limpiar estado anterior.
        document.getElementById( 'pos-cierre-resultado' )?.setAttribute( 'hidden', '' );
        document.getElementById( 'pos-efectivo-contado' ).value = '';
        const tarjetaEl = document.getElementById( 'pos-tarjeta-contado' );
        if ( tarjetaEl ) tarjetaEl.value = '';
        const transferenciaEl = document.getElementById( 'pos-transferencia-contado' );
        if ( transferenciaEl ) transferenciaEl.value = '';
        const tarjetaInput = document.getElementById( 'pos-tarjeta-contado' );
        if ( tarjetaInput ) tarjetaInput.value = '';
        const submitBtn = document.getElementById( 'pos-cierre-submit' );
        if ( submitBtn ) {
            submitBtn.disabled   = false;
            submitBtn.textContent = 'Cerrar caja';
        }

        // Cargar info de caja abierta solo para mostrar fecha de apertura.
        const json = await PosAuth.apiFetch( '/caja-abierta' );
        const caja = json?.data?.caja;

        if ( ! caja ) {
            PosToast.show( 'No tienes una caja abierta.', 'warning' );
            return;
        }

        // El servidor ya entrega la hora formateada en hora de Chile
        // ( fecha_apertura_local ). El fallback solo reordena el texto: usar
        // new Date() aplicaria la zona del navegador y volveria a correr la hora,
        // que es justo el error que se corrigio.
        let aperturaTexto = caja.fecha_apertura_local || '';

        if ( ! aperturaTexto && caja.fecha_apertura ) {
            const m = String( caja.fecha_apertura )
                .match( /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/ );
            if ( m ) {
                aperturaTexto = `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}`;
            }
        }

        document.getElementById( 'pos-cierre-apertura' ).textContent = aperturaTexto || '—';

        overlay.removeAttribute( 'hidden' );

        // Foco en el input para escribir directo.
        setTimeout( () => document.getElementById( 'pos-efectivo-contado' )?.focus(), 100 );
    }

    function closeCierreCaja() {
        document.getElementById( 'pos-modal-cierre-overlay' )?.setAttribute( 'hidden', '' );
    }

    async function confirmarCierre() {
        const efectivoReal = parseFloat( document.getElementById( 'pos-efectivo-contado' ).value || 0 );

        if ( isNaN( efectivoReal ) || efectivoReal < 0 ) {
            PosToast.show( 'Ingresa el monto de efectivo contado.', 'error' );
            return;
        }

        // Total del voucher Transbank (débito + crédito). Es opcional: si el
        // campo queda vacío se envía null y no se compara nada.
        const tarjetaRaw = ( document.getElementById( 'pos-tarjeta-contado' )?.value || '' ).trim();
        let tarjetaReal  = null;

        if ( '' !== tarjetaRaw ) {
            tarjetaReal = parseFloat( tarjetaRaw );
            if ( isNaN( tarjetaReal ) || tarjetaReal < 0 ) {
                PosToast.show( 'El total de tarjetas no es válido.', 'error' );
                return;
            }
        }

        // Transferencias recibidas según el banco. También opcional.
        const transferenciaRaw = ( document.getElementById( 'pos-transferencia-contado' )?.value || '' ).trim();
        let transferenciaReal  = null;

        if ( '' !== transferenciaRaw ) {
            transferenciaReal = parseFloat( transferenciaRaw );
            if ( isNaN( transferenciaReal ) || transferenciaReal < 0 ) {
                PosToast.show( 'El total de transferencias no es válido.', 'error' );
                return;
            }
        }

        const submitBtn = document.getElementById( 'pos-cierre-submit' );
        submitBtn.disabled   = true;
        submitBtn.textContent = 'Cerrando…';

        try {
            const json = await PosAuth.apiFetch( '/cierre-caja', {
                method: 'POST',
                body:   JSON.stringify( {
                    efectivo_real:      efectivoReal,
                    tarjeta_real:       tarjetaReal,
                    transferencia_real: transferenciaReal,
                    cajera_id:     ( window.PosMain && window.PosMain.getCajeraActivaId )
                                    ? window.PosMain.getCajeraActivaId()
                                    : PosAuth.getUserId(),
                } ),
            } );

            if ( ! json?.success ) {
                throw new Error( json?.message || 'Error al cerrar la caja.' );
            }

            // Mostrar confirmación genérica (sin montos ni diferencias).
            const resEl = document.getElementById( 'pos-cierre-resultado' );
            if ( resEl ) {
                resEl.removeAttribute( 'hidden' );
                resEl.className = 'pos-diferencia-display pos-diferencia-display--ok';
                resEl.innerHTML = `
                    <span class="pos-diferencia-display-value" style="font-size:1.5rem;">✓</span>
                    <span class="pos-diferencia-display-label">Caja cerrada correctamente</span>
                    <div style="margin-top:8px;font-size:.875rem;color:#6b7280;">
                        ¡Buen turno!
                    </div>
                `;
            }

            submitBtn.textContent = 'Caja cerrada';
            PosToast.show( json.message || 'Caja cerrada.', 'success', 3000 );

            // Cerrar modal y desloguear tras 2 segundos para que la cajera salga del POS.
            setTimeout( () => {
                document.getElementById( 'pos-modal-cierre-overlay' )?.setAttribute( 'hidden', '' );
                PosAuth.logout();
            }, 2200 );

        } catch ( err ) {
            PosToast.show( err.message || 'Error al cerrar la caja.', 'error' );
            submitBtn.disabled   = false;
            submitBtn.textContent = 'Cerrar caja';
        }
    }

    // ── Init ──────────────────────────────────────────────────
    function init() {
        // Pago.
        document.getElementById( 'pos-btn-pagar' )?.addEventListener( 'click', openPago );
        document.getElementById( 'pos-pago-close' )?.addEventListener( 'click', closePago );
        document.getElementById( 'pos-modal-pago-overlay' )?.addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closePago();
        } );

        // Inputs de monto por método: recalcular en cada cambio.
        document.querySelectorAll( '.pos-pago-input' ).forEach( input => {
            input.addEventListener( 'input', recalcularPago );
        } );

        // Botones "Todo" → completar resto con ese método.
        document.querySelectorAll( '.pos-pago-fill' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const targetId = btn.dataset.target;
                const target   = document.getElementById( targetId );
                if ( ! target ) return;

                const total = PosCarrito.getTotal();
                // Sumar los OTROS inputs (excepto el del botón).
                const otros = Array.from( document.querySelectorAll( '.pos-pago-input' ) )
                    .filter( i => i.id !== targetId )
                    .reduce( ( s, i ) => s + ( parseFloat( i.value || 0 ) || 0 ), 0 );
                const resto = Math.max( 0, total - otros );
                target.value = resto > 0 ? resto : '';
                recalcularPago();
                target.focus();
            } );
        } );

        // Submit de pago.
        document.getElementById( 'pos-pago-submit' )?.addEventListener( 'click', completarVenta );

        // Editor de descuento dentro del modal de pago.
        // Cambia el descuento en el carrito (fuente única de verdad) y refresca
        // el total del modal sin tener que volver atrás.
        function aplicarDescuentoDesdePago( pct ) {
            PosCarrito.setDescuentoPct( pct );
            const descInput = document.getElementById( 'pos-pago-discount-input' );
            if ( descInput ) descInput.value = pct > 0 ? pct : '';
            actualizarTotalPago();
            recalcularPago();
        }

        document.querySelectorAll( '#pos-modal-pago-overlay .pos-discount-preset' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                aplicarDescuentoDesdePago( parseInt( btn.dataset.pct, 10 ) || 0 );
            } );
        } );

        document.getElementById( 'pos-pago-discount-input' )?.addEventListener( 'input', ( e ) => {
            aplicarDescuentoDesdePago( e.target.value );
        } );

        // Comprobante.
        document.getElementById( 'pos-comprobante-close' )?.addEventListener( 'click', closeComprobante );
        document.getElementById( 'pos-comprobante-print-btn' )?.addEventListener( 'click', () => imprimirComprobante() );
        document.getElementById( 'pos-comprobante-nueva-venta' )?.addEventListener( 'click', () => {
            closeComprobante();
        } );

        // Cierre de caja.
        document.getElementById( 'pos-btn-cierre-caja' )?.addEventListener( 'click', openCierreCaja );
        document.getElementById( 'pos-cierre-close' )?.addEventListener( 'click', closeCierreCaja );
        document.getElementById( 'pos-cierre-submit' )?.addEventListener( 'click', confirmarCierre );
        document.getElementById( 'pos-modal-cierre-overlay' )?.addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeCierreCaja();
        } );

        // Cerrar sesión.
        document.getElementById( 'pos-btn-logout' )?.addEventListener( 'click', () => {
            if ( confirm( '¿Cerrar sesión POS?' ) ) PosAuth.logout();
        } );
    }

    return { init, openPago, imprimirBoleta: imprimirComprobante };
} )();