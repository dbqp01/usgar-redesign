import type { IHttpClient, HttpResponse } from './interfaces/IHttpClient';

/**
 * Implementación concreta de IHttpClient utilizando fetch nativo.
 * Maneja timeouts de red, headers predeterminados y captura de errores sin crashing.
 */
export class FetchHttpClient implements IHttpClient {
  private readonly defaultTimeoutMs: number;

  constructor(timeoutMs = 10000) {
    this.defaultTimeoutMs = timeoutMs;
  }

  async get<T>(url: string, headers: Record<string, string> = {}): Promise<HttpResponse<T>> {
    return this.request<T>(url, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
        ...headers,
      },
    });
  }

  async post<T>(url: string, body: unknown, headers: Record<string, string> = {}): Promise<HttpResponse<T>> {
    return this.request<T>(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...headers,
      },
      body: JSON.stringify(body),
    });
  }

  private async request<T>(url: string, config: RequestInit): Promise<HttpResponse<T>> {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.defaultTimeoutMs);

    try {
      const response = await fetch(url, {
        ...config,
        signal: controller.signal,
      });

      clearTimeout(timeoutId);

      let data: T;
      const contentType = response.headers.get('content-type');
      if (contentType && contentType.includes('application/json')) {
        data = await response.json();
      } else {
        const text = await response.text();
        data = (text ? { message: text } : {}) as T;
      }

      return {
        ok: response.ok,
        status: response.status,
        data,
      };
    } catch (error: any) {
      clearTimeout(timeoutId);

      const isAbort = error?.name === 'AbortError';
      const errorMessage = isAbort
        ? 'La petición excedió el tiempo límite de espera (timeout).'
        : (error?.message || 'Error de conexión de red al servidor.');

      return {
        ok: false,
        status: isAbort ? 408 : 503,
        data: {
          success: false,
          error: {
            code: isAbort ? 'TIMEOUT_ERROR' : 'NETWORK_ERROR',
            message: errorMessage,
          },
        } as unknown as T,
      };
    }
  }
}

// Instancia singleton por defecto para inyección conveniente
export const defaultHttpClient = new FetchHttpClient();
