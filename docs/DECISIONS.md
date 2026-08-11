# DECISIONS — USGAR Hotels

Registro de decisiones de arquitectura vivas (las que siguen gobernando el código). Histórico recuperable en git. Última actualización: 2026-08-11.

## Pagos y PMS (2026-08-04/05)
- **MercadoPago es la pasarela única** (Checkout API / Custom Checkout, webhooks con firma HMAC). Stripe + LLC descartados definitivamente (costo ~$900/año vs MP ~4% efectivo sin setup; ver análisis en git histórico). No reevaluar.
- **QloApps se mantiene como CMS/PMS**. Cancelada la migración a Filament PHP (2026-08-04); panel eliminado del repo y del subdominio (2026-08-05). El stack se simplifica: `QloAppAdapter`, schema PrestaShop y flujo de reservas actuales se conservan.
- **Channel manager = QloApps Channel Manager (Webkul)** ($30/propiedad/mes, conexiones Booking/Expedia/Airbnb incluidas). Sin código en el repo: módulo del lado PMS, sync vía webservice `cm_api`. Activación pendiente de pago. Runbook: `docs/PMS.md` §D.

## Backend (2026-08-01/03)
- **Monolito ADR en PHP 8 nativo** (sin framework): Action → Domain → Ports/Adapters, DI PSR-11 con autowiring, PDO/MySQL. Se mantiene mientras el PMS sea QloApps.
- **Transaccionalidad de pagos**: el cobro ocurre dentro de la transacción del lock (`FOR UPDATE`) — la ventana "commit-antes-de-cobrar" queda eliminada. Eventos de dominio vía **outbox transaccional** (`event_outbox`) con máquina de estados de reclaim/backoff/lease; nunca se pierde una confirmación de PMS.
- **El cliente nunca envía precios**: el backend recalcula con fuente única (QloApps) y congela precio + tipo de cambio en el hold (snapshot PEN).
- **Guard de entorno por allowlist de hashes** (no por prefijo de token): el prefijo `APP_USR`/`TEST-` no define entorno en MercadoPago.
- **Modelo de entrada**: validar input (Validator), escapar output (frontend). Nunca re-escapar la entrada (fix 2026-08-01 que eliminó el doble-escape que corrompía contraseñas).
- **JWT propio HMAC-SHA256** en cookie HttpOnly (sin dependencia de librería; algoritmo fijado, `hash_equals`, exp). Aceptado mientras no se necesite revocación server-side (ver `docs/ROADMAP.md` P1-7).

## Frontend (2026-08-01)
- **Astro 7 estático + i18n routing oficial** (default `en`, fallback de páginas fr→en, pt→es; fallback de claves en `src/i18n/utils.ts`). Sin SSR — el contenido dinámico vive en la API PHP.
- **Wizard de reserva en 3 pasos** (Guest → Allocación → Pago) con store TS puro y transiciones GSAP; pago MP intacto (createHold → cardForm → processPayment).
- **Modo claro = referencia visual**; dark mode solo ajusta el morado lavanda, nunca los fondos (#121214/#1A1A1E intocables).
- **Capacidades +1 persona con $30/noche**: `baseOccupancy` + `maxCapacity` + `extraGuestCharge` (doble 3, matri 3, triple 4, familiar 8).
- **Algoritmo de habitaciones**: individual + combinaciones multi-habitación, orden por precio, top 3-4, badge "Mejor precio" (`src/features/booking/roomAllocator.ts`). El backend soporta UN roomSlug por hold → solo opciones de 1 habitación son pagables online.
- **Mapa SVG ilustrado hecho a mano** (evita look AI) — reemplaza Leaflet en la home (perf -145 KB). Leaflet sigue para el mapa de ubicación.
- **Calendario custom** (mismo motor `calendar.ts`): build una vez + refresh in-place, sin re-render por clic.
- **Inventario de display**: `roomInventory` en `settings.json` con fallback en `src/data/settings.ts` (el stock real lo da la BD QloApps).

## Operación
- **Deploy**: solo rama `main` con integración Node.js de Hostinger (build en servidor). Prohibido FTP/File Manager/rama build (histórico DEPLOY-B2 desactivado).
- **Cron**: `cron/process_outbox.php` (5 min) + `cron/reconcile_payments.php` (10 min) — nunca mover su lógica a la petición HTTP.
- **Hooks git**: `core.hooksPath=.githooks`; pre-commit comprime media y nunca bloquea. Commits por tema con prefijo `feat:`/`fix:`/`chore:`/`docs:`.
