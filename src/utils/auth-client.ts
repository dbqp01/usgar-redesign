/**
 * USGAR Hotels — Auth Client (Browser-side)
 *
 * Helpers para interactuar con la API de autenticación desde el frontend.
 * Se ejecutan en el navegador (client-side), no en el servidor.
 *
 * Usa sessionStorage como cache para evitar llamadas repetidas a /api/auth/me
 * en cada navegación (las View Transitions de Astro recargan scripts).
 */

const AUTH_API_BASE = '/api/auth';
const CACHE_KEY = 'usgar_auth_user';

export interface AuthUser {
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
export async function getUser(): Promise<AuthUser | null> {
  // Intentar cache primero
  const cached = sessionStorage.getItem(CACHE_KEY);
  if (cached) {
    try {
      return JSON.parse(cached) as AuthUser;
    } catch {
      sessionStorage.removeItem(CACHE_KEY);
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
      return null;
    }

    const user = data.user as AuthUser;
    sessionStorage.setItem(CACHE_KEY, JSON.stringify(user));
    return user;
  } catch {
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

/**
 * Verifica si hay una sesión activa (solo cache, sin llamada a red).
 * Para verificación real, usar getUser().
 */
export function isLoggedIn(): boolean {
  return sessionStorage.getItem(CACHE_KEY) !== null;
}

/**
 * Pre-llena el formulario de reserva con los datos del usuario.
 * Busca los inputs por sus IDs existentes en book.astro.
 */
export function prefillBookingForm(user: AuthUser): void {
  const nameInput = document.getElementById('guest-name') as HTMLInputElement | null;
  const emailInput = document.getElementById('guest-email') as HTMLInputElement | null;
  const phoneInput = document.getElementById('guest-phone') as HTMLInputElement | null;

  if (nameInput && user.name && !nameInput.value) {
    nameInput.value = user.name;
    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
  }

  if (emailInput && user.email && !emailInput.value) {
    emailInput.value = user.email;
    emailInput.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // El teléfono no viene del perfil OAuth, solo si lo guardó previamente
  if (phoneInput && !phoneInput.value) {
    // Se podría pre-llenar si lo guardamos en la DB en futuras iteraciones
  }
}

/**
 * Genera la URL de login con redirect de vuelta.
 */
export function getLoginUrl(provider?: string): string {
  const redirect = encodeURIComponent(window.location.pathname + window.location.search);
  const base = `${AUTH_API_BASE}/login?redirect=${redirect}`;
  return provider ? `${base}&provider=${provider}` : `/login?redirect=${redirect}`;
}
