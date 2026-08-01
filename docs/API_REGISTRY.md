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

**Env vars:** `QLOAPPS_DB_*` (conexión directa a QloApps MySQL)

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
| POST | `/api/webhook-mercado-pago` | `HandleMercadoPagoWebhookAction` | Token |
| POST | `/api/webhook/channex` | `HandleChannexWebhookAction` | Token |

**Nota:** Ambas rutas `/api/webhook` y `/api/webhook-mercado-pago` apuntan al mismo Action (compatibilidad).

**Env vars:** `MP_WEBHOOK_SECRET`, `CHANNEX_WEBHOOK_TOKEN`

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
| GET | `/api/auth/providers` | `AuthProvidersAction` |  |
| GET | `/api/user/bookings` | `GetUserBookingsAction` | JWT |

### POST `/api/auth/register`
**Body:** `{ "name": "...", "email": "...", "password": "..." }`

### POST `/api/auth/login-email`
**Body:** `{ "email": "...", "password": "..." }`
**Response:** `{ "success": true, "token": "...", "user": { ... } }`

### GET `/api/auth/me`
**Header:** Cookie `usgar_session` (JWT HttpOnly)
**Response:** `{ "success": true, "user": { "id": ..., "name": "...", "email": "..." } }`

---

## Variables de Entorno Requeridas

| Variable | Usado por | Descripción |
|----------|-----------|-------------|
| `QLOAPPS_DB_HOST` | QloAppAdapter | Host MySQL de QloApps |
| `QLOAPPS_DB_NAME` | QloAppAdapter | Nombre de DB |
| `QLOAPPS_DB_USER` | QloAppAdapter | Usuario DB |
| `QLOAPPS_DB_PASS` | QloAppAdapter | Password DB |
| `MP_ACCESS_TOKEN` | MercadoPagoAdapter | Token de Mercado Pago |
| `MP_WEBHOOK_SECRET` | WebhookAction | Secreto para validar webhooks MP |
| `CHANNEX_API_KEY` | ChannexAdapter | API key de Channex |
| `CHANNEX_PROPERTY_ID` | ChannexAdapter | ID de propiedad en Channex |
| `CHANNEX_ROOM_*` | RoomTypeRegistry | UUIDs de room types en Channex |
| `CHANNEX_RATE_*` | RoomTypeRegistry | UUIDs de rate plans en Channex |
| `JWT_SECRET` | SessionService | Secreto para firmar tokens JWT |
| `APP_ENV` | Config | `development` o `production` |
| `APP_URL` | Config | URL base de la aplicación |
