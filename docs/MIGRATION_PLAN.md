# Plan de Migración — USGAR Hotels

Plan de trabajo pendiente. Nada de esto está hecho todavía: úsalo como lista de tareas, no como documentación del estado actual.

Estado global: **TODO** (nada completado).

---

## 1. Pagos: MercadoPago → Payoneer Checkout (DECIDIDO)

**Decisión (jul 2026): Payoneer Checkout — sin entidad legal, menor comisión de las opciones plug-and-play desde Perú, y liquidación USD directa.** Stripe+LLC (Stripe Atlas, ~$900 año 1) descartado por ahora: no se conoce el volumen del hotel y el break-even de la LLC (~$700/año ÷ $5.64 de ahorro por reserva ≈ 125 reservas/año) no se justifica sin ese dato. TAB sin API pública.

### Por qué Payoneer Checkout
- **Tarjeta: hasta 3.99% + $0.49** (mínimo $1 en pagos <$100) — la menor de las opciones sin entidad desde Perú (PayPal ≈ 4.4-6% efectivo; MercadoPago 4-5% + conversión).
- **Liquidación en USD/EUR sin comisión de conversión** (1.5% solo otras divisas), T+2. El repo ya cobra USD → cero conversión.
- Acepta Visa, Mastercard, **Amex** + métodos locales, 120+ divisas. Sin setup fees ni mensualidad ("pay as you go").
- API REST oficial (Checkout API) + webhooks de pago; opciones **hosted payment page** (redirección, más simple) o **embedded payment page** (formulario embebido, patrón similar al actual).
- Retiros a banco en Perú vía cuenta Payoneer normal (Payoneer opera en Perú).

### ⚠️ Bloqueante 1 (fuera del repo) — confirmar elegibilidad ANTES de tocar código
- [ ] Solicitar **Payoneer Checkout** desde la cuenta Payoneer peruana (la solicitud es **gratis**; el FAQ oficial indica que arrancó solo para entidades de APAC/Hong Kong y se extiende gradualmente — confirmar que aceptan Perú y el vertical hotel/travel, que pasa revisión de riesgo "subject to full risk assessment").
- [ ] Si Payoneer Checkout rechaza/indispone Perú → **fallback**: PayPal Perú (funciona desde Perú, USD, REST API + webhooks; más caro ~4.4%+intl y con riesgo de holds) o mantener MercadoPago. Decidir al momento de la respuesta, no antes.
- [ ] Obtener credenciales de sandbox y luego producción (API key / merchant id).

### Tareas técnicas (en el repo)
- [ ] `.env`: credenciales de Payoneer Checkout (API key, ids de store) — nunca literales en código.
- [ ] Crear `PayoneerAdapter` en `app/Features/Shared/Adapters/` implementando `PaymentGatewayPortInterface` (sustituir `MercadoPagoAdapter`). Verificar docs oficiales de la Checkout API (hosted vs embedded) durante la implementación.
- [ ] Refactor `CreateBookingAction`: crear el checkout/pago en Payoneer (monto USD en **centavos**, `metadata` con id de reserva provisional); devolver `payment_url` (hosted) o los datos del embedded form (sustituir `cart_id`/`access_token`/`mp_public_key`/`gateway_price`).
- [ ] Frontend (`src/pages/book.astro`): redirigir a la hosted payment page o embeker el formulario de pago; reemplazar el SDK de MercadoPago.
- [ ] Webhooks: adaptar `HandleMercadoPagoWebhookAction` → webhook de Payoneer Checkout (verificar esquema de firma/secret en docs oficiales al implementar). Eventos: pago exitoso / fallido / reembolso.
- [ ] Confirmar orden: al pago exitoso → `ConfirmQloAppsOrderListener` (ya existe; solo cambia el disparador) + sync con Channex.
- [ ] Tests locales de webhook: exponer endpoint en local (ngrok/tunnel) o usar entorno de test de Payoneer.
- [ ] Pruebas: tarjetas de test de Payoneer sandbox (éxito, declinada, 3DS si aplica), flujo completo de reserva, reembolso.
- [ ] Limpiar: quitar `mercadopago/dx-php` de `composer.json`, borrar `MercadoPagoAdapter` y vistas/flujo viejos de MP.

### Nota a mediano plazo (no bloqueante)
Stripe sigue siendo el mejor a largo plazo si el volumen sube (~10+ reservas online/mes lo justifican) o si se necesita cobertura Diners/Discover/JCB/UnionPay. No requiere acción ahora; reevaluar cuando haya datos de volumen.

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
- Cualquier dependencia nueva (Laravel, Filament, SDK Payoneer, Nobeds) documentar versión exacta.
