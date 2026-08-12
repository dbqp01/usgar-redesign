/**
 * USGAR Hotels — Auth Client (Browser-side)
 *
 * Helpers para interactuar con la API de autenticación desde el frontend.
 * Se ejecutan en el navegador (client-side), no en el servidor.
 *
 * Usa sessionStorage como cache para evitar llamadas repetidas a /api/auth/me
 * en cada navegación (las View Transitions de Astro recargan scripts).
 * (audit 2026-08-12: /api/auth/me + cookie HttpOnly usgar_session confirmados
 * en public/index.php y SessionService)
 */

const AUTH_API_BASE = '/api/auth';
const CACHE_KEY = 'usgar_auth_user';

interface AuthUser {
  sub: number;
  name: string;
  email: string;
  photo: string | null;
  provider: string;
}

/**
 * Obtiene el usuario autenticado actual.
 * Primero revisa el cache de sessionStorage, luego llama a la API.
 * Retorna null si no hay sesión activa.
 */
export async function getUser(forceRefresh = false): Promise<AuthUser | null> {
  // Intentar cache solo si no se fuerza el refresco
  if (!forceRefresh) {
    const cached = sessionStorage.getItem(CACHE_KEY);
    if (cached && cached !== 'null' && cached !== 'undefined') {
      try {
        const parsed = JSON.parse(cached);
        if (parsed && typeof parsed === 'object' && parsed.email) {
          return parsed as AuthUser;
        }
      } catch {
        sessionStorage.removeItem(CACHE_KEY);
      }
    }
  }

  try {
    const res = await fetch(`${AUTH_API_BASE}/me`, {
      credentials: 'include', // Enviar cookie usgar_session
    });

    if (!res.ok) {
      sessionStorage.removeItem(CACHE_KEY);
      return null;
    }

    const data = await res.json();
    if (!data.success || !data.user) {
      sessionStorage.removeItem(CACHE_KEY);
      return null;
    }

    const user = data.user as AuthUser;
    sessionStorage.setItem(CACHE_KEY, JSON.stringify(user));
    return user;
  } catch {
    sessionStorage.removeItem(CACHE_KEY);
    return null;
  }
}

/**
 * Cierra la sesión del usuario.
 * Limpia el cache y llama al endpoint de logout.
 */
export async function logout(): Promise<void> {
  sessionStorage.removeItem(CACHE_KEY);

  try {
    await fetch(`${AUTH_API_BASE}/logout`, {
      method: 'POST',
      credentials: 'include',
    });
  } catch {
    // Silenciar errores de red — la cookie se limpia en el servidor
  }

  // Recargar la página para actualizar el estado visual
  window.location.reload();
}
