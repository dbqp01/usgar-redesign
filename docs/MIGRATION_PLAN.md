# Plan de Migración — USGAR Hotels

Plan de trabajo pendiente. Nada de esto está hecho todavía: úsalo como lista de tareas, no como documentación del estado actual.

Estado global: **TODO** (nada completado).

---

## 1. Pagos: MercadoPago → TAB

### Qué es TAB (investigado, jul 2026)
- Plataforma de pagos **especializada en turismo** (hoteles, hostales, tours, retiros) — no es una pasarela genérica estilo MercadoPago. Sitio: `business.tab.travel` (param `cc=pe` = Perú).
- Productos: cobros con tarjeta, **VCCs** (tarjetas virtuales), **widget de reservas embebible** y **conectores no-code** con booking engines/PMS ("integra sin desarrollador").
- Respaldo de los mismos inversores que Airbnb/OpenAI/Dropbox/Stripe. Trustpilot 4★ (78 reseñas).
- **NO hay documentación de API pública**: no existe portal de desarrolladores ni docs indexadas (mapa del sitio verificado). La integración pública es widget/conector; la API parece privada (bajo contrato).

### Impacto en la arquitectura actual (importante)
El flujo actual (Custom Checkout: backend devuelve `cart_id`/`access_token`/`mp_public_key` y el frontend cobra con SDK) depende de un modelo API-driven. Hay que **confirmar con TAB** si ofrecen API directa (Checkout API + webhooks) o solo widget/conector — eso define si el frontend sigue custom o pasa a widget embebido.

### Tareas
- [ ] **Bloqueante**: contactar a TAB (soporte/comercial) para: (a) credenciales sandbox/prod, (b) confirmar si hay API REST para desarrolladores o solo widget/conectores no-code, (c) pedir su documentación técnica si existe (privada).
- [ ] Decidir modelo según la respuesta: API directa (patrón actual: token backend → cobro frontend → webhook) vs widget embebido vs connector.
- [ ] Si hay API: crear `TabAdapter` implementando `PaymentGatewayPortInterface` (sustituir `MercadoPagoAdapter`).
- [ ] Refactor `CreateBookingAction`: devolver los datos de checkout que TAB requiera (sustituir `cart_id`/`access_token`/`mp_public_key`/`gateway_price`).
- [ ] Frontend (`src/pages/book.astro`): reemplazar SDK de MercadoPago por el mecanismo de TAB (SDK propio o widget).
- [ ] Webhooks: adaptar `HandleMercadoPagoWebhookAction` → webhook de TAB con verificación de firma (o IPN equivalente).
- [ ] VCCs (si aplica): evaluar uso para pagos a proveedores/OTA — fuera del flujo de reserva directo.
- [ ] Limpiar: quitar `mercadopago/dx-php` de `composer.json`, borrar adapters/vistas viejos de MP.
- [ ] Pruebas en sandbox: pago exitoso, rechazado, expirado, reembolso y flujo completo de reserva.

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
