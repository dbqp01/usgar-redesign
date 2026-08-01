# Plan de Migración — USGAR Hotels

Plan de trabajo pendiente. Nada de esto está hecho todavía: úsalo como lista de tareas, no como documentación del estado actual.

Estado global: **TODO** (nada completado).

---

## 1. Pagos: MercadoPago → Stripe (DECIDIDO)

**Decisión (jul 2026): Stripe con LLC en EEUU (Stripe Atlas).** TAB descartado: sin API pública + costo similar o mayor.

### Por qué Stripe (para el enfoque internacional del hotel)
- **2.9% + $0.30** flat en línea (+1% tarjetas internacionales), **USD directo** (el repo ya cobra USD → cero conversión).
- Acepta todas las marcas: Visa, MC, Amex, Diners, Discover, JCB, UnionPay.
- API de referencia: SDK PHP oficial, Stripe.js, webhooks firmados — patrón 1:1 con la arquitectura actual (Ports/Adapters).

### Requisitos previos (fuera del repo)
- [ ] Constituir LLC en EEUU vía **Stripe Atlas** (~$500 inicial + EIN) y revisar implicaciones fiscales con contador.
- [ ] Crear cuenta Stripe y activar el modo de pago por tarjeta.
- [ ] Obtener credenciales: `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET` (firmar en el dashboard: `whsec_...`).

### Tareas técnicas (en el repo)
- [ ] `composer require stripe/stripe-php` (última estable; API moderna: `StripeClient` + servicios, no el estilo legacy de `Stripe::setApiKey`).
- [ ] `.env`: agregar las 3 credenciales de Stripe (nunca literales en código).
- [ ] Crear `StripeAdapter` en `app/Features/Shared/Adapters/` implementando `PaymentGatewayPortInterface` (sustituir `MercadoPagoAdapter`). Inicializar `new StripeClient(['api_key' => ..., 'stripe_version' => ...])` con valores de `.env`.
- [ ] Refactor `CreateBookingAction`: crear `PaymentIntent` con `amount` (en **centavos** de USD), `currency => 'usd'`, `automatic_payment_methods => ['enabled' => true, 'allowed' => ['card']]`, `metadata` con el id de reserva provisional; devolver `client_secret` (sustituir `cart_id`/`access_token`/`mp_public_key`/`gateway_price`).
- [ ] Frontend (`src/pages/book.astro`): **Stripe.js + Payment Element** — `stripe.confirmPayment({ clientSecret, ... })`; reemplazar el SDK de MercadoPago.
- [ ] Webhooks: adaptar `HandleMercadoPagoWebhookAction` → `Webhook::constructEvent($payload, $header['stripe-signature'], $secret)` (HMAC-SHA256, tolerancia 300s). Eventos: `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`.
- [ ] Confirmar orden: al `payment_intent.succeeded` → `ConfirmQloAppsOrderListener` (ya existe; solo cambia el disparador) + sync con Channex.
- [ ] Tests locales de webhook: **Stripe CLI** (`stripe listen --forward-to localhost:8000/api/webhooks/stripe`) — no depender de URLs públicas.
- [ ] Pruebas: tarjetas de test de Stripe (`4242 4242 4242 4242` = éxito; `4000 0000 0000 0002` = declinada; `4000 0025 0000 3155` = requires auth/3DS), flujo completo de reserva, reembolso (`paymentIntents->refund` o API de reembolsos).
- [ ] Limpiar: quitar `mercadopago/dx-php` de `composer.json`, borrar `MercadoPagoAdapter` y vistas/flujo viejos de MP.

## 2. Channel Manager: Channex → Nobeds

- [ ] Crear cuenta/API key de Nobeds (docs en https://api.nobeds.com — incluye MCP docs).
- [ ] Revisar endpoints reales a usar: Bookings Engine, Prices & Availability, WebHooks, Health.
- [ ] Crear `NobedsAdapter` (sustituir `ChannexAdapter` en `app/Features/Shared/Adapters/`).
- [ ] `RoomTypeRegistry`: re-mapear room types a los IDs de Nobeds.
- [ ] Sincronización de inventario/tarifas: sustituir lógica de Channex (availability + rates).
- [ ] Listener de bookings: `SyncChannexBookingListener` → equivalente Nobeds (crear/actualizar/cancelar reservas).
- [ ] Webhooks de Nobeds: registrar y verificar eventos en `app/Features/Webhooks/`.
- [ ] Health/self-service: monitorear estado de conexión OTA (endpoint Health de Nobeds).
- [ ] Eliminar código de Channex (adapters, mappers, listeners).
- [ ] Pruebas con OTAs reales en sandbox (Booking.com / Airbnb) y verificación de overbooking.

## 3. CMS/PMS: QloApps → Filament PHP

### Decisión de arquitectura (hacer ANTES de codear)
- [ ] **Bloqueante**: Filament es un panel de administración para **Laravel**. El backend actual es PHP 8 nativo (monolito ADR con DI PSR-11). Elegir entre:
  - (A) Migrar TODO el backend a Laravel + Filament (reescritura gradual feature a feature, ADR → controllers/servicios Laravel).
  - (B) Backend dual temporal: Laravel+Filament solo para admin, API pública PHP nativa hasta migrar.
  - (C) Otro (depende de lo que el usuario quiera conservar de QloApps: PrestaShop schema, módulos, etc.)
- [ ] Confirmar alcance real de "QloApps": ¿qué se reemplaza? ¿booking engine? ¿admin de habitaciones/tarifas? ¿quién queda como PMS (QloApps seguirá siendo el PMS de verdad y Filament solo es el admin del sitio)?

### Si se aprueba Laravel + Filament
- [ ] Bootstrap: instalar Laravel + Filament v5 (ver docs context7: `/websites/filamentphp_5_x`) + panel admin.
- [ ] Migrar esquema BD: QloApps (schema PrestaShop: `ps_room_type`, órdenes, clientes…) → Eloquent migrations.
- [ ] Recursos Filament: Habitaciones, Reservas, Huéspedes, Tarifas, Webhooks (con policies/permissions).
- [ ] Migrar Ports/Adapters → servicios Laravel (`PmsPortInterface`, `PaymentGatewayPortInterface` → service containers).
- [ ] Auth: sesiones propias + hybridauth → Laravel Auth (guards + socialite si aplica).
- [ ] Cron → Laravel Scheduler (reemplazar scripts crudos de `app/Features/Cron`).
- [ ] `/api` público: migrar actions ADR a rutas/controllers Laravel (mantener contrato JSON igual para no romper frontend).
- [ ] Deploy Hostinger: Laravel en hosting compartido (ajustes: public/, rutas, artisan optimize).
- [ ] Pruebas: portar `phpunit.xml` + `api-harness.php` a tests Laravel; mantener Playwright.

---

## Reglas del plan

- Marcar `[x]` solo cuando esté hecho y verificado (tests pasando).
- No mezclar migraciones en un mismo commit: una migración = una serie de commits `feat(migration-<nombre>):`.
- Al terminar cada bloque: actualizar README y borrar esta sección completada.
- Cualquier dependencia nueva (Laravel, Filament, SDK TAB, Nobeds) documentar versión exacta.
