# API — USGAR Hotels

Contrato completo del backend PHP. **Fuente de verdad: `public/index.php`** (registro de rutas) — verificada 2026-08-11. Si un endpoint no está aquí o aquí dice algo que el código no hace, el código manda y este doc se corrige.

**Base URL:** `/api` (en dev, el Vite proxy redirige `localhost:4321/api` → `localhost:8000/api`).

## Envelope general (todas las respuestas)

- Éxito: `{ "success": true, ... }`
- Error: `{ "success": false, "error": { "code": "<CODIGO>", "message": "<texto>", "details?": [...] } }`
- `Content-Type: application/json; charset=utf-8`
- CORS: `Access-Control-Allow-Origin` según `ALLOWED_ORIGINS` (en prod, lista explícita + `Vary: Origin` + credentials). Headers permitidos: `Content-Type, Authorization, X-Requested-With, x-signature, x-request-id`.
- Método no permitido → 404 (pendiente 405: ver `docs/ROADMAP.md` P2-5).
- Errores no controlados → 500 genérico, detalle solo al log.

## Endpoints

| # | Method | Endpoint | Action | Auth |
|---|---|---|---|---|
| 1 | GET | `/api/health` | `HealthCheckAction` | — |
| 2 | GET | `/api/rooms` | `GetRoomsAction` | — |
| 3 | GET | `/api/rooms/calendar` | `GetRoomsCalendarAction` | — |
| 4 | POST | `/api/booking` | `CreateBookingAction` | — (devuelve access_token) |
| 5 | POST | `/api/process-payment` | `ProcessPaymentAction` | `access_token` (HMAC) |
| 6 | POST | `/api/extend-hold` | `ExtendHoldAction` | — |
| 7 | GET | `/api/booking-status` | `GetBookingStatusAction` | — |
| 8 | GET | `/api/payment-check` | `GetPaymentCheckAction` | — |
| 9 | POST | `/api/webhook` | `HandleMercadoPagoWebhookAction` | Firma HMAC MP |
| 10 | POST | `/api/cron/cleanup` | `CleanExpiredCartsAction` | CLI only |
| 11 | POST | `/api/cron/manual-review` | `RetryManualReviewAction` | CLI only |
| 12 | GET | `/api/auth/providers` | `AuthProvidersAction` | — |
| 13 | GET | `/api/auth/login` | `AuthLoginAction` | — (OAuth redirect) |
| 14 | GET | `/api/auth/callback` | `AuthCallbackAction` | — (OAuth return) |
| 15 | POST | `/api/auth/register` | `AuthRegisterAction` | — |
| 16 | POST | `/api/auth/login-email` | `AuthLoginEmailAction` | — |
| 17 | GET | `/api/auth/me` | `AuthMeAction` | Cookie JWT |
| 18 | POST | `/api/auth/logout` | `AuthLogoutAction` | Cookie JWT |
| 19 | GET | `/api/auth/logout` | `AuthLogoutAction` | Cookie JWT |
| 20 | GET | `/api/user/bookings` | `GetUserBookingsAction` | Cookie JWT |
| 21 | POST | `/api/user/profile` | `UpdateUserProfileAction` | Cookie JWT |
| 22 | POST | `/api/newsletter` | `SubscribeNewsletterAction` | — |
| 23 | POST | `/api/contact` | `SubmitContactAction` | — |

Notas:
- Los endpoints CLI (10, 11) se invocan como `php public/index.php /api/cron/cleanup` (el front controller detecta `PHP_SAPI === 'cli'` y usa `$argv[1]` como ruta). Los crons reales viven en `cron/` y usan los mismos actions — ver `docs/DEPLOYMENT.md` §Cron.
- No existe alias `/api/webhook-mercado-pago` (eliminado del registro; si aparece en docs viejas, ignorar).

## GET /api/rooms

Disponibilidad por rango. **Query (opcionales):** `checkIn`, `checkOut` (YYYY-MM-DD), `id_hotel`. Sin fechas → default hoy/+1 (200 con datos, no 400).

Respuesta (campos reales):
```json
{
  "success": true,
  "rooms": [{
    "id_room_type": 1, "id_product": 1, "room_name": "Matrimonial Superior",
    "price": 90.0, "non_refundable_price": 81.0, "max_guests": 2,
    "total_rooms": 5, "available_qty": 3,
    "slug": "matrimonial", "currency": "USD",
    "price_formatted": "$90.00", "nights": 1, "total_stay_price": 90.0
  }]
}
```
- `non_refundable_price` = precio base con el Feature Price Plan de QloApps aplicado por `DiscountResolver` (fuente única: planes de `qlo_htl_room_type_feature_pricing` con `id_cart=0 AND active=1`). Sin plan → igual a `price`. Ver `docs/PMS.md` §C.
- `nights` = `max(1, round((checkOut - checkIn) / 86400))`.
- Sin BD/inventario: `available_qty` nunca negativo (fallback del adapter).

## GET /api/rooms/calendar

Disponibilidad por día. **Query:** `from`, `to` (default: hoy → +60 días), `id_hotel`. Máx. 120 días.

Respuesta: `{ "success": true, "days": { "YYYY-MM-DD": { "<id_room_type>": qty, ... } }, "from": "...", "to": "..." }`

## POST /api/booking

Crea el hold (bloqueo 15 min, `BOOKING_HOLD_TTL`). **Body:**
```json
{
  "roomSlug": "matrimonial",           // o "id_room_type" (normalización adaptativa)
  "checkIn": "2026-08-01", "checkOut": "2026-08-03",
  "guests": 2, "rateType": "standard", // standard | non_refundable (whitelist cerrada)
  "guestDetails": { "firstName": "...", "lastName": "...", "email": "...", "phone": "..." }
}
```
El backend recalcula el precio (fuente única QloApps) y lo congela junto con el tipo de cambio — **el cliente nunca envía precios**. Campos requeridos: `id_room_type | roomSlug`, `checkIn`, `checkOut`, `guestName`, `guestEmail`.

Respuesta:
```json
{
  "success": true, "cart_id": "...", "access_token": "<HMAC cart_id:email>",
  "currency": "USD", "price": 214.5, "rate_type": "standard",
  "exchange_rate": 3.80, "gateway_currency": "PEN", "gateway_price": 815.1,
  "mp_public_key": "APP_USR-...", "expires_at": "...", "time_left_seconds": 900,
  "room_summary": { "id_room_type": 1, "slug": "matrimonial", "room_name": "...", "price_per_night": 90.0, "nights": 2, "guests": 2 }
}
```
Errores: 400 (validación/disponibilidad), 500. Concurrencia: lock de serialización por habitación (`room_locks` FOR UPDATE) — dos creates simultáneos no duplican holds.

## POST /api/process-payment

Cobro con Checkout API. **Body:**
```json
{
  "cart_id": "...", "access_token": "...",
  "payment_data": {
    "token": "card_token_mp", "issuer_id": "...", "payment_method_id": "visa",
    "installments": 3, "device_session_id": "...",
    "payer": { "email": "...", "identification": { "type": "DNI", "number": "..." } }
  }
}
```
Comportamiento verificado:
- Gates antes de cobrar: token HMAC válido, hold existe y `status=pending`, no expirado, sin `payment_id` previo (evita doble cobro en reintentos).
- `approved` → payment_id + status `paid` + evento `booking.paid` al outbox (misma transacción) → respuesta `success:true`.
- `pending`/`in_process` → se persiste el payment_id; polling (`/api/payment-check`) y webhook reconcilian.
- Rechazado → rollback total, `success:false` con `status` + `status_detail`.
- Fallo de commit tras cobro exitoso → attach best-effort + `success:false status:pending` (reconciliación automática; nunca doble cobro).
- Errores MP → status HTTP real (400/422) con `error.code: PAYMENT_REJECTED`.

## POST /api/extend-hold · GET /api/booking-status · GET /api/payment-check

- `POST /api/extend-hold` — body `{ "cart_id": "..." }` → `{ "success": true, "expires_at": "..." }`.
- `GET /api/booking-status?cart_id=...` → estado del hold (`pending|paid|expired|cancelled`).
- `GET /api/payment-check?cart_id=...` → estado del pago (usa el payment_id persistido; polling del frontend en `book/success.astro`).

## POST /api/webhook

Webhook de MercadoPago (topic `payment`). Registrado en el panel MP (app de producción) con callback `https://usgarhoteles.com/api/webhook`.

- Verificación: firma HMAC `x-signature` + `x-request-id` vía `WebhookSignatureValidator` del SDK (tolerancia 300s de replay); si `MERCADO_PAGO_WEBHOOK_SECRET` no está configurado, el webhook se rechaza (fail-closed).
- Flujo: getPaymentDetails (1 solo intento, timeout 8s — el ACK de MP espera ≤22s) → dedup (`isOrderConfirmed`) → confirmación de orden en QloApps + evento outbox → ACK 200.
- Responder 200 rápido: `Response::jsonAsync` cierra la conexión antes del trabajo pesado (fastcgi_finish_request).

## Auth (cookie JWT `usgar_session`, HttpOnly, SameSite=Lax, 30 días)

- `POST /api/auth/register` — `{ "email", "password", "first_name"|"fullName", "last_name?", "redirect?" }` → 201 + cookie. Mín. 8 caracteres; bcrypt.
- `POST /api/auth/login-email` — `{ "email", "password", "redirect?" }` → 200 + cookie. Cuentas OAuth-only → 400 con `isOAuth: true` + provider.
- `GET /api/auth/me` — usuario actual desde la cookie → `{ "success": true, "user": { id, name, email, photo, provider } }`.
- `POST|GET /api/auth/logout` — limpia la cookie.
- `GET /api/auth/providers` — `{ "success": true, "providers": ["Google", ...] }` (solo los con credenciales en `.env`).
- `GET /api/auth/login` + `/api/auth/callback` — OAuth (hybridauth 3). El callback valida redirect contra open-redirect (solo rutas `/...` internas, rechaza `//`).
- `GET /api/user/bookings` — reservas del usuario (`provisional_bookings` por `user_id`).
- `POST /api/user/profile` — `{ "fullName", "last_name?", "phone" }` → actualiza perfil.
- Nota: el JWT es HMAC-SHA256 con `AUTH_JWT_SECRET` (≥32 chars), `exp` + `iat`, sin `iss`/`aud`/`jti` (revocación server-side pendiente — `docs/ROADMAP.md` P1-7).

## Newsletter y Contacto

- `POST /api/newsletter` — `{ "email", "locale" }` → tabla `newsletter_subscribers` (auto-creada). 422 email inválido, 503 BD offline.
- `POST /api/contact` — formulario de contacto → `SubmitContactAction`.

## Panel de disponibilidad del dueño (cookie `usgar_panel`, JWT HMAC, 12h)

Página `/panel` (Astro, `noindex`) + API protegida para el dueño del hotel: calendario mensual de reservas por habitación física (timeline por canal) con import/export CSV y Excel.

- `POST /api/panel/login` — `{ "password" }` → 200 + cookie `usgar_panel`. Password = env `PANEL_PASSWORD` (fail-closed: si no está configurada, nadie entra; comparación `hash_equals`).
- `POST /api/panel/logout` — limpia la cookie.
- `GET /api/panel/availability?month=YYYY-MM` — grid del mes: `{ month, today, rooms[], bookings[] }`. `rooms` = habitaciones físicas de `qlo_htl_room_information` (join `qlo_htl_room_type` por `id_product`); `bookings` = reservas confirmadas de `qlo_htl_booking_detail` (con `id_room`/`room_num`/cliente/`total_paid_amount`), holds web de `provisional_bookings`, bloqueos manuales de `manual_blocks` (tabla propia, auto-creada) y fuera-de-servicio de `qlo_htl_room_disable_dates`. Canales: `web | walkin | ota | phone | qlo | maint`; estados: `confirmed | hold | maint`.
- `GET /api/panel/export?format=csv|xlsx&month=YYYY-MM` — descarga de reservas del mes. CSV con BOM UTF-8 (abre directo en Excel); XLSX real vía PhpSpreadsheet (hoja Reservas + hoja Resumen con ocupación/ingreso por canal).
- `POST /api/panel/import` — `{ "filename", "content_base64" }` (o `{ "csv" }`). Formato: `habitacion, checkin (YYYY-MM-DD), checkout, huesped, canal, estado, precio` (cabecera opcional). CSV parseado nativo; XLSX vía PhpSpreadsheet. Cada fila válida crea un bloqueo en `manual_blocks` (la habitación deja de venderse en la web); filas con habitación inexistente → `skipped` con detalle. Respuesta: `{ success, imported, skipped, errors[] }`.
- Dependencia nueva: `phpoffice/phpspreadsheet` (solo usado por export/import del panel).

## Variables de entorno

Fuente completa: `.env.example` (canónico). Lectura: `App\Core\Config::get()` con defaults en `app/Core/Config.php::DEFAULTS`. Ubicación del `.env` en prod: un nivel arriba de `public_html` o `.builds/config/.env` (hPanel); el build NO lo copia a `dist/`.

| Variable | Usado por | Descripción |
|---|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | Database | MySQL (apunta a la BD de QloApps: tablas `qlo_*` + propias) |
| `QLOAPP_API_URL` / `QLOAPP_API_KEY` | QloAppAdapter | URL + key del webservice XML del PMS |
| `MERCADO_PAGO_ACCESS_TOKEN` | MercadoPagoAdapter | Token MP (fuente única; prefijo no define entorno) |
| `PUBLIC_MERCADO_PAGO_PUBLIC_KEY` | CreateBookingAction | Public key para el cardForm |
| `MERCADO_PAGO_WEBHOOK_SECRET` | Webhook | Secreto HMAC de firma (debe ser EXACTO al del panel MP) |
| `MERCADO_PAGO_CURRENCY` | Booking/PMS | Moneda de cobro (PEN) |
| `EXCHANGE_RATE_USD_PEN` | Booking | Tipo de cambio congelado en cada hold |
| `HOTEL_BASE_CURRENCY` | GetRoomsAction | Moneda de precios (USD) |
| `MP_STATEMENT_DESCRIPTOR` / `MP_BINARY_MODE` | MercadoPagoAdapter | Descriptor de extracto / modo binario |
| `MERCADO_PAGO_TIMEOUT_CREATE_MS` / `MERCADO_PAGO_TIMEOUT_GET_MS` | MercadoPagoAdapter | Timeouts totales SDK (15s / 8s) |
| `BOOKING_TOKEN_SECRET` (fallback `CRON_SECRET`) | Booking | Secreto HMAC del access_token de hold |
| `AUTH_JWT_SECRET` | SessionService / PanelAuth | Secreto JWT (≥32 chars); el panel firma su cookie con el mismo secret |
| `PANEL_PASSWORD` | PanelLoginAction | Password del panel del dueño (`/panel`). Sin valor = panel inaccesible (fail-closed) |
| `BOOKING_HOLD_TTL` | CreateBookingAction | TTL del hold (strtotime, `+15 minutes`) |
| `ALLOWED_ORIGINS` / `TRUSTED_PROXIES` / `TIMEZONE` / `SITE_URL` | Core | CORS, proxies confiables, zona, URL base |
| `RATE_LIMIT_MAX_REQUESTS` / `RATE_LIMIT_WINDOW_SECONDS` | RateLimiter | Límite global por IP (300/600s) |
| `DEFAULT_HOTEL_ID` / `DEFAULT_GUEST_EMAIL` / `DEFAULT_REPLY_EMAIL` / `DEFAULT_GUEST_NAME` | Booking/PMS | Defaults de negocio |
| `OTA_DEFAULT_PHONE` / `OTA_DEFAULT_EMAIL` / `OTA_DEFAULT_NAME` / `OTA_DEFAULT_SURNAME` / `OTA_HOLD_TTL` / `QLOAPPS_DEFAULT_GUEST_NAME` | QloAppAdapter / Listener | Defaults de huéspedes OTA (algunos reservados para la sync del Channel Manager) |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `MICROSOFT_*` / `FACEBOOK_*` | AuthService | Credenciales OAuth (solo si existen se activan) |

## Pruebas del contrato

- `php tests/api-harness.php` — contrato del flujo de reserva vía curl (requiere dev server en :8000).
- `php scripts/run-exhaustive-tests.php` — suite exhaustiva (unit + integración + asserts del port del channel manager).
- `npm run audit:security` / `npm run audit:seo` — auditorías.
- `npx playwright test` — E2E de flujos de usuario.
