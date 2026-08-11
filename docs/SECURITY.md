# Seguridad — USGAR Hotels

Modelo de seguridad verificado contra el código (2026-08-11). Los pendientes priorizados viven en `docs/ROADMAP.md` (P1).

## Decisiones verificadas (estado actual)

### Transporte y headers
- Todo HTTPS (HSTS `max-age=31536000; includeSubDomains; preload` en Middleware).
- CSP única en `app/Core/Middleware.php::securityHeaders` (con `'unsafe-inline' 'unsafe-eval'` para el SDK de MercadoPago; dominios MP/ML/fonts/maps allowlisteados). Sin CSP duplicada en `.htaccess`.
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` (sin cámara/micrófono/geolocalización).

### Entrada y datos
- Todo SQL con prepared statements PDO (`EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`). `PDO::exec` solo para DDL propio (CREATE TABLE IF NOT EXISTS) sin input.
- Validación de entrada: `App\Core\Validator` en cada action; **no** se escapa la entrada (modelo correcto: validar input, escapar output — fix 2026-08-01 que eliminó el doble-escape).
- JSON parseado con `JSON_THROW_ON_ERROR`; body limitado por configuración del servidor.
- IP real solo vía `TRUSTED_PROXIES` (vacío por defecto = `X-Forwarded-For` ignorado, sin spoofing).
- Rate limiting global por IP: archivos con `flock(LOCK_EX)` + SHA-256, fuera del directorio público (`data/limits/`). Límite 300/600s configurable.
- CORS: en prod `ALLOWED_ORIGINS` explícito (nunca `*` con credentials); `Vary: Origin` cuando hay lista.

### Pagos (MercadoPago)
- SDK oficial dx-php v3 detrás de `PaymentGatewayPortInterface`; token solo en el backend (`.env`), nunca en el frontend.
- Webhooks: firma HMAC validada con `WebhookSignatureValidator` del SDK (`x-signature` + `x-request-id`, tolerancia 300s anti-replay); fail-closed si falta el secret.
- Doble cobro imposible por diseño: gates de hold (expirado / payment_id existente) + transacción con `FOR UPDATE` alrededor del cobro + idempotency key fresca por intento + dedup `isOrderConfirmed` en el PMS + reconciliación por cron.
- Guard de deploy: `scripts/check-prod-env.php` valida el token de producción por allowlist de hashes (el prefijo del token no define entorno).
- Secretos nunca en git: `.env` gitignored, historial escaneado limpio; el postbuild borra `dist/.env`.

### Autenticación
- JWT propio HMAC-SHA256 (`SessionService`): `hash_equals` para comparación, `exp`/`iat`, algoritmo fijado en el header, secret ≥32 chars, cookie HttpOnly + SameSite=Lax + Secure (en HTTPS).
- Contraseñas con `password_hash` bcrypt (mín. 8 chars).
- OAuth (hybridauth): redirects validados contra open-redirect (solo rutas internas `/...`, rechaza `//`); errores de callback sin detalle interno al cliente.
- Sesión PHP nativa (para OAuth) con parámetros de cookie seguros.

### Auditorías (herramientas del repo)
- `npm run audit:security` (script propio), `composer audit` + `npm audit` (0 vulnerabilidades al 2026-08-01), Semgrep MCP para el diff.
- Patrones peligrosos auditados (2026-08-01): sin eval/exec/system reales; `unserialize` solo en el outbox con payload escrito por el propio sistema (pendiente hardening: `docs/ROADMAP.md` P1-1).

## Modelo de amenazas (resumen para explicar)

1. **Ataques a la web** (XSS, clickjacking, MIME sniffing) → CSP + headers defensivos + escape de salida en templates del frontend.
2. **Ataques a la API** (inyección, fuerza bruta, spoofing) → prepared statements + rate limit + Validator + IP confiable.
3. **Fraude de pagos** (doble cobro, webhooks falsos, precio manipulado) → firma HMAC + idempotencia + precios calculados solo en backend + transacciones atómicas.
4. **Fuga de secretos** → .env fuera de git y del web root + guard de deploy + tokens solo en backend.
5. **Abuso de sesiones** (robo de cookie, CSRF) → HttpOnly + SameSite=Lax + JWT firmado (CSRF token explícito pendiente: `docs/ROADMAP.md` P1-8).

## Pendientes de seguridad (priorizados en ROADMAP)

- P1-1: `unserialize` del outbox sin `allowed_classes` (endurecer).
- P1-3: `/api/health` expone diagnóstico de config en producción (gate por entorno).
- P1-4: `password_needs_rehash` para migrar hashes (PASSWORD_DEFAULT/argon2id).
- P1-5: rate limit por cuenta en login (fuerza bruta dirigida).
- P1-7: JWT sin `iss`/`aud`/`jti` (revocación server-side).
- P1-8: CSRF token para mutaciones autenticadas por cookie.
