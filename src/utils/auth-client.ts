/**
 * USGAR Hotels — Auth Client (Browser-side)
 *
 * Helpers para interactuar con la API de autenticación desde el frontend.
 * Se ejecutan en el navegador (client-side), no en el servidor.
 *
 * Cache del usuario autenticado con TTL corto (5 min) para evitar llamadas
 * repetidas a /api/auth/me en cada navegación (las View Transitions de Astro
 * recargan scripts). Invalidación cross-tab vía BroadcastChannel + storage
 * (sessionStorage es por pestaña: al llegar el evento se limpia el cache local
 * y el próximo getUser() re-sincroniza contra la cookie).
 * 401: la cookie JWT no tiene endpoint de renovación (docs/API.md §Auth) —
 * el "refresh" es de ESTADO: invalidar cache, notificar y reintentar una vez
 * por si otra pestaña acaba de re-loggear; si persiste, no hay sesión.
 * /api/auth/me + cookie HttpOnly usgar_session confirmados en public/index.php
 * y SessionService; la cookie usgar_csrf la exige X-CSRF-Token.
 */

const AUTH_API_BASE = '/api/auth';
const CACHE_KEY = 'usgar_auth_user';
const AUTH_BROADCAST_KEY = 'usgar_auth_event';
const BROADCAST_CHANNEL_NAME = 'usgar_auth';
const TAB_ID = (() => {
  try {
    const key = 'usgar_auth_tab_id';
    const existing = sessionStorage.getItem(key);
    if (existing) return existing;
    const created = Date.now() + '-' + Math.random().toString(36).slice(2);
    sessionStorage.setItem(key, created);
    return created;
  } catch {
    return Date.now() + '-' + Math.random().toString(36).slice(2);
  }
})();
/** TTL del cache: acota la ventana de staleness sin costar un /me por click. */
const CACHE_TTL_MS = 5 * 60 * 1000;
/** Espera antes del único reintento tras un 401 (carrera entre pestañas). */
const AUTH_RETRY_DELAY_MS = 250;
const CSRF_COOKIE = 'usgar_csrf';

interface AuthUser {
  sub: number;
  name: string;
  email: string;
  photo: string | null;
  provider: string;
}

interface AuthCacheEntry {
  user: AuthUser;
  cachedAt: number;
}

/**
 * Token CSRF de la cookie de doble envio (P1-8): las mutaciones autenticadas
 * deben mandarlo en el header X-CSRF-Token. '' si no hay sesión.
 */
export function csrfToken(): string {
  const match = document.cookie.match(new RegExp(`(?:^|; )${CSRF_COOKIE}=([^;]+)`));
  return match ? decodeURIComponent(match[1]) : '';
}

function clearLocalCache(): void {
  sessionStorage.removeItem(CACHE_KEY);
}

/** Lee el cache respetando el TTL; acepta el formato legacy (usuario crudo). */
function readCache(): AuthUser | null {
  try {
    const raw = sessionStorage.getItem(CACHE_KEY);
    if (!raw || raw === 'null' || raw === 'undefined') return null;
    const parsed: unknown = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return null;
    if ('cachedAt' in parsed && 'user' in parsed) {
      const entry = parsed as AuthCacheEntry;
      if (!entry.user || !entry.user.email) return null;
      if (Date.now() - entry.cachedAt > CACHE_TTL_MS) {
        clearLocalCache();
        return null;
      }
      return entry.user;
    }
    // Formato legacy (pre-TTL): usuario crudo. Se acepta una vez; el próximo
    // write lo reemplaza por el formato con TTL.
    const legacy = parsed as AuthUser;
    if (!legacy.email) return null;
    sessionStorage.setItem(CACHE_KEY, JSON.stringify({ user: legacy, cachedAt: Date.now() } satisfies AuthCacheEntry));
    return legacy;
  } catch {
    clearLocalCache();
    return null;
  }
}

/** Notifica el cambio a las OTRAS pestañas (BroadcastChannel + storage). */
function broadcastAuthChange(user: AuthUser | null): void {
  try {
    localStorage.setItem(AUTH_BROADCAST_KEY, JSON.stringify({ user, at: Date.now() }));
  } catch {
    // localStorage bloqueado/lleno: la invalidación queda local
  }
  if (typeof BroadcastChannel !== 'undefined') {
    try {
      const channel = new BroadcastChannel(BROADCAST_CHANNEL_NAME);
      channel.postMessage({ user, source: TAB_ID });
      channel.close();
    } catch {
      // canal no disponible: el fallback storage cubre el resto de pestañas
    }
  }
}

// Storage events are delivered only to other documents, while BroadcastChannel
// also delivers to the sender. Keep a per-tab marker so the sender does not
// invalidate its own cache immediately after populating it.
let localBroadcastAt = 0;

function setAuthState(user: AuthUser | null): void {
  const previous = readCache();
  if (user) {
    sessionStorage.setItem(CACHE_KEY, JSON.stringify({ user, cachedAt: Date.now() } satisfies AuthCacheEntry));
  } else {
    clearLocalCache();
  }
  const changed = user === null
    ? previous !== null
    : previous === null || previous.sub !== user.sub || previous.email !== user.email;
  if (changed) {
    localBroadcastAt = Date.now();
    broadcastAuthChange(user);
  }
}

async function parseMeResponse(res: Response): Promise<AuthUser | null> {
  const data = await res.json();
  if (!data?.success || !data.user) {
    setAuthState(null);
    return null;
  }
  const user = data.user as AuthUser;
  setAuthState(user);
  return user;
}

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function fetchAuthUser(): Promise<AuthUser | null> {
  try {
    const res = await fetch(`${AUTH_API_BASE}/me`, {
      credentials: 'include', // Enviar cookie usgar_session
    });

    if (res.status === 401) {
      // "Refresh" de estado de auth (no de token: no existe ese endpoint):
      // invalidar cache y reintentar UNA vez por si la sesión se renovó en
      // otra pestaña en el interín; si persiste, no hay sesión.
      setAuthState(null);
      await wait(AUTH_RETRY_DELAY_MS);
      const retry = await fetch(`${AUTH_API_BASE}/me`, { credentials: 'include' });
      if (!retry.ok) return null;
      return await parseMeResponse(retry);
    }

    if (!res.ok) {
      clearLocalCache();
      return null;
    }

    return await parseMeResponse(res);
  } catch {
    clearLocalCache();
    return null;
  }
}

/** Peticiones /me en vuelo: dedupe de llamadas concurrentes (View Transitions). */
let inFlightRequest: Promise<AuthUser | null> | null = null;

/**
 * Obtiene el usuario autenticado actual.
 * Primero revisa el cache (con TTL) salvo forceRefresh, luego llama a la API.
 * Retorna null si no hay sesión activa.
 */
export async function getUser(forceRefresh = false): Promise<AuthUser | null> {
  if (!forceRefresh) {
    const cached = readCache();
    if (cached) return cached;
  }
  inFlightRequest ??= fetchAuthUser().finally(() => {
    inFlightRequest = null;
  });
  return inFlightRequest;
}

/**
 * Cierra la sesión del usuario.
 * Invalida el cache (y avisa a las demás pestañas), llama al endpoint de
 * logout y recarga la página para actualizar el estado visual.
 */
export async function logout(): Promise<void> {
  setAuthState(null);

  try {
    await fetch(`${AUTH_API_BASE}/logout`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'X-CSRF-Token': csrfToken() },
    });
  } catch {
    // Silenciar errores de red — la cookie se limpia en el servidor
  }

  // Recargar la página para actualizar el estado visual
  window.location.reload();
}

/**
 * Suscripción a cambios de sesión originados en OTRAS pestañas.
 * Devuelve una función de limpieza. También invalida el cache local en cada
 * evento (el próximo getUser() re-sincroniza contra /me).
 */
export function onAuthChange(listener: (user: AuthUser | null) => void): () => void {
  if (typeof window === 'undefined') return () => {};
  const cleanups: Array<() => void> = [];

  if (typeof BroadcastChannel !== 'undefined') {
    const channel = new BroadcastChannel(BROADCAST_CHANNEL_NAME);
    const onMessage = (event: MessageEvent) => {
      if (Date.now() - localBroadcastAt < 1000) return;
      const message = event.data as { user?: AuthUser | null; source?: string } | null;
      if (message?.source === TAB_ID) return;
      listener((message?.user ?? message ?? null) as AuthUser | null);
    };
    channel.addEventListener('message', onMessage);
    cleanups.push(() => {
      channel.removeEventListener('message', onMessage);
      channel.close();
    });
  }

  const onStorage = (event: StorageEvent) => {
    if (Date.now() - localBroadcastAt < 1000) return;
    if (event.key !== AUTH_BROADCAST_KEY || !event.newValue) return;
    try {
      const payload = JSON.parse(event.newValue) as { user?: AuthUser | null; source?: string };
      if (payload.source === TAB_ID) return;
      listener(payload.user ?? null);
    } catch {
      // valor corrupto: ignorar
    }
  };
  window.addEventListener('storage', onStorage);
  cleanups.push(() => window.removeEventListener('storage', onStorage));

  return () => {
    for (const cleanup of cleanups) cleanup();
  };
}

// Invalidación cross-tab del cache: sessionStorage es por pestaña, así que al
// llegar un evento de otra pestaña (login/logout) se limpia el cache local.
if (typeof window !== 'undefined') {
  onAuthChange(() => clearLocalCache());
}
