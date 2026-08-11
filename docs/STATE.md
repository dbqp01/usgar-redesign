# STATE — Backend USGAR (handoff de sesión)

> Documento canónico de estado del backend (antes `docs/refactoring/BACKEND_STATE.md`). Fuente de la auditoría de mejores prácticas 2026-08-11: `docs/ROADMAP.md`.

## Verificación 2026-08-11 (centralización de docs; sin cambios de código)
- `npm run check` → 0 errors / 0 warnings (1 hint pre-existente en `scripts/dev.js`).
- `php vendor/bin/phpunit` → **160 tests / 569 assertions, 12 skipped, 1 fallo flaky** (`ProcessOutboxActionTest::testConcurrentRunsProcessEachEventExactlyOnce` — pasa aislado; workers proc_open + timing en Windows). Backlog: ROADMAP P3-7.
- `scripts/run-exhaustive-tests.php` → **no termina**: golpea MP real (404 "Payment not found", timeouts 8s c/u) y BD remota (1045 si la IP no está re-anillada). Evidencia: `logs/app.log` 12:47. Backlog: ROADMAP P3-6.

## Historial — STATE original (Fase 0/2: línea base + limpieza de pagos)

> Memoria de sesión del backend: este archivo es el handoff del trabajo de backend (migraciones + refactor).
> Última actualización: 2026-08-05 (F0 completa; F2 cerrada; verificación webhook y checkout completadas; ola de refactor de limpieza en curso).

## Contexto (decisiones aprobadas por el usuario)

- **Orden de trabajo**: F0 línea base → F2 limpieza de pagos → F4 frontend (redesign pendiente) → F1 Nobeds (bloqueada: requiere suscripción pagada, se hace al final). **F3 (salir de QloApps) CANCELADA — QloApps se mantiene como PMS (decisión 2026-08-04); ver `docs/DECISIONS.md`.**
- **CMS**: QloApps se mantiene. **Pagos**: MercadoPago se mantiene (Checkout API).
- **Channel manager**: QloApps Channel Manager (Webkul) — sin código en el repo (módulo del PMS); activación pendiente de pago (runbook `docs/PMS.md` §D).

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
- `notifications_history`: **sin notificaciones registradas** — probablemente el webhook NO está registrado en la app de MercadoPago (candidato #1 de la "falta de comunicación"). El código envía `notification_url` por-pago, pero MP recomienda registrarlo en el panel. → **Diagnóstico SUPERSEDIDO 2026-08-03**: el webhook sí estaba registrado desde 2026-07-13; el historial vacío era por cero eventos (ver "Verificación webhook (2026-08-03) — COMPLETADA").
- **PENDIENTE (requiere confirmación del usuario — cambia producción)**: registrar webhook vía `mercadopago-mcp-server_save_webhook` con callback `https://usgarhoteles.com/api/webhook`, topic `payment`, o hacerlo en el panel. → **RESUELTO 2026-08-03**: ya estaba registrado (callback `https://usgarhoteles.com/api/webhook`, topic `payment`); no se re-escribió.

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

### Pendiente del usuario (webhook) — RESUELTO 2026-08-03
- Verificación de `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` vs el secreto del panel MP (app 8501374849722569): **CONFIRMADO** — el secret del panel empieza con `a8c54ec...`, idéntico a `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64) y al secret proporcionado por el usuario. El webhook SÍ estaba registrado en la app (callback `https://usgarhoteles.com/api/webhook`, topic `payment`, desde 2026-07-13); el historial vacío era por cero eventos. Detalle en "Verificación webhook (2026-08-03)".

### Nota: agente paralelo
- Hay una sesión paralela trabajando el wizard de reserva (BookingWidget, GuestStep, BookingCalendarStep, PaymentStep, book.astro, i18n). Al cierre de esta auditoría, `astro check` reporta **15 errores TS en su WIP** (null-checks y variables sin definir en BookingWidget/BookingCalendarStep/book.astro) — no tocados para no pisar su trabajo; quedan para cuando cierre su tarea.

## Pendiente (F2 restante) — cerrado 2026-08-03
- [x] Verificar `MERCADO_PAGO_WEBHOOK_SECRET` == secreto del panel MP — **RESUELTO 2026-08-03** (user-gated completado). **Evidencia**: secret del panel empieza con `a8c54ec...` == `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64) == secret proporcionado por el usuario.
- [x] Confirmar el registro del webhook — **CONFIRMADO 2026-08-03**: registrado en la app 8501374849722569 desde 2026-07-13 (callback `https://usgarhoteles.com/api/webhook`, topic `payment`; URLs prod+sandbox). El historial vacío de `notifications_history` era por cero eventos, no por webhook sin registrar.
- [x] Pago de prueba + re-ejecutar `notifications_history` — **EJECUTADO 2026-08-03**: pagos 1349900853 (`pagoefectivo_atm`, pending) y 1327783012 (Visa MPE test, **approved**); 2 eventos `payment` entregados; simulación local firmada con HTTP 200. Detalle en "Verificación webhook (2026-08-03)".
- **FUERA del alcance de la app de test** (pendiente, no bloquea): registrar el webhook en el panel de la app de **producción** (8776209959654245) para pagos reales; restaurar las credenciales `APP_USR` en el `.env` antes de cualquier deploy (el `.env` local quedó en modo TEST por instrucción del usuario).
- [x] `success.astro` 3 estados (confirmado / pendiente / verificando) — refactor PLAN P6-1. **Evidencia (verificada 2026-08-03)**: `src/pages/book/success.astro:214` (`pollForPayment`), `:287` (call con AbortSignal), `:291` (comentario "3-state flow: paid -> success card, pending -> pending card, else -> error"), `:294` (`PENDING_STATUSES`), `:299` (branch de estado pendiente); claves i18n en las 4 locales: `en.json:319/321`, `es.json:323/325`, `fr.json:323/325`, `pt.json:323/325` (`paymentPending`, `verifyingPaymentMessage`).
- [x] Reducir el logging diagnóstico pesado ("WEBHOOK DIAGNOSTICS") a un log conciso. **Evidencia (verificada 2026-08-03)**: `grep -rn "WEBHOOK DIAGNOSTICS" app/` → 0 resultados; commit `1e3226b` ("refactor: trim webhook diagnostic logging to concise per-event info"); `HandleMercadoPagoWebhookAction.php` con 5 `Logger::info` (líneas 57/71/99/117/164), entrada concisa con solo `type`+`payment_id` (:71), cero headers/query/`$_SERVER`; ERRORs intactos.
- [x] DIP restante (P3-1): constructores nullable en actions. **Evidencia (verificada 2026-08-03)**: `grep -rn "= null)" app/Features/Booking/Actions/ app/Features/Webhooks/Actions/` → 0 matches; constructores no-nullable en `CreateBookingAction.php:32`, `ProcessPaymentAction.php:29`, `GetBookingStatusAction.php:22`, `ExtendHoldAction.php:21`, `HandleMercadoPagoWebhookAction.php:30`. CSP duplicada ya confirmada (no hay `.htaccess` raíz; `public/.htaccess` solo setea nosniff — CSP única en Middleware).
- [x] Revisar/fixear los 15 errores TS del WIP del agente paralelo. **Evidencia (verificada 2026-08-03)**: `npm run check` → 0 errors / 0 warnings / 0 hints (136 archivos); corroborado en `docs/refactoring/STATE.md` (Fases 3+4: "astro check 0 errores", STATE.md:30/48).

## F2 — cerrada (2026-08-03)

Cierre de la Fase 2 del refactor backend (plan `f2-close-backend-refactor`), con evidencia verificada en sesión:

- **Recorte de logging diagnóstico** (Todo 1, commit `1e3226b`): bloque "WEBHOOK DIAGNOSTICS" eliminado de `HandleMercadoPagoWebhookAction.php`; quedan 5 `Logger::info` por evento (líneas 57/71/99/117/164), entrada concisa con solo `type` + `payment_id` (:71), cero headers/query/`$_SERVER`; ERRORs intactos. Comportamiento HTTP y dispatch de `BookingPaidEvent` sin cambios (refactor behavior-preserving).
- **Verificación webhook** (Todo 2): **COMPLETADA 2026-08-03**. El webhook estaba registrado en la app 8501374849722569 desde 2026-07-13 (callback `https://usgarhoteles.com/api/webhook`, topic `payment`); secret del panel (`a8c54ec...`) == `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64); 2 pagos de prueba (1327783012 **approved**) + 2 notificaciones entregadas; simulación local firmada con HTTP 200. Detalle en "Verificación webhook (2026-08-03)".
- **P6-1 (success.astro 3 estados) y P3-1 (DIP no-nullable) ya hechos** — verificados con evidencia file:line en la sección "Pendiente (F2 restante) — cerrado 2026-08-03".

**User-gated 2026-08-03 — RESUELTO (app de test):**
1. Comparación del secret del panel MP (app 8501374849722569) vs `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64): **VERIFICADO** — panel `a8c54ec...` == `.env`.
2. Registro del webhook (callback `https://usgarhoteles.com/api/webhook`, topic `payment`): **CONFIRMADO** — registrado desde 2026-07-13.
3. Pago de prueba + monitoreo de `notifications_history`: **EJECUTADO** — pagos 1349900853 y 1327783012 (**approved**), 2 notificaciones entregadas, simulación local HTTP 200.

**FUERA del alcance de la app de test (pendiente para pagos reales, no bloquea):**
- Registrar el webhook en el panel de la app de **producción** (8776209959654245) — el webhook verificado pertenece a la app de test 8501374849722569.
- Restaurar las credenciales `APP_USR` de producción en el `.env` antes de cualquier deploy (el `.env` local quedó en modo TEST por instrucción del usuario).

## Ola de refactor de limpieza (dead code + helpers + hardcodes → config) — 2026-08-05 (EN CURSO)

Auditoría de limpieza en curso (P5-2, decisión 2026-08-05). Estado verificado con evidencia en el árbol de trabajo al cierre de esta sesión — el agente paralelo trabaja sobre `app/`/`scripts/`/`tests/` y todavía **no ha commiteado** nada de esta ola:

- **Scripts candidatos a eliminar — AÚN EXISTEN (no borrados al 2026-08-05)**: `scripts/seed-database.php`, `scripts/test-db-connection.php`, `scripts/audit-performance.js`, `scripts/test_endpoints.php`, `scripts/test-api-runtime.php`, `scripts/update-i18n.js`. Verificado por listado de `scripts/` + grep: ninguno está referenciado desde `package.json` (scripts), `app/`, `public/` ni `src/` — solo self-references en sus cabeceras. Candidatos seguros a borrar cuando la ola los procese.
- **Helpers nuevos en `app/Core/`**: NINGUNO verificado todavía. No existen `BookingHoldToken`, `PriceCalculator`, `Retry` ni `GuestName` (grep 0 resultados en `app/`). La extracción de helpers queda pendiente de la ola.
- **Hardcodes → config (parcial, verificado)**: `app/Core/Config.php::DEFAULTS` ya centraliza `SITE_URL`, `TIMEZONE`, `DB_HOST`/`DB_PORT`/`DB_NAME`, `ALLOWED_ORIGINS`, `DEFAULT_HOTEL_ID`, `EXCHANGE_RATE_USD_PEN`, `DEFAULT_GUEST_EMAIL`, `DEFAULT_REPLY_EMAIL`. Queda auditar los hardcodes restantes en actions/adapters.
- **Drift de docs corregido en esta misma sesión (2026-08-05)**: `docs/API_REGISTRY.md` (variables de entorno obsoletas → nombres reales verificados con `Config::get()`; endpoints sin documentar añadidos), `AGENTS.md` + `README.md` (distinción de los dos contratos de test), `docs/multi-hotel.md` (estructura `public/index.php` + `app/`).
- **Cierre**: la ola queda **EN CURSO**; actualizar esta sección cuando el agente paralelo commitee el borrado de scripts muertos, los helpers de `app/Core/` y la migración de hardcodes a config.

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

## Verificación webhook (2026-08-03) — COMPLETADA

Verificación end-to-end del flujo de webhooks de MercadoPago ejecutada el 2026-08-03. **Reemplaza la interpretación previa de esta sección** (diagnóstico read-only que infería "webhook probablemente NO registrado" a partir del historial vacío de `notifications_history`): esa inferencia era errónea y queda corregida por la evidencia real de abajo.

### Hallazgos (evidencia verificada vía mercadopago-mcp y logs)

1. **Webhook REGISTRADO** (app 8501374849722569): existía desde 2026-07-13; actualizado hoy. URLs prod+sandbox = `https://usgarhoteles.com/api/webhook`; topic `payment` incluido. Confirmado vía `save_webhook`/`notifications_history`. El historial vacío previo ("📭 No Notifications Found") era por **cero eventos**, no por falta de registro.
2. **Secret VERIFICADO**: el secret del panel empieza con `a8c54ec...` == `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64, valor nunca impreso) == el secret que el usuario proporcionó.
3. **Pagos de prueba ejecutados**: `1349900853` (`pagoefectivo_atm`, **pending**, 20:43 UTC) y `1327783012` (tarjeta Visa MPE de test, **approved**, 20:47 UTC).
4. **Notificaciones ENTREGADAS al sitio**: 2 eventos `payment` recibidos en producción, ambos con HTTP 500: "No se pudieron obtener los detalles del pago de Mercado Pago". **Diagnóstico**: el `.env` de producción usa el token `APP_USR` de la app 8776209959654245, que no puede leer pagos TEST de la app 8501374849722569 — artefacto del entorno de test, **NO un bug de código**.
5. **Simulación local EXITOSA** (con `.env` en TEST): POST firmado a `/api/webhook?data.id=1327783012` → **HTTP 200**. Log: "Webhook recibido" (payment_id 1327783012) → "Firma de webhook validada correctamente" → "Pago ID 1327783012 tiene estado 'approved'. Omitiendo confirmacion." → el path completo (firma HMAC + fetch de detalles + respuesta) funciona.

### Conclusión

- El diagnóstico previo era incorrecto: el webhook sí estaba registrado desde 2026-07-13; el historial vacío era por cero eventos y el secret del panel no se había comparado todavía.
- El flujo de código (firma + fetch de detalles + respuesta) está verificado localmente con HTTP 200; los 500 de producción corresponden al mismatch de tokens (test vs prod) documentado en el punto 4.

### Fuera del alcance de la app de test

- Registrar el webhook en el panel de la app de **producción** (8776209959654245) para pagos reales (el webhook verificado pertenece a la app de test 8501374849722569).
- Restaurar las credenciales `APP_USR` de producción en el `.env` antes de cualquier deploy (el `.env` local quedó en modo TEST por instrucción del usuario).

## Verificación checkout + pagos en vivo (2026-08-03) — COMPLETADA (fix incluido)

**CORRECCIÓN DE IDENTIDAD DE APP (aclaración del usuario)**: la app `8501374849722569` **ES la app de producción** de MercadoPago (con credenciales APP_USR-8501374849722569-... y public key APP_USR-24cfc998-...). La app `8776209959654245` era la app ANTERIOR/legacy (sus tokens APP_USR-8776209959654245 estaban en el .env del servidor → no podían leer los pagos de la app correcta → 500 en webhook). Las secciones previas que decían "app de test 8501374849722569 / producción 8776209959654245" quedan superseded por esta corrección.

### Diagnóstico en vivo (producción, 2026-08-03)
- `POST https://usgarhoteles.com/api/booking` → **200** con `mp_public_key: APP_USR-24cfc998-...` (credenciales APP_USR correctas YA en el servidor) y hold real (`USGAR-5a972718a6c5`, gateway_price 684 PEN = 180 USD × 3.8).
- Token APP_USR válido: `GET api.mercadopago.com/v1/payments/search` → 200, 5 pagos reales legibles (approved 2025-11-27).
- **Aislamiento TEST/PROD confirmado**: token APP_USR → 404 "Payment not found" para pagos TEST (`live_mode: false`); token TEST → 200. Los 500 históricos del webhook eran eventos TEST entregados a un servidor con token de la app vieja — **irrecuperables por diseño, NO bug**.

### BUG BLOQUEANTE ENCONTRADO Y CORREGIDO (este es el fix grande del día)

**Síntoma del usuario**: "no me deja poner ni la tarjeta ni el tipo de documento" en el checkout de producción.

**Causa raíz 1 — cardForm nunca montaba (frontend)**: `PaymentStep.start()` solo se re-evaluaba con cambios del `wizardStore`; el usuario llegaba al paso 3 con los campos del huésped vacíos → `start()` retornaba; al llenarlos y pulsar CONTINUAR, `wizardStore.next()` era **no-op** en step 3 (`if (state.step < 3)`) → nadie volvía a llamar `start()` → 0 POST `/api/booking` desde el navegador → `cardForm` de MP nunca montaba (divs de tarjeta/CVV vacíos, select de documento sin opciones).
- **Fix**: `src/components/booking/GuestStep.astro` — el botón `[data-guest-next]` ahora llama `wizardStore.setState({ step: 3 })` (setState SIEMPRE notifica) en vez de `wizardStore.next()` (no-op en step 3). Además `PaymentStep.astro`: `started = false` en los paths de error (permite reintento).

**Causa raíz 2 — 500 en process-payment (backend, bug SDK dx-php)**: `MercadoPagoAdapter::processPayment()` pasaba `setCustomHeaders(['X-Idempotency-Key' => $key])` (clave con mayúscula). El SDK `MercadoPagoClient::getIdempotencyKey()` (línea 149-159) detecta la key con `array_change_key_case()` pero devuelve `$headers[strtolower($key)]` del array ORIGINAL (case-sensitive) → **null → TypeError → 500** "Return value must be of type string, null returned".
- **Fix**: clave en **minúsculas** `['x-idempotency-key' => $key]` en `processPayment` y `refundPayment` (el SDK busca exactamente `$headers['x-idempotency-key']`). Test `testProcessPaymentSendsIdempotencyHeader` actualizado al formato correcto.

### E2E verificado en local (entorno TEST, sin dinero real) — evidencia completa

| Escenario | Evidencia |
|---|---|
| cardForm monta (iframes tarjeta/exp/CVV + select doc `DNI/C.E/RUC/Otro`) | ✅ snapshot + `hasIframe: true` |
| Pago Visa test `4009 1753 3280 6176`, titular `APRO`, doc `123456789` | ✅ payment `1327783766` **approved/accredited**, `live_mode: false`, 684 PEN, `external_reference: USGAR-20376530e184` |
| DB: hold → `paid` + `processed_payments` (payment_id, processed_at) | ✅ `provisional_bookings` + `processed_payments` |
| Redirect a `/book/success` | ✅ |
| Rama OTHE (rechazo general): titular `OTHE` | ✅ payment `1349906085` **rejected** `cc_rejected_other_reason`; wizard muestra "Error al procesar el pago." + botón re-habilitado; hold queda **pending** (NO paid) |
| Rama CONT (pendiente): titular `CONT` | ✅ payment `1327783824` **rejected** (esperado con `binary_mode=true`: MP fuerza approved/rejected, nunca pending); hold **pending**; error mostrado |
| Webhook firmado, pago approved ya procesado | ✅ **200 "Payment already processed"** (idempotencia) |
| Webhook firmado, pago rejected | ✅ **200 "Payment status is not approved"** |
| Webhook firma inválida | ✅ **401 "Firma de webhook invalida o ausente"** |
| Stress: 50× GET /api/rooms + 20× POST /api/booking + 10× webhook idempotente | ✅ **0 errores 5xx**; p95: rooms 574ms / booking 1239ms / webhook 578ms (umbral plan: <2s) |

### Notas de la verificación
- `npm run check` → 0 errores; `php composer.phar check` → **27/27 tests / 103 assertions PASS + PHPStan OK**.
- `quality_evaluation` del MCP NO funciona con pagos TEST (el wrapper consulta con token APP_USR → 404) ni con pagos de la app anterior ("not originated from app") — límite de la herramienta; funcionará con el primer pago LIVE de la app.
- El `.env` local queda en modo TEST (para E2E/stress); el servidor tiene APP_USR (verificado en vivo).

## Siguiente
- **Checkout VERIFICADO end-to-end (2026-08-03)** con tarjetas de prueba (APRO/OTHE/CONT), webhook firmado (3 ramas) y stress sin errores. Los fixes (cardForm trigger + idempotency key) están en el branch pendiente de commit.
- Para el cierre definitivo en producción: desplegar los fixes + primera reserva real (user-gated) → verificar webhook 200 + email + booking Paid (y `quality_evaluation` del MCP funcionará con ese pago LIVE).
- F4 frontend (fases 3-4 redesign: internas + wizard reserva).
- F3 CMS (mini-contrato de alcance al empezar).
- F1 Nobeds (requiere sub pagada; instrucciones de cuenta/API key al llegar).
