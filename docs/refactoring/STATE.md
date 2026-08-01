# STATE — Refactor USGAR (línea base)

Actualizado: 2026-08-01. Estado documentado al inicio del refactor. Actualizar al final de cada fase.

## Entorno

- Repo: `C:\Users\akim\Desktop\usgar-redesign` — rama `main`, ahead 9 de origin/main.
- PHP 8.x (Hostinger), MySQL. Windows (desarrollo) / Linux (producción).
- Composer vía `composer.phar` local (no hay `composer` global asumido).

## Dependencias (composer.json)

- `hybridauth/hybridauth ^3.13`, `mercadopago/dx-php ^3.12`.
- Dev (P0): `phpunit/phpunit ^11`, `phpstan/phpstan ^2` — INSTALAR.
- `vendor/bin/phpunit` NO existe aún (hay polyfill casero en `tests/Unit/TestCase.php`).

## QA

- `phpstan.neon`: level 6, paths `app/Core` + `app/Features`, bootstrapFiles `app/Core/Autoloader.php`, ignoreErrors `#Constant [A-Z_]+ not found#`. Sin baseline todavía.
- 17 archivos de tests en `tests/` (suites Unit + postman + stress). Runner casero `scripts/run-exhaustive-tests.php`.
- PHPStan binario NO instalado; `phpstan.neon` existe pero no se ejecuta.

## Arquitectura

- `app/Core/`: Autoloader PSR-4 casero (prefijo `App\`), Container DI (autowiring Reflection), Config singleton (parsea `.env`, método real `Config::boot()`), Database (PDO MySQL), Events/EventDispatcher con outbox (`event_outbox`), Router (tabla plana), Request/Response/Validator/Middleware/RateLimiter/Logger.
- `app/Features/`: Auth, Booking, Webhooks, Shared, Cron.
- Front controller `public/index.php`. Frontend Astro estático en `src/` (sin `/api` en src — la API es PHP).
- NO existe `app/bootstrap.php` (cron/process_outbox.php lo requiere → roto).

## Pagos MercadoPago (mantener)

- `MercadoPagoAdapter` (única puerta al SDK `MercadoPago\Client\Payment\PaymentClient`).
- `HandleMercadoPagoWebhookAction`: rutas `/api/webhook` + `/api/webhook-mercado-pago`.
- `ProcessPaymentAction` (POST /api/process-payment), `CreateBookingAction` (POST /api/booking), `GetBookingStatusAction` (GET /api/booking-status).
- `ProvisionalBookingRepository`: tablas `provisional_bookings` + `processed_payments`.
- `BookingPaidEvent` + listeners `ConfirmQloAppsOrderListener`, `SyncChannexBookingListener` (registrados SOLO en index.php).
- Fix pendiente de auditoría: `(int)$paymentId` → string en `MercadoPagoAdapter.php:183`.

### Residuos Checkout Pro (a eliminar)

- Columna `preference_id` + `updatePreferenceId()`.
- `User.php:244` (¿campo preference?).
- `IBookingService.ts:41-42` (`init_point` / `preference_url`).
- `createHoldAndPreference` → `createHold`.
- Filtro IPN legacy `HandleMercadoPagoWebhookAction.php:111-116`.
- `WebhookDebugAction.php` (dead code, sin rutas registradas).
- `postman-payment-suite.php:112-114` aserciones obsoletas.
- Logging ruidoso de headers webhook `:53-66` (revisar; puede ser útil en prod — decidir).

## Outbox (roto)

- `cron/process_outbox.php` requiere `app/bootstrap.php` que NO existe.
- Listeners solo registrados en `public/index.php`.
- Sin crontab configurado en Hostinger.
- No hay job de reconciliación (pagos aprobados cuyo webhook nunca llegó quedan huérfanos).

## Secretos en git (P4)

- `.env.example` con credenciales reales de BD.
- `tests/test_sdk_webhook.php:27` webhook secret de producción hardcodeado.
- `scripts/run-stress-tests.php:85` idem.
- CSP duplicada: `.htaccess:26` vs `app/Core/Middleware.php:94`.
- Hardcodes: `EXCHANGE_RATE_USD_PEN=3.80`; URLs `cms.hotelesusgar.com`, `api.channex.io`, `localhost:4321`; `id_hotel=1`; emails.

## Diagnóstico MercadoPago (MCP, 2026-08-01)

- MCP `mercadopago-mcp-server` activo (token APP_USR... app `usgar test`, AppID 8501374849722569).
- `notifications_history`: **sin notificaciones configuradas ni entregadas** para la app.
- `https://usgarhoteles.com/api/webhook` responde (404 a GET — esperado; solo POST).
- Pendiente: registrar webhook formal vía `save_webhook` (topic payment) — requiere confirmación del usuario.

## Cron Hostinger (P2)

- Comando previsto: `php /home/uXXXXX/<ruta>/cron/process_outbox.php` cada 5 min. Confirmar ruta real al desplegar.
