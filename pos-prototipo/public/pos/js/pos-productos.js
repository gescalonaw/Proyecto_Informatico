/**
 * pos-productos.js – Búsqueda de productos y pistola de código de barras
 *
 * Estrategia de búsqueda:
 *   1. Input visible → búsqueda por nombre con debounce + autocomplete.
 *   2. Input oculto  → recibe datos de la pistola (escribe rápido + Enter).
 *      La pistola se detecta por velocidad de escritura (< 50ms entre chars).
 *      Al presionar Enter (o acumular ≥ 4 chars en < 200ms) → busca por código.
 *
 * Resultados se renderizan en el grid central.
 */

const PosProductos = ( () => {
    'use strict';

    const api = window.POS_CONFIG?.apiBase || '/wp-json/pos/v1';

    // Estado interno.
    let searchTimer    = null;
    let lastKeyTime    = 0;
    let barcodeBuffer  = '';
    let barcodeTimer   = null;
    let currentSearch  = '';

    // Paginación del grid.
    const PAGE_SIZE    = 48;
    let currentPage    = 1;
    let totalProducts  = 0;

    // ── Helpers ───────────────────────────────────────────────
    function esc( str ) {
        const d = document.createElement( 'div' );
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    function formatPrice( num ) {
        const cfg = window.POS_CONFIG;
        if ( ! cfg ) return String( num );
        const n = Number( num );
        const decimals = cfg.decimals || 0;
        const dec  = cfg.decimalSep  || ',';
        const thou = cfg.thousandSep || '.';
        const parts = n.toFixed( decimals ).split( '.' );
        parts[0] = parts[0].replace( /\B(?=(\d{3})+(?!\d))/g, thou );
        const formatted = parts.join( dec );

        // WooCommerce devuelve algunas monedas como HTML entity (ej: &#36; para $).
        // Decodificar a texto plano antes de concatenar.
        const currency = decodeHtmlEntity( cfg.currency || '$' );

        if ( cfg.currencyPos === 'left'  ) return currency + formatted;
        if ( cfg.currencyPos === 'right' ) return formatted + currency;
        return currency + formatted;
    }

    // Decodifica HTML entities (&#36; → $, &amp; → &, etc.) a texto plano.
    function decodeHtmlEntity( str ) {
        const tmp = document.createElement( 'textarea' );
        tmp.innerHTML = str;
        return tmp.value;
    }

    function stockBadgeClass( qty ) {
        if ( qty <= 0 )  return 'pos-product-stock-badge--zero';
        if ( qty <= 3  ) return 'pos-product-stock-badge--low';
        return 'pos-product-stock-badge--ok';
    }

    function stockBadgeText( qty ) {
        if ( qty <= 0 ) return 'Sin stock';
        if ( qty <= 3 ) return `Stock: ${ qty }`;
        return `Stock: ${ qty }`;
    }

    function autoStockClass( qty ) {
        if ( qty <= 0 ) return 'pos-autocomplete-stock--zero';
        if ( qty <= 3 ) return 'pos-autocomplete-stock--low';
        return 'pos-autocomplete-stock--ok';
    }

    // ── API ───────────────────────────────────────────────────
    async function fetchProductos( params = {} ) {
        const ubicId = PosAuth.getUbicacionId();
        if ( ! ubicId ) return { productos: [], total: 0 };

        const qs = new URLSearchParams( { ubicacion_id: ubicId, limit: 20, ...params } );

        const json = await PosAuth.apiFetch( `/productos?${ qs }` );
        return {
            productos: json?.data?.productos || [],
            total:     json?.data?.total     || 0,
        };
    }

    async function fetchProductoById( id ) {
        const json = await PosAuth.apiFetch( `/producto/${ id }` );
        return json?.data?.producto || null;
    }

    // ── Carga paginada del grid ───────────────────────────────
    async function loadPage( pageNum = 1 ) {
        currentPage = Math.max( 1, parseInt( pageNum, 10 ) || 1 );
        const offset = ( currentPage - 1 ) * PAGE_SIZE;

        const { productos, total } = await fetchProductos( { limit: PAGE_SIZE, offset } );
        totalProducts = total;

        renderGrid( productos );
        renderPagination();

        // Scroll al tope del grid.
        document.querySelector( '.pos-panel-products' )?.scrollTo( { top: 0, behavior: 'smooth' } );
    }

    function renderPagination() {
        const grid = document.getElementById( 'pos-products-grid' );
        if ( ! grid ) return;

        // Remover paginación previa.
        document.querySelectorAll( '.pos-pag-wrapper' ).forEach( el => el.remove() );

        if ( totalProducts <= PAGE_SIZE ) return; // No hace falta paginación.

        const totalPages = Math.ceil( totalProducts / PAGE_SIZE );
        const hasPrev    = currentPage > 1;
        const hasNext    = currentPage < totalPages;

        const pag = document.createElement( 'div' );
        pag.className = 'pos-pag-wrapper';
        pag.innerHTML = `
            <button class="pos-pag-btn" id="pos-pag-prev" ${ ! hasPrev ? 'disabled' : '' }>
                ← Anterior
            </button>
            <span class="pos-pag-info">
                Página <strong>${ currentPage }</strong> de <strong>${ totalPages }</strong>
                · <span style="color:#9ca3af;">${ totalProducts } productos</span>
            </span>
            <button class="pos-pag-btn" id="pos-pag-next" ${ ! hasNext ? 'disabled' : '' }>
                Siguiente →
            </button>
        `;
        grid.parentNode.appendChild( pag );

        document.getElementById( 'pos-pag-prev' )?.addEventListener( 'click', () => {
            if ( hasPrev ) loadPage( currentPage - 1 );
        } );
        document.getElementById( 'pos-pag-next' )?.addEventListener( 'click', () => {
            if ( hasNext ) loadPage( currentPage + 1 );
        } );
    }

    // ── Renderizado ───────────────────────────────────────────
    function renderGrid( productos ) {
        const grid = document.getElementById( 'pos-products-grid' );
        if ( ! grid ) return;

        if ( ! productos.length ) {
            grid.innerHTML = `
                <div class="pos-products-empty">
                    <span class="pos-products-empty-icon"></span>
                    <p>No se encontraron productos.</p>
                    <p style="font-size:.8rem;margin-top:4px;color:#9ca3af;">
                        Intenta con otro nombre o código de barras.
                    </p>
                </div>`;
            return;
        }

        grid.innerHTML = productos.map( p => renderCard( p ) ).join( '' );

        // Bind events en tarjetas.
        grid.querySelectorAll( '.pos-product-card' ).forEach( card => {
            const id = parseInt( card.dataset.id, 10 );

            card.querySelector( '.pos-add-btn' )?.addEventListener( 'click', ( e ) => {
                e.stopPropagation();
                const qty = parseInt( card.querySelector( '.pos-qty-input' )?.value || '1', 10 );
                addToCartFromCard( card, qty );
            } );

            card.querySelector( '.pos-qty-btn--minus' )?.addEventListener( 'click', ( e ) => {
                e.stopPropagation();
                const inp = card.querySelector( '.pos-qty-input' );
                if ( inp ) inp.value = Math.max( 1, parseInt( inp.value, 10 ) - 1 );
            } );

            card.querySelector( '.pos-qty-btn--plus' )?.addEventListener( 'click', ( e ) => {
                e.stopPropagation();
                const inp = card.querySelector( '.pos-qty-input' );
                if ( inp ) inp.value = Math.min( 999, parseInt( inp.value, 10 ) + 1 );
            } );

            // Click en la tarjeta también agrega (si no es en los botones).
            card.addEventListener( 'click', () => {
                const qty = parseInt( card.querySelector( '.pos-qty-input' )?.value || '1', 10 );
                addToCartFromCard( card, qty );
            } );
        } );
    }

    function renderCard( p ) {
        const imgHtml = p.imagen_url
            ? `<img src="${ esc( p.imagen_url ) }" alt="${ esc( p.nombre ) }" class="pos-product-img" loading="lazy">`
            : `<div class="pos-product-no-img"></div>`;

        const stock    = p.stock_disponible ?? 0;
        const stockCls = stockBadgeClass( stock );
        const disabled = stock <= 0 ? 'disabled' : '';

        return `
        <div class="pos-product-card" data-id="${ p.id }"
             data-nombre="${ esc( p.nombre ) }"
             data-precio="${ p.precio }"
             data-sku="${ esc( p.sku || '' ) }"
             data-imagen="${ esc( p.imagen_url || '' ) }"
             data-stock="${ stock }">
            <div class="pos-product-img-wrap">
                ${ imgHtml }
                <span class="pos-product-stock-badge ${ stockCls }">
                    ${ stockBadgeText( stock ) }
                </span>
            </div>
            <div class="pos-product-body">
                <div class="pos-product-name">${ esc( p.nombre ) }</div>
                <div class="pos-product-sku">${ esc( p.sku || p.codigo_barras || '' ) }</div>
                <div class="pos-product-price">${ formatPrice( p.precio ) }</div>
            </div>
            <div class="pos-product-footer">
                <div class="pos-qty-controls">
                    <button class="pos-qty-btn pos-qty-btn--minus" ${ disabled }>−</button>
                    <input class="pos-qty-input" type="number" value="1" min="1" max="${ stock || 1 }" ${ disabled }>
                    <button class="pos-qty-btn pos-qty-btn--plus" ${ disabled }>+</button>
                </div>
                <button class="pos-add-btn" ${ disabled }>
                    ${ stock <= 0 ? 'Sin stock' : '+ Agregar' }
                </button>
            </div>
        </div>`;
    }

    function addToCartFromCard( card, qty ) {
        const stock = parseInt( card.dataset.stock, 10 );
        if ( stock <= 0 ) {
            PosToast.show( 'Sin stock disponible.', 'error' );
            return;
        }

        const producto = {
            id:       parseInt( card.dataset.id, 10 ),
            nombre:   card.dataset.nombre,
            precio:   parseFloat( card.dataset.precio ),
            imagen:   card.dataset.imagen,
            sku:      card.dataset.sku,
            stock,
        };

        PosCarrito.agregar( producto, qty );

        // Feedback visual.
        card.classList.add( 'pos-product-card--added' );
        setTimeout( () => card.classList.remove( 'pos-product-card--added' ), 800 );

        PosToast.show( `✓ ${ producto.nombre } agregado`, 'success', 1500 );
    }

    // ── Autocomplete ──────────────────────────────────────────
    function renderAutocomplete( productos ) {
        const list = document.getElementById( 'pos-autocomplete-list' );
        if ( ! list ) return;

        if ( ! productos.length ) {
            list.innerHTML = `<div class="pos-autocomplete-empty">Sin resultados</div>`;
            list.classList.add( 'pos-open' );
            return;
        }

        list.innerHTML = productos.map( p => {
            const stock    = p.stock_disponible ?? 0;
            const stockCls = autoStockClass( stock );
            const thumbHtml = p.imagen_url
                ? `<img src="${ esc( p.imagen_url ) }" class="pos-autocomplete-thumb" alt="">`
                : `<span style="font-size:1.5rem"></span>`;

            return `
            <div class="pos-autocomplete-item" data-id="${ p.id }">
                ${ thumbHtml }
                <div class="pos-autocomplete-info">
                    <div class="pos-autocomplete-name">${ esc( p.nombre ) }</div>
                    <div class="pos-autocomplete-meta">
                        ${ esc( p.sku || p.codigo_barras || '—' ) }
                        · ${ formatPrice( p.precio ) }
                    </div>
                </div>
                <div class="pos-autocomplete-stock ${ stockCls }">
                    ${ stock > 0 ? stock : 'Agotado' }
                </div>
            </div>`;
        } ).join( '' );

        list.classList.add( 'pos-open' );

        // Click en sugerencia → agrega al carrito directamente.
        list.querySelectorAll( '.pos-autocomplete-item' ).forEach( item => {
            item.addEventListener( 'click', () => {
                const id = parseInt( item.dataset.id, 10 );
                const prod = productos.find( p => p.id === id );
                if ( prod && ( prod.stock_disponible ?? 0 ) > 0 ) {
                    PosCarrito.agregar( {
                        id:     prod.id,
                        nombre: prod.nombre,
                        precio: parseFloat( prod.precio ),
                        imagen: prod.imagen_url || '',
                        sku:    prod.sku || '',
                        stock:  prod.stock_disponible,
                    }, 1 );
                    PosToast.show( `✓ ${ prod.nombre } agregado`, 'success', 1500 );
                }
                hideAutocomplete();
                // Limpiar el campo y volver a mostrar todos los productos.
                document.getElementById( 'pos-search-input' ).value = '';
                currentSearch = '';
                loadPage( 1 );
            } );
        } );
    }

    function hideAutocomplete() {
        const list = document.getElementById( 'pos-autocomplete-list' );
        if ( list ) list.classList.remove( 'pos-open' );
    }

    // ── Búsqueda por texto ────────────────────────────────────
    async function searchByText( query ) {
        if ( ! query || query.length < 2 ) {
            hideAutocomplete();
            return;
        }
        currentSearch = query;
        const { productos: prods } = await fetchProductos( { search: query, limit: PAGE_SIZE } );
        if ( currentSearch !== query ) return; // Búsqueda cancelada.

        renderAutocomplete( prods );
        renderGrid( prods );
        // Ocultar paginación durante búsqueda activa.
        document.querySelectorAll( '.pos-pag-wrapper' ).forEach( el => el.remove() );
    }

    // ── Búsqueda por código de barras ─────────────────────────
    async function searchByBarcode( codigo ) {
        if ( ! codigo ) return;

        PosToast.show( `Buscando código: ${ codigo }…`, 'info', 1500 );

        const { productos: prods } = await fetchProductos( { codigo_barras: codigo } );

        if ( ! prods.length ) {
            PosToast.show( `Código "${ codigo }" no encontrado.`, 'error' );
            return;
        }

        agregarProductoAlCarrito( prods[0] );
    }

    /**
     * Intenta agregar al carrito por coincidencia EXACTA de código de barras o SKU.
     * Devuelve true si agregó un producto (match único), false si no hubo match
     * exacto (para que el llamador caiga a la búsqueda de texto normal).
     *
     * Este es el flujo de pistola: escanear → Enter → producto al carrito.
     */
    async function tryScanToCart( codigo ) {
        codigo = ( codigo || '' ).trim();
        if ( codigo.length < 1 ) return false;

        const { productos: prods } = await fetchProductos( { codigo_barras: codigo } );

        // Solo automatizamos si hay EXACTAMENTE una coincidencia (código único).
        if ( ! prods || prods.length !== 1 ) {
            return false;
        }

        return agregarProductoAlCarrito( prods[0] );
    }

    /**
     * Agrega un producto al carrito validando stock. Devuelve true si se agregó.
     */
    function agregarProductoAlCarrito( prod ) {
        const stock = prod.stock_disponible ?? 0;

        if ( stock <= 0 ) {
            PosToast.show( `${ prod.nombre }: sin stock disponible.`, 'warning' );
            return false;
        }

        PosCarrito.agregar( {
            id:     prod.id,
            nombre: prod.nombre,
            precio: parseFloat( prod.precio ),
            imagen: prod.imagen_url || '',
            sku:    prod.sku || '',
            stock,
        }, 1 );

        PosToast.show( `✓ ${ prod.nombre } agregado`, 'success', 1800 );
        return true;
    }

    // ── Inicialización ────────────────────────────────────────
    function init() {
        const searchInput   = document.getElementById( 'pos-search-input' );
        const barcodeInput  = document.getElementById( 'pos-barcode-input' );
        const searchBtn     = document.getElementById( 'pos-search-btn' );
        const searchForm    = document.getElementById( 'pos-search-form' );
        const grid          = document.getElementById( 'pos-products-grid' );

        if ( ! searchInput ) return;

        // ── Búsqueda por texto con debounce ──
        searchInput.addEventListener( 'input', () => {
            clearTimeout( searchTimer );
            const q = searchInput.value.trim();
            if ( ! q ) {
                hideAutocomplete();
                currentSearch = '';
                // Volver a mostrar todos los productos (página 1).
                loadPage( 1 );
                return;
            }
            searchTimer = setTimeout( () => searchByText( q ), 350 );
        } );

        searchInput.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Enter' ) {
                e.preventDefault();
                clearTimeout( searchTimer );
                const q = searchInput.value.trim();
                hideAutocomplete();
                if ( ! q ) {
                    loadPage( 1 );
                    return;
                }
                // Flujo de pistola: al recibir Enter, intentar primero agregar por
                // código/SKU exacto. Si hay exactamente 1 coincidencia exacta, se
                // agrega al carrito y se limpia el campo (listo para el siguiente
                // escaneo). Si no, cae a la búsqueda de texto normal.
                tryScanToCart( q ).then( ( added ) => {
                    if ( added ) {
                        searchInput.value = '';
                        currentSearch = '';
                        searchInput.focus();
                    } else {
                        searchByText( q );
                    }
                } );
            }
            if ( e.key === 'Escape' ) {
                searchInput.value = '';
                hideAutocomplete();
                currentSearch = '';
                loadPage( 1 );
            }
        } );

        searchBtn?.addEventListener( 'click', () => {
            clearTimeout( searchTimer );
            const q = searchInput.value.trim();
            if ( q ) {
                searchByText( q );
            } else {
                loadPage( 1 );
            }
        } );

        searchForm?.addEventListener( 'submit', ( e ) => {
            e.preventDefault();
            clearTimeout( searchTimer );
            const q = searchInput.value.trim();
            if ( q ) {
                searchByText( q );
            } else {
                loadPage( 1 );
            }
        } );

        // Cerrar autocomplete al hacer click fuera.
        document.addEventListener( 'click', ( e ) => {
            if ( ! e.target.closest( '.pos-autocomplete' ) ) {
                hideAutocomplete();
            }
        } );

        // ── Pistola de código de barras ──────────────────────
        // La pistola es un HID que funciona como teclado.
        // Escribe los caracteres muy rápido (< 30ms entre teclas) y envía Enter.
        // Estrategia: monitorear keydown en document, detectar velocidad de escritura.
        // Si la velocidad es > 4 chars/200ms → es pistola, no humano.

        let barcodeChars   = [];
        let lastBarcodeKey = 0;
        const BARCODE_SPEED_MS  = 50;  // Intervalo máximo entre teclas de pistola.
        const BARCODE_MIN_CHARS = 4;   // Mínimo de caracteres para considerar código.

        // Con un modal abierto (cambio, devolución, cierre de caja…) la pistola
        // le pertenece a ese flujo. Sin esta guarda, un escaneo hecho mientras el
        // foco no está en un input caería aquí y agregaría el producto al carrito
        // por detrás, sin que la cajera lo vea.
        const hayModalAbierto = () =>
            !! document.querySelector( '.pos-modal-overlay:not([hidden])' );

        document.addEventListener( 'keydown', ( e ) => {
            if ( hayModalAbierto() ) return;

            // Ignorar si el foco está en un input de texto (excepto el de pistola).
            const tag   = document.activeElement.tagName;
            const isBarcodeInput = document.activeElement === barcodeInput;
            const isInputField   = [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( tag ) && ! isBarcodeInput;

            if ( isInputField ) return; // Dejar que el input maneje la tecla.

            const now = Date.now();
            const gap = now - lastBarcodeKey;

            if ( e.key === 'Enter' ) {
                // Enter: fin del código de barras.
                const code = barcodeChars.join( '' ).trim();
                barcodeChars = [];
                lastBarcodeKey = 0;

                if ( code.length >= BARCODE_MIN_CHARS ) {
                    // Mostrar indicador visual.
                    updateBarcodeIndicator( code, true );
                    searchByBarcode( code ).finally( () => {
                        setTimeout( () => updateBarcodeIndicator( '', false ), 1500 );
                    } );
                }
                return;
            }

            // Caracter imprimible de 1 carácter.
            if ( e.key.length === 1 && gap < BARCODE_SPEED_MS ) {
                barcodeChars.push( e.key );
            } else if ( e.key.length === 1 ) {
                // Velocidad humana: reiniciar buffer.
                barcodeChars = [ e.key ];
            }

            lastBarcodeKey = now;

            // Timeout de seguridad: si no llega Enter en 300ms, limpiar.
            clearTimeout( barcodeTimer );
            barcodeTimer = setTimeout( () => {
                barcodeChars = [];
            }, 300 );
        } );

        // El barcodeInput oculto captura la pistola como fallback.
        barcodeInput?.addEventListener( 'keydown', ( e ) => {
            if ( hayModalAbierto() ) return;
            if ( e.key === 'Enter' ) {
                const code = barcodeInput.value.trim();
                barcodeInput.value = '';
                if ( code.length >= BARCODE_MIN_CHARS ) {
                    updateBarcodeIndicator( code, true );
                    searchByBarcode( code ).finally( () => {
                        setTimeout( () => updateBarcodeIndicator( '', false ), 1500 );
                    } );
                }
            }
        } );
    }

    function updateBarcodeIndicator( code, reading ) {
        const el = document.getElementById( 'pos-barcode-indicator' );
        if ( ! el ) return;
        if ( reading ) {
            el.className = 'pos-barcode-reading';
            el.innerHTML = `<span class="pos-barcode-indicator-icon"></span> Leyendo: <strong>${ esc( code ) }</strong>`;
        } else {
            el.className = 'pos-barcode-indicator';
            el.innerHTML = `<span class="pos-barcode-indicator-icon"></span> Listo para escanear`;
        }
    }

    // ── Refrescar grid actual (mantiene página o búsqueda activa) ─
    async function recargar() {
        // Los atajos de favoritos muestran stock, así que se refrescan junto
        // con el grid: tras una venta, un cambio o el auto-refresco periódico.
        if ( 'undefined' !== typeof PosFavoritos && PosFavoritos.recargar ) {
            PosFavoritos.recargar();
        }

        if ( currentSearch ) { await searchByText( currentSearch ); return; }
        await loadPage( currentPage );
    }

    // ── Cargar productos al inicio (página 1) ─────────────────
    async function loadInitial() {
        await loadPage( 1 );
    }

    // ══════════════════════════════════════════════════════════
    // AUTO-REFRESCO DE STOCK (varias cajas abiertas a la vez)
    // ══════════════════════════════════════════════════════════
    // Cada caja solo ve el stock del momento en que cargó la página. Si dos
    // cajeras trabajan en paralelo, una puede intentar vender lo que la otra
    // acaba de agotar. El servidor SIEMPRE valida el stock al cobrar (con
    // SELECT ... FOR UPDATE), así que la sobreventa real es imposible; este
    // refresco evita que la cajera se entere recién al momento de cobrar.

    const AUTO_REFRESH_MS = 45000; // 45 s: balance entre frescura y carga al servidor.
    let autoRefreshTimer  = null;

    /**
     * Determina si es seguro refrescar en este instante.
     * No se refresca si la cajera está en medio de algo, para no
     * moverle la pantalla ni perder lo que está escribiendo.
     */
    function puedeRefrescar() {
        // 1. Pestaña en segundo plano: no gastar requests.
        if ( document.hidden ) return false;

        // 2. Algún modal abierto (pago, cierre, devolución, cambio...).
        const modalAbierto = document.querySelector(
            '.pos-modal-overlay:not([hidden])'
        );
        if ( modalAbierto ) return false;

        // 3. La cajera está escribiendo en el buscador.
        const activo = document.activeElement;
        if ( activo && ( 'INPUT' === activo.tagName || 'TEXTAREA' === activo.tagName ) ) {
            return false;
        }

        return true;
    }

    async function autoRefrescar() {
        if ( ! puedeRefrescar() ) return;

        try {
            await recargar();
        } catch {
            // Silencioso: un fallo de red puntual no debe molestar a la cajera.
            // El siguiente ciclo lo reintenta.
        }
    }

    function startAutoRefresh() {
        if ( autoRefreshTimer ) return;
        autoRefreshTimer = setInterval( autoRefrescar, AUTO_REFRESH_MS );

        // Al volver a la pestaña, refrescar de inmediato: es el momento en que
        // más probable es que el stock haya cambiado en la otra caja.
        document.addEventListener( 'visibilitychange', () => {
            if ( ! document.hidden ) autoRefrescar();
        } );
    }

    function stopAutoRefresh() {
        if ( autoRefreshTimer ) {
            clearInterval( autoRefreshTimer );
            autoRefreshTimer = null;
        }
    }

    return {
        init, loadInitial, loadPage, renderGrid, fetchProductos, formatPrice,
        recargar, startAutoRefresh, stopAutoRefresh,
    };
} )();