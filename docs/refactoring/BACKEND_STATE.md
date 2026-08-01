# STATE — Backend USGAR (Fase 0/2: línea base + limpieza de pagos)

> Memoria de sesión del backend. El `STATE.md` anterior documenta el redesign del frontend;
> este archivo es el handoff del trabajo de backend (migraciones + refactor).
> Última actualización: 2026-08-01 (F0 completa, F2 parcial).

## Contexto (decisiones aprobadas por el usuario)

- **Orden de trabajo**: F0 línea base → F2 limpieza de pagos → F4 frontend (redesign pendiente) → F3 salir de QloApps (tablas propias + Filament) → F1 Nobeds (bloqueada: requiere suscripción pagada, se hace al final).
- **CMS**: salir de QloApps por completo (estable y simple). **Pagos**: MercadoPago se mantiene (Checkout API).
- **Nobeds**: sin free trial — requiere sub mensual; se integra cuando el proyecto esté presentado. Channex sigue en producción hasta entonces.

## Línea base (2026-08-01, verificada)

| Suite | Resultado |
|---|---|
| `php composer.phar check` (PHPUnit 11.5.56 + PHPStan 2) | **26 tests / 97 assertions PASS** + PHPStan sin errores |
| `npm run check` (astro check) | 0 errores, 6 hints pre-existentes (Hero.astro Props, RoomCard index, TypographicMarquee i, heroTextReveal ScrollTrigger, Layout media, profile.astro profileCard) |
| `npm run test:php` (run-exhaustive-tests) | **22/22 PASS** tras fix de harness |
| `npm run audit:security` | 3 hallazgos, **todos falsos positivos**: `PDO::exec()` solo para `CREATE TABLE IF NOT EXISTS` sin input (User.php:31, ProvisionalBookingRepository.php:87, SubscribeNewsletterAction.php:34) |

### Cambios de F0 aplicados
- `scripts/run-exhaustive-tests.php`: (a) expectativas obsoletas de `GET /api/rooms` sin params — el código usa hoy/mañana por defecto (el frontend hace polling sin params, src/services/bookingService.ts:155) y la prueba esperaba 400; actualizado a 200+success. (b) log del servidor de pruebas único por ejecución (`php-test-server-<pid>.log`) — Windows deja zombies de `php -S` que bloquean el archivo de log fijo.

## Fase 2 — hecho (2026-08-01)

### Residuos Checkout Pro — AUDITADO (resultado: backend limpio)
- **Backend PHP (`app/`)**: SIN residuos. No existe `preference`/`init_point`/`WebhookDebugAction`/filtro IPN legacy (ya eliminados en commits previos). Verificado por grep global.
- **Frontend (`src/`)**: limpio — `book.astro:929` ya usa MercadoPago.js cardForm (Custom Checkout) → `processPayment`; `bookingService.ts` sin referencia a preference. Solo falsos positivos (`prefers-reduced-motion`, tab de preferencias de perfil).
- **`access_token` en /api/booking y /api/process-payment**: NO es residuo — es el token HMAC de hold (`BOOKING_TOKEN_SECRET`, `cart_id:email`), parte del flujo Checkout API actual. Se mantiene.
- **Limpiado** (commit `cca8fd5`): `tests/postman-payment-suite.php` (asertaba `preference_id` → ahora `access_token` + `mp_public_key`), `docs/API_REGISTRY.md` (respuesta con `init_point` → contrato real + endpoint `/api/process-payment` documentado por primera vez), `docs/ARCHITECTURE.md` (flujo de reserva con nombres obsoletos → flujo real), borrado `task.md` (lista obsoleta, ítem de frontend ya estaba hecho).

### Diagnóstico webhooks MP (MCP oficial, read-only)
- `notifications_history`: **sin notificaciones registradas** — probablemente el webhook NO está registrado en la app de MercadoPago (candidato #1 de la "falta de comunicación"). El código envía `notification_url` por-pago, pero MP recomienda registrarlo en el panel.
- **PENDIENTE (requiere confirmación del usuario — cambia producción)**: registrar webhook vía `mercadopago-mcp-server_save_webhook` con callback `https://usgarhoteles.com/api/webhook`, topic `payment`, o hacerlo en el panel.

### Secretos en git — AUDITADO (resultado: limpio, NO rotar)
- `.env` está gitignored y nunca se commiteó. Historial completo escaneado (`git grep APP_USR-` sobre todos los commits): solo placeholders (`YOUR_MP_*`) y comentarios. Los tokens reales solo viven en `.env` local/producción (fuera de git).
- `tests/test_sdk_webhook.php:27` y `scripts/run-stress-tests.php:85` ya leen de env con fallback de test (P4-1 ya hecho).

### DIP y schema
- `cron/reconcile_payments.php`: instanciaba `new MercadoPagoAdapter()` a mano → ahora resuelve `PaymentGatewayPortInterface` desde el container (commit `90e2442`).
- `scripts/create_processed_payments_table.sql`: duplicaba el schema con `created_at` y PK en payment_id → unificado con `ProvisionalBookingRepository::ensureTablesExist()` (`id` AI + `payment_id` UNIQUE + `processed_at`). La tabla vieja de producción sigue siendo funcionalmente compatible (INSERT no usa `processed_at`); el script es la definición canónica para instalaciones nuevas (commit `65d7751`).

## Auditoría de seguridad completa (2026-08-01) — hallazgos y fixes

### Dependencias
- `composer audit` (Packagist advisories): **0 vulnerabilidades**. `npm audit`: **0 vulnerabilidades**.

### BUGS CORREGIDOS (con tests, commits por tema)
1. **Refund parcial roto** (commit `58c497a`): `MercadoPagoAdapter::refundPayment()` llamaba `PaymentRefundClient::create()` que **no existe** en el SDK (los métodos son `refund()` y `refundTotal()`) → cualquier reembolso parcial crasheaba con Error fatal. Corregido a `refund()` + test de caracterización nuevo (`testRefundPaymentPartialRefundUsesRefundMethod`).
2. **Header de idempotencia malformado** (commit `58c497a`): el SDK espera `["X-Idempotency-Key" => valor]` (claves→valores) y el código pasaba `["X-Idempotency-Key: valor"]` (clave entera) — `array_merge` lo reindexaba y **el header no se enviaba** → sin protección de idempotencia (riesgo de pagos duplicados en reintentos). Corregido en `processPayment` y `refundPayment`; test actualizado (el test viejo fijaba el formato buggy — TDD rojo→verde).
3. **`payment_id` faltante en producción** (commit `c4f529d`): la tabla `provisional_bookings` real NO tenía la columna `payment_id` (la migración de docs/refactoring/CRON.md nunca se ejecutó) → `attachPaymentId()` y `getPendingHoldsWithPaymentId()` fallaban silenciosamente → **la reconciliación de pagos estaba muerta en producción**. Fix: ALTER ejecutado en producción (verificado por schema MCP) + auto-heal en `ensureTablesExist()` para entornos nuevos.
4. **Escape de entrada corrupta datos** (commit `95c0eb4`): `Request::sanitize()` aplicaba `htmlspecialchars()` a TODOS los strings de entrada → contraseñas con `&`/`<` se corrompían (login roto), nombres con doble-escape (`&amp;amp;`), `CreateBookingAction` re-escapaba (doble). Fix: modelo correcto (validar input, escapar output) — `sanitize()` ya no altera strings; se quitó el doble-escape; **se añadieron escapes de salida** en los templates innerHTML del frontend (my-bookings, profile, AllocationStep — helper `escapeHtml`). XSS por innerHTML con datos de reservas eliminado.
5. **Fuga de detalles internos en OAuth** (commit `d8a0c89`): el catch de `AuthCallbackAction` ponía `$e->getMessage()` (rutas, hosts, mensajes internos) en la URL de error → ahora mensaje genérico; detalle solo al log del servidor.
6. **Dependencias muertas** (commit `7685577`): `HandleMercadoPagoWebhookAction::$pms` y `CreateBookingAction::$paymentGateway` inyectadas pero nunca usadas (+ imports de adapters concretos) — eliminadas; tests actualizados; baseline PHPStan regenerado (2× patrones obsoletos por bugs corregidos).
7. **Higiene git** (chore): `logs/app.log`, `preview.log` y `.playwright-mcp/*` estaban versionados → untracked (`git rm --cached`); `.gitignore` extendido (`playwright-report/`, `test-results/`, `.playwright-mcp/`, `preview.log`, `*.log`).

### Verificaciones sin hallazgos
- Patrones peligrosos (eval/exec/system/unserialize/extract/assert): **ninguno real** (solo `PDO::exec` DDL y `curl_exec` de adaptadores).
- JWT (SessionService): HMAC-SHA256 + `hash_equals` + exp + alg pinned + secret ≥32 chars; cookie HttpOnly/SameSite=Lax/Secure. OK.
- Rate limiter: archivos con `flock(LOCK_EX)` + SHA-256 + fuera de dir público. OK.
- IP spoofing: `X-Forwarded-For` solo confía en `TRUSTED_PROXIES` (vacío por defecto = seguro). OK.
- Open redirects: validación `str_starts_with($redirect, '/')` + rechazo `//` en callback/register/login. OK.
- SQL del código vs schema real (mysql MCP): `qlo_*` de `getAvailableRooms` **válido**; `processed_payments`/`event_outbox`/`users` correctos.
- SQLi: todo prepared statements. XSS frontend: textContent en success/payment; innerHTML restantes revisados.
- `preference_id` (residuo Checkout Pro) existe en `provisional_bookings` con **52 registros históricos** → NO se elimina (preservación de datos); queda documentado.

### Pendiente del usuario (webhook)
- Verificar que `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` sea idéntico al secreto del panel MP (developers/panel/app/8501374849722569/webhooks). El webhook quedó registrado (callback `https://usgarhoteles.com/api/webhook`, topic `payment`) y `notifications_history` se monitorea tras un pago de prueba.

### Nota: agente paralelo
- Hay una sesión paralela trabajando el wizard de reserva (BookingWidget, GuestStep, BookingCalendarStep, PaymentStep, book.astro, i18n). Al cierre de esta auditoría, `astro check` reporta **15 errores TS en su WIP** (null-checks y variables sin definir en BookingWidget/BookingCalendarStep/book.astro) — no tocados para no pisar su trabajo; quedan para cuando cierre su tarea.

## Pendiente (F2 restante)
- [ ] Verificar `MERCADO_PAGO_WEBHOOK_SECRET` == secreto del panel MP + pago de prueba + `notifications_history`.
- [ ] `success.astro` 3 estados (confirmado / pendiente / verificando) — refactor PLAN P6-1.
- [ ] Reducir el logging diagnóstico pesado ("WEBHOOK DIAGNOSTICS") a un log conciso, UNA VEZ verificado el webhook.
- [ ] DIP restante (P3-1): constructores nullable en actions; verificar CSP duplicada (no hay `.htaccess` raíz; `public/.htaccess` solo setea nosniff — CSP única en Middleware, ya confirmado).
- [ ] Revisar/fixear los 15 errores TS del WIP del agente paralelo cuando cierre su tarea.

## Hook pre-commit (2026-08-01) — reescrito y VERIFICADO
- Causa: `.githooks/pre-commit` (sh) fallaba en esta máquina — `sh` del PATH (shim scoop) delegaba en el relay WSL (`execvpe(/bin/bash) failed`).
- Solución: hook reescrito en **PowerShell puro** (Windows PowerShell 5.1) con shebang `#!/usr/bin/env powershell`; misma lógica (mp4 → ffmpeg con tag USGAR_COMPRESSED; imágenes → `node scripts/compress-images.js`; re-stage; nunca bloquea el commit). Docs: `docs/HOOKS.md`.
- Verificación con evidencia: commit real con PNG 40x40 staged → blob commiteado **difiere** del PNG fresco (hash SHA256 distinto) → sharp re-encodeó vía el hook; `GIT_TRACE=1` confirma `start_command: .githooks/pre-commit` (~5s = arranque PowerShell). Commits posteriores OK por terminal.
- **Limitación**: el servidor MCP de git (`git_git_commit`) falla con CUALQUIER hook (ejecuta `bash` → relay WSL). Los commits se hacen por terminal (bash tool). Documentado en `docs/HOOKS.md`.
- Commit: `3a5b50c` (hook + HOOKS.md), `b6555e4`/`bb15b4c` (fixture de prueba ida y vuelta).

## Notas de entorno (Windows)
- `npm run test:php` puede dejar zombies `php -S 127.0.0.1:8089` si se interrumpe; limpiar con `Stop-Process` o matando listeners de 8089.
- WIP sin commitear del usuario (NO tocar): `app/Core/Container.php`, `app/bootstrap.php`, `scripts/dev.js`. **Ojo**: hay una sesión paralela tocando el frontend F4 (`src/pages/book.astro`, `src/components/booking/PaymentStep.astro`, `tests/e2e/internals.spec.ts`, `tests/e2e/wizard-flow.spec.ts`) — no pisar; stagear solo lo propio al commitear.
- **Hook pre-commit**: ahora PowerShell (funciona por terminal). El MCP de git (`git_git_commit`) NO puede ejecutar hooks en esta máquina (usa bash→WSL) → commits por terminal (bash tool).

## Siguiente
- F2: confirmar registro de webhook MP (1 decisión del usuario) → completar P6-1/P3-1/P4-3.
- F4 frontend (fases 3-4 redesign: internas + wizard reserva).
- F3 CMS (mini-contrato de alcance al empezar).
- F1 Nobeds (requiere sub pagada; instrucciones de cuenta/API key al llegar).
