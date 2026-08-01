# Plan de Migración — USGAR Hotels

Plan de trabajo pendiente. Nada de esto está hecho todavía: úsalo como lista de tareas, no como documentación del estado actual.

Estado global: **TODO** (nada completado).

---

## 1. Pagos: MercadoPago — SE MANTIENE (sin migración de pasarela)

**Decisión (jul 2026): no migrar.** Análisis comparado con tarifas oficiales 2026: MercadoPago (3.29-3.49% + S/1 + IGV → ~4.1% efectivo, S/0 setup/mensualidad, retiros gratis) es la mejor opción sin entidad legal desde Perú; cumple el criterio del usuario ("se asume ~4% si funciona, está documentada y no tiene cobros raros posteriores"). Las alternativas fallan: PayPal Perú 4.4-5.4%, Culqi ~4.7% efectivo en internacionales, Payoneer Checkout requiere entidad HK + $20k/mes, Stripe exige LLC (~$900 año 1).

- [ ] **Reevaluar Stripe + LLC cuando existan ~6 meses de datos de volumen** (break-even ~10-12 reservas online/mes; 2.9% + $0.30 es el único <4% real).
- [ ] Los problemas reportados con MP (falta de comunicación, residuos de Checkout Pro) **no se resuelven cambiando de pasarela**: se resuelven en la refactorización (sección 4) — aislar SDK, limpiar flujo viejo, tests de caracterización.

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

## 4. Refactorización completa del código (transversal, PENDIENTE)

**Objetivo:** eliminar deuda acumulada antes de tocar las migraciones 2 y 3: integración de pagos con residuos del flujo Checkout Pro (se migró a Checkout API), "falta de comunicación" entre capas (webhooks/confirmaciones que no llegan), y en general estructura ADR desprolija. Multi-sesión, sin dependencia de memoria.

### Método (contrato de no-regresión)
- [ ] **Línea base ANTES de tocar nada**: correr la suite de tests existente + linter + smoke test de arranque; documentar qué pasa y qué falla. Toda sesión verifica que la línea base no empeora.
- [ ] **Memoria entre sesiones** en `docs/refactoring/`: `PLAN.md` (roadmap por paquetes de trabajo), `STATE.md` (handoff: hecho/en curso/siguiente), `DECISIONS.md` (decisiones y por qué). Regla: cada sesión empieza leyendo `STATE.md` y termina actualizándolo + commit descriptivo.
- [ ] Registrar decisiones y deuda detectada en el memory MCP (persistencia fuera del repo).
- [ ] **Paquetes de trabajo de 1-2 sesiones**, cada uno con criterio "done" verificable (tests + línea base intacta); prioridad riesgo×impacto. No agregar features nuevas durante el refactor.

### Paquetes semilla (expandir en PLAN.md tras auditoría real)
- [ ] **Pagos MP**: tests de caracterización con mocks del SDK (fijar comportamiento actual); aislar SDK detrás de `PaymentGatewayPortInterface` ya existente; **eliminar residuos del flujo Checkout Pro viejo** (auditar qué campos/flujo quedaron: `cart_id`, `access_token`, `checkout_pro`…); diagnosticar la "falta de comunicación" (revisar `HandleMercadoPagoWebhookAction`, listeners y confirmación de órdenes).
- [ ] **Comunicación entre capas**: formalizar reglas de dependencia ADR (qué capa llama a cuál), eliminar saltos ilegales, centralizar manejo de errores.
- [ ] **Higiene**: quitar dead code, dependencias no usadas, archivos generados; unificar convenciones.
- [ ] (Cada hallazgo de auditoría agrega un paquete con su criterio de done.)

---

## Reglas del plan

- Marcar `[x]` solo cuando esté hecho y verificado (tests pasando).
- No mezclar migraciones en un mismo commit: una migración = una serie de commits `feat(migration-<nombre>):`.
- Al terminar cada bloque: actualizar README y borrar esta sección completada.
- Cualquier dependencia nueva (Laravel, Filament, SDK MercadoPago, Nobeds) documentar versión exacta.
