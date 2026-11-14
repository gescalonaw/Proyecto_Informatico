/**
 * pos-main.js – Bootstrap y renderizado del shell HTML del POS
 *
 * Renderiza dinámicamente la interfaz completa dentro de #pos-root
 * después de verificar la autenticación. Inyecta el HTML de la
 * pantalla de login o del POS principal según el estado de sesión.
 */

( async () => {
    'use strict';

    const cfg  = window.POS_CONFIG;
    const root = document.getElementById( 'pos-root' );

    // ── Ocultar boot screen con fade ──────────────────────────
    function hideBoot() {
        const boot = document.getElementById( 'pos-boot-screen' );
        if ( ! boot ) return;
        boot.classList.add( 'pos-boot--fade' );
        setTimeout( () => boot.remove(), 320 );
    }

    // ── Renderizar login ──────────────────────────────────────
    async function renderLogin() {
        // Las ubicaciones se cargan luego con loadUbicacionesLogin() desde /ubicaciones.
        // No hacemos requests aquí: cualquier fetch antes de tener token genera 401
        // y contamina el rate limit de la API.

        root.innerHTML = `
        <div class="pos-login-page">
            <div class="pos-login-card">
                <div class="pos-login-header">
                    <span class="pos-login-icon"></span>
                    <h1 class="pos-login-title">POS</h1>
                    <p class="pos-login-subtitle">Sistema de Punto de Venta</p>
                </div>
                <div class="pos-login-body">
                    <div class="pos-login-error" id="pos-login-error"></div>

                    <form id="pos-login-form" novalidate>
                        <div class="pos-form-group">
                            <label for="pos-login-user">Usuario</label>
                            <input
                                type="text"
                                id="pos-login-user"
                                class="pos-input"
                                autocomplete="username"
                                placeholder="Usuario o email"
                                autofocus
                                required>
                        </div>
                        <div class="pos-form-group">
                            <label for="pos-login-pass">Contraseña</label>
                            <input
                                type="password"
                                id="pos-login-pass"
                                class="pos-input"
                                autocomplete="current-password"
                                placeholder="Contraseña"
                                required>
                        </div>
                        <div class="pos-form-group" id="pos-login-ubicacion-group">
                            <label for="pos-login-ubicacion">Sede / Ubicación</label>
                            <select id="pos-login-ubicacion" class="pos-input" required>
                                <option value="">Cargando sedes…</option>
                            </select>
                        </div>
                        <button
                            type="submit"
                            id="pos-login-submit"
                            class="pos-btn pos-btn--primary pos-btn--full"
                            style="margin-top:8px;">
                            Ingresar →
                        </button>
                    </form>
                </div>
                <div class="pos-login-footer">
                    POS Sistema de Inventario Multisede
                </div>
            </div>
        </div>`;

        // Cargar ubicaciones en el select desde la API admin (pública sin auth).
        loadUbicacionesLogin();

        // Activar lógica de login (definida en pos-auth.js).
        // El event listener del form se registra dentro de pos-auth.js al detectar #pos-login-form.
        // Reiniciar binding ya que el DOM cambió.
        initLoginForm();
    }

    async function loadUbicacionesLogin() {
        const select = document.getElementById( 'pos-login-ubicacion' );
        if ( ! select ) return;

        try {
            // Endpoint especial sin auth para listar ubicaciones activas.
            // Laravel: solo las sedes del negocio de la URL (multi-tenant).
            const res  = await fetch( `${ cfg.apiBase }/ubicaciones?negocio=${ encodeURIComponent( cfg.negocio || '' ) }` );
            const json = await res.json();
            const ubs  = json?.data?.ubicaciones || [];

            if ( ubs.length ) {
                select.innerHTML = '<option value="">— Selecciona sede —</option>'
                    + ubs.map( u => `<option value="${ u.id }">${ u.nombre } (${ u.codigo })</option>` ).join( '' );
            } else {
                select.innerHTML = '<option value="">Sin sedes disponibles</option>';
            }
        } catch {
            select.innerHTML = '<option value="">Error al cargar sedes</option>';
        }
    }

    function initLoginForm() {
        const form      = document.getElementById( 'pos-login-form' );
        const errorEl   = document.getElementById( 'pos-login-error' );
        const submitBtn = document.getElementById( 'pos-login-submit' );
        const ubicSel   = document.getElementById( 'pos-login-ubicacion' );

        if ( ! form ) return;

        form.addEventListener( 'submit', async ( e ) => {
            e.preventDefault();
            errorEl.style.display = 'none';

            const user    = document.getElementById( 'pos-login-user' ).value.trim();
            const pass    = document.getElementById( 'pos-login-pass' ).value;
            const ubicId  = parseInt( ubicSel?.value || '0', 10 );

            if ( ! user || ! pass ) {
                showLoginError( errorEl, 'Completa usuario y contraseña.' );
                return;
            }
            if ( ! ubicId ) {
                showLoginError( errorEl, 'Selecciona una sede.' );
                return;
            }

            submitBtn.disabled   = true;
            submitBtn.textContent = 'Verificando…';

            try {
                await PosAuth.login( user, pass, ubicId );
                const nombre = ubicSel.options[ ubicSel.selectedIndex ]?.text || '';
                PosAuth.setUbicacionNombre( nombre );
                renderApp(); // Ir al POS principal.
            } catch ( err ) {
                showLoginError( errorEl, err.message || 'Credenciales incorrectas.' );
                submitBtn.disabled   = false;
                submitBtn.textContent = 'Ingresar →';
            }
        } );
    }

    function showLoginError( el, msg ) {
        el.textContent     = msg;
        el.style.display   = 'block';
        el.style.animation = 'pos-fade-in .2s ease';
    }

    // ── Renderizar POS principal ──────────────────────────────
    function renderApp() {
        const nombre    = PosAuth.getNombre() || 'Usuario';
        const ubicNombre = PosAuth.getUbicacionNombre() || 'Sin sede';

        root.innerHTML = `
        <div class="pos-app" id="pos-app-inner">

            <!-- HEADER -->
            <header class="pos-header">
                <div class="pos-header-brand">
                    <span class="pos-header-brand-icon"></span>
                    POS
                </div>
                <div class="pos-header-divider"></div>
                <div class="pos-header-info">
                    <span class="pos-header-greeting">Hola, ${ escH( nombre ) }</span>
                    <span class="pos-header-location">${ escH( ubicNombre ) }</span>
                </div>
                <div class="pos-header-divider"></div>
                <button class="pos-cajera-selector" id="pos-cajera-selector" title="Cambiar cajera activa">
                    <span class="pos-cajera-selector-label">Cajera:</span>
                    <span class="pos-cajera-selector-name" id="pos-cajera-activa-nombre">—</span>
                    <span class="pos-cajera-selector-caret">▾</span>
                </button>
                <div class="pos-header-spacer"></div>
                <span class="pos-header-clock" id="pos-clock">00:00</span>
                <div class="pos-header-divider"></div>
                <div class="pos-header-actions">
                    <button class="pos-btn pos-btn--ghost pos-btn--sm" id="pos-btn-cambio">
                        ⇄ Cambio
                    </button>
                    <button class="pos-btn pos-btn--ghost pos-btn--sm" id="pos-btn-reimpresion">
                        Reimprimir
                    </button>
                    <button class="pos-btn pos-btn--ghost pos-btn--sm" id="pos-btn-cierre-caja">
                        Cierre de caja
                    </button>
                    <button class="pos-btn pos-btn--ghost pos-btn--sm pos-btn--icon" id="pos-btn-logout" title="Cerrar sesión">
                        ⏻
                    </button>
                </div>
            </header>

            <!-- BODY: 3 columnas -->
            <div class="pos-body">

                <!-- ── COLUMNA IZQUIERDA: búsqueda ── -->
                <aside class="pos-panel-search">
                    <div class="pos-panel-title">Buscar producto</div>

                    <!-- Indicador de pistola -->
                    <div class="pos-barcode-indicator" id="pos-barcode-indicator">
                        <span class="pos-barcode-indicator-icon"></span>
                        Listo para escanear
                    </div>

                    <!-- Input oculto para pistola de código de barras -->
                    <input
                        type="text"
                        id="pos-barcode-input"
                        class="pos-barcode-field"
                        autocomplete="off"
                        tabindex="-1"
                        aria-hidden="true">

                    <!-- Búsqueda por texto -->
                    <div class="pos-autocomplete">
                        <form id="pos-search-form" style="display:flex;gap:6px;">
                            <input
                                type="search"
                                id="pos-search-input"
                                class="pos-input"
                                autocomplete="off"
                                placeholder="Nombre, SKU…">
                            <button
                                type="submit"
                                id="pos-search-btn"
                                class="pos-btn pos-btn--primary pos-btn--sm"
                                style="flex-shrink:0;">
                                Buscar
                            </button>
                        </form>
                        <div id="pos-autocomplete-list" class="pos-autocomplete-list"></div>
                    </div>

                    <!-- Atajos: productos favoritos de esta sede.
                         Se marcan desde Inventario en el admin. Si la sede no
                         tiene ninguno, el panel queda oculto. -->
                    <section class="pos-favoritos-panel" id="pos-favoritos-panel" hidden>
                        <div class="pos-favoritos-header">
                            <span class="pos-favoritos-title">
                                Favoritos <span class="pos-favoritos-count" id="pos-favoritos-count"></span>
                            </span>
                            <button
                                type="button"
                                class="pos-favoritos-toggle"
                                id="pos-favoritos-toggle"
                                aria-expanded="true"
                                aria-controls="pos-favoritos-list"
                                title="Mostrar u ocultar los atajos">▾</button>
                        </div>
                        <div class="pos-favoritos-list" id="pos-favoritos-list"></div>
                    </section>
                </aside>

                <!-- ── COLUMNA CENTRAL: grid de productos ── -->
                <main class="pos-panel-products">
                    <div class="pos-products-grid" id="pos-products-grid">
                        <!-- productos renderizados por JS -->
                    </div>
                </main>

                <!-- ── COLUMNA DERECHA: carrito ── -->
                <aside class="pos-panel-cart">
                    <div class="pos-cart-header">
                        <div class="pos-cart-title">
                            Carrito
                            <span class="pos-cart-count" id="pos-cart-count"></span>
                        </div>
                    </div>

                    <div class="pos-cart-items" id="pos-cart-items">
                        <div class="pos-cart-empty" id="pos-cart-empty" style="display:flex;">
                            <span class="pos-cart-empty-icon"></span>
                            <span class="pos-cart-empty-text">El carrito está vacío</span>
                        </div>
                    </div>

                    <div class="pos-cart-footer">
                        <!-- Panel de descuento -->
                        <div class="pos-discount-bar">
                            <button class="pos-btn pos-btn--outline pos-btn--sm" id="pos-discount-toggle" type="button">
                                Descuento
                            </button>
                            <div class="pos-discount-panel" id="pos-discount-panel" hidden>
                                <div class="pos-discount-presets">
                                    <button class="pos-discount-preset" data-pct="5"  type="button">5%</button>
                                    <button class="pos-discount-preset" data-pct="10" type="button">10%</button>
                                    <button class="pos-discount-preset" data-pct="15" type="button">15%</button>
                                    <button class="pos-discount-preset" data-pct="20" type="button">20%</button>
                                </div>
                                <div class="pos-discount-custom">
                                    <input type="number" id="pos-discount-input" class="pos-input pos-input--sm"
                                        placeholder="Otro %" min="0" max="100" step="1">
                                    <span class="pos-discount-pct-sign">%</span>
                                    <button class="pos-btn pos-btn--ghost pos-btn--sm" id="pos-discount-clear" type="button" title="Quitar descuento">
                                        ✕
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="pos-cart-summary-row">
                            <span>Subtotal</span>
                            <span id="pos-cart-subtotal-amount">$0</span>
                        </div>
                        <div class="pos-cart-summary-row pos-cart-discount-row" id="pos-cart-discount-row" hidden>
                            <span>Descuento (<span id="pos-cart-discount-pct">0</span>%)</span>
                            <span id="pos-cart-discount-amount">−$0</span>
                        </div>
                        <div class="pos-cart-total">
                            <span class="pos-cart-total-label">TOTAL</span>
                            <span class="pos-cart-total-amount" id="pos-cart-total-amount">$0</span>
                        </div>
                        <div class="pos-cart-actions">
                            <button class="pos-btn pos-btn--outline pos-btn--sm" id="pos-btn-vaciar" disabled>
                                Vaciar
                            </button>
                            <button class="pos-btn pos-btn--success pos-btn--lg" id="pos-btn-pagar" disabled>
                                Pagar
                            </button>
                        </div>
                    </div>
                </aside>
            </div>
        </div>

        ${ tplModalPago() }
        ${ tplModalComprobante() }
        ${ tplModalCierre() }
        ${ tplModalApertura() }
        ${ tplModalCajera() }
        ${ 'undefined' !== typeof PosCambio ? PosCambio.tplModal() : '' }
        ${ 'undefined' !== typeof PosReimpresion ? PosReimpresion.tplModal() : '' }

        <div id="pos-toast-wrap"></div>
        <div id="pos-loading-overlay" class="pos-loading-overlay" hidden>
            <div class="pos-spinner-ring" style="border-color:rgba(255,255,255,.3);border-top-color:#fff;"></div>
        </div>`;

        // Inicializar módulos.
        PosCarrito.init();
        PosProductos.init();
        if ( 'undefined' !== typeof PosFavoritos ) PosFavoritos.init();
        PosPago.init();
        if ( 'undefined' !== typeof PosCambio ) PosCambio.init();
        if ( 'undefined' !== typeof PosReimpresion ) PosReimpresion.init();
        initClock();
        PosProductos.loadInitial();

        // Mantener el stock sincronizado si hay más de una caja abierta.
        PosProductos.startAutoRefresh();

        // Cajera activa: por defecto, quien inició sesión.
        cajeraActiva = { id: parseInt( PosAuth.getUserId(), 10 ), nombre: nombre };
        actualizarCajeraUI();
        bindCajeraEvents();
        bindAperturaEvents();

        // Apertura de caja si no hay caja abierta en la ubicación.
        checkAndOpenCaja();
    }

    // ── Cajera activa (modelo de caja compartida) ─────────────
    let cajeraActiva = null;
    let cajerasCache = null;

    function getCajeraActivaId() {
        return cajeraActiva?.id || parseInt( PosAuth.getUserId(), 10 );
    }

    function actualizarCajeraUI() {
        const el = document.getElementById( 'pos-cajera-activa-nombre' );
        if ( el ) el.textContent = cajeraActiva?.nombre || '—';
    }

    function bindAperturaEvents() {
        document.getElementById( 'pos-apertura-submit' )
            ?.addEventListener( 'click', confirmarApertura );
        // Enter en el input confirma.
        document.getElementById( 'pos-apertura-efectivo' )
            ?.addEventListener( 'keydown', ( e ) => {
                if ( e.key === 'Enter' ) { e.preventDefault(); confirmarApertura(); }
            } );
    }

    function bindCajeraEvents() {
        document.getElementById( 'pos-cajera-selector' )
            ?.addEventListener( 'click', abrirSelectorCajera );
        document.getElementById( 'pos-modal-cajera-overlay' )
            ?.addEventListener( 'click', ( e ) => {
                if ( e.target === e.currentTarget ) {
                    e.currentTarget.setAttribute( 'hidden', '' );
                }
            } );
    }

    async function abrirSelectorCajera() {
        const overlay = document.getElementById( 'pos-modal-cajera-overlay' );
        const lista   = document.getElementById( 'pos-cajera-lista' );
        if ( ! overlay || ! lista ) return;

        overlay.removeAttribute( 'hidden' );

        // Cargar cajeras (con caché para no repetir la llamada).
        if ( ! cajerasCache ) {
            try {
                const ubic = PosAuth.getUbicacionId();
                const json = await PosAuth.apiFetch( '/cajeras?ubicacion_id=' + encodeURIComponent( ubic ) );
                cajerasCache = json?.data?.cajeras || [];
            } catch ( _ ) {
                cajerasCache = [];
            }
        }

        if ( cajerasCache.length === 0 ) {
            lista.innerHTML = '<div style="color:#9ca3af;">No hay cajeras disponibles.</div>';
            return;
        }

        const activaId = getCajeraActivaId();
        lista.innerHTML = cajerasCache.map( c => `
            <button class="pos-cajera-item ${ c.id === activaId ? 'pos-cajera-item--active' : '' }"
                data-id="${ c.id }" data-nombre="${ escH( c.nombre ) }">
                <span class="pos-cajera-item-name">${ escH( c.nombre ) }</span>
                ${ c.id === activaId ? '<span class="pos-cajera-item-check">✓</span>' : '' }
            </button>
        ` ).join( '' );

        lista.querySelectorAll( '.pos-cajera-item' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const id     = parseInt( btn.dataset.id, 10 );
                const nombre = btn.dataset.nombre;
                // Si ya es la activa, no pedir nada.
                if ( id === getCajeraActivaId() ) {
                    overlay.setAttribute( 'hidden', '' );
                    return;
                }
                pedirPasswordCajera( id, nombre );
            } );
        } );
    }

    // Pide la contraseña de la cajera seleccionada y la verifica en el backend
    // antes de activarla. Sin verificación correcta, no se cambia.
    function pedirPasswordCajera( id, nombre ) {
        const lista = document.getElementById( 'pos-cajera-lista' );
        if ( ! lista ) return;

        lista.innerHTML = `
            <div class="pos-cajera-pass">
                <p class="pos-cajera-pass-title">Contraseña de <strong>${ escH( nombre ) }</strong></p>
                <input type="password" id="pos-cajera-pass-input" class="pos-input"
                    autocomplete="off" placeholder="Contraseña">
                <div id="pos-cajera-pass-error" class="pos-cajera-pass-error" hidden></div>
                <div class="pos-cajera-pass-actions">
                    <button class="pos-btn pos-btn--outline" id="pos-cajera-pass-cancel">Volver</button>
                    <button class="pos-btn pos-btn--success" id="pos-cajera-pass-confirm">Confirmar</button>
                </div>
            </div>`;

        const input   = document.getElementById( 'pos-cajera-pass-input' );
        const errorEl = document.getElementById( 'pos-cajera-pass-error' );
        const btnOk   = document.getElementById( 'pos-cajera-pass-confirm' );
        const btnBack = document.getElementById( 'pos-cajera-pass-cancel' );

        setTimeout( () => input?.focus(), 50 );

        btnBack?.addEventListener( 'click', () => abrirSelectorCajera() );

        async function confirmar() {
            const password = input?.value || '';
            if ( ! password ) {
                errorEl.textContent = 'Ingresa la contraseña.';
                errorEl.removeAttribute( 'hidden' );
                return;
            }
            btnOk.disabled = true;
            btnOk.textContent = 'Verificando…';
            errorEl.setAttribute( 'hidden', '' );

            try {
                const json = await PosAuth.apiFetch( '/verificar-cajera', {
                    method: 'POST',
                    skipAuthRedirect: true,
                    body:   JSON.stringify( { cajera_id: id, password } ),
                } );

                if ( ! json?.success ) {
                    throw new Error( json?.message || 'Contraseña incorrecta.' );
                }

                // Verificada: activar.
                cajeraActiva = { id, nombre };
                actualizarCajeraUI();
                document.getElementById( 'pos-modal-cajera-overlay' )?.setAttribute( 'hidden', '' );
                PosToast.show( 'Cajera activa: ' + nombre, 'success', 2000 );
            } catch ( err ) {
                errorEl.textContent = err.message || 'Contraseña incorrecta.';
                errorEl.removeAttribute( 'hidden' );
                btnOk.disabled = false;
                btnOk.textContent = 'Confirmar';
                input?.focus();
                input?.select();
            }
        }

        btnOk?.addEventListener( 'click', confirmar );
        input?.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Enter' ) { e.preventDefault(); confirmar(); }
        } );
    }

    // ── Verificar y abrir caja (modelo de caja compartida por ubicación) ──
    async function checkAndOpenCaja() {
        try {
            const ubic = PosAuth.getUbicacionId();
            const json = await PosAuth.apiFetch( '/caja-abierta?ubicacion_id=' + encodeURIComponent( ubic ) );

            if ( ! json?.data?.caja ) {
                // No hay caja abierta en esta ubicación: pedir el efectivo inicial
                // del día a la primera cajera antes de poder vender.
                pedirEfectivoInicial();
            }
        } catch ( _ ) { /* silencioso – no bloquear el POS */ }
    }

    // Diálogo de apertura de caja: la primera cajera ingresa el efectivo inicial.
    function pedirEfectivoInicial() {
        const overlay = document.getElementById( 'pos-modal-apertura-overlay' );
        if ( ! overlay ) return;
        const input = document.getElementById( 'pos-apertura-efectivo' );
        if ( input ) input.value = '';
        overlay.removeAttribute( 'hidden' );
        setTimeout( () => input?.focus(), 100 );
    }

    async function confirmarApertura() {
        const input = document.getElementById( 'pos-apertura-efectivo' );
        const btn   = document.getElementById( 'pos-apertura-submit' );
        const efectivo = Math.round( parseFloat( input?.value || '0' ) || 0 );

        if ( efectivo < 0 ) {
            PosToast.show( 'El monto no puede ser negativo.', 'error' );
            return;
        }

        if ( btn ) { btn.disabled = true; btn.textContent = 'Abriendo…'; }

        try {
            const json = await PosAuth.apiFetch( '/apertura-caja', {
                method: 'POST',
                body:   JSON.stringify( {
                    ubicacion_id:     PosAuth.getUbicacionId(),
                    efectivo_inicial: efectivo,
                } ),
            } );

            if ( ! json?.success ) {
                throw new Error( json?.message || 'No se pudo abrir la caja.' );
            }

            document.getElementById( 'pos-modal-apertura-overlay' )?.setAttribute( 'hidden', '' );
            PosToast.show( 'Caja abierta con ' + PosProductos.formatPrice( efectivo ) + '. ¡Buen turno!', 'success', 3500 );
        } catch ( err ) {
            PosToast.show( err.message || 'Error al abrir la caja.', 'error' );
        } finally {
            if ( btn ) { btn.disabled = false; btn.textContent = 'Abrir caja'; }
        }
    }

    // ── Reloj ─────────────────────────────────────────────────
    function initClock() {
        const clockEl = document.getElementById( 'pos-clock' );
        if ( ! clockEl ) return;

        function tick() {
            const now = new Date();
            clockEl.textContent = now.toLocaleTimeString( 'es-CL', { hour: '2-digit', minute: '2-digit' } );
        }
        tick();
        setInterval( tick, 10000 );
    }

    // ── Templates HTML de modales ─────────────────────────────
    function tplModalPago() {
        return `
        <div id="pos-modal-pago-overlay" class="pos-modal-overlay" hidden>
            <div class="pos-modal pos-modal--wide">
                <div class="pos-modal-header">
                    <span class="pos-modal-title">Pago</span>
                    <button class="pos-modal-close" id="pos-pago-close">✕</button>
                </div>
                <div class="pos-modal-body">
                    <!-- Total -->
                    <div class="pos-pago-total">
                        <div class="pos-pago-total-label">Total a pagar</div>
                        <div class="pos-pago-total-amount" id="pos-pago-total-amount">$0</div>
                        <div class="pos-pago-breakdown" id="pos-pago-breakdown" hidden>
                            <div class="pos-pago-breakdown-row">
                                <span>Subtotal</span>
                                <span id="pos-pago-subtotal">$0</span>
                            </div>
                            <div class="pos-pago-breakdown-row pos-pago-breakdown-row--discount">
                                <span>Descuento (<span id="pos-pago-discount-pct">0</span>%)</span>
                                <span id="pos-pago-discount-amount">−$0</span>
                            </div>
                        </div>

                        <!-- Editor de descuento dentro del pago (corrige sin volver atrás) -->
                        <div class="pos-pago-discount-edit">
                            <div class="pos-discount-presets">
                                <button class="pos-discount-preset" data-pct="0"  type="button">Sin desc.</button>
                                <button class="pos-discount-preset" data-pct="5"  type="button">5%</button>
                                <button class="pos-discount-preset" data-pct="10" type="button">10%</button>
                                <button class="pos-discount-preset" data-pct="15" type="button">15%</button>
                                <button class="pos-discount-preset" data-pct="20" type="button">20%</button>
                            </div>
                            <div class="pos-discount-custom">
                                <input type="number" id="pos-pago-discount-input" class="pos-input pos-input--sm"
                                    placeholder="Otro %" min="0" max="100" step="1">
                                <span class="pos-discount-pct-sign">%</span>
                            </div>
                        </div>
                    </div>

                    <p style="font-size:.875rem;color:#6b7280;margin:14px 0 8px;">
                        Ingresa cuánto se paga con cada método. Puedes combinar varios.
                    </p>

                    <!-- Inputs por método de pago -->
                    <div class="pos-pagos-grid">
                        <div class="pos-pago-row">
                            <label for="pos-pago-efectivo">
                                <span class="pos-pago-icon"></span> Efectivo
                            </label>
                            <input type="number" id="pos-pago-efectivo" class="pos-input pos-pago-input"
                                placeholder="0" min="0" step="any" data-metodo="efectivo">
                            <button type="button" class="pos-pago-fill" data-target="pos-pago-efectivo" title="Pagar todo con este método">
                                Todo
                            </button>
                        </div>

                        <div class="pos-pago-row">
                            <label for="pos-pago-debito">
                                <span class="pos-pago-icon"></span> Débito
                            </label>
                            <input type="number" id="pos-pago-debito" class="pos-input pos-pago-input"
                                placeholder="0" min="0" step="any" data-metodo="debito">
                            <button type="button" class="pos-pago-fill" data-target="pos-pago-debito" title="Pagar todo con este método">
                                Todo
                            </button>
                        </div>

                        <div class="pos-pago-row">
                            <label for="pos-pago-credito">
                                <span class="pos-pago-icon"></span> Crédito
                            </label>
                            <input type="number" id="pos-pago-credito" class="pos-input pos-pago-input"
                                placeholder="0" min="0" step="any" data-metodo="credito">
                            <button type="button" class="pos-pago-fill" data-target="pos-pago-credito" title="Pagar todo con este método">
                                Todo
                            </button>
                        </div>

                        <div class="pos-pago-row">
                            <label for="pos-pago-transferencia">
                                <span class="pos-pago-icon"></span> Transferencia
                            </label>
                            <input type="number" id="pos-pago-transferencia" class="pos-input pos-pago-input"
                                placeholder="0" min="0" step="any" data-metodo="transferencia">
                            <button type="button" class="pos-pago-fill" data-target="pos-pago-transferencia" title="Pagar todo con este método">
                                Todo
                            </button>
                        </div>
                    </div>

                    <!-- Comprobante de la transferencia. Aparece solo cuando hay
                         monto en transferencia: es dato muerto en cualquier otro caso. -->
                    <div class="pos-pago-comprobante" id="pos-pago-comprobante-row" hidden>
                        <label for="pos-pago-comprobante">
                            Comprobante <span class="pos-pago-comprobante-opt">(opcional)</span>
                        </label>
                        <input type="text" id="pos-pago-comprobante" class="pos-input"
                            maxlength="120" autocomplete="off"
                            placeholder="Nº de operación o nombre de quien transfirió">
                    </div>

                    <!-- Resumen: total pagado, falta o vuelto -->
                    <div class="pos-pago-resumen">
                        <div class="pos-pago-resumen-row">
                            <span>Total ingresado:</span>
                            <span id="pos-pago-ingresado">$0</span>
                        </div>
                        <div class="pos-pago-resumen-row pos-pago-resumen-row--main" id="pos-pago-estado-row">
                            <span id="pos-pago-estado-label">Falta:</span>
                            <span id="pos-pago-estado-amount" class="pos-pago-estado-amount">$0</span>
                        </div>
                        <div class="pos-pago-resumen-row pos-pago-resumen-row--vuelto" id="pos-pago-vuelto-row" hidden>
                            <span>Vuelto en efectivo:</span>
                            <span id="pos-pago-vuelto-amount" class="pos-pago-vuelto-amount">$0</span>
                        </div>
                    </div>
                </div>
                <div class="pos-modal-footer">
                    <button class="pos-btn pos-btn--outline" id="pos-pago-close-2"
                        onclick="document.getElementById('pos-modal-pago-overlay').setAttribute('hidden','')">
                        Cancelar
                    </button>
                    <button class="pos-btn pos-btn--success pos-btn--lg" id="pos-pago-submit" disabled>
                        ✓ Completar venta
                    </button>
                </div>
            </div>
        </div>`;
    }

    function tplModalComprobante() {
        return `
        <div id="pos-modal-comprobante-overlay" class="pos-modal-overlay" hidden>
            <div class="pos-modal pos-modal--wide">
                <div class="pos-modal-header">
                    <span class="pos-modal-title">✓ Venta completada</span>
                    <button class="pos-modal-close" id="pos-comprobante-close">✕</button>
                </div>
                <div class="pos-modal-body" id="pos-comprobante-print">
                    <div class="pos-comprobante">
                        <div class="pos-comprobante-header">
                            <div class="pos-comprobante-title">Comprobante de Venta</div>
                            <div class="pos-comprobante-ref">
                                Ref: <span id="pos-comprobante-ref">—</span>
                                · <span id="pos-comprobante-fecha">—</span>
                            </div>
                            <div style="font-size:.75rem;opacity:.8;margin-top:2px;">
                                Método: <span id="pos-comprobante-metodo">—</span>
                            </div>
                        </div>
                        <div class="pos-comprobante-body">
                            <table class="pos-comprobante-items">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th style="text-align:center">Cant.</th>
                                        <th style="text-align:right">P.Unit.</th>
                                        <th style="text-align:right">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody id="pos-comprobante-items-body"></tbody>
                            </table>
                            <div class="pos-comprobante-total">
                                <span>TOTAL</span>
                                <span id="pos-comprobante-total-value">$0</span>
                            </div>
                            <div id="pos-comprobante-vuelto-row" style="margin-top:8px;font-size:.875rem;">
                                <div style="display:flex;justify-content:space-between;">
                                    <span>Recibido:</span>
                                    <span id="pos-comprobante-recibido">$0</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;font-weight:700;">
                                    <span>Vuelto:</span>
                                    <span id="pos-comprobante-vuelto">$0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="pos-modal-footer">
                    <button class="pos-btn pos-btn--outline" id="pos-comprobante-print-btn">Imprimir</button>
                    <button class="pos-btn pos-btn--primary" id="pos-comprobante-nueva-venta">✚ Nueva venta</button>
                </div>
            </div>
        </div>`;
    }

    function tplModalCierre() {
        return `
        <div id="pos-modal-cierre-overlay" class="pos-modal-overlay" hidden>
            <div class="pos-modal">
                <div class="pos-modal-header">
                    <span class="pos-modal-title">Cierre de Caja</span>
                    <button class="pos-modal-close" id="pos-cierre-close">✕</button>
                </div>
                <div class="pos-modal-body">
                    <p style="font-size:.875rem;color:#6b7280;margin-bottom:14px;">
                        Caja abierta desde: <strong id="pos-cierre-apertura">—</strong>
                    </p>

                    <div class="pos-form-group">
                        <label for="pos-efectivo-contado">Efectivo contado en caja</label>
                        <input type="number" id="pos-efectivo-contado" class="pos-input pos-input-large"
                            placeholder="0" min="0" step="any" autofocus>
                        <p style="font-size:.8125rem;color:#6b7280;margin-top:6px;">
                            Cuenta físicamente el efectivo en caja e ingresa el monto total.
                        </p>
                    </div>

                    <div class="pos-form-group">
                        <label for="pos-tarjeta-contado">
                            Total tarjetas según Transbank
                            <span style="font-weight:400;color:#9ca3af;font-size:.8rem;">(opcional)</span>
                        </label>
                        <input type="number" id="pos-tarjeta-contado" class="pos-input pos-input-large"
                            placeholder="0" min="0" step="any">
                        <p style="font-size:.8125rem;color:#6b7280;margin-top:6px;">
                            Débito + crédito juntos, tal como aparece en el cierre
                            de la maquinita. Si lo dejas vacío, no se compara.
                        </p>
                    </div>

                    <div class="pos-form-group">
                        <label for="pos-transferencia-contado">
                            Total transferencias según el banco
                            <span style="font-weight:400;color:#9ca3af;font-size:.8rem;">(opcional)</span>
                        </label>
                        <input type="number" id="pos-transferencia-contado" class="pos-input pos-input-large"
                            placeholder="0" min="0" step="any">
                        <p style="font-size:.8125rem;color:#6b7280;margin-top:6px;">
                            Suma de las transferencias recibidas en el turno, según
                            la cartola o la app del banco. Si lo dejas vacío, no se compara.
                        </p>
                    </div>

                    <div id="pos-cierre-resultado" hidden></div>
                </div>
                <div class="pos-modal-footer">
                    <button class="pos-btn pos-btn--outline" id="pos-cierre-cancel"
                        onclick="document.getElementById('pos-modal-cierre-overlay').setAttribute('hidden','')">
                        Cancelar
                    </button>
                    <button class="pos-btn pos-btn--danger" id="pos-cierre-submit">
                        Cerrar caja
                    </button>
                </div>
            </div>
        </div>`;
    }

    // Modal: apertura de caja (efectivo inicial del día).
    function tplModalApertura() {
        return `
        <div id="pos-modal-apertura-overlay" class="pos-modal-overlay" hidden>
            <div class="pos-modal pos-modal--sm">
                <div class="pos-modal-header">
                    <h2>Abrir caja del día</h2>
                </div>
                <div class="pos-modal-body">
                    <p style="margin-top:0;color:#6b7280;">
                        Ingresa el efectivo con el que parte la caja (fondo para vuelto).
                        Si parte en cero, deja el campo en 0.
                    </p>
                    <div class="pos-form-group">
                        <label for="pos-apertura-efectivo">Efectivo inicial en caja</label>
                        <input type="number" id="pos-apertura-efectivo" class="pos-input"
                            inputmode="numeric" min="0" step="1" placeholder="0">
                    </div>
                </div>
                <div class="pos-modal-footer">
                    <button class="pos-btn pos-btn--success" id="pos-apertura-submit" style="width:100%;">
                        Abrir caja
                    </button>
                </div>
            </div>
        </div>`;
    }

    // Modal: selección rápida de cajera activa (sin clave; confían entre ellas).
    function tplModalCajera() {
        return `
        <div id="pos-modal-cajera-overlay" class="pos-modal-overlay" hidden>
            <div class="pos-modal pos-modal--sm">
                <div class="pos-modal-header">
                    <h2>Cambiar cajera activa</h2>
                    <button class="pos-modal-close" id="pos-cajera-close"
                        onclick="document.getElementById('pos-modal-cajera-overlay').setAttribute('hidden','')">✕</button>
                </div>
                <div class="pos-modal-body">
                    <p style="margin-top:0;color:#6b7280;">
                        Selecciona quién está atendiendo. Las ventas se registrarán a su nombre.
                    </p>
                    <div id="pos-cajera-lista" class="pos-cajera-lista">
                        <div style="color:#9ca3af;">Cargando cajeras…</div>
                    </div>
                </div>
            </div>
        </div>`;
    }

    // ── Helper escapeHtml ──────────────────────────────────────
    function escH( str ) {
        const d = document.createElement( 'div' );
        d.textContent = str ?? '';
        return d.innerHTML;
    }

    // ── Bootstrap ──────────────────────────────────────────────
    hideBoot();

    if ( PosAuth.isLoggedIn() ) {
        renderApp();
    } else {
        renderLogin();
    }

    // Exponer la cajera activa para que otros módulos (pago) la usen al vender.
    window.PosMain = {
        getCajeraActivaId,
    };


} )();