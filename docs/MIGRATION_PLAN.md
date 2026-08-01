# Plan de Migración — USGAR Hotels

Plan de trabajo pendiente. Nada de esto está hecho todavía: úsalo como lista de tareas, no como documentación del estado actual.

Estado global: **TODO** (nada completado).

---

## 1. Pagos: MercadoPago → TAB (¿o Culqi?)

### Veredicto de viabilidad de TAB (investigado, jul 2026)
- **No viable para integración API-driven en este momento**: TAB no publica API REST ni documentación de desarrollador (mapa del sitio verificado: solo existe la página de "integrations"). Su modelo público es **widget embebido + conectores no-code + VCCs** ("integra sin desarrollador").
- El flujo actual del repo (Custom Checkout: backend devuelve datos de cobro y el frontend cobra con SDK + webhooks) **no se puede construir** sin acceso a su API privada. Solo sería viable si: (a) TAB les concede acceso API bajo contrato, o (b) aceptan usar su widget embebido en `book.astro` (pérdida del control del checkout y del contrato de datos actual).
- **Acción recomendada antes de descartar**: un correo/chat a TAB preguntando por API REST + webhooks para desarrolladores. Hasta respuesta, el plan asume Culqi como destino.

### Alternativa recomendada: Culqi (plan B)
- **Por qué**: pasarela peruana nativa (grupo Credicorp/BCP), documentación en español, API REST completa + IPN/webhooks, checkout embebido con tokenización (cumple PCI DSS sin tocar datos de tarjeta), **sin costos de afiliación ni mensualidad**, plugin para **PrestaShop** (relevante: QloApps es base PrestaShop), acepta tarjetas internacionales, Yape y PagoEfectivo.
- **Comisiones 2026**: nacional 3.44% + USD 0.20 + IGV; **tarjetas extranjeras 4.99–5.49% + USD 0.30 + IGV** (público principal del hotel → considerar en precio).
- **Caveats a decidir**: Culqi opera en **PEN** (el repo hoy cobra en USD) → decidir si precios finales van en PEN o se maneja conversión; Stripe **no opera en Perú** (descartado sin entidad en EEUU); Niubiz es alternativa solo si se necesita Amex/Diners o volumen alto (S/300 setup + S/50/mes).
- **Arquitectura compatible**: mismo patrón que hoy — `PaymentGatewayPortInterface` → `CulqiAdapter`, `CreateBookingAction` devuelve token/session del checkout Culqi, frontend usa SDK Culqi.js, webhooks IPN con firma.

### Tareas (TAB o Culqi, según la respuesta de TAB)
- [ ] **Bloqueante**: contactar a TAB (API REST/webhooks para desarrolladores, docs privadas, credenciales). Si no hay API → cerrar TAB y seguir con Culqi.
- [ ] Si TAB da API: crear `TabAdapter` (patrón actual). Si no: crear `CulqiAdapter` implementando `PaymentGatewayPortInterface`.
- [ ] Refactor `CreateBookingAction`: devolver los datos de checkout del proveedor elegido (sustituir `cart_id`/`access_token`/`mp_public_key`/`gateway_price`).
- [ ] Frontend (`src/pages/book.astro`): SDK del proveedor (Culqi.js o SDK TAB) o widget, según lo decidido.
- [ ] Webhooks: adaptar `HandleMercadoPagoWebhookAction` → webhook/IPN del proveedor con verificación de firma.
- [ ] Decidir moneda: PEN vs USD (Culqi liquida PEN; verificar si el proveedor elegido soporta USD).
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
