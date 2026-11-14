/**
 * pos-auth.js – Autenticación y gestión de sesión POS
 *
 * Almacena en sessionStorage:
 *   pos_token        – Token opaco de sesión
 *   pos_user_id      – ID del usuario WP
 *   pos_ubicacion_id – ID de la ubicación activa
 *   pos_rol          – Rol POS
 *   pos_nombre       – Nombre del usuario
 *   pos_ubicacion_nombre – Nombre de la sede
 */

const PosAuth = ( () => {
    'use strict';

    const STORE_PREFIX = 'pos_';
    const API          = window.POS_CONFIG?.apiBase || '/wp-json/pos/v1';

    // ── Almacenamiento ────────────────────────────────────────
    const store = {
        set( key, val ) { sessionStorage.setItem( STORE_PREFIX + key, JSON.stringify( val ) ); },
        get( key )      {
            try { return JSON.parse( sessionStorage.getItem( STORE_PREFIX + key ) ); }
            catch { return null; }
        },
        remove( key )   { sessionStorage.removeItem( STORE_PREFIX + key ); },
        clear()         {
            Object.keys( sessionStorage )
                .filter( k => k.startsWith( STORE_PREFIX ) )
                .forEach( k => sessionStorage.removeItem( k ) );
        },
    };

    // ── Headers autenticados ──────────────────────────────────
    function getAuthHeaders() {
        const token    = store.get( 'token' );
        const userId   = store.get( 'user_id' );
        const ubicId   = store.get( 'ubicacion_id' );

        if ( ! token || ! userId || ! ubicId ) return null;

        return {
            'Content-Type':    'application/json',
            'Authorization':   `Bearer ${ token }`,
            'X-POS-User-Id':   String( userId ),
            'X-POS-Location-Id': String( ubicId ),
        };
    }

    // ── Verificar sesión activa ───────────────────────────────
    function isLoggedIn() {
        const hasAll = !! ( store.get( 'token' ) && store.get( 'user_id' ) && store.get( 'ubicacion_id' ) );
        if ( ! hasAll ) return false;

        // Verificar expiración local (timestamp en segundos del backend).
        const expiry = store.get( 'expiry' );
        if ( expiry && Date.now() / 1000 > expiry ) {
            // Token expirado en el cliente: limpiar y forzar relogin.
            store.clear();
            return false;
        }
        return true;
    }

    // ── Login ─────────────────────────────────────────────────
    async function login( userLogin, password, ubicacionId ) {
        const res = await fetch( `${ API }/login`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify( { user_login: userLogin, password, ubicacion_id: ubicacionId } ),
        } );

        const json = await res.json();

        if ( ! res.ok || ! json.success ) {
            throw new Error( json.message || 'Error de autenticación.' );
        }

        const d = json.data;

        // Limpiar cualquier sesión anterior antes de guardar la nueva.
        // Evita estado corrupto si había token expirado o de otra ubicación.
        store.clear();

        store.set( 'token',             d.token );
        store.set( 'user_id',           d.user_id );
        store.set( 'ubicacion_id',      d.ubicacion_id );
        store.set( 'rol',               d.rol );
        store.set( 'nombre',            d.nombre_usuario );
        store.set( 'expiry',            d.token_expiry );

        return d;
    }

    // ── Guardar nombre de ubicación (llamado tras login) ──────
    function setUbicacionNombre( nombre ) {
        store.set( 'ubicacion_nombre', nombre );
    }

    // ── Logout ────────────────────────────────────────────────
    function logout() {
        store.clear();
        // Redirigir a la página de login del POS.
        window.location.href = POS_CONFIG.loginUrl || ( POS_CONFIG.posUrl + 'login/' );
    }

    // ── Fetch autenticado ─────────────────────────────────────
    async function apiFetch( endpoint, options = {} ) {
        const headers = getAuthHeaders();
        if ( ! headers ) {
            // No hay token: redirigir al login solo si no estamos ya ahí.
            if ( ! document.getElementById( 'pos-login-form' ) ) {
                logout();
            }
            return { success: false, message: 'No hay sesión activa.' };
        }

        let res;
        try {
            res = await fetch( `${ API }${ endpoint }`, {
                ...options,
                headers: {
                    ...headers,
                    ...( options.headers || {} ),
                },
            } );
        } catch ( err ) {
            // Error de red: no hacer logout. Retornar error para que el UI lo maneje.
            return { success: false, message: 'Error de conexión: ' + err.message };
        }

        // Solo cerrar sesión si el server explícitamente dice que el token
        // ya no sirve. NO en errores 5xx ni en errores de red transitorios.
        // Algunas llamadas (ej. verificar contraseña de otra cajera) pueden
        // recibir un 401 que NO significa que la sesión murió: esas pasan
        // options.skipAuthRedirect = true para manejar el error ellas mismas.
        if ( res.status === 401 && ! options.skipAuthRedirect ) {
            // Limpiar y mandar al login.
            logout();
            return { success: false, message: 'Sesión expirada. Inicia sesión nuevamente.' };
        }

        try {
            return await res.json();
        } catch {
            return { success: false, message: 'Respuesta inválida del servidor.' };
        }
    }

    return {
        isLoggedIn,
        login,
        logout,
        apiFetch,
        getHeaders:           getAuthHeaders,
        getToken:             () => store.get( 'token' ),
        getUserId:            () => store.get( 'user_id' ),
        getUbicacionId:       () => store.get( 'ubicacion_id' ),
        getRol:               () => store.get( 'rol' ),
        getNombre:            () => store.get( 'nombre' ),
        getUbicacionNombre:   () => store.get( 'ubicacion_nombre' ),
        setUbicacionNombre,
    };
} )();

// ── Lógica de la página de login ─────────────────────────────
( () => {
    const cfg  = window.POS_CONFIG;
    const isLoginPage = document.getElementById( 'pos-login-form' );
    const isMainPage  = ! isLoginPage; // pos-template.php carga todo en una sola página

    // Si es la página de login.
    if ( isLoginPage ) {
        // Si ya está logueado, redirigir al POS.
        if ( PosAuth.isLoggedIn() ) {
            window.location.href = cfg.posUrl;
            return;
        }

        const form       = document.getElementById( 'pos-login-form' );
        const errorEl    = document.getElementById( 'pos-login-error' );
        const submitBtn  = document.getElementById( 'pos-login-submit' );
        const ubicSelect = document.getElementById( 'pos-login-ubicacion' );

        form?.addEventListener( 'submit', async ( e ) => {
            e.preventDefault();
            errorEl.classList.remove( 'pos-visible' );

            const userLogin   = document.getElementById( 'pos-login-user' ).value.trim();
            const password    = document.getElementById( 'pos-login-pass' ).value;
            const ubicacionId = parseInt( ubicSelect?.value || '0', 10 );

            if ( ! userLogin || ! password ) {
                errorEl.textContent = 'Usuario y contraseña son requeridos.';
                errorEl.classList.add( 'pos-visible' );
                return;
            }
            if ( ! ubicacionId ) {
                errorEl.textContent = 'Selecciona una ubicación.';
                errorEl.classList.add( 'pos-visible' );
                return;
            }

            submitBtn.disabled   = true;
            submitBtn.textContent = 'Verificando…';

            try {
                const data = await PosAuth.login( userLogin, password, ubicacionId );
                // Guardar nombre de ubicación.
                const nombreUb = ubicSelect.options[ ubicSelect.selectedIndex ]?.text || '';
                PosAuth.setUbicacionNombre( nombreUb );
                // Redirigir al POS.
                window.location.href = cfg.posUrl;
            } catch ( err ) {
                errorEl.textContent = err.message || 'Credenciales incorrectas.';
                errorEl.classList.add( 'pos-visible' );
                submitBtn.disabled   = false;
                submitBtn.textContent = 'Ingresar';
            }
        } );
    }
} )();
