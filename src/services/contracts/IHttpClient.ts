/**
 * Interface genérica para abstracción de clientes HTTP.
 * Aplica los principios de Inversión de Dependencias (DIP) y Segregación de Interfaces (ISP).
 */

export interface HttpResponse<T> {
  ok: boolean;
  status: number;
  data: T;
}

export interface IHttpClient {
  get<T>(url: string, headers?: Record<string, string>): Promise<HttpResponse<T>>;
  post<T>(url: string, body: unknown, headers?: Record<string, string>): Promise<HttpResponse<T>>;
}
