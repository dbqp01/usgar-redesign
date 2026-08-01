# Plan de Migración — USGAR Hotels

Plan de trabajo pendiente. Nada de esto está hecho todavía: úsalo como lista de tareas, no como documentación del estado actual.

Estado global: **TODO** (nada completado).

---

## 1. Pagos: MercadoPago → TAB (¿o Culqi?)

### Veredicto de viabilidad de TAB (investigado, jul 2026)
- **No viable para integración API-driven en este momento**: TAB no publica API REST ni documentación de desarrollador (mapa del sitio verificado: solo existe la página de "integrations"). Su modelo público es **widget embebido + conectores no-code + VCCs** ("integra sin desarrollador").
- El flujo actual del repo (Custom Checkout: backend devuelve datos de cobro y el frontend cobra con SDK + webhooks) **no se puede construir** sin acceso a su API privada. Solo sería viable si: (a) TAB les concede acceso API bajo contrato, o (b) aceptan usar su widget embebido en `book.astro` (pérdida del control del checkout y del contrato de datos actual).
- **Acción recomendada antes de descartar**: un correo/chat a TAB preguntando por API REST + webhooks para desarrolladores. Hasta respuesta, el plan asume Culqi como destino.

### Alternativa según el enfoque INTERNACIONAL del hotel (decidido: USD + tarjetas extranjeras + mínimas comisiones)

Análisis comparado (investigado, jul 2026):

| Proveedor | Comisión (tarjeta extranjera) | Tarjetas | Moneda | Desde Perú sin entidad | Notas |
|---|---|---|---|---|---|
| **Stripe** (vía LLC EEUU) | **2.9% + $0.30** online (+1% intl) | Todas: Visa, MC, **Amex, Diners, Discover, JCB, UnionPay** | **USD**, 135+ divisas | ❌ requiere LLC (Stripe Atlas) | La mejor API del mercado, webhooks, SDKs — patrón idéntico al actual |
| **TAB** | s/d (privado) | Internacional (diseñado para turismo) | USD | ✅ (si dan acceso API) | Enfocado a turismo pero **sin API pública** → bloqueado |
| **PayPal** | 2–4.5% + fijo + margen de conversión | Visa, MC, Amex, Discover | USD/PEN | ✅ | Caro de facto (~$25–35 por $500) y retenciones; solo fallback |
| **Culqi** (plan B anterior) | **4.99–5.49% + $0.30** extranjeras | Visa, MC (+Yape, efectivo) | **PEN** | ✅ | Muy caro para público extranjero y liquida soles → **descartado** para este enfoque |
| **Niubiz** | ~3.45% +1% intl + setup S/300 + S/50/mes | Amex, Diners | **PEN** | ✅ | Caro en fijos, liquida soles |
| **Izipay** | 3.95–4.09% extranjeras | Visa, MC | **PEN** | ✅ | Liquida soles |

**Conclusión:** para cobrar a turistas internacionales en USD con la mayor cobertura de tarjetas y las menores comisiones:
1. **Stripe (con LLC vía Stripe Atlas)** — la única con 2.9% flat, todas las marcas y USD; requiere constituir entidad en EEUU (costo único ~$500 + mantenimiento anual; implicación fiscal a revisar con contador).
2. **TAB** — si su equipo confirma acceso a API (ideal por ser travel-first, con VCCs y USD), gana por no requerir LLC; sigue bloqueado a su respuesta.
3. **PayPal** — plan C sin entidad legal, pero el más caro de facto.

**Acción recomendada:** contactar TAB (1 correo) Y evaluar Stripe Atlas en paralelo; descartar Culqi/Niubiz/Izipay por liquidación en PEN y comisiones altas en tarjetas extranjeras.

### Tareas (Stripe o TAB, según decisión)
- [ ] **Bloqueante 1**: correo a TAB → ¿API REST + webhooks para desarrolladores? ¿credenciales sandbox? (si sí → TAB es el ganador, sin LLC).
- [ ] **Bloqueante 2**: decidir si se constituye LLC en EEUU para Stripe (Stripe Atlas ~$500) — requiere aprobación del usuario y revisión fiscal/contable.
- [ ] Con proveedor elegido: crear adapter (`StripeAdapter` o `TabAdapter`) implementando `PaymentGatewayPortInterface`.
- [ ] Refactor `CreateBookingAction`: devolver los datos de checkout del proveedor (sustituir `cart_id`/`access_token`/`mp_public_key`/`gateway_price`).
- [ ] Frontend (`src/pages/book.astro`): SDK del proveedor (Stripe.js / SDK TAB) — Stripe.js mantiene el patrón actual casi 1:1.
- [ ] Webhooks: adaptar `HandleMercadoPagoWebhookAction` → webhook del proveedor con verificación de firma (Stripe: `stripe-signature`).
- [ ] Moneda: **USD directo** con Stripe (el precio ya está en USD en el repo — sin conversión).
- [ ] Limpiar: quitar `mercadopago/dx-php` de `composer.json`, borrar adapters/vistas viejos de MP.
- [ ] Pruebas en sandbox: pago exitoso, rechazado, expirado, reembolso y flujo completo de reserva (Stripe test cards: 4242…).

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
