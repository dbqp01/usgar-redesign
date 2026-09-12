# Spec: Flujo de reserva (wizard, holds, precio, confirmación QloApps)

**Estado:** en desarrollo
**Código:** `src/components/booking/`, `src/features/booking/`, `app/Features/Booking/Actions/`, `app/Features/Booking/Domain/`, `app/Core/PriceCalculator.php`, `app/Core/BookingStatus.php`, `app/Core/BookingHoldToken.php`
**Docs relacionadas:** [API.md](../API.md) · [ARCHITECTURE.md](../ARCHITECTURE.md) · [PMS.md](../PMS.md) · [SECURITY.md](../SECURITY.md)

## Propósito
Permitir al huésped reservar 1–3 habitaciones en 3 pasos (fechas → asignación → datos), con precios calculados íntegramente en el backend y un hold temporal de 15 min sobre el inventario de QloApps. El pago (MercadoPago) y los webhooks se documentan en `payments.spec.md`.

## Requisitos funcionales (EARS)

### Wizard frontend (3 pasos)
- RF-01: CUANDO el usuario ingresa al wizard, EL SISTEMA DEBE presentar paso 1 (calendario), paso 2 (asignación de habitaciones) y paso 3 (datos del huésped + pago), navegables con `next`/`back` del store (`src/features/booking/wizardStore.ts`).
- RF-02: CUANDO el usuario selecciona check-in/check-out, EL SISTEMA DEBE normalizar el rango (`calendar.ts::clampRange`): invertirlo si está al revés, completarlo si falta y limitarlo a 30 noches.
- RF-03: CUANDO se seleccionan huéspedes y fechas, EL SISTEMA DEBE generar opciones de asignación individuales y combinaciones de 2 habitaciones (`roomAllocator.ts::allocateRooms`), descartando las que exceden capacidad o disponibilidad, ordenadas por precio total y marcando la más barata con `bestPrice`.
- RF-04: CUANDO el usuario elige tarifa en el paso 2, EL SISTEMA DEBE fijar `rateType` (`standard` | `non_refundable`) desde los `ratePlans` servidos por `GET /api/rooms`; el precio mostrado es informativo, la fuente de verdad es el backend.
- RF-05: CUANDO el usuario confirma el paso 3, EL SISTEMA DEBE enviar `POST /api/booking` (con `rooms[]`, `rateType`, `guestDetails`, `locale`) y en éxito iniciar el hold con countdown (`time_left_seconds`) en el paso de pago.
- RF-06: CUANDO el hold está próximo a expirar durante el pago, EL SISTEMA DEBE llamar `POST /api/extend-hold` una vez para cubrir el pago en vuelo (tope backend: 3).
- RF-07: CUANDO la reserva queda pagada o rechazada, EL SISTEMA DEBE sondear `GET /api/booking-status` cada 15 s hasta 40 intentos (10 min) y renderizar el resultado (`bookingStatus.ts::pollForPayment`), reutilizado por PaymentStep y `book/success`.
- RF-08: CUANDO no hay combinación viable para los huéspedes pedidos, EL SISTEMA DEBE mostrar el estado vacío sin opciones y no avanzar.

### Creación de hold (`CreateBookingAction`, `POST /api/booking`)
- RF-09: CUANDO llega el payload, EL SISTEMA DEBE normalizar formatos legacy: `guestDetails` → campos planos (incluido `specialRequests`), y `roomSlug`/`id_room_type` → `rooms[]` de 1 elemento.
- RF-10: CUANDO faltan campos obligatorios (`checkIn`, `checkOut`, `guestName`, `guestEmail`), el email es inválido, el rango de fechas es inválido, hay 0 o >3 habitaciones, o algún tipo de habitación es desconocido, EL SISTEMA DEBE responder 400 sin crear hold.
- RF-11: CUANDO un `rateType` no está en la whitelist, EL SISTEMA DEBE degradarlo a `standard` (fail-safe: nunca regalar descuento).
- RF-12: CUANDO los huéspedes pedidos exceden la capacidad máxima de una habitación, EL SISTEMA DEBE responder 400.
- RF-13: SIEMPRE el precio se resuelve server-side: precio/noche de QloApps (no reembolsable vía `non_refundable_price`), cargo por huésped extra (`PriceCalculator::extraGuestCharge`, base = maxGuests−1, a precio completo) y totales acumulados en centavos enteros (`PriceCalculator::roomTotalCents`).
- RF-14: CUANDO hay disponibilidad, EL SISTEMA DEBE, dentro de UNA transacción: tomar locks de serialización por room type en orden ascendente (`room_locks::lockRoom`, get-or-create + `FOR UPDATE`), re-verificar disponibilidad contando holds nuevos desde la lectura inicial (`getHoldCountForRoomForUpdate`) y responder 400 si ya no alcanza la cantidad pedida.
- RF-15: CUANDO la verificación pasa, EL SISTEMA DEBE crear el carrito en QloApps (`PmsPortInterface::createCartMulti`), congelar tasa y precio PEN en la misma lectura del PMS (`exchange_rate_snapshot`, `price_snapshot_pen`), insertar el hold en `provisional_bookings` con `status=pending` y `expires_at` = TTL (15 min) y derivar `access_token` HMAC (`BookingHoldToken::derive`); ante fallo de credenciales responder 503.
- RF-16: CUANDO el hold se crea, EL SISTEMA DEBE responder con `cart_id`, `access_token`, precio USD/PEN congelados, `expires_at`, `time_left_seconds`, `room_summary` (y campos legacy del primer room para back-compat).

### Extensión de hold (`ExtendHoldAction`, `POST /api/extend-hold`)
- RF-17: CUANDO no existe el `cart_id`, EL SISTEMA DEBE responder 404.
- RF-18: CUANDO el token provisto no coincide con el HMAC esperado, EL SISTEMA DEBE responder 401.
- RF-19: CUANDO el hold no está en `pending`, EL SISTEMA DEBE responder 400.
- RF-20: CUANDO `extend_count` < tope (3, `BOOKING_HOLD_MAX_EXTENSIONS`), EL SISTEMA DEBE extender `expires_at` a un TTL completo, incrementar `extend_count`, extender la sesión del carrito en QloApps y responder con el nuevo `expires_at`; si el UPDATE no afecta filas, EL SISTEMA DEBE responder error (nunca éxito sin extender).

### Consulta de estado (`GetBookingStatusAction`, `GET /api/booking-status`)
- RF-21: CUANDO el hold está `pending` y `expires_at` < ahora, EL SISTEMA DEBE reportar `status=expired` e `is_expired=true` (sin mutar la fila).
- RF-22: SIEMPRE los datos PII (`guest_name`, `guest_email`, `guest_phone`, `payment_id`, `special_requests`) solo se devuelven con token HMAC válido; sin token la respuesta se limita a estado y resumen de precio/habitaciones.

### Persistencia y expiración (`ProvisionalBookingRepository`)
- RF-23: CUANDO falta una tabla/columna propia (`provisional_bookings`, `room_locks`, `processed_payments`, `payment_id`, `payment_idempotency_key`, `price_snapshot_pen`, `exchange_rate_snapshot`, `extend_count`), EL SISTEMA DEBE auto-crearla/migrarla de forma idempotente (`ensureTablesExist` o el auto-heal específico del primer intento de pago); las sentencias DDL deben ejecutarse fuera de transacciones activas.
- RF-24: SIEMPRE las transiciones de estado son atómicas por target (`updateStatus`): `paid`/`fraud_review` solo desde estados transitorios (pending/manual_review/fraud_review), `expired_paid` solo desde `expired`, `failed` solo desde `pending`/`expired`; target no declarado = fail-closed.
- RF-25: CUANDO corre la limpieza (cron), EL SISTEMA DEBE marcar `expired` los holds en estados transitorios con `expires_at` < NOW (`cleanExpiredCarts`), nunca los `paid`/`expired_paid`.

### Confirmación en QloApps (`ConfirmQloAppsOrderListener`)
- RF-26: CUANDO se dispara `BookingPaidEvent`, EL SISTEMA DEBE verificar por `external_reference = USGAR-{cartId}` que la orden ya no esté confirmada (dedup-skip) antes de llamar `confirmOrder` con el monto PEN congelado.
- RF-27: SIEMPRE el listener ejecuta 1 intento y lanza excepción ante fallo o resultado null — el reintento es responsabilidad del outbox (`cron/process_outbox.php`), no hay retry loop interno.

## Requisitos no funcionales
- **Seguridad**: token HMAC-SHA256 por hold (compara con `hash_equals`); PII protegida; whitelist de tarifas fail-safe; errores sin stack traces en producción.
- **Consistencia de dinero**: acumulación en centavos enteros; tasa y PEN congelados al cotizar (una sola lectura del PMS).
- **Concurrencia**: locks por room type con orden determinista (anti-deadlock) y re-check dentro de la misma transacción.
- **i18n**: `locale` del huésped persistido en `guest_data` para el email de confirmación; UI del wizard en las 4 locales con fallback (`src/i18n/utils.ts`).
- **Compatibilidad**: payload y respuesta aceptan formatos legacy de 1 habitación (Zero-Breakage).

## Contratos
- `POST /api/booking` — crea hold multi-room; in/out detallado en `docs/API.md`.
- `POST /api/extend-hold` — extiende hold (+15 min, máx 3).
- `GET /api/booking-status` — estado del hold; PII solo con token.
- Tablas propias: `provisional_bookings`, `room_locks` (auto-creadas, ver RF-23).
- Integraciones: PMS vía `PmsPortInterface` (`getAvailableRooms`, `createCartMulti`, `extendCartSession`, `confirmOrder`, `isOrderConfirmed`, `getExchangeRatePEN`). Evento interno `BookingPaidEvent` → outbox → `ConfirmQloAppsOrderListener` (detalle de pagos/outbox en `payments.spec.md`).

## Verificación
- TS (vitest): `npx vitest run` — `tests/Unit/wizardStore.test.ts`, `roomAllocator.test.ts`, `allocator-display.test.ts`, `calendar.test.ts`, `holdCountdown.test.ts`.
- PHP (phpunit): `npm run test:php` (=`php scripts/run-exhaustive-tests.php`) o `composer test` — `tests/Unit/Core/PriceCalculatorTest.php`, `tests/Unit/Core/ConfigHoldTtlTest.php`, `tests/Unit/Features/Booking/Domain/ProvisionalBookingRepositoryTest.php`, `ProvisionalBookingConcurrencyTest.php`, `CurrencyPlumbingTest.php`, `tests/Unit/Features/Booking/Domain/Listeners/ConfirmQloAppsOrderListenerTest.php`, `tests/Unit/Features/Cron/ProcessOutboxActionTest.php`.
- Contrato end-to-end: `php tests/api-harness.php`; E2E usuario: `npx playwright test`.

## Desviaciones conocidas
- `roomAllocator` trata la disponibilidad como válida para todo el rango; la validación día a día es responsabilidad del caller (`BookingCalendarStep`, contrato documentado en el propio archivo).
- La tabla aditiva `provisional_booking_rooms` se crea oportunistamente. Si la cuenta de producción no permite `CREATE TABLE`, el flujo de disponibilidad conserva las filas legacy, pero la creación de una reserva multi-habitación falla de forma cerrada hasta completar la migración.
