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

## En curso / pendiente (F2)
- [ ] **Webhook MP en panel — REGISTRADO (2026-08-01)**: app `8501374849722569`, callback prod/sandbox `https://usgarhoteles.com/api/webhook`, topic `payment` (vía MCP `save_webhook`). La app ya tenía webhook creado (2026-07-13) pero `notifications_history` estaba vacío → nunca recibió eventos. **ACCIÓN DEL USUARIO**: verificar que `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` sea idéntico al secreto del panel (developers/panel/app/8501374849722569/webhooks); luego probar con un pago sandbox y confirmar entrega en `notifications_history`.
- [ ] `success.astro` 3 estados (confirmado / pendiente / verificando) — refactor PLAN P6-1.
- [ ] Logging diagnóstico pesado en `HandleMercadoPagoWebhookAction` (bloque "WEBHOOK DIAGNOSTICS"): reducirlo a un log conciso UNA VEZ que el webhook esté verificado funcionando.
- [ ] DIP restante (P3-1): constructores nullable en actions; CSP duplicada (P4-3: .htaccess vs Middleware).

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
