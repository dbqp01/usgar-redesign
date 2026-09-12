# Spec: Pagos (MercadoPago Checkout API, webhooks, outbox y crons)

**Estado:** estable
**Código:** `app/Features/Booking/Actions/` (ProcessPayment, PaymentCheck), `app/Features/Webhooks/`, `app/Features/Cron/`, `app/Features/Shared/Adapters/MercadoPagoAdapter.php`, `cron/`
**Docs relacionadas:** [API.md](../API.md) (contrato de endpoints), [ARCHITECTURE.md](../ARCHITECTURE.md) (flujo de reserva), [SECURITY.md](../SECURITY.md), [DEPLOYMENT.md](../DEPLOYMENT.md) (crons), [PMS.md](../PMS.md) (confirmación QloApps)

## Propósito

Cobrar la reserva vía MercadoPago Checkout API (Custom Checkout, cardForm), confirmarla localmente de forma atómica e idempotente, y propagar el evento `booking.paid` al PMS (QloApps) y al correo del huésped mediante un outbox transaccional con reintentos. El precio se muestra en USD pero el cobro se captura en PEN con la tasa congelada al cotizar.

## Requisitos funcionales (EARS)

### Cobro síncrono — `ProcessPaymentAction` (`app/Features/Booking/Actions/ProcessPaymentAction.php`, ruta `POST /api/process-payment`)

- RF-01: CUANDO falta `cart_id`, `access_token` o `payment_data`, EL SISTEMA DEBE responder 400.
- RF-02: CUANDO el `access_token` no coincide con el HMAC derivado de `cart_id` + email del huésped (`BookingHoldToken::derive`), EL SISTEMA DEBE responder 401 y abortar.
- RF-03: CUANDO el hold no está en estado `pending` o ya expiró, EL SISTEMA DEBE NO llamar la pasarela y responder success:false con el estado actual.
- RF-04: CUANDO el hold ya tiene `payment_id` (intento previo pending), EL SISTEMA DEBE NO volver a cobrar: responder success:false (status pending) y dejar que polling/webhook reconcilien.
- RF-05: SIEMPRE el cobro ocurre DENTRO de la transacción del lock pesimista (`SELECT ... FOR UPDATE`) y usa una clave `payment_idempotency_key` durable por hold, reservada antes de abrir esa transacción: pago + attach de `payment_id` + estado son atómicos; un segundo intento concurrente o timeout ambiguo reutiliza la clave y no puede cobrar dos veces.
- RF-06: SIEMPRE el monto enviado a la pasarela es el PEN congelado (`price_snapshot_pen`); fallback legacy: derivación USD→PEN vía `PriceCalculator::toGatewayPrice`.
- RF-07: SIEMPRE el create de pago incluye `external_reference = USGAR-{cartId}` y NO incluye `currency_id` (MP infiere la moneda de la cuenta; verificado en sandbox 2026-08-06).
- RF-08: CUANDO la gateway responde `approved`, EL SISTEMA DEBE persistir `payment_id` + status `paid` + idempotencia `approved`, y despachar `BookingPaidEvent` dentro de la misma transacción (outbox).
- RF-09: CUANDO la gateway responde `pending`/`in_process`, EL SISTEMA DEBE persistir `payment_id` (para reconciliación) y responder success:false sin marcar paid.
- RF-10: CUANDO la gateway responde rechazo sin excepción, EL SISTEMA DEBE hacer rollback: no persistir ni `payment_id` ni status y liberar la clave reservada para permitir un nuevo intento legítimo.
- RF-11: CUANDO MercadoPago lanza `MPApiException`, EL SISTEMA DEBE propagar el status HTTP real (400/422) con `status_detail`, nunca 500 genérico.
- RF-12: CUANDO el cobro es exitoso pero el commit/INSERT del outbox falla, EL SISTEMA DEBE hacer attach best-effort de `payment_id` y responder success:false (pending) para que polling/webhook reconcilien — nunca habilitar un reintento que doble-cobre. La clave se conserva ante timeout/excepción ambigua y solo se libera tras un rechazo inequívoco (estado rechazado o HTTP 400/422).

### Consulta de pago — `GetPaymentCheckAction` (`GET /api/payment-check`)

- RF-13: CUANDO el token de ownership no es válido o falta, EL SISTEMA DEBE responder 401 sin exponer datos (mismo patrón HMAC que GetBookingStatusAction).
- RF-14: CUANDO el hold ya tiene `payment_id` local, EL SISTEMA DEBE responder desde esa fuente sin consultar MP.
- RF-15: CUANDO no hay `payment_id` local, EL SISTEMA DEBE buscar en MP por `external_reference` con hasta 3 intentos y backoff (~1s) por consistencia eventual, respondiendo `source: mercadopago|none`.

### Webhook — `HandleMercadoPagoWebhookAction` (`app/Features/Webhooks/`, ruta `POST /api/webhook`)

- RF-16: CUANDO el `type`/`action` no es exactamente `payment`, EL SISTEMA DEBE responder 200 sin procesar ni validar firma (evita consumir topics separados que contienen la subcadena "payment").
- RF-16a: CUANDO llegan eventos no-payment, EL SISTEMA DEBE aplicar un límite local por IP (`WEBHOOK_NON_PAYMENT_MAX_REQUESTS`/`WEBHOOK_NON_PAYMENT_WINDOW_SECONDS`) antes de responder 200, para que topics ignorados no puedan usarse como bypass del control de abuso.
- RF-16b: SI los IDs de pago del query y del body no coinciden, o el ID devuelto por Mercado Pago no coincide con el webhook, EL SISTEMA DEBE responder 400 `PAYMENT_ID_MISMATCH` sin abrir una transacción local.
- RF-17: SIEMPRE el ID de pago se extrae de `data.id` del body o `data_id`/`data.id` del query, nunca de `body.id` (que es el ID de la notificación).
- RF-18: CUANDO la firma `x-signature`/`x-request-id` está ausente o no valida (HMAC-SHA256 vía SDK, `PaymentGatewayPortInterface::verifySignature` con `MERCADO_PAGO_WEBHOOK_SECRET`), EL SISTEMA DEBE responder 401.
- RF-19: SIEMPRE la transacción inicia ANTES del chequeo de idempotencia (fail-closed: si falla, 500 y MP reintenta; nunca reprocesar un duplicado).
- RF-20: SIEMPRE la idempotencia es por par `(payment_id, event_type)` en `processed_payments` (índice único; permite que un refund coexista con el approved).
- RF-21: CUANDO el pago es `rejected`/`failed`, EL SISTEMA DEBE responder 200, marcar el hold como `failed` y registrar idempotencia `rejected` (cortar reintentos de MP).
- RF-22: CUANDO el pago es `refunded`/`charged_back`/`cancelled`, EL SISTEMA DEBE responder 200, registrar idempotencia + alerta para resolución manual (`cancelled` además marca el hold `failed`); la cancelación en QloApps queda diferida (ver Desviaciones).
- RF-23: CUANDO el pago es `approved` sin `external_reference` localizable o sin hold correspondiente, EL SISTEMA DEBE marcar idempotencia `orphan` (cart_id vacío) y responder 200.
- RF-24: CUANDO el pago es `approved` sobre un hold expirado, EL SISTEMA DEBE marcar `expired_paid` + alerta y NO revivir la reserva ni confirmar el PMS.
- RF-25: CUANDO el monto cobrado (en centavos enteros) es menor al esperado (derivado del PEN congelado), EL SISTEMA DEBE marcar `fraud_review` + alerta y responder 200 (nunca 400/500, que provocarían reintento infinito).
- RF-26: CUANDO el pago es `approved` válido sobre hold `pending`, EL SISTEMA DEBE marcar `paid` + idempotencia `approved` + insertar `BookingPaidEvent` en `event_outbox` dentro de la misma transacción, y responder 200 (ACK) DESPUÉS del commit.


- RF-26a: CUANDO llega `approved` sobre un hold `fraud_review` o `manual_review`, EL SISTEMA DEBE conservar el estado de revisión, registrar idempotencia `approved_after_review` + alerta y responder 200; solo `RetryManualReviewAction` con `CRON_SECRET` puede resolverlo a `paid` después de revalidar el monto.

### Eventos y outbox — `BookingPaidEvent`, `EventDispatcher`, `ProcessOutboxAction`

- RF-27: SIEMPRE `BookingPaidEvent` (`booking.paid`, `app/Features/Booking/Domain/Events/`) incluye `amount_pen` (centavos), `currency` (de `MERCADO_PAGO_CURRENCY`, default PEN) y `exchange_rate` congelados del hold.
- RF-28: SIEMPRE el dispatch del evento persiste el payload en `event_outbox` en la MISMA transacción que el cambio de estado (patrón transactional-outbox): si el commit se confirma, el evento no se pierde. La persistencia no depende de que el listener ya esté registrado en el proceso productor; el cron valida y entrega el evento después.
- RF-29: CUANDO corre `cron/process_outbox.php` (solo CLI), EL SISTEMA DEBE reclamar eventos en `PENDING`/`FAILED` con `attempts < 5` y lease vencido usando `FOR UPDATE SKIP LOCKED` (batch 50), marcarlos `IN_PROGRESS` con lease de 30 min, despachar vía `dispatchNow`, y marcar `COMPLETED` o `FAILED`.
- RF-30: CUANDO un evento falla, EL SISTEMA DEBE incrementar attempts y reintentar con backoff exponencial acotado `min(2^(n-1), 16)` minutos; al alcanzar 5 intentos queda `FAILED` terminal con alerta.
- RF-31: CUANDO un evento queda `IN_PROGRESS` con lease vencido (crash del worker), EL SISTEMA DEBE reclamarlo: `PENDING` si aún es reintentable, `FAILED` terminal + alerta si alcanzó el máximo. Nunca deja eventos `PENDING` silenciosos en el máximo.
- RF-32: SIEMPRE el deserializado del payload restringe clases (`allowed_classes`: BookingPaidEvent + DateTimeImmutable).

### Listeners (ejecutados por el cron del outbox)

- RF-33: CUANDO `ConfirmQloAppsOrderListener` recibe `BookingPaidEvent`, EL SISTEMA DEBE pre-chequear dedup por `external_reference` en QloApps (`isOrderConfirmed`); si ya está confirmada, skip sin llamar `confirmOrder`; si el pre-chequeo falla o `confirmOrder` devuelve null, debe lanzar (fail-closed → outbox reintenta). El PMS recibe monto PEN.
- RF-34: CUANDO `SendBookingConfirmationEmailListener` recibe `BookingPaidEvent` y `SMTP_HOST` está configurado, EL SISTEMA DEBE enviar el voucher HTML/texto al email del huésped en su idioma (`guest_data.locale`, en/es/fr/pt, default `en`); si SMTP no está configurado, fail-soft (sin reintento); si el envío falla, fail-closed (outbox reintenta).

### Crons de reconciliación y limpieza (`app/Features/Cron/Actions/`)

- RF-35: CUANDO corre la reconciliación (`cron/reconcile_payments.php` → `ReconcilePaymentsAction`), EL SISTEMA DEBE recorrer holds `pending` con `payment_id`, saltar los ya procesados, y para pagos `rejected`/`failed`/`cancelled` marcar el hold `failed`; para `approved` con monto suficiente marcar `paid` + outbox; para `approved` sobre hold expirado aplicar `expired_paid` + alerta (misma semántica que el webhook).
- RF-36: CUANDO se invoca `POST /api/cron/manual-review` (`RetryManualReviewAction`), EL SISTEMA DEBE exigir `CRON_SECRET` mediante el header `x-cron-secret` (hash_equals; en CLI se omite) y soportar `action=list` (holds en manual/fraud review con contador de re-despachos), `action=redispatch` (re-chequea monto contra la gateway; si coincide → `paid` + outbox; si no, permanece en revisión con auditoría) y `action=expire` (libera el hold para resolución manual, nunca afecta paid/expired_paid).
- RF-37: CUANDO se invoca `POST /api/cron/cleanup` (`CleanExpiredCartsAction`, mismo guard de `CRON_SECRET`), EL SISTEMA DEBE liberar los carritos expirados sin pago y reportar el conteo.

### Moneda

- RF-38: SIEMPRE la cotización visible es USD y la captura es PEN con la tasa congelada al cotizar (`price_snapshot_pen` / `exchange_rate_snapshot` del hold); el cambio posterior de `EXCHANGE_RATE_USD_PEN` no altera el monto cobrado ni genera falsos fraudes. La moneda viaja vía `BookingPaidEvent`/`MERCADO_PAGO_CURRENCY`, no en el create de pago.

## Requisitos no funcionales

- **Seguridad:** token HMAC de ownership en payment-check/process-payment; firma HMAC de webhook obligatoria; `CRON_SECRET` en endpoints de cron HTTP; montos comparados en centavos enteros (sin igualdad float); `unserialize` con clases restringidas; SQL preparado.
- **Idempotencia/duplicados:** lock pesimista en cobro, `(payment_id, event_type)` en webhook, dedup por external_reference en PMS. Un evento pagado no puede doble-cobrar ni doble-confirmar.
- **Resiliencia:** MP reintenta webhooks ante no-200 → todas las ramas terminan en 200 o 500 deliberado con idempotencia que protege; backoff del outbox; reclaim de leases.
- **i18n:** voucher de confirmación en el idioma del huésped (4 locales, default `en`).

## Contratos

| Endpoint | Acción | Resumen |
|---|---|---|
| `POST /api/process-payment` | ProcessPaymentAction | in: cart_id, access_token, payment_data (cardForm) · out: success, status, payment_id → docs/API.md |
| `GET /api/payment-check` | GetPaymentCheckAction | in: cart_id, token · out: payment_id, status, source |
| `POST /api/webhook` | HandleMercadoPagoWebhookAction | in: notificación MP firmada · out: 200 ACK (todas las ramas) |
| `POST /api/cron/cleanup` | CleanExpiredCartsAction | guard CRON_SECRET · out: cleaned_count |
| `POST /api/cron/manual-review` | RetryManualReviewAction | guard CRON_SECRET · list/redispatch/expire |

- **Tablas propias:** `provisional_bookings` (hold, payment_id, payment_idempotency_key, snapshots de precio/tasa), `processed_payments` (idempotencia, índice único payment_id+event_type), `event_outbox` (transactional outbox: status, attempts, next_attempt_at), `payment_alerts` (alertas: expired_paid, fraud_review, redispatch, orphan…). Auto-creación/migración idempotente en `ensureTablesExist`; la clave de pago se auto-migra antes del primer intento y la DDL nunca se ejecuta dentro de la transacción del cobro.
- **Integraciones externas:** MercadoPago (`/v1/payments`, `/v1/payments/search`, firma webhook, SDK dx-php v3 con parches de idempotency-case y timeout — ver `composer.json` extra.patches) vía `PaymentGatewayPortInterface`; QloApps vía `PmsPortInterface` (`confirmOrder`, `isOrderConfirmed`); SMTP vía `MailerPortInterface`.
- **Eventos:** `booking.paid` (único evento del outbox hoy) → `ConfirmQloAppsOrderListener` + `SendBookingConfirmationEmailListener` (registro de listeners en `app/bootstrap.php`).

## Verificación

- Suite completa: `npm run test:php` (= `php scripts/run-exhaustive-tests.php`).
- Unit tests relevantes ( PHPUnit, `tests/Unit/`):
  - Cobro: `Features/Booking/Actions/ProcessPaymentActionTest.php`
  - Check: `Features/Booking/Actions/GetPaymentCheckActionTest.php`
  - Webhook: `Features/Webhooks/HandleMercadoPagoWebhookActionTest.php`, `Features/Webhooks/HandleMercadoPagoWebhookActionW6Test.php`, `Features/Shared/MercadoPagoWebhookAckTest.php`
  - Firma/idempotency/timeout SDK: `Features/Shared/SdkIdempotencyKeyTest.php`, `Features/Shared/MercadoPagoTimeoutTest.php`, `Features/Shared/MercadoPagoAdapterTest.php`
  - Evento/outbox/listeners: `Features/Booking/Domain/BookingPaidEventTest.php`, `Features/Booking/Domain/CurrencyPlumbingTest.php`, `Features/Booking/Domain/Listeners/ConfirmQloAppsOrderListenerTest.php`, `Features/Booking/SendBookingConfirmationEmailListenerTest.php`, `Core/Events/EventDispatcherTest.php`, `Features/Cron/ProcessOutboxActionTest.php`
  - Crons: `Features/Cron/ReconcilePaymentsActionTest.php`, `Features/Cron/RetryManualReviewActionTest.php`, `Features/Cron/ProcessOutboxSchemaTest.php`

## Desviaciones conocidas / TODO

- **Refund/chargeback en PMS:** el webhook registra alerta para resolución manual pero NO cancela la orden en QloApps (refactor W3 diferido; ver comentarios en `HandleMercadoPagoWebhookAction`).
- **Orden de entrega al PMS:** dos eventos del mismo cart pueden entregarse desordenados durante una ventana de redeploy — convergencia eventual garantizada por dedup + throw-on-null (documentado en `ProcessOutboxAction`).
- **Holds legacy sin `price_snapshot_pen`:** derivan PEN con la tasa actual vía `PriceCalculator::toGatewayPrice` (back-compat documentado en webhook y reconcile).
