# Plan de Migración — USGAR Hotels

Plan de trabajo con migraciones en curso y estado verificado (2026-08-03): úsalo como lista de tareas y como documentación del estado real.

Estado global (2026-08-05): **sección 1 cerrada** — Stripe descartado definitivamente; **sección 2 pendiente** — decisión tomada: Channex → **QloApps Channel Manager** ($30/propiedad/mes, conexiones incluidas); **sección 3 RESUELTA** — panel Filament eliminado del repo (2026-08-05); **sección 4 completada** (refactor backend, cierre F2 el 2026-08-03) con pendientes menores; auditoría de refactor adicional en curso (2026-08-05).

---

## 1. Pagos: MercadoPago — SE MANTIENE (sin migración de pasarela)

**Decisión (jul 2026): no migrar.** Análisis comparado con tarifas oficiales 2026: MercadoPago (3.29-3.49% + S/1 + IGV → ~4.1% efectivo, S/0 setup/mensualidad, retiros gratis) es la mejor opción sin entidad legal desde Perú; cumple el criterio del usuario ("se asume ~4% si funciona, está documentada y no tiene cobros raros posteriores"). Las alternativas fallan: PayPal Perú 4.4-5.4%, Culqi ~4.7% efectivo en internacionales, Payoneer Checkout requiere entidad HK + $20k/mes, Stripe exige LLC (~$900 año 1).

- [x] ~~Reevaluar Stripe + LLC con datos de volumen~~ — **DESCARTADO DEFINITIVAMENTE (2026-08-05)**: no hay migración de pasarela ni reevaluación futura. MercadoPago es la pasarela única.
- [ ] Los problemas reportados con MP (falta de comunicación, residuos de Checkout Pro) **no se resuelven cambiando de pasarela**: se resuelven en la refactorización (sección 4) — aislar SDK, limpiar flujo viejo, tests de caracterización.

## 2. Channel Manager: Channex → QloApps Channel Manager (pendiente, decisión 2026-08-04)

**Decisión (2026-08-04, usuario):** sustituir la migración prevista a Nobeds por la suscripción SaaS **QloApps Channel Manager** (Webkul). Investigación de precios exactos (2026-08-04): Channex cuesta $130/mes plataforma + $7/propiedad (**$137/mes** — su único plan publicado); Nobeds €99/mes plan API con el peor historial de fiabilidad/soporte del grupo; el QloApps CM cuesta **$30/propiedad/mes** ($300/año, descuento 16.6%) e **incluye las conexiones** a Booking.com, Expedia, Airbnb (+Agoda, Google Hotels, Ctrip, Despegar, Goibibo/MakeMyTrip, Yatra, Bakuun) **sin costo por canal**, con la conectividad Channex operada por Webkul dentro del plan (no se paga Channex aparte). Requisito del hotel: solo Booking/Expedia/Airbnb — cubierto.

- [ ] Confirmar con Webkul (ticket en webkul.uvdesk.com) antes de pagar: (1) conectividad Channex de Booking/Expedia incluida en el $30 (sin factura aparte de Channex), (2) compatibilidad con la versión de QloApps de la instancia, (3) límite de canales por propiedad (la doc lista 10, sin límite publicado).
- [ ] Activar suscripción en channels.qloapps.com — ojo: el trial de 15 días NO sincroniza precios/inventario (solo la versión pagada) y no hay reembolsos.
- [ ] Conector PMS↔CM: habilitar webservice QloApps (clave + permisos `cm_api`) + módulo gratuito "QloApps PMS & Channel Manager Connector".
- [ ] Configurar canales siguiendo las guías de qloapps.com: Booking.com y Expedia (seleccionar "Channex" como proveedor en el extranet OTA) + Airbnb (authorize) + mapeo de room types/rate plans.
- [ ] Eliminar `ChannexAdapter` y la lógica de sincronización de Channex en `app/Features/Shared/Adapters/` (sustituida por la sync CM↔PMS vía webservice, cron ~1 min).
- [ ] Revisar listeners de bookings: `SyncChannexBookingListener` → las reservas OTA entran directo a QloApps (verificar flujo con `QloAppAdapter` y webhooks existentes).
- [ ] Monitoreo: reconciliación periódica inventario/tarifas OTA vs QloApps — el CM de Webkul no tiene status page pública (mitigar con checks propios).
- [ ] Pruebas con OTAs reales (Booking.com / Airbnb) y verificación de overbooking.

## 3. CMS/PMS: QloApps → Filament PHP — CANCELADA (2026-08-04) y PANEL ELIMINADO (2026-08-05)

**Decisión (2026-08-04, usuario): nos quedamos con QloApps como PMS.** Se cancela la migración del PMS (Fases 1-2: rate engine, migración de esquema BD, recursos Filament, API pública Laravel). Contexto: la investigación de channel managers (2026-08-04) mostró que el QloApps Channel Manager ($30/mes) es el camino económico y funciona sobre QloApps; mantener QloApps como PMS simplifica el stack y conserva `QloAppAdapter`, el schema PrestaShop y el flujo de reservas actual.

- [x] **Histórico — Fase 0 completada (2026-08-02)**: Laravel 12 + Filament v5.7.5 desplegado en `https://admin.usgarhoteles.com/admin/login` (multi-tenant Property, deploy zip `usgar-admin-deploy.zip` → `public_html/admin`). Evidencia: `docs/refactoring/DECISIONS.md`, `docs/refactoring/STATE.md`, commits `7333841`/`f7642ec`/`4f1eff6`. **Queda fuera de alcance a partir de la cancelación.**
- [x] **Decisión (2026-08-05): el panel se ELIMINA.** Borrados del repo: `backend/` (Laravel+Filament completo), `usgar-admin-deploy.zip`, docs de la fase (`docs/superpowers/` plan/spec Laravel, `docs/refactoring/PLAN.md` + `STATE.md`). **Pendiente en Hostinger (acción manual, no bloqueante para el repo)**: retirar `public_html/admin` y el subdominio `admin.usgarhoteles.com`. Los docs de `docs/refactoring/DECISIONS.md` y `BACKEND_STATE.md` se conservan como registro de decisiones vivas y estado del backend.

## 4. Refactorización completa del código (transversal) — COMPLETADA (2026-08-03)

**Objetivo:** eliminar la deuda acumulada antes de tocar las migraciones 2 y 3: integración de pagos con residuos del flujo Checkout Pro (se migró a Checkout API), "falta de comunicación" entre capas (webhooks/confirmaciones que no llegan), y en general estructura ADR desprolija. Multi-sesión, sin dependencia de memoria. **Estado: completada — cierre F2 el 2026-08-03** (nota al final de la sección).

### Método (contrato de no-regresión) — cumplido
- [x] **Línea base ANTES de tocar nada** — verificada 2026-08-01 y mantenida en cada sesión: `php composer.phar check` → **26 tests / 97 assertions PASS** + PHPStan sin errores; `npm run check` → 0 errores; `npm run test:php` → 22/22; `npm run audit:security` → 3 hallazgos, todos falsos positivos. Evidencia: `docs/refactoring/BACKEND_STATE.md` → "Línea base".
- [x] **Memoria entre sesiones** en `docs/refactoring/`: `DECISIONS.md` (decisiones y por qué), `BACKEND_STATE.md` (estado backend), `backend-contract.md` (contrato API); `PLAN.md` y `STATE.md` retirados al cerrar el refactor (2026-08-05, recuperables en git). Regla cumplida: cada sesión lee y actualiza su STATE + commit descriptivo (ver `git log --oneline`).
- [ ] Registrar decisiones y deuda detectada en el memory MCP (persistencia fuera del repo) — sin verificar en esta sesión.
- [x] **Paquetes de trabajo de 1-2 sesiones**, cada uno con criterio "done" verificable (tests + línea base intacta) — aplicado en F0 (backend Laravel) y F2 (pagos/higiene/webhook): sesiones worker, línea base intacta, sin features nuevas.

### Paquetes semilla — estado verificado (evidencia: `docs/refactoring/BACKEND_STATE.md`)
- [x] **Pagos MP**: tests de caracterización con mocks del SDK (26/97 PASS, incl. refund parcial e idempotencia — commit `58c497a`); SDK aislado detrás de `PaymentGatewayPortInterface` ya existente (DIP: `cron/reconcile_payments.php` resuelve del container — commit `90e2442`); **residuos del flujo Checkout Pro eliminados** (commit `cca8fd5`: greps sin `preference`/`init_point`/`WebhookDebugAction` en `app/`; `access_token` de `/api/booking` es el token HMAC del hold actual, NO residuo; `preference_id` histórico en BD preservado por datos); "falta de comunicación" **diagnosticada** (webhook MP sin historial en `notifications_history` → candidato #1: no registrado).
- [x] **Comunicación entre capas — DIP corregido (P3-1)**: constructores no-nullable en los 5 actions (`grep -rn "= null)"` → 0 matches; `CreateBookingAction.php:32`, `ProcessPaymentAction.php:29`, `GetBookingStatusAction.php:22`, `ExtendHoldAction.php:21`, `HandleMercadoPagoWebhookAction.php:30`). **Pendiente (en curso en auditoría 2026-08-05)**: formalizar reglas de dependencia ADR y centralizar manejo de errores.
- [x] **Higiene**: dead code y dependencias muertas eliminadas (commit `7685577`); `logs/app.log`, `preview.log` y `.playwright-mcp/*` sacados de git (`git rm --cached` + `.gitignore` extendido); secretos limpios (historial completo escaneado `git grep APP_USR-` → solo placeholders; `.env` gitignored y nunca commiteado); CSP unificada (sin `.htaccess` raíz; `public/.htaccess` solo nosniff; CSP única en Middleware); dependencias 0 vulnerabilidades (`composer audit` + `npm audit`).
- [ ] (Cada hallazgo de auditoría agrega un paquete con su criterio de done.)

> **Cierre F2 (2026-08-03)**: logging diagnóstico del webhook recortado a 5 `Logger::info` concisos por evento, sin headers/query/`$_SERVER` (commit `1e3226b`); verificación read-only del webhook MP ejecutada (commit `e760992`): `notifications_history` con app `8501374849722569` → "No Notifications Found"; `.env` `MERCADO_PAGO_WEBHOOK_SECRET` presente (len=64, valor nunca impreso). Quedan **user-gated** (pendientes de confirmación del usuario, no bloquean el cierre): comparar el secret del panel MP vs `.env`, confirmar el registro del webhook (`save_webhook`, callback `https://usgarhoteles.com/api/webhook`, topic `payment`), pago de prueba + monitoreo de `notifications_history`. Evidencia: `docs/refactoring/BACKEND_STATE.md` → "F2 — cerrada (2026-08-03)".

---

## Reglas del plan

- Marcar `[x]` solo cuando esté hecho y verificado (tests pasando).
- No mezclar migraciones en un mismo commit: una migración = una serie de commits `feat(migration-<nombre>):`.
- Al terminar cada bloque: actualizar README y borrar esta sección completada.
- Cualquier dependencia nueva (Laravel, Filament, SDK MercadoPago, QloApps Channel Manager) documentar versión exacta.
