# STATE — Backend USGAR (estado actual)

> **Última verificación: 2026-10-20 11:30–12:45 y 15:50–16:15, contra producción.**
> Este archivo describe **lo que el sistema es hoy**, no lo que se hizo. La
> bitácora de sesiones (2026-08 → 2026-10) está en
> [`docs/archive/state-log-2026-08-10.md`](archive/state-log-2026-08-10.md).
>
> **Regla de lectura:** si un documento contradice al código, **manda el
> código**; y si el código cambió de comportamiento, el doc se corrige en el
> mismo commit. Los documentos con fecha dentro del texto son histórico: para
> eso está `docs/archive/`.

## Qué está desplegado y verificado

### Flujo de reserva con pago (camino crítico)

1. `POST /api/booking` → hold local (`provisional_bookings`, TTL 15 min) + hold
   del PMS vía el webservice de QloApps (crea el carrito y **bloquea la
   habitación física** mientras el huésped paga). El `cart_id` que ve la web
   **es** el `id_order` de ese booking del webservice.
2. `POST /api/process-payment` → Mercado Pago (precio en USD, cobro en PEN).
3. Webhook firmado de MP → `processed_payments` → outbox `booking.paid` → cron
   `process_outbox` → `confirmOrder()`, que:
   - crea la orden pagada en QloApps (`current_state=2`) **con** su fila en
     `qlo_order_payment` y `total_paid_real` (sin ella el back-office muestra
     «Total pagado S/. 0»);
   - **hereda la habitación física** del hold y **libera** el hold
     (`is_refunded=1`, `is_cancelled=1`, orden del hold a «Cancelado»);
   - manda el email de confirmación (solo si hay SMTP configurado).
4. Si el huésped abandona el checkout: el cron `cleanup` marca `expired` el hold
   local y libera en el PMS la reserva pendiente (misma operación que el paso 3).

**E2E verificado en producción el 2026-10-20:** hold 292 → pago `approved`
(payment_id 1328489214) → webhook → outbox `COMPLETED` → orden **293** pagada
(S/. 660.67) con su fila en `qlo_order_payment`, booking con `id_room`
heredado y hold liberado. Datos crudos y trazas:
[`docs/archive/state-log-2026-08-10.md`](archive/state-log-2026-08-10.md).

### Entorno y despliegue

- **Deploy**: push a `main` → integración Node.js de Hostinger (pull +
  `npm install` + `npm run build`, salida `dist/`). Solo `main`; el build
  publica en 3–4 min.
- **Dependencias PHP**: en prod no hay Composer, así que `vendor/` viaja
  versionado — pero **solo con las dependencias de producción** (9,9 MB de los
  68 MB que eran antes; las 58 MB de phpstan/phpunit no se commitean). El
  autoloader versionado está generado en `--no-dev` y el CI lo verifica antes
  de instalar (`scripts/check-vendor-prod.php`), porque con dev el sitio
  muere al arrancar.
- **Variables de entorno**: **hPanel es la única fuente** → Despliegues →
  Ajustes → Variables de entorno → «Guardar» escribe `hbuilds/config/.env` (la
  app lo lee). El `.env` de la raíz del dominio está renombrado a
  `.env.pre-hpanel-2026-10-19` (rollback). La página «Sitos web → Variables de
  entorno» **no** reescribe el archivo: no sirve.
- **Crons en producción (solo estos 5)**: `process_outbox` (5 min),
  `reconcile_payments` (15 min), `cleanup` (10 min), `manual-review` (20 min),
  `refresh-reviews` (03:00).
- **PHP 8.4** en el sitio y en el CMS, con las opciones del CMS en **defaults**
  (`display_errors=Off`, `log_errors=Off`).

### Estado verificado el 2026-10-20

| Comprobación | Resultado |
|---|---|
| PHPUnit (`tests/Unit`) | 342 tests · 1020 aserciones · 38 skipped · 0 fallos |
| PHPStan (nivel 7) | `ok` |
| Vitest (`npm run test:unit`) | 11 archivos · 77 tests · 0 fallos |
| `astro check` | 0 errores · 0 avisos |
| Auditoría SEO / contenido / CSP | 123 páginas, 0 errores de schema · 121 páginas, 0 errores · CSP 114/114 sin violaciones |
| Build de Hostinger (commit `a87fc65`, con `vendor/` solo de producción) | `completed` (3 min 34 s) |
| CI (`.github/workflows/build.yml`) | `success` — incluye el guard de `vendor/` |
| `GET /api/health` | `healthy` · `database: online` |
| `GET /api/rooms` 20→22 nov | **8/8** en Doble Superior (holds caducados liberados) |
| `GET /api/rooms` 25→28 nov | **6/8** (dos reservas pagadas en ese rango, una por habitación) |
| `GET /api/reviews-score` | `kayak 8.6 / 701` (cron ejecutado a mano, scores reales) |
| E2E de pago en producción | hold 292 → `approved` → orden 293 pagada |
| `composer audit` | sin avisos de seguridad |

## Decisiones vigentes

- **PMS**: QloApps 1.7.0 se mantiene (migración a Filament cancelada).
- **Pagos**: Mercado Pago Checkout API es la única pasarela.
- **Moneda**: precio en USD, cobro en PEN (tasa de `Config`).
- **Credenciales de MP en producción**: hoy es el par **sandbox** (decisión del
  dueño: tarjetas de prueba reales contra el sitio). Cambiar a las reales
  antes de cobrar de verdad (procedimiento en `docs/DEPLOYMENT.md`).
- **QloApps Channel Manager (Webkul)**: sin código en el repo; activación de
  pago pendiente (runbook en `docs/PMS.md` §D).

## Pendiente real (a 2026-10-20)

| Qué | Quién | Por qué / dónde |
|---|---|---|
| Reembolsar en el back-office de QloApps las órdenes de prueba **266, 268, 287, 289 y 293** | dueño | 289 y 293 tienen S/. 660.67 capturados. **No por SQL** (regla 1 de `docs/PMS.md` §F) |
| Rotar `PANEL_PASSWORD` | dueño | Tiene hoy el **mismo valor** que `DB_PASS` |
| Pasar MP a credenciales reales | dueño | Solo cuando se vaya a cobrar de verdad |
| Configurar SMTP (`SMTP_*`) | dueño | Sin SMTP no sale el email de confirmación (fail-soft por diseño) |
| Activar QloApps Channel Manager | dueño | Activación de pago (Webkul) |

## Deuda técnica abierta

- **P2-7**: `Response::json()` hace `exit()` dentro de la clase → moverlo al
  front controller. Diferido: ~40 call sites asumen que la respuesta termina el
  script; tocarlo sin refactorizar las acciones es una regresión.
- **Compensación del carrito de QloApps** si el INSERT local falla después de
  crear el carrito remoto: el contrato de cancelación no está verificado.
- **Rutas i18n `/fr` vs `/fr/`**: Astro emite un warning no fatal; falta
  decidir una única estrategia.
- **`run-exhaustive-tests.php`** sigue golpeando servicios reales (MP, BD
  remota) si se amplían los tests de integración: hay que mockear los adapters.
- **Cancelaciones por refund/chargeback** siguen siendo manuales.
- `provisional_bookings` conserva la columna legacy `preference_id` (52
  registros históricos): no se borra, por preservación de datos.

## Limitaciones conocidas

- Dos tests son flaky en Windows (`ProcessOutboxActionTest::
  testConcurrentRunsProcessEachEventExactlyOnce` y
  `testRunWithSlowListenersCompletesAllEvents`): workers `proc_open` + timing.
  Tratar como limitación de entorno, no como regresión.
- La suite unitaria no cubre pagos, PMS ni concurrencia real: eso exige un
  destino de pruebas verificado (ver `AGENTS.md`).
- El CMS comparte versión de PHP con el sitio: 8.4 es el máximo soportado por
  QloApps 1.7.0 (8.5 queda fuera).
