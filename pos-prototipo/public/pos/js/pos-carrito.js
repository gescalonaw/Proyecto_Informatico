/**
 * pos-carrito.js – Gestión del carrito de compras POS
 *
 * Estado almacenado en memoria (no persiste entre sesiones intencionalmente).
 * items[]: { id, nombre, precio, imagen, sku, cantidad, stock }
 */

const PosCarrito = ( () => {
    'use strict';

    let items = [];
    let descuentoPct = 0; // 0..100
    let onChangeCallbacks = [];

    // ── Suscripción a cambios ──────────────────────────────────
    function onChange( cb ) { onChangeCallbacks.push( cb ); }
    function emit()         { onChangeCallbacks.forEach( cb => cb( items ) ); }

    // ── Operaciones ───────────────────────────────────────────
    function agregar( producto, cantidad = 1 ) {
        cantidad = Math.max( 1, parseInt( cantidad, 10 ) );

        const idx = items.findIndex( i => i.id === producto.id );

        if ( idx >= 0 ) {
            const nuevo = items[idx].cantidad + cantidad;
            items[idx].cantidad = Math.min( nuevo, items[idx].stock );
        } else {
            items.push( {
                id:       producto.id,
                nombre:   producto.nombre,
                precio:   parseFloat( producto.precio ) || 0,
                imagen:   producto.imagen || '',
                sku:      producto.sku || '',
                cantidad: Math.min( cantidad, producto.stock ),
                stock:    producto.stock || 999,
            } );
        }

        emit();
    }

    function quitar( productoId ) {
        items = items.filter( i => i.id !== productoId );
        emit();
    }

    function setCantidad( productoId, cantidad ) {
        cantidad = Math.max( 0, parseInt( cantidad, 10 ) );
        const idx = items.findIndex( i => i.id === productoId );
        if ( idx < 0 ) return;

        if ( cantidad === 0 ) {
            items.splice( idx, 1 );
        } else {
            items[idx].cantidad = Math.min( cantidad, items[idx].stock );
        }
        emit();
    }

    function vaciar() {
        items = [];
        descuentoPct = 0;
        emit();
    }

    function setDescuentoPct( pct ) {
        const n = parseFloat( pct );
        if ( isNaN( n ) || n < 0 ) {
            descuentoPct = 0;
        } else {
            descuentoPct = Math.min( 100, n );
        }
        emit();
    }

    function getDescuentoPct()    { return descuentoPct; }
    // CLP no usa decimales: redondeamos el descuento a entero UNA sola vez aquí.
    // Así el total siempre es entero y coincide con lo que valida el backend,
    // evitando el "peso fantasma" por redondeos inconsistentes.
    function getDescuentoMonto()  { return Math.round( getSubtotal() * ( descuentoPct / 100 ) ); }
    function getSubtotal()        { return items.reduce( ( acc, i ) => acc + i.precio * i.cantidad, 0 ); }
    function getItems()           { return [ ...items ]; }
    function getTotal()           { return Math.max( 0, getSubtotal() - getDescuentoMonto() ); }
    function getCount()           { return items.reduce( ( acc, i ) => acc + i.cantidad, 0 ); }
    function isEmpty()            { return items.length === 0; }

    // ── Renderizado ───────────────────────────────────────────
    function render() {
        const container   = document.getElementById( 'pos-cart-items' );
        const emptyEl     = document.getElementById( 'pos-cart-empty' );
        const countEl     = document.getElementById( 'pos-cart-count' );
        const subtotalEl  = document.getElementById( 'pos-cart-subtotal-amount' );
        const totalEl     = document.getElementById( 'pos-cart-total-amount' );
        const discRow     = document.getElementById( 'pos-cart-discount-row' );
        const discAmount  = document.getElementById( 'pos-cart-discount-amount' );
        const discPctEl   = document.getElementById( 'pos-cart-discount-pct' );
        const discInput   = document.getElementById( 'pos-discount-input' );
        const pagarBtn    = document.getElementById( 'pos-btn-pagar' );
        const vaciarBtn   = document.getElementById( 'pos-btn-vaciar' );

        if ( ! container ) return;

        const subtotal = getSubtotal();
        const descuento = getDescuentoMonto();
        const total    = getTotal();
        const count    = getCount();

        // Badge y montos.
        if ( countEl )    countEl.textContent    = count || '';
        if ( subtotalEl ) subtotalEl.textContent = PosProductos.formatPrice( subtotal );
        if ( totalEl )    totalEl.textContent    = PosProductos.formatPrice( total );
        if ( pagarBtn )   pagarBtn.disabled  = isEmpty();
        if ( vaciarBtn )  vaciarBtn.disabled = isEmpty();
        if ( emptyEl )    emptyEl.style.display = isEmpty() ? 'flex' : 'none';

        // Línea de descuento (solo visible si pct > 0 y hay items).
        // Usamos style.display además de hidden para que CSS heredado no la
        // deje visible con un monto/porcentaje viejo (confusión para la cajera).
        if ( discRow ) {
            if ( descuentoPct > 0 && ! isEmpty() ) {
                discRow.removeAttribute( 'hidden' );
                discRow.style.display = '';
                if ( discAmount ) discAmount.textContent = '−' + PosProductos.formatPrice( descuento );
                if ( discPctEl )  discPctEl.textContent  = descuentoPct;
            } else {
                discRow.setAttribute( 'hidden', '' );
                discRow.style.display = 'none';
                // Limpiar textos para que nunca quede un valor stale.
                if ( discAmount ) discAmount.textContent = '−' + PosProductos.formatPrice( 0 );
                if ( discPctEl )  discPctEl.textContent  = '0';
            }
        }

        // Sincronizar input (sin disparar evento change).
        if ( discInput && document.activeElement !== discInput ) {
            discInput.value = descuentoPct > 0 ? descuentoPct : '';
        }

        if ( isEmpty() ) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = items.map( item => renderItem( item ) ).join( '' );

        // Bind controles por ítem.
        container.querySelectorAll( '.pos-cart-item' ).forEach( el => {
            const id = parseInt( el.dataset.id, 10 );

            el.querySelector( '.pos-qty-btn--minus' )?.addEventListener( 'click', () => {
                const item = items.find( i => i.id === id );
                if ( item ) setCantidad( id, item.cantidad - 1 );
            } );

            el.querySelector( '.pos-qty-btn--plus' )?.addEventListener( 'click', () => {
                const item = items.find( i => i.id === id );
                if ( item ) setCantidad( id, item.cantidad + 1 );
            } );

            el.querySelector( '.pos-qty-input' )?.addEventListener( 'change', ( e ) => {
                setCantidad( id, parseInt( e.target.value, 10 ) );
            } );

            el.querySelector( '.pos-cart-item-del' )?.addEventListener( 'click', () => {
                quitar( id );
            } );
        } );
    }

    function renderItem( item ) {
        const subtotal = item.precio * item.cantidad;
        const thumbHtml = item.imagen
            ? `<img src="${ item.imagen }" class="pos-cart-item-thumb" alt="">`
            : `<div class="pos-cart-item-no-thumb"></div>`;

        return `
        <div class="pos-cart-item" data-id="${ item.id }">
            ${ thumbHtml }
            <div class="pos-cart-item-info">
                <div class="pos-cart-item-name" title="${ item.nombre }">${ item.nombre }</div>
                <div class="pos-cart-item-price">${ PosProductos.formatPrice( item.precio ) } c/u</div>
                <div class="pos-cart-item-controls">
                    <button class="pos-qty-btn pos-qty-btn--minus">−</button>
                    <input class="pos-qty-input" type="number"
                        value="${ item.cantidad }" min="1" max="${ item.stock }">
                    <button class="pos-qty-btn pos-qty-btn--plus">+</button>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
                <div class="pos-cart-item-subtotal">
                    ${ PosProductos.formatPrice( subtotal ) }
                </div>
                <button class="pos-cart-item-del" title="Quitar">✕</button>
            </div>
        </div>`;
    }

    // ── Init ──────────────────────────────────────────────────
    function init() {
        onChange( render );

        document.getElementById( 'pos-btn-vaciar' )?.addEventListener( 'click', () => {
            if ( isEmpty() ) return;
            if ( confirm( '¿Vaciar el carrito?' ) ) vaciar();
        } );

        // Toggle del panel de descuento.
        const toggleBtn = document.getElementById( 'pos-discount-toggle' );
        const panel     = document.getElementById( 'pos-discount-panel' );
        if ( toggleBtn && panel ) {
            toggleBtn.addEventListener( 'click', () => {
                const isHidden = panel.hasAttribute( 'hidden' );
                if ( isHidden ) {
                    panel.removeAttribute( 'hidden' );
                    document.getElementById( 'pos-discount-input' )?.focus();
                } else {
                    panel.setAttribute( 'hidden', '' );
                }
            } );
        }

        // Input numérico del descuento.
        document.getElementById( 'pos-discount-input' )?.addEventListener( 'input', ( e ) => {
            setDescuentoPct( e.target.value );
        } );

        // Botones preset (5/10/15/20).
        document.querySelectorAll( '.pos-discount-preset' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const pct = parseInt( btn.dataset.pct, 10 );
                setDescuentoPct( pct );
            } );
        } );

        // Limpiar descuento.
        document.getElementById( 'pos-discount-clear' )?.addEventListener( 'click', () => {
            setDescuentoPct( 0 );
        } );

        render(); // Render inicial (carrito vacío).
    }

    return {
        init, agregar, quitar, vaciar, setCantidad,
        getItems, getTotal, getSubtotal, getCount, isEmpty,
        setDescuentoPct, getDescuentoPct, getDescuentoMonto,
        onChange,
    };
} )();

// Toast global (usado por varios módulos).
const PosToast = ( () => {
    let wrap = null;

    function getWrap() {
        if ( ! wrap ) {
            wrap = document.getElementById( 'pos-toast-wrap' );
            if ( ! wrap ) {
                wrap = document.createElement( 'div' );
                wrap.id = 'pos-toast-wrap';
                document.body.appendChild( wrap );
            }
        }
        return wrap;
    }

    function show( msg, type = 'info', duration = 3000 ) {
        const el = document.createElement( 'div' );
        el.className = `pos-toast pos-toast--${ type }`;
        el.textContent = msg;
        getWrap().appendChild( el );
        setTimeout( () => {
            el.classList.add( 'pos-toast-out' );
            el.addEventListener( 'animationend', () => el.remove(), { once: true } );
        }, duration );
    }

    return { show };
} )();
