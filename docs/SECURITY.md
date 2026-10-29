# Seguridad — USGAR Hotels

Modelo de seguridad verificado contra el código (2026-09-11). Los pendientes priorizados viven en `docs/ROADMAP.md` (P1).

## Decisiones verificadas (estado actual)

### Transporte y headers
- Todo HTTPS (HSTS `max-age=31536000; includeSubDomains; preload` en Middleware).
- Una sola allowlist de CSP para todo el sitio, aplicada en **dos puntos**: `public/.htaccess` (bloque `<FilesMatch "\.html$">`) para el HTML estático y `app/Core/Middleware.php::securityHeaders` para las respuestas PHP. La allowlist es idéntica en ambos y `npm run audit:content` falla (error `CSP-DRIFT`) si divergen; `npm run audit:csp` la carga en Chromium sobre el build y falla si el navegador rechaza algún recurso. Con `'unsafe-inline' 'unsafe-eval'` para el SDK de MercadoPago; dominios MP/ML/fonts/maps allowlisteados. La CSP estática va en `<FilesMatch>` a propósito: declarada a nivel global, `index.php` recibiría una segunda cabecera CSP y el navegador aplicaría la intersección de ambas.
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` (sin cámara/micrófono/geolocalización).
- Los directorios de runtime copiados al webroot (`app/`, `vendor/`, `cron/`, `logs/`, `storage/`) están denegados por `.htaccess`; los scripts de cron siguen disponibles únicamente mediante CLI.

### Entrada y datos
- Todo SQL con prepared statements PDO (`EMULATE_PREPARES=false`, `ERRMODE_EXCEPTION`). `PDO::exec` solo para DDL propio (CREATE TABLE IF NOT EXISTS) sin input.
- Validación de entrada: `App\Core\Validator` en cada action; **no** se escapa la entrada (modelo correcto: validar input, escapar output — fix 2026-08-01 que eliminó el doble-escape).
- JSON parseado con `JSON_THROW_ON_ERROR`; body limitado por configuración del servidor.
- IP real solo vía `TRUSTED_PROXIES` (vacío por defecto = `X-Forwarded-For` ignorado, sin spoofing).
- Rate limiting global por IP: archivos con `flock(LOCK_EX)` + SHA-256, fuera del directorio público (`data/limits/`). Límite 300/600s configurable.
- CORS: `ALLOWED_ORIGINS` es una allowlist autoritativa; no se agregan excepciones automáticas para localhost. Nunca `*` con credentials y `Vary: Origin` cuando hay lista. Con una allowlist explícita, una petición sin `Origin` no recibe `Access-Control-Allow-Origin: *`; las respuestas HTML estáticas reciben headers defensivos desde `public/.htaccess` y el shell `/panel/` usa `private, no-store`.

### Pagos (MercadoPago)
- SDK oficial dx-php v3 detrás de `PaymentGatewayPortInterface`; token solo en el backend (`.env`), nunca en el frontend.
- Webhooks: firma HMAC validada con `WebhookSignatureValidator` del SDK (`x-signature` + `x-request-id`) + ventana anti-replay de 300s calculada por el adaptador (`MercadoPagoAdapter::verifySignature`): MP documenta el `ts` en milisegundos pero lo envía en **segundos** (evidencia: `ts=1792467232`, log prod 2026-10-19 22:33:52) y el chequeo de skew del SDK asume ms — por eso el cálculo de drift vive en el adaptador (normaliza ambos formatos) y el SDK se invoca sin tolerancia; fail-closed si falta el secret.
- Doble cobro imposible por diseño: gates de hold (expirado / payment_id existente) + transacción con `FOR UPDATE` alrededor del cobro + idempotency key fresca por intento + dedup `isOrderConfirmed` en el PMS + reconciliación por cron.
- Guard de deploy: `scripts/check-prod-env.php` valida el token de producción por allowlist de hashes (el prefijo del token no define entorno).
- Secretos nunca en git: `.env` gitignored, historial escaneado limpio; el postbuild borra `dist/.env`.

### Autenticación
- JWT propio HMAC-SHA256 (`SessionService`): `hash_equals` para comparación, `exp`/`iat`, algoritmo fijado en el header, secret ≥32 chars, cookie HttpOnly + SameSite=Lax + Secure (en HTTPS). Los `jti` revocados se guardan server-side en `TokenRevocationStore` bajo `runtime/` junto al `.env` efectivo y se rechazan inmediatamente.
- Contraseñas con `password_hash` (`PASSWORD_DEFAULT`, mín. 8 chars) y `password_needs_rehash()` en login válido.
- Registro público: un email existente siempre responde conflicto; no puede convertir una cuenta OAuth en una cuenta de contraseña ni tomarla.
- OAuth (hybridauth): redirects validados contra open-redirect (solo rutas internas `/...`, rechaza `//`); errores de callback sin detalle interno al cliente. Solo se aceptan perfiles con email verificado; el linking implícito por email queda rechazado y Microsoft Graph permanece deshabilitado salvo `MICROSOFT_TRUST_EMAIL=true` explícito.
- Sesión PHP nativa (para OAuth) con parámetros de cookie seguros.

### Auditorías (herramientas del repo)
- Auditorías disponibles: `npm run audit:security` (script propio), `composer audit`, `npm audit` y Semgrep para el diff. `composer audit` reportó 0 vulnerabilidades el 2026-09-10.
- Revisión local del 2026-10-29: `npm audit` y `npm audit --omit=dev` reportan 0 vulnerabilidades tras actualizar Astro, Vitest, `fast-uri`, `undici` y `zod`. El despliegue debe actualizarse y volver a escanearse en hPanel para verificar el estado en producción.
- Patrones peligrosos auditados (2026-08-01): sin eval/exec/system reales; `unserialize` solo en el outbox y restringido con `allowed_classes` a `BookingPaidEvent` y `DateTimeImmutable` (payload escrito por el propio sistema).

## Modelo de amenazas (resumen para explicar)

1. **Ataques a la web** (XSS, clickjacking, MIME sniffing) → CSP + headers defensivos + escape de salida en templates del frontend.
2. **Ataques a la API** (inyección, fuerza bruta, spoofing) → prepared statements + rate limit + Validator + IP confiable.
3. **Fraude de pagos** (doble cobro, webhooks falsos, precio manipulado) → firma HMAC + idempotencia + precios calculados solo en backend + transacciones atómicas.
4. **Fuga de secretos** → .env fuera de git y del web root + guard de deploy + tokens solo en backend.
**Abuso de sesiones** (robo de cookie, CSRF) → HttpOnly + SameSite=Lax + JWT firmado; las mutaciones autenticadas de usuario y panel exigen doble cookie/header CSRF.

## Pendientes de seguridad (priorizados en ROADMAP)

- P1-1: **CERRADO (2026-08-12)** — `ProcessOutboxAction` restringe `unserialize` mediante `allowed_classes` a `BookingPaidEvent` y `DateTimeImmutable`; revisar la lista si se añade otra clase serializable al outbox.
- P1-3: **CERRADO (2026-08-15)** — `/api/health` no expone diagnóstico de entorno por HTTP público.
- P1-4: **CERRADO (2026-08-12)** — `PASSWORD_DEFAULT` + `password_needs_rehash()` en login válido.
- P1-5: **CERRADO (2026-08-12)** — rate limit de 5 intentos/15 min por email+IP en login. El panel mantiene como mejora separada un límite por cuenta/credencial.
- P1-9: **CERRADO (2026-09-09)** — `User::createFromEmail()` ya no modifica cuentas existentes; el registro público no puede tomar cuentas OAuth.
- P1-7: **CERRADO (2026-09-10)** — `SessionService` y `PanelAuth` emiten/validan `iss`, `aud` y `jti`; logout revoca el `jti` server-side mediante `TokenRevocationStore`.
- P1-10: **CERRADO (2026-09-10)** — `emailVerified` de HybridAuth se normaliza y se exige; el linking implícito por email se rechaza, y Microsoft Graph requiere `MICROSOFT_TRUST_EMAIL=true`.
- P2-10: `User::__construct()` y algunos repositorios ejecutan probes/DDL `CREATE TABLE IF NOT EXISTS` en rutas calientes; mover migraciones a deploy/CLI cuando el flujo de Hostinger esté validado.
- Login del panel: límite específico de **5 intentos por IP cada 15 minutos** antes de validar la credencial (**implementado y verificado; ver `PanelLoginAction` y `docs/ROADMAP.md` P1-7**).
- Panel: cookie `usgar_panel_csrf` JS-readable (32 hex, SameSite=Lax, Secure bajo HTTPS) y header `X-CSRF-Token` obligatorio para logout/import/booking/block/unblock.
- Importación del panel: límites de 8 MiB raw, 5 MiB CSV, 1000 filas y 32 MiB XLSX descomprimido para reducir consumo de memoria y bombas de descompresión.
- Cron de reseñas: las peticiones HTTP a `/api/cron/refresh-reviews` requieren `x-cron-secret`; CLI sigue siendo el canal del cron del servidor. Las URLs configurables se restringen a Booking/KAYAK/Expedia por HTTPS y DNS público; se bloquean credenciales, puertos no estándar, redirecciones y rangos IP privados/reservados.
- MercadoPago search: la recuperación por `external_reference` limita la ventana a pagos recientes y rechaza coincidencias múltiples; una ambigüedad no se asocia automáticamente al hold.
- Outbox: cada claim usa `event_outbox.claim_token`; las transiciones a COMPLETED/FAILED incluyen id, estado IN_PROGRESS y token, evitando que un lease obsoleto sobrescriba al worker actual.
- Voucher SMTP: el outbox evita reenvíos normales mediante `voucher_sent`, pero la frontera `send SMTP → marcar enviado` no puede ser atómica con MySQL; dos workers pueden pasar el check simultáneamente y un crash después de SMTP puede causar un duplicado. La semántica operacional es at-least-once. El PMS usa deduplicación por `external_reference`.
- Revocación JWT: `TokenRevocationStore` comprueba que el directorio siga existiendo y siendo legible en cada validación; si desaparece tras el health-check inicial, la autenticación falla cerrado.
- Semgrep OSS 1.172.0 revisó 299 archivos: las advertencias restantes sobre `unserialize()`/`unlink()` son patrones conservadores sobre payloads con `allowed_classes` y temporales internos; los workflows de GitHub Actions quedaron fijados a SHA completos.
