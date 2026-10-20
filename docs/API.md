# API — USGAR Hotels

Contrato completo del backend PHP. **Fuente de verdad: `public/index.php`** (registro de rutas) — verificada 2026-10-20 (33 endpoints, 0 rutas fantasma). Si un endpoint no está aquí o aquí dice algo que el código no hace, el código manda y este doc se corrige.

**Base URL:** `/api` (en dev, el Vite proxy redirige `localhost:4321/api` → `localhost:8000/api`).

## Envelope general (todas las respuestas)

- Éxito: `{ "success": true, ... }`
- Error: `{ "success": false, "error": { "code": "<CODIGO>", "message": "<texto>", "details?": { ... } } }`
- Los endpoints JSON responden `Content-Type: application/json; charset=utf-8`. Las exportaciones del panel son una excepción: CSV usa `text/csv` y XLSX usa `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`; los flujos de formulario/OAuth pueden responder con un redirect `3xx` y header `Location`.
- CORS: `Access-Control-Allow-Origin` según `ALLOWED_ORIGINS` (en prod, lista explícita + `Vary: Origin` + credentials). Headers permitidos: `Content-Type, Authorization, X-Requested-With, X-CSRF-Token, x-signature, x-request-id`. CORS usa `ALLOWED_ORIGINS` como allowlist autoritativa; no agrega localhost automáticamente.
- Método no permitido → 405 con header `Allow`.
- Preflight CORS OPTIONS permitido → 204 sin cuerpo; si el origen no está en la allowlist, responde con el mismo envelope de error (incluye `code` y `details`) y el status HTTP correspondiente.
- Correlación HTTP: las respuestas incluyen `X-Request-ID`; el valor entrante se conserva o se genera con CSPRNG y también se escribe en logs de errores.
- Errores no controlados → 500 genérico, detalle solo al log.

### Códigos de error estándar

Las acciones y el Router usan el mismo envelope para errores controlados:

| HTTP | error.code | Uso |
|---:|---|---|
| 400 | BAD_REQUEST | Entrada inválida, rango o payload inconsistente. |
| 401 | UNAUTHORIZED | Sesión, token de reserva, firma o credencial inválida. |
| 403 | FORBIDDEN | CSRF u origen no permitido. |
| 404 | NOT_FOUND | Recurso o endpoint inexistente. |
| 405 | METHOD_NOT_ALLOWED | Ruta conocida con método HTTP no permitido; incluye Allow. |
| 409 | CONFLICT | Conflicto de estado o recurso duplicado. |
| 422 | VALIDATION_ERROR | Validación semántica de formularios. |
| 429 | TOO_MANY_REQUESTS | Rate limit global o específico del webhook. |
| 502 | BAD_GATEWAY | Integración externa no disponible durante una recuperación. |
| 500 | INTERNAL_ERROR / UNHANDLED_EXCEPTION | Fallo interno; el detalle solo se registra en servidor. |
| 503 | SERVICE_UNAVAILABLE / MISSING_CREDENTIALS | Servicio o credenciales requeridas no disponibles. |

Las acciones también pueden emitir códigos de dominio más específicos dentro del mismo envelope (`PAYMENT_REJECTED`, `PAYMENT_ID_MISMATCH`, `RATE_LIMITED`, `SERVER_ERROR`, `NEWSLETTER_ERROR`, `PANEL_BOOKING_FAILED`, `ROOM_NOT_FOUND`, `BLOCK_NOT_FOUND`, `OAUTH_ACCOUNT`, `SERIALIZATION_ERROR`). El status HTTP sigue siendo la fuente principal para clientes genéricos.

## Endpoints

La tabla incluye las 33 rutas registradas en `public/index.php`, incluidas las nueve rutas del panel listadas abajo.
`GET /api/health` es un **liveness probe**: devuelve HTTP 200 y `success:true` cuando el proceso responde. El campo `database` conserva el estado observado (`online|offline|error`); este endpoint no es una comprobación de readiness de reservas.
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
| 10 | POST | `/api/cron/cleanup` | `CleanExpiredCartsAction` | CLI o `x-cron-secret` |
| 11 | POST | `/api/cron/manual-review` | `RetryManualReviewAction` | CLI o `x-cron-secret` |
| 12 | GET | `/api/auth/providers` | `AuthProvidersAction` | — |
| 13 | GET | `/api/auth/login` | `AuthLoginAction` | — (OAuth redirect) |
| 14 | GET | `/api/auth/callback` | `AuthCallbackAction` | — (OAuth return) |
| 15 | POST | `/api/auth/register` | `AuthRegisterAction` | — |
| 16 | POST | `/api/auth/login-email` | `AuthLoginEmailAction` | — |
| 17 | GET | `/api/auth/me` | `AuthMeAction` | Cookie JWT |
| 18 | POST | `/api/auth/logout` | `AuthLogoutAction` | Cookie JWT |
| 19 | GET | `/api/user/bookings` | `GetUserBookingsAction` | Cookie JWT |
| 20 | POST | `/api/user/profile` | `UpdateUserProfileAction` | Cookie JWT |
| 21 | POST | `/api/newsletter` | `SubscribeNewsletterAction` | — |
| 22 | POST | `/api/contact` | `SubmitContactAction` | — |
| 23 | GET | `/api/reviews-score` | `GetReviewScoresAction` | — |
| 24 | POST | `/api/cron/refresh-reviews` | `RefreshReviewScoresAction` | CLI o `x-cron-secret` |

| 25 | POST | `/api/panel/login` | `PanelLoginAction` | — |
| 26 | POST | `/api/panel/logout` | `PanelLogoutAction` | Cookie panel + CSRF |
| 27 | GET | `/api/panel/availability` | `GetAvailabilityAction` | Cookie panel |
| 28 | GET | `/api/panel/export` | `ExportAvailabilityAction` | Cookie panel |
| 29 | POST | `/api/panel/import` | `ImportAvailabilityAction` | Cookie panel + CSRF |
| 30 | GET | `/api/panel/rooms-availability` | `PanelRoomsAvailabilityAction` | Cookie panel |
| 31 | POST | `/api/panel/booking` | `PanelBookingAction` | Cookie panel + CSRF |
| 32 | POST | `/api/panel/block` | `PanelBlockAction` | Cookie panel + CSRF |
| 33 | DELETE | `/api/panel/block` | `PanelUnblockAction` | Cookie panel + CSRF |

Notas:
- Los endpoints de cron (cleanup, manual-review y refresh-reviews) se invocan por CLI o por HTTP protegido con `x-cron-secret`; el front controller detecta `PHP_SAPI === 'cli'` y usa `$argv[1]` como ruta. Los crons oficiales viven en `cron/` y usan los mismos actions — ver `docs/DEPLOYMENT.md` §Cron.
- No existe alias `/api/webhook-mercado-pago` (eliminado del registro; si aparece en docs viejas, ignorar).
- `/api/panel/availability`, `/api/panel/rooms-availability` y `/api/reviews-score` incluyen `success: true` junto con sus datos.
- `GET /api/auth/me` puede responder `200 {"success":false,"user":null}` cuando no hay sesión. Las mutaciones de formularios HTML de auth redirigen a `/login` en lugar de emitir JSON.

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

Disponibilidad por día. **Query:** `from`, `to` (default: hoy → +60 días), `id_hotel`. Máx. 120 días; si se solicita un rango mayor responde 400.

Respuesta: `{ "success": true, "days": { "YYYY-MM-DD": { "<id_room_type>": qty, ... } }, "from": "...", "to": "..." }`

## POST /api/booking

Crea el hold (bloqueo 15 min, `BOOKING_HOLD_TTL`), de 1 a 3 habitaciones (multi-room, 2026-08-12).
**Body (multi-room):**
```json
{
  "rooms": [                                  // lista de habitaciones (1-3)
    { "slug": "doble-superior", "guests": 2 },
    { "slug": "matrimonial", "guests": 3 }    // 3 = 2 base + 1 extra ($30/noche)
  ],
  "checkIn": "2026-08-01", "checkOut": "2026-08-03",
  "rateType": "standard",                     // standard | non_refundable (whitelist cerrada, GLOBAL por reserva)
  "guestDetails": { "firstName": "...", "lastName": "...", "email": "...", "phone": "...", "specialRequests": "Airport transfer: flight LA-2451 arriving 14:30" }
}
```

**`guestDetails.specialRequests` (opcional, fix 2026-08-14):** texto libre de peticiones del huésped — hoy lo usa el pickup del aeropuerto (`#airport-transfer` + `#flight-time` + `#flight-details` del wizard). Se persiste en `provisional_bookings.guest_data.special_requests`, se devuelve autenticado en `GET /api/booking-status` (campo `special_requests`) y viaja en el evento `booking.paid` hasta el PMS. Antes del fix el campo se descartaba en el backend (pérdida de datos: el hotel nunca se enteraba del traslado).

**Legacy (1 habitación, sin regresión):** `roomSlug` (o `id_room_type`) + `guests` — se normaliza a `rooms[]` internamente.

El backend recalcula el precio por habitación (fuente única QloApps) y lo congela junto con el tipo de cambio — **el cliente nunca envía precios**. Reglas de precio (2026-08-12):
- Por habitación: `price_per_night (rate) × nights` + cargo extra `EXTRA_GUEST_CHARGE_USD (30) × nights` por el 1er huésped sobre la ocupancia base (`max_guests − 1`, tope +1 persona — verificado en `qlo_htl_room_type`).
- El descuento no reembolsable (−10%) aplica SOLO al precio base; el cargo extra va a precio completo (decisión del negocio).
- Total = Σ por habitación. Campos requeridos: `rooms` (o legacy `id_room_type|roomSlug`), `checkIn`, `checkOut`, `guestName`, `guestEmail`.

Respuesta:
```json
{
  "success": true, "cart_id": "...", "access_token": "<HMAC cart_id:email>",
  "currency": "USD", "price": 420.0, "rate_type": "standard",
  "exchange_rate": 3.80, "gateway_currency": "PEN", "gateway_price": 1596.0,
  "mp_public_key": "APP_USR-...", "expires_at": "...", "time_left_seconds": 900,
  "id_room_type": 2, "slug": "doble-superior", "room_name": "Doble Superior",
  "room_summary": [
    { "id_room_type": 2, "slug": "doble-superior", "room_name": "...", "price_per_night": 90.0, "nights": 2, "guests": 2, "room_total": 180.0 },
    { "id_room_type": 1, "slug": "matrimonial", "room_name": "...", "price_per_night": 90.0, "nights": 2, "guests": 3, "room_total": 240.0 }
  ]
}
```
`id_room_type`/`slug`/`room_name` de primer room = back-compat con success flow y payment-status polling.

Errores: 400 (validación/disponibilidad/capacidad), 503 (`MISSING_CREDENTIALS` cuando Mercado Pago o QloApps no están configurados), 500. Concurrencia: lock de serialización por CADA habitación (`room_locks` FOR UPDATE, orden ascendente de room types anti-deadlock); el re-check exige la CANTIDAD pedida (2 rooms del mismo tipo ⇒ 2 unidades).

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

El evento `booking.paid` conserva las líneas multi-habitación congeladas en el hold.
El listener las entrega al PMS y QloApps crea un detalle por unidad; los holds cuya
referencia ya aparece en una orden PMS confirmada se excluyen del cálculo de
disponibilidad para evitar doble descuento.

## POST /api/extend-hold · GET /api/booking-status · GET /api/payment-check

- `POST /api/extend-hold` — body `{ "cart_id": "..." }` → `{ "success": true, "expires_at": "..." }`.
- `GET /api/booking-status?cart_id=...&token=...` → estado del hold (`pending|paid|failed|expired|cancelled|manual_review|fraud_review|expired_paid`). El token HMAC es opcional para consultar el estado básico, pero obligatorio para incluir PII (`guest_name`, `guest_email`, `guest_phone`, `special_requests`).
- `GET /api/payment-check?cart_id=...&token=...` → estado del pago (usa el `payment_id` persistido; el token HMAC es obligatorio y el polling vive en `book/success.astro`).

## POST /api/webhook

Webhook de MercadoPago (topic `payment`). Registrado en el panel MP (app de producción) con callback `https://usgarhoteles.com/api/webhook`.

- Verificación: firma HMAC `x-signature` + `x-request-id` vía `WebhookSignatureValidator` del SDK + ventana anti-replay de 300s calculada por el adaptador (el `ts` se normaliza a segundos: MP lo documenta en ms pero lo envía en segundos — el chequeo de skew del SDK asume ms y rechazaba todas las notificaciones); si `MERCADO_PAGO_WEBHOOK_SECRET` no está configurado, el webhook se rechaza (fail-closed).
- Flujo: getPaymentDetails (1 solo intento, timeout 8s — el ACK de MP espera ≤22s) → dedup (`isOrderConfirmed`) → transición de estado + registro idempotente del pago + evento outbox en la misma transacción → ACK 200. Si una escritura requerida devuelve `false` o falla, la transacción hace rollback y responde 500 para que el evento pueda reintentarse; no se confirma parcialmente.
- Rechazo (`rejected`/`failed`/`cancelled`): ACK 200 + `markPaymentProcessed('rejected')` + transición del hold `pending → failed` (fix 2026-08-13 — antes el hold quedaba pending hasta el TTL y la página de éxito mostraba "expired" aunque MP había rechazado; el cron `reconcile_payments` hace lo mismo cuando el webhook nunca llegó).
- Monto aprobado inferior al monto congelado: ACK 200 después de persistir `fraud_review` + alerta (`recordAlert`); no se confirma QloApps ni se publica `booking.paid`. Un hold ya expirado conserva la rama `expired_paid`, incluso si el monto recibido es inferior.
- Responder 200 rápido: `Response::jsonAsync` cierra la conexión antes del trabajo pesado (fastcgi_finish_request).

### Reconciliación y outbox (cron)

- `cron/reconcile_payments.php` reclama holds `pending|expired` con `payment_id` en lotes ordenados, usando lease (`reconcile_claimed_until` + token) para que dos workers no procesen la misma fila. Si la auto-migración de esas columnas no puede completarse, la reconciliación se difiere y queda registrada en logs; no se trata como cola vacía.
- `cron/process_outbox.php` reclama filas con `FOR UPDATE SKIP LOCKED` y `claim_token`; las transiciones terminales (`COMPLETED`/`FAILED`) exigen que el token siga siendo el vigente (fencing). El cron conserva backoff y límite de cinco intentos.
- `cron/cleanup.php` (y `POST /api/cron/cleanup`) marca `expired` los holds vencidos y **libera en el PMS** los holds del webservice cuyas reservas locales ya no están activas (`releaseStaleWebserviceHolds`): respuesta `{"success": true, "cleaned_count": N, "released_pms_holds": M, "timestamp": "…"}`.

## GET /api/reviews-score

Scores de reseñas de Booking/KAYAK/Expedia servidos al ribbon de la home (sección de reviews). Los refresca el cron `refresh-reviews` (POST `/api/cron/refresh-reviews`, CLI o HTTP con `x-cron-secret`) en `storage/review-scores.json`, bajo la raíz del dominio (`Config::deploymentBase()`) y **fuera** del árbol que cada build reemplaza; si `STORAGE_PATH` está definido, manda esa ruta. Sin `STORAGE_PATH` y sin raíz de despliegue (dev/CLI) cae en `<raíz del proyecto>/storage/`, nunca en `app/` (que el postbuild copia a `dist/`).

Respuesta:
La respuesta incluye `success: true` además de `scores` y `updatedAt`.

```json
{
  "success": true,
  "scores": {
    "booking": { "score": 8.7, "count": 414 },
    "kayak": { "score": 8.7, "count": 683 },
    "expedia": { "score": 8.6, "count": null }
  },
  "updatedAt": "2026-08-15T00:00:00-05:00"
}
```

- Sin cron ejecutado aún → `{"scores": [], "updatedAt": null}` (el frontend conserva los valores del build).
- Fuentes: páginas públicas del hotel (Booking: JSON-LD `aggregateRating` o `Scored 8.7`; KAYAK: score + `based on N reviews`; Expedia: JSON-LD o `8.6 out of 10`). **Fallback por fuente**: si el fetch falla o no parsea (p. ej. Expedia responde 429 rate-limit), se conserva el valor anterior del JSON — nunca degrada.
- `Cache-Control: public, max-age=3600`.
- URLs de las fuentes configurables vía env: `REVIEW_BOOKING_URL`, `REVIEW_KAYAK_URL`, `REVIEW_EXPEDIA_URL` (ver `.env.example`).

## Auth (cookie JWT `usgar_session`, HttpOnly, SameSite=Lax, 30 días)

- `POST /api/auth/register` — `{ "email", "password", "first_name"|"fullName", "last_name?", "redirect?" }` → 201 + cookie. Mín. 8 caracteres; bcrypt.
- `POST /api/auth/login-email` — `{ "email", "password", "redirect?" }` → 200 + cookie. Cuentas OAuth-only → 400 con el envelope general (`error.code: OAUTH_ACCOUNT`) y los metadatos legacy `isOAuth: true` + `provider` en el nivel superior.
- `GET /api/auth/me` — usuario actual desde la cookie → `{ "success": true, "user": { id, name, email, photo, provider } }`.
- `POST /api/auth/logout` — expira las cookies `usgar_session` (JWT HttpOnly) y `usgar_csrf` (nonce CSRF legible por JS); no se ofrece logout por GET para evitar mutaciones CSRF. Las peticiones AJAX deben enviar `X-CSRF-Token` coincidente con `usgar_csrf`.
- `GET /api/auth/providers` — `{ "success": true, "providers": ["Google", ...] }` (solo los con credenciales en `.env`).
- `GET /api/auth/login` + `/api/auth/callback` — OAuth (hybridauth 3). El callback valida redirect contra open-redirect (solo rutas `/...` internas, rechaza `//`); los errores internos se registran en servidor y el cliente recibe un mensaje genérico.
- `GET /api/user/bookings` — reservas del usuario (`provisional_bookings` por `user_id`).
- `POST /api/user/profile` — `{ "fullName", "last_name?", "phone" }` → actualiza perfil.
- Nota: el JWT es HMAC-SHA256 con `AUTH_JWT_SECRET` (≥32 chars), `exp` + `iat` y claims `iss`, `aud` y `jti`, validados explícitamente. Logout revoca el `jti` server-side mediante `TokenRevocationStore`; los tokens revocados dejan de ser válidos aunque su cookie siga presente.

## Newsletter y Contacto

- `POST /api/newsletter` — `{ "email", "locale" }` → tabla `newsletter_subscribers` (auto-creada). 422 email inválido (`INVALID_EMAIL`), 503 BD offline (`SERVICE_UNAVAILABLE`); los errores siguen el envelope general `{ success:false, error:{ code, message } }`.
- `POST /api/contact` — formulario de contacto → `SubmitContactAction`.

## Panel de disponibilidad del dueño (cookie `usgar_panel`, JWT HMAC, 12h)

Página `/panel` (Astro, `noindex`) + API protegida para el dueño del hotel: calendario mensual de reservas por habitación física (timeline por canal) con import/export CSV y Excel.

Las respuestas JSON del panel incluyen `success: true`; las descargas de exportación son binarias y usan su `Content-Type` específico. `/api/panel/booking` acepta `room_id`, `room_type_id`, `checkin`, `checkout`, `guest_name`, `guest_email`, `guest_phone`, `guests`, `price_usd` opcional y `channel`. `/api/panel/block` acepta `room_id`, `date_from`, `date_to`, `reason`; el DELETE recibe `id` por query.

- `POST /api/panel/login` — `{ "password" }` → 200 + cookie `usgar_panel`. Password = env `PANEL_PASSWORD` (fail-closed: si no está configurada, nadie entra; comparación `hash_equals`).
- `POST /api/panel/logout` — limpia la cookie. Las mutaciones del panel (logout, import, booking y block/unblock) requieren `X-CSRF-Token` coincidente con la cookie JS-readable `usgar_panel_csrf`.
- `GET /api/panel/availability?month=YYYY-MM` — grid del mes: `{ month, today, rooms[], bookings[] }`. `rooms` = habitaciones físicas de `qlo_htl_room_information` (join `qlo_htl_room_type` por `id_product`); `bookings` = reservas confirmadas de `qlo_htl_booking_detail` (con `id_room`/`room_num`/cliente/`total_paid_amount`), holds web de `provisional_bookings`, bloqueos manuales de `manual_blocks` (tabla propia, auto-creada) y fuera-de-servicio de `qlo_htl_room_disable_dates`. Canales: `web | walkin | ota | phone | owner | qlo | maint`; las reservas creadas por el panel derivan `phone`/`ota`/`owner`/`walkin` desde `qlo_orders.module=panel-{channel}`; estados: `confirmed | hold | maint`.
- `GET /api/panel/export?format=csv|xlsx&month=YYYY-MM` — descarga de reservas del mes. CSV con BOM UTF-8 (abre directo en Excel); XLSX real vía PhpSpreadsheet (hoja Reservas + hoja Resumen con ocupación/ingreso por canal).
- `POST /api/panel/import` — `{ "filename", "content_base64" }` (o `{ "csv" }`). Formato: `habitacion, checkin (YYYY-MM-DD), checkout, huesped, canal, estado, precio` (cabecera opcional). CSV parseado nativo; XLSX vía PhpSpreadsheet. Cada fila válida crea un bloqueo en `manual_blocks` (la habitación deja de venderse en la web); filas con habitación inexistente → `skipped` con detalle. Respuesta: `{ success, imported, skipped, errors[] }`. Límites defensivos: raw/base64 8 MiB, CSV 5 MiB, máximo 1000 filas y 32 MiB descomprimidos para XLSX.
- Dependencia nueva: `phpoffice/phpspreadsheet` (solo usado por export/import del panel).

## Variables de entorno

Fuente completa: `.env.example` (canónico). Lectura: `App\Core\Config::get()` con defaults en `app\Core\Config.php::DEFAULTS`. Ubicación del `.env` en prod: `hbuilds/config/.env` (hPanel → Environment variables) o, como fallback, un nivel arriba de `public_html`; el build NO lo copia a `dist/`.

| Variable | Usado por | Descripción |
|---|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | Database | MySQL (apunta a la BD de QloApps: tablas `qlo_*` + propias) |
| `QLOAPP_API_URL` / `QLOAPP_API_KEY` | QloAppAdapter | URL + key del webservice XML del PMS |
| `MERCADO_PAGO_ACCESS_TOKEN` | MercadoPagoAdapter | Token MP (fuente única; prefijo no define entorno) |
| `PUBLIC_MERCADO_PAGO_PUBLIC_KEY` | CreateBookingAction | Public key para el cardForm |
| `MERCADO_PAGO_WEBHOOK_SECRET` | Webhook | Secreto HMAC de firma (debe ser EXACTO al del panel MP) |
| `WEBHOOK_NON_PAYMENT_MAX_REQUESTS` / `WEBHOOK_NON_PAYMENT_WINDOW_SECONDS` | Webhook | Límite local por IP para topics no-payment ignorados (30/60s) |
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
| `SMTP_HOST` / `SMTP_PORT` / `SMTP_SECURE` / `SMTP_USER` / `SMTP_PASS` / `SMTP_TIMEOUT` / `MAIL_FROM` / `MAIL_FROM_NAME` | PhpMailerAdapter (voucher) | SMTP del correo transaccional. `SMTP_HOST` vacío = email desactivado (fail-soft, sin reintentos del outbox) |
| `OTA_DEFAULT_PHONE` / `OTA_DEFAULT_EMAIL` / `OTA_DEFAULT_NAME` / `OTA_DEFAULT_SURNAME` / `OTA_HOLD_TTL` / `QLOAPPS_DEFAULT_GUEST_NAME` | QloAppAdapter / Listener | Defaults de huéspedes OTA (algunos reservados para la sync del Channel Manager) |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` / `MICROSOFT_*` / `FACEBOOK_*` | AuthService | Credenciales OAuth; Microsoft Graph además exige `MICROSOFT_TRUST_EMAIL=true` porque su adapter no expone email verificado estándar |

## Pruebas del contrato

`GET /api/health` es un endpoint de liveness: conserva HTTP 200 y `success: true` aunque `database` sea `offline` o `error`; el campo `database` expone el estado observado sin convertir el probe en una comprobación de readiness.

- `php tests/user-flow.php` — **flujo completo del usuario** (buen + mal usuario): health → rooms → booking → extend-hold → booking-status → payment-check → process-payment, + 20 casos adversariales (fechas pasadas/invertidas/0 noches, guests negativos/cero/overcapacity, slug inexistente, XSS, SQLi, webhook sin firma/firma falsa, sin auth, email inválido, rateType inventado, cart inexistente). Fechas dinámicas (nunca hardcodeadas). Arranca server propio en puerto libre o acepta `php tests/user-flow.php 8000` contra uno existente. Reemplaza funcionalmente a `pentest.php` y `api-harness.php` (ambos con fechas stale).
- `php scripts/run-exhaustive-tests.php` — suite exhaustiva (unit + integración + asserts del port del channel manager).
- `npm run audit:security` / `npm run audit:seo` — auditorías.
- `npx playwright test` — E2E de flujos de usuario.
