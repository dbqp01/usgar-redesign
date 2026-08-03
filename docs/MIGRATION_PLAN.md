# Plan de Migración — USGAR Hotels

Plan de trabajo con migraciones en curso y estado verificado (2026-08-03): úsalo como lista de tareas y como documentación del estado real.

Estado global (2026-08-03): **sección 3 en curso** — Fase 0 completada (decisión variante B + panel Laravel 12 / Filament v5.7.5 en admin.hotelesusgar.com), Fases 1-2 pendientes; **sección 4 completada** (refactor backend, cierre F2 el 2026-08-03); **sección 2 bloqueada** (Nobeds requiere suscripción pagada); **sección 1 sin cambios** (MP se mantiene).

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

### Decisión de arquitectura — RESUELTA (Fase 0, 2026-08-02)
- [x] **Variante (B) — backend dual temporal**: Filament es un panel de administración para **Laravel** y el backend actual es PHP 8 nativo (monolito ADR con DI PSR-11). Se eligió (B): Laravel + Filament **solo para admin**, API pública PHP nativa hasta migrar. Alternativas descartadas:
  - (A) Migrar TODO el backend a Laravel + Filament (reescritura gradual feature a feature, ADR → controllers/servicios Laravel).
  - (C) Otro (depende de lo que el usuario quiera conservar de QloApps: PrestaShop schema, módulos, etc.)
  - **Implementada en Fase 0**: Laravel 12 + Filament v5.7.5 desplegado en `https://admin.hotelesusgar.com/admin/login` (multi-tenant Property, deploy zip `usgar-admin-deploy.zip` → `public_html/admin` en Hostinger compartido, sin Composer en prod). Evidencia: `docs/refactoring/DECISIONS.md` (2026-08-02), `docs/refactoring/STATE.md` ("FASE 0 COMPLETA"), commits `7333841`/`f7642ec`/`4f1eff6`.
- [x] **Alcance de "QloApps" confirmado**: se sale de QloApps por completo (tablas propias + Filament como admin del sitio); Filament gestiona habitaciones/tarifas/reservas/huéspedes y QloApps deja de ser el PMS (decisión del usuario — contexto en `docs/refactoring/BACKEND_STATE.md`).

### Fase 0 — completada (2026-08-02)
- [x] Bootstrap: Laravel 12 + Filament v5 instalados en `backend/` + panel admin en producción (evidencia: commits `e2b6476`/`7333841`; `docs/refactoring/STATE.md` "FASE 0 COMPLETA").

### Fase 1 — pendiente (rate engine + recursos Filament)
- [ ] Rate engine (siguiente ciclo según `docs/refactoring/DECISIONS.md`, 2026-08-02).
- [ ] Migrar esquema BD: QloApps (schema PrestaShop: `ps_room_type`, órdenes, clientes…) → Eloquent migrations (tablas propias).
- [ ] Recursos Filament: Habitaciones, Reservas, Huéspedes, Tarifas, Webhooks (con policies/permissions).

### Fase 2 — pendiente (API pública Laravel)
- [ ] `/api` público: migrar actions ADR a rutas/controllers Laravel **1:1 con el contrato de `docs/refactoring/backend-contract.md`** (mantener contrato JSON igual para no romper frontend).
- [ ] Migrar Ports/Adapters → servicios Laravel (`PmsPortInterface`, `PaymentGatewayPortInterface` → service containers).
- [ ] Auth: sesiones propias + hybridauth → Laravel Auth (guards + socialite si aplica).
- [ ] Cron → Laravel Scheduler (reemplazar scripts crudos de `app/Features/Cron`).
- [ ] Deploy Hostinger: Laravel en hosting compartido (ajustes: public/, rutas, artisan optimize).
- [ ] Pruebas: portar `phpunit.xml` + `api-harness.php` a tests Laravel; mantener Playwright.

## 4. Refactorización completa del código (transversal) — COMPLETADA (2026-08-03)

**Objetivo:** eliminar la deuda acumulada antes de tocar las migraciones 2 y 3: integración de pagos con residuos del flujo Checkout Pro (se migró a Checkout API), "falta de comunicación" entre capas (webhooks/confirmaciones que no llegan), y en general estructura ADR desprolija. Multi-sesión, sin dependencia de memoria. **Estado: completada — cierre F2 el 2026-08-03** (nota al final de la sección).

### Método (contrato de no-regresión) — cumplido
- [x] **Línea base ANTES de tocar nada** — verificada 2026-08-01 y mantenida en cada sesión: `php composer.phar check` → **26 tests / 97 assertions PASS** + PHPStan sin errores; `npm run check` → 0 errores; `npm run test:php` → 22/22; `npm run audit:security` → 3 hallazgos, todos falsos positivos. Evidencia: `docs/refactoring/BACKEND_STATE.md` → "Línea base".
- [x] **Memoria entre sesiones** en `docs/refactoring/`: `PLAN.md` (roadmap por paquetes de trabajo), `STATE.md` (handoff: hecho/en curso/siguiente), `DECISIONS.md` (decisiones y por qué), `BACKEND_STATE.md` (estado backend), `backend-contract.md` (contrato API). Regla cumplida: cada sesión lee y actualiza su STATE + commit descriptivo (ver `git log --oneline`).
- [ ] Registrar decisiones y deuda detectada en el memory MCP (persistencia fuera del repo) — sin verificar en esta sesión.
- [x] **Paquetes de trabajo de 1-2 sesiones**, cada uno con criterio "done" verificable (tests + línea base intacta) — aplicado en F0 (backend Laravel) y F2 (pagos/higiene/webhook): sesiones worker, línea base intacta, sin features nuevas.

### Paquetes semilla — estado verificado (evidencia: `docs/refactoring/BACKEND_STATE.md`)
- [x] **Pagos MP**: tests de caracterización con mocks del SDK (26/97 PASS, incl. refund parcial e idempotencia — commit `58c497a`); SDK aislado detrás de `PaymentGatewayPortInterface` ya existente (DIP: `cron/reconcile_payments.php` resuelve del container — commit `90e2442`); **residuos del flujo Checkout Pro eliminados** (commit `cca8fd5`: greps sin `preference`/`init_point`/`WebhookDebugAction` en `app/`; `access_token` de `/api/booking` es el token HMAC del hold actual, NO residuo; `preference_id` histórico en BD preservado por datos); "falta de comunicación" **diagnosticada** (webhook MP sin historial en `notifications_history` → candidato #1: no registrado).
- [x] **Comunicación entre capas — DIP corregido (P3-1)**: constructores no-nullable en los 5 actions (`grep -rn "= null)"` → 0 matches; `CreateBookingAction.php:32`, `ProcessPaymentAction.php:29`, `GetBookingStatusAction.php:22`, `ExtendHoldAction.php:21`, `HandleMercadoPagoWebhookAction.php:30`). Pendiente (sin evidencia de cierre): formalizar reglas de dependencia ADR y centralizar manejo de errores.
- [x] **Higiene**: dead code y dependencias muertas eliminadas (commit `7685577`); `logs/app.log`, `preview.log` y `.playwright-mcp/*` sacados de git (`git rm --cached` + `.gitignore` extendido); secretos limpios (historial completo escaneado `git grep APP_USR-` → solo placeholders; `.env` gitignored y nunca commiteado); CSP unificada (sin `.htaccess` raíz; `public/.htaccess` solo nosniff; CSP única en Middleware); dependencias 0 vulnerabilidades (`composer audit` + `npm audit`).
- [ ] (Cada hallazgo de auditoría agrega un paquete con su criterio de done.)

> **Cierre F2 (2026-08-03)**: logging diagnóstico del webhook recortado a 5 `Logger::info` concisos por evento, sin headers/query/`$_SERVER` (commit `1e3226b`); verificación read-only del webhook MP ejecutada (commit `e760992`): `notifications_history` con app `8501374849722569` → "No Notifications Found"; `.env` `MERCADO_PAGO_WEBHOOK_SECRET` presente (len=64, valor nunca impreso). Quedan **user-gated** (pendientes de confirmación del usuario, no bloquean el cierre): comparar el secret del panel MP vs `.env`, confirmar el registro del webhook (`save_webhook`, callback `https://usgarhoteles.com/api/webhook`, topic `payment`), pago de prueba + monitoreo de `notifications_history`. Evidencia: `docs/refactoring/BACKEND_STATE.md` → "F2 — cerrada (2026-08-03)".

---

## Reglas del plan

- Marcar `[x]` solo cuando esté hecho y verificado (tests pasando).
- No mezclar migraciones en un mismo commit: una migración = una serie de commits `feat(migration-<nombre>):`.
- Al terminar cada bloque: actualizar README y borrar esta sección completada.
- Cualquier dependencia nueva (Laravel, Filament, SDK MercadoPago, Nobeds) documentar versión exacta.
