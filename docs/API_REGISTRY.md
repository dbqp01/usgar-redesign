# API Registry — USGAR Hotels

Catálogo completo de endpoints del backend PHP. Todos los endpoints se sirven desde `public/index.php` y se despachan a clases Action individuales (patrón ADR).

**Base URL:** `/api` (en desarrollo, Vite proxy redirige localhost:4321/api → localhost:8000/api)

---

## Health

| Method | Endpoint | Action | Auth |
|--------|----------|--------|------|
| GET | `/api/health` | `HealthCheckAction` |  |

**Response:** `{ "success": true, "status": "ok", "timestamp": "..." }`

---

## Newsletter

| Method | Endpoint | Action | Auth |
|--------|----------|--------|------|
| POST | `/api/newsletter` | `SubscribeNewsletterAction` |  |

**Body:** `{ "email": "user@example.com", "locale": "es" }` (locale opcional, default `en`)

**Response:** `{ "success": true, "message": "subscribed" }` (422 email inválido, 503 BD offline, 500 error)

**Nota:** Crea la tabla `newsletter_subscribers` (email único, ip, source, locale, created_at) si no existe.

---

## Rooms (Disponibilidad)

| Method | Endpoint | Action | Auth |
|--------|----------|--------|------|
| GET | `/api/rooms` | `GetRoomsAction` |  |
| GET | `/api/rooms/calendar` | `GetRoomsCalendarAction` |  |

**Query Params:** `?checkIn=YYYY-MM-DD&checkOut=YYYY-MM-DD` (opcionales)

**Response:**
```json
{
  "success": true,
  "rooms": [
    { "id": "1", "slug": "matrimonial", "name": "...", "pricePerNight": 45, "available": true, "maxGuests": 2 }
  ]
}
```

**Frontend consumer:** [book.astro](file:///c:/Users/akim/Desktop/usgar-redesign/src/pages/book.astro) vía [bookingService.ts](file:///c:/Users/akim/Desktop/usgar-redesign/src/services/bookingService.ts)

**Env vars:** `QLOAPP_API_URL` + `QLOAPP_API_KEY` (API XML del PMS QloApps, vía `QloAppAdapter`/`PmsPortInterface`), `HOTEL_BASE_CURRENCY` (moneda de precios), `DB_*` (**apuntan a la BD de QloApps**: `QloAppAdapter` lee/escribe tablas `qlo_*` por PDO directo — `qlo_htl_room_type`, `qlo_product`, `qlo_htl_room_information`, `qlo_htl_booking_detail`, `qlo_cart`, `qlo_htl_cart_booking_data`; el usuario MySQL necesita SELECT/UPDATE sobre `qlo_*` y CREATE/ALTER para las tablas propias `provisional_bookings`/`event_outbox` que se auto-crean)

### GET `/api/rooms/calendar`

Disponibilidad por día y por habitación para el calendario de reservas.

**Query:** `?from=YYYY-MM-DD&to=YYYY-MM-DD&id_hotel=1` (default: hoy → +60 días, máx. 120 días)

**Response:** `{ "success": true, "days": { "YYYY-MM-DD": { "slug": qty, ... } }, "from": "...", "to": "..." }`

---

## Booking (Reservas)

| Method | Endpoint | Action | Auth |
|--------|----------|--------|------|
| POST | `/api/booking` | `CreateBookingAction` |  |
| POST | `/api/process-payment` | `ProcessPaymentAction` | `access_token` (HMAC `cart_id:email`) |
| POST | `/api/extend-hold` | `ExtendHoldAction` |  |
| GET | `/api/booking-status` | `GetBookingStatusAction` |  |

### POST `/api/booking`

**Request body:**
```json
{
  "roomSlug": "matrimonial",
  "checkIn": "2026-08-01",
  "checkOut": "2026-08-03",
  "guests": 2,
  "guestDetails": {
    "firstName": "John",
    "lastName": "Doe",
    "email": "john@example.com",
    "phone": "+51999999999"
  }
}
```

**Response:** `{ "success": true, "cart_id": "...", "access_token": "...", "currency": "USD", "price": 214.5, "exchange_rate": 3.75, "gateway_currency": "PEN", "gateway_price": 804.38, "mp_public_key": "APP_USR-...", "expires_at": "...", "time_left_seconds": 900, "room_summary": { "id_room_type": 1, "slug": "matrimonial", ... } }` (Custom Checkout API — sin preference_id/init_point)

**Env vars:** `MERCADO_PAGO_ACCESS_TOKEN`, `PUBLIC_MERCADO_PAGO_PUBLIC_KEY`, `QLOAPP_API_URL`, `QLOAPP_API_KEY`, `BOOKING_TOKEN_SECRET`, `EXCHANGE_RATE_USD_PEN`

### POST `/api/process-payment`

Procesa el pago con tarjeta vía Checkout API (Custom Checkout). Verifica `access_token` (HMAC `cart_id:email` con `BOOKING_TOKEN_SECRET`) y despacha `BookingPaidEvent` si el pago queda `approved`.

**Request body:**
```json
{
  "cart_id": "...",
  "access_token": "...",
  "payment_data": {
    "token": "card_token_mp",
    "issuer_id": "...",
    "payment_method_id": "visa",
    "installments": 3,
    "payer": { "email": "john@example.com", "identification": { "type": "DNI", "number": "..." } }
  }
}
```

**Response:** `{ "success": true, "status": "approved", "payment_id": "...", "message": "Pago aprobado exitosamente." }` o `{ "success": false, "status": "rejected|pending", ... }`

**Env vars:** `MERCADO_PAGO_ACCESS_TOKEN`, `EXCHANGE_RATE_USD_PEN`, `BOOKING_TOKEN_SECRET`

### POST `/api/extend-hold`

**Request body:** `{ "cart_id": "booking_id_here" }`

**Response:** `{ "success": true, "expires_at": "..." }`

### GET `/api/booking-status`

**Query:** `?cart_id=booking_id_here`

**Response:** `{ "success": true, "data": { "booking_id": "...", "status": "CONFIRMED|PENDING_PAYMENT|EXPIRED", ... } }`

---

## Webhooks

| Method | Endpoint | Action | Auth |
|--------|----------|--------|------|
| POST | `/api/webhook` | `HandleMercadoPagoWebhookAction` | Token |
| POST | `/api/webhook-mercado-pago` | `HandleMercadoPagoWebhookAction` | Token (alias deprecated) |

**Nota:** `/api/webhook-mercado-pago` es un alias de compatibilidad que apunta al mismo Action; **sigue registrado en `public/index.php`** — pendiente de eliminación. El callback canónico registrado en el panel de MercadoPago es `/api/webhook`.

**Configuración del webhook en el panel de MercadoPago (registrada 2026-08-01):**

- App: `8501374849722569` ("usgar test").
- Callback (producción y sandbox): `https://usgarhoteles.com/api/webhook` — topics: `payment` (+ tópicos por defecto de la app).
- ⚠️ **Verificar que `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` sea EXACTO al secreto del panel** (developers/panel/app/8501374849722569/webhooks). Si no coincide, la validación de firma HMAC en `HandleMercadoPagoWebhookAction` rechazará todos los webhooks (HTTP 401).
- Diagnóstico: `mercadopago-mcp-server_notifications_history` (app 8501374849722569). Al 2026-08-01 no había notificaciones registradas → probablemente el webhook nunca recibió eventos o el callback anterior era incorrecto; probar con un pago real/sandbox tras verificar el secreto.

**Env vars:** `MERCADO_PAGO_WEBHOOK_SECRET` (nombre real en producción; `MP_WEBHOOK_SECRET` solo sobrevive como fallback en los scripts de test `tests/test_sdk_webhook.php` y `scripts/run-stress-tests.php`).

---

## Cron

| Method | Endpoint | Action | Auth |
|--------|----------|--------|------|
| POST | `/api/cron/cleanup` | `CleanExpiredCartsAction` | CLI only |

**Uso:** `php public/index.php /api/cron/cleanup` (desde crontab en Hostinger)

---

## Auth (Autenticación)

| Method | Endpoint | Action | Auth |
|--------|----------|--------|------|
| GET | `/api/auth/login` | `AuthLoginAction` |  |
| GET | `/api/auth/callback` | `AuthCallbackAction` |  |
| POST | `/api/auth/register` | `AuthRegisterAction` |  |
| POST | `/api/auth/login-email` | `AuthLoginEmailAction` |  |
| GET | `/api/auth/me` | `AuthMeAction` | JWT |
| POST | `/api/auth/logout` | `AuthLogoutAction` | JWT |
| GET | `/api/auth/logout` | `AuthLogoutAction` | JWT |
| GET | `/api/auth/providers` | `AuthProvidersAction` |  |
| GET | `/api/user/bookings` | `GetUserBookingsAction` | JWT |
| POST | `/api/user/profile` | `UpdateUserProfileAction` | JWT |

### POST `/api/auth/register`
**Body:** `{ "name": "...", "email": "...", "password": "..." }`

### POST `/api/auth/login-email`
**Body:** `{ "email": "...", "password": "..." }`
**Response:** `{ "success": true, "token": "...", "user": { ... } }`

### GET `/api/auth/me`
**Header:** Cookie `usgar_session` (JWT HttpOnly)
**Response:** `{ "success": true, "user": { "id": ..., "name": "...", "email": "..." } }`

### POST `/api/user/profile`
Actualiza la información personal del usuario autenticado.
**Body:** `{ "fullName": "...", "last_name": "...", "phone": "..." }` (`fullName` se separa en first/last si falta `last_name`)

---

## Variables de Entorno Requeridas

Todas las claves se leen vía `App\Core\Config` (`Config::get('KEY')`, con fallbacks en `app/Core/Config.php::DEFAULTS`). Verificadas contra el código 2026-08-05.

| Variable | Usado por | Descripción |
|----------|-----------|-------------|
| `DB_HOST` | Database | Host MySQL (default `127.0.0.1`) |
| `DB_PORT` | Database | Puerto MySQL (default `3306`) |
| `DB_NAME` | Database | Nombre de la BD local de la app (default `usgar_hotels`) |
| `DB_USER` | Database | Usuario MySQL |
| `DB_PASS` | Database | Password MySQL |
| `QLOAPP_API_URL` | QloAppAdapter | URL de la API XML del PMS (default `https://cms.usgarhoteles.com/api`) |
| `QLOAPP_API_KEY` | QloAppAdapter | API key del PMS QloApps |
| `MERCADO_PAGO_ACCESS_TOKEN` | MercadoPagoAdapter | Token de Mercado Pago (fuente de verdad única) |
| `MERCADO_PAGO_WEBHOOK_SECRET` | MercadoPagoAdapter / WebhookAction | Secreto para validar la firma HMAC de los webhooks MP |
| `MERCADO_PAGO_CURRENCY` | QloAppAdapter | Moneda de pagos (default `USD`) |
| `PUBLIC_MERCADO_PAGO_PUBLIC_KEY` | CreateBookingAction | Public key MP para el cardForm del frontend |
| `MP_STATEMENT_DESCRIPTOR` | MercadoPagoAdapter | Descriptor del extracto (default `USGAR HOTELES CUSCO`) |
| `MP_BINARY_MODE` | MercadoPagoAdapter | `true`/`false` (default `true`) |
| `EXCHANGE_RATE_USD_PEN` | Booking actions | Tipo de cambio USD→PEN (default `3.80`) |
| `BOOKING_TOKEN_SECRET` | Booking actions | Secreto HMAC del hold token (`cart_id:email`); fallback `CRON_SECRET` |
| `CRON_SECRET` | Cron / Booking actions | Secreto de endpoints cron; fallback de `BOOKING_TOKEN_SECRET` |
| `HOTEL_BASE_CURRENCY` | GetRoomsAction | Moneda de los precios de habitaciones (default `USD`) |
| `OTA_DEFAULT_PHONE` | QloAppAdapter | Teléfono de huésped por defecto (default `000000000`) |
| `OTA_DEFAULT_EMAIL` / `OTA_DEFAULT_NAME` / `OTA_DEFAULT_SURNAME` | — (reservado) | Fallbacks de huésped para reservas OTA; definidos en `.env` pero sin consumo en código (preparados para la sync del QloApps Channel Manager) |
| `OTA_HOLD_TTL` | — (reservado) | TTL del hold de reservas OTA (`+1 year`); sin consumo en código todavía |
| `QLOAPPS_DEFAULT_GUEST_NAME` | ConfirmQloAppsOrderListener | Nombre de huésped por defecto al confirmar orden en QloApps (default `Huésped USGAR`) |
| `AUTH_JWT_SECRET` | SessionService | Secreto para firmar tokens JWT (≥32 caracteres) |
| `SITE_URL` | AuthService / MercadoPagoAdapter | URL base de la aplicación (default `https://usgarhoteles.com`) |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | AuthService | Credenciales OAuth de Google |
| `MICROSOFT_CLIENT_ID` / `MICROSOFT_CLIENT_SECRET` | AuthService | Credenciales OAuth de Microsoft |
| `FACEBOOK_APP_ID` / `FACEBOOK_APP_SECRET` | AuthService | Credenciales OAuth de Facebook |
| `DEFAULT_HOTEL_ID` | Booking / Rooms | `id_hotel` por defecto (default `1`) |
| `DEFAULT_GUEST_EMAIL` | ConfirmQloAppsOrderListener | Email de huésped por defecto (default `reserva@usgarhoteles.com`) |
| `DEFAULT_REPLY_EMAIL` | QloAppAdapter | Email de reply (default `no-reply@usgarhoteles.com`) |
| `TRUSTED_PROXIES` | Config | IPs de proxies confiables para `X-Forwarded-For` (vacío = seguro) |
| `ALLOWED_ORIGINS` | Config | Orígenes CORS permitidos (default `*`) |
| `TIMEZONE` | Config | Zona horaria (default `America/Lima`) |
| `APP_ENV` | Config / Response | `development` / `production` / `testing` |
