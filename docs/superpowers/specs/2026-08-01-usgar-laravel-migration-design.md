# Spec — Migración USGAR: Laravel + Filament (PMS/CMS/API propio)

Fecha: 2026-08-01 · Estado: aprobada por el usuario (diseño) — pendiente de revisión del spec escrito
Propietario: usuario USGAR · Ejecución: agente con subagentes (SDD)

## 1. Objetivo y alcance

Reemplazar por completo el stack actual (Astro estático + backend PHP 8 nativo ADR + QloApps como PMS/CMS + schema PrestaShop `qlo_*`) por un sistema propio: **Laravel 12 + Filament v5** como PMS (tarifas/disponibilidad/reservas/huéspedes), CMS (panel admin) y API pública (contrato JSON idéntico al actual).

- **Se retira**: `app/` (PHP ADR), QloApps (cms.hotelesusgar.com), schema `qlo_*` (backup congelado antes del corte).
- **Se mantiene**: frontend Astro (contrato `/api` intacto), MercadoPago (Checkout API + CardForm + webhook), canal OTA (Channex portado tal cual; Nobeds = migración separada del MIGRATION_PLAN §2).
- **Datos**: los datos actuales de QloApps son mayormente de test → **schema nuevo, sin migración de datos** (QloApps se congela como archivo).
- **Fuera de alcance (YAGNI)**: emails de confirmación, multi-propiedad activa (pero el schema y Filament multi-tenancy lo soportan desde el día 1), channel manager nuevo.

## 2. Decisiones tomadas (conversación 2026-08-01)

| Decisión | Valor |
|---|---|
| Stack | Laravel 12 + Filament v5 (requiere PHP 8.2+, Laravel v11.28+ — verificado context7) |
| Panel admin | **Subdominio `admin.hotelesusgar.com`** (aislamiento de sesiones, deploy independiente, escalable a N propiedades con multi-tenancy nativo de Filament v5 — `->tenant(Property::class)`) |
| API pública | **Mismo dominio que Astro** (mismo origen: cero CORS, auth de huésped simple); rutas `/api/*` servidas por Laravel, el resto estático Astro |
| Arquitectura | **Ports/Adapters estricto (DIP)** — el dominio conoce interfaces, no implementaciones; comunicación por DTOs ("paquetes") y eventos con outbox |
| Datos históricos | No migrar (test data); schema nuevo Eloquent |
| Rate engine | Tarifa por noche + temporadas + promociones + reglas de ocupación ($30/huésped extra, tope `max_capacity`), todo en BD (zero hardcoding) |
| Execución | Subagentes (implementador/revisor/auditor) con MCPs obligatorios; auditoría whole-branch al final |

## 3. Arquitectura objetivo

```
[Astro (sin cambios)] ──fetch /api──▶ [Laravel 12 + Filament v5]
                                          │
                           ┌──────────────┼───────────────┐
                           ▼              ▼               ▼
                   BD nueva propia  MercadoPago      Channex→(Nobeds)
                   (schema Eloquent) (Checkout API)  (canal OTA)

[admin.hotelesusgar.com] ──► Panel Filament (multi-tenant listo)
```

Capas Laravel:

- `app/Domain/` — modelos, reglas de negocio, **Ports** (interfaces): `PmsPort`, `PaymentGatewayPort`, `ChannelManagerPort`, `RateRepository`.
- `app/Adapters/` — fuera del dominio, inyectados (DIP): `QloAppsAdapter` (transición), `NobedsAdapter` (futuro), `MercadoPagoAdapter`.
- `app/DTOs/` — paquetes inmutables: `BookingRequest`, `AvailabilityResult`, `PaymentResult`, `ChannelBookingPayload`.
- `app/Events/` + tabla `outbox_events` — sincronización externa por eventos con reintentos (jobs Laravel, driver `database`; sin Redis en Hostinger).
- `app/Filament/` — panel admin (recursos, widgets, policies).

## 4. Modelo de datos (schema Eloquent)

| Tabla | Campos clave | Notas |
|---|---|---|
| `properties` | name, slug, timezone | tenant de Filament (1 hoy, N mañana) |
| `room_types` | property_id, slug, name, base_occupancy, max_capacity, extra_guest_charge, sort_order | reglas de ocupación |
| `rooms` | property_id, room_type_id, label | unidades físicas (inventario por conteo) |
| `seasons` | property_id, name, starts_on, ends_on | |
| `room_type_rates` | season_id, room_type_id, price_per_night | tarifa base por noche |
| `promotions` | property_id, name, type (percent\|fixed), value, starts_on, ends_on, min_nights, room_type_ids, active | |
| `bookings` | property_id, reference, guest_id, checkin, checkout, status, total_usd, total_pen, source (web\|ota\|walk_in\|admin), booking_token, hold_expires_at | estados: pending/paid/confirmed/cancelled/failed/fraud_review |
| `booking_rooms` | booking_id, room_type_id, qty, price_per_night | |
| `guests` | first_name, last_name, email, phone, country | **global** (sin property_id): un huésped puede reservar en N propiedades; la relación es vía bookings.guest_id |
| `payments` | booking_id, mp_payment_id (único = idempotencia), status, amount_pen, amount_usd, raw, processed_at | |
| `outbox_events` | type, payload, status, attempts, next_attempt_at, last_error | |
| `users` / `roles` | Laravel auth + Filament | roles: admin, staff |

## 5. Rate engine (`PriceCalculator`)

Cálculo por noche: `room_type_rates` de la temporada vigente (fallback: `room_types.price` opcional) → promoción activa (percent/fixed, min_nights, applies_to) → cargo extra ocupación `extra_guest_charge × (guests − base_occupancy)` con tope `max_capacity` → total = Σ noches + cargos − descuentos. Todo desde BD; tests unitarios PHPUnit obligatorios.

## 6. API pública — contrato idéntico (21 endpoints)

Portar 1:1 con el mismo formato JSON (`success/error/code/message`): health, rooms (pricing real del rate engine), booking, process-payment, extend-hold, booking-status, webhooks MP + Channex, cron/cleanup, auth (providers/login/callback/register/login-email/me/logout), user/bookings, user/profile, newsletter.

Flujo de pago (rediseñado con outbox — arregla el bug histórico de "falta de comunicación"):
1. POST `/api/booking` → valida (FormRequest) → rate engine → hold en BD (15 min) → `booking_token` HMAC.
2. Frontend CardForm → POST `/api/process-payment` → token HMAC + verificación de hold → MP (PEN, idempotency key, notification_url) → si approved: status Paid + evento → outbox.
3. POST `/api/webhook` (MP) → firma HMAC (WebhookSignatureValidator) → idempotencia (payments.mp_payment_id) → monto vs reserva (`bccomp`, anti-fraude) → Paid → outbox (200 inmediato antes de sync externa).
4. Jobs de outbox con reintentos+backoff sincronizan PMS/canal; `php artisan schedule:run` (ReconcilePayments + CleanExpiredHolds).

## 7. Panel Filament (admin.hotelesusgar.com)

Recursos: Properties, RoomTypes (tarifas inline), Seasons, Promotions, Bookings (acciones: confirmar/cancelar/reembolsar vía PaymentRefundClient), Guests, Payments, widgets dashboard (ocupación, ingresos). Tenancy: `->tenant(Property::class)`, menú de cambio. Policies por rol.

## 8. Seguridad (sistema crítico — pagos)

- Firma HMAC webhooks (SDK oficial), idempotencia por payment_id, anti-fraude de monto (`bccomp`), `booking_token` HMAC por reserva.
- Validación FormRequests, CSRF (Laravel nativo), rate limiting (heredar 300/600 del actual), secrets solo en `.env` (nunca en código ni logs), prepared statements (Eloquent/PDO).
- Threat model documentado en `docs/SECURITY.md` + ADRs por decisión; logs de pago sin datos sensibles.

## 9. Auditoría y mejora continua (subagentes + MCPs)

- Ejecución con **Subagent-Driven Development**: 1 subagente implementador fresco por tarea (brief en archivo, contexto aislado) → 1 subagente revisor (spec compliance + calidad sobre diff empaquetado) → fix loop máx 5 rondas → **auditoría whole-branch final** (revisión completa del código de las 6 sesiones en una pasada, modelo más capaz).
- **MCPs obligatorios para todos los agentes** (regla del usuario): context7 (docs Laravel/Filament antes de escribir código), astro-docs (si se toca frontend), chrome-devtools (verificación visual/perf), mercadopago-mcp (validación del flujo MP contra docs oficiales). Si un MCP falta → BLOCKED, no continuar.
- Memoria entre sesiones: `docs/refactoring/STATE.md` (hecho/en curso/siguiente), `DECISIONS.md`, ledger `.superpowers/sdd/` (sobrevive a compactación); cada sesión empieza leyendo STATE.md y termina actualizándolo + commit.
- Contrato de no-regresión: `api-harness.php` portado a PHPUnit verde contra Laravel + `npm run check` + E2E Playwright antes/después de cada fase.

## 10. Fases y criterios de salida

| Fase | Qué | Salida verificable |
|---|---|---|
| 0 — Plataforma paralela | Caracterizar backend actual (tests); subir Laravel+Filament en `admin.` subdominio; **verificar PHP 8.2+ en panel Hostinger**; BD nueva; deploy Laravel probado | Panel admin vacío en producción, sitio actual intacto |
| 1 — Rate engine + panel | Migraciones, seeds, PriceCalculator (PHPUnit), recursos Filament (properties/room_types/seasons/rates/promotions) | Tarifas/temporadas/promociones gestionadas desde el panel |
| 2 — API migrada | Portar endpoints 1:1 (health→rooms→auth→cron→booking-status), api-harness verde contra Laravel | Sitio web funcionando con backend nuevo (sin pago aún) |
| 3 — Pago + outbox + canal | ProcessPayment/webhook MP con outbox+jobs, Channex portado, scheduler | Reserva web end-to-end sandbox + OTA sincronizada |
| 4 — Corte | Backup QloApps congelado, retiro de `app/`, docs actualizadas, SECURITY.md/ADR | QloApps fuera, cero regresión, auditoría whole-branch aprobada |

Estimación: 4-6 sesiones; el sitio web nunca se detiene (fases 0-3 en paralelo).

## 11. Deploy (Hostinger compartido)

- Laravel: raíz web → `public/`, `storage/` fuera de la raíz, `vendor/` subido desde build (sin Composer en prod — verificado el flujo actual de `npm run build`), `php artisan optimize` en cada deploy.
- Panel en directorio/subdominio propio (`admin.hotelesusgar.com` → su propio `public_html/`), mismo servidor.
- Queue driver `database` (sin Redis); scheduler vía cron del hosting apuntando a `php artisan schedule:run`.
- El build Astro se mantiene tal cual (postbuild copia app/vendor/.env); la Fase 4 retira `app/` del build cuando el corte esté verificado.
