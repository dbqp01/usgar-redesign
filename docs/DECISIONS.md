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
- **JWT HMAC-SHA256** en cookie HttpOnly mediante `firebase/php-jwt`; algoritmo fijado, validación de firma/expiración, claims `iss`/`aud`/`jti` y revocación server-side por `jti` mediante `TokenRevocationStore`.

## Frontend (2026-08-01)
- **Astro 7 estático + i18n routing oficial** (default `en`, fallback de páginas fr→en, pt→es; fallback de claves en `src/i18n/utils.ts`). Sin SSR — el contenido dinámico vive en la API PHP.
- **Wizard de reserva en 3 pasos** (Fechas + habitación → Huéspedes → Pago) con store TS puro (`WizardStep = 1 | 2 | 3`) y transiciones GSAP; pago MP intacto (createHold → cardForm → processPayment).
- **Modo claro = referencia visual**; dark mode solo ajusta el morado lavanda, nunca los fondos (#121214/#1A1A1E intocables).
- **Capacidades +1 persona con $30/noche**: `baseOccupancy` + `maxCapacity` + `extraGuestCharge` (doble 3, matri 3, triple 4, familiar 8).
- **Algoritmo de habitaciones**: individual + combinaciones multi-habitación, orden por precio, top 3-4, badge "Mejor precio" (`src/features/booking/roomAllocator.ts`). El backend soporta UN roomSlug por hold → solo opciones de 1 habitación son pagables online.
- **Mapa SVG ilustrado hecho a mano** (evita look AI) — reemplaza Leaflet en la home (perf -145 KB). Leaflet sigue para el mapa de ubicación.
- **Calendario custom** (mismo motor `calendar.ts`): build una vez + refresh in-place, sin re-render por clic.
- **Inventario de display**: `roomInventory` en `settings.json` con fallback en `src/data/settings.ts` (el stock real lo da la BD QloApps).
- **Un solo catálogo de servicios**: `src/content/services/services.json` es el dato (35 ítems, cada uno con `group: "hotel" | "room"`); `src/data/services.ts` lo expone tipado y de ahí se derivan las dos listas de la web (servicios del hotel / en la habitación) y las tarjetas destacadas. Antes el mismo catálogo estaba duplicado en `src/data/hotelServices.ts` con otros nombres y una línea repetida ("24-hour reception" dos veces).
- **Estrategia de rutas i18n**: las URLs canónicas llevan barra final (`/fr/`, `/es/`, `/pt/`), que es lo que publica el sitemap y lo que responde 200; `/fr` responde 301 a `/fr/`. Las páginas que faltan en un locale caen al idioma de fallback (fr→en, pt→es) con una página de redirección (`noindex`). El aviso de Astro al prerenderizar la raíz de un locale con fallback es benigno —gana la ruta de archivo, que es la correcta— y se silencia con `prerenderConflictBehavior: 'ignore'` en `astro.config.mjs`.

## Operación
- **Deploy**: solo rama `main` con integración Node.js de Hostinger (build en servidor). Prohibido FTP/File Manager/rama build (histórico DEPLOY-B2 desactivado).
- **`vendor/` se versiona, pero solo con las dependencias de producción** (composer.lock → `packages`). En prod no hay Composer, así que el build copia `vendor/` a `dist/`; versionar las herramientas de desarrollo (phpstan, phpunit: 58 MB) solo engordaría el repo y el deploy. El autoloader versionado se genera en modo `--no-dev` y `scripts/check-vendor-prod.php` (con paso en el CI) verifica que resuelva contra archivos presentes: con dev, el autoloader incluye phpstan/phpunit en "files" y el sitio muere al arrancar.
- **Cron**: `cron/process_outbox.php` (5 min) + `cron/reconcile_payments.php` (15 min) — nunca mover su lógica a la petición HTTP.
- **Hooks git**: `core.hooksPath=.githooks`; pre-commit comprime media y nunca bloquea. Commits por tema con prefijo `feat:`/`fix:`/`chore:`/`docs:`.

## Contratos HTTP y ciclo de vida (2026-09-09)
- `Response::fromHttpException()` es el punto único para convertir excepciones HTTP en el envelope estándar; el `Router` lo usa junto con los helpers `Response::*` para conservar códigos y detalles de forma uniforme.
- Las peticiones frontend que dependen de la página (disponibilidad, estado de pago, payment-check, hold y proceso de pago) reciben un `AbortSignal` combinado con timeout. Los módulos que reparentan DOM (lightbox) eliminan sus nodos y listeners en `astro:before-preparation`.
- El hero no precarga clips completos mediante `fetch`; cada vídeo comienza cuando el elemento lo necesita, evitando descargas de varios MB durante navegación o en móvil.

## Seguridad operativa (2026-09-07)
- Las mutaciones del panel usan la cookie usgar_panel_csrf y el header X-CSRF-Token coincidente.
- event_outbox usa claim_token para fencing: un lease obsoleto no puede completar ni fallar el claim de otro worker.

## Newsletter (2026-10-29)
- **Doble opt-in con consentimiento explícito**: `POST /api/newsletter` exige `consent === true`, guarda evidencia (IP, user-agent, locale, `consent_at`, `consent_version`) y no da de alta hasta que se abre el enlace firmado del correo. Un tercero que escriba el email de otra persona no puede suscribirla.
- **Baja en dos pasos**: el enlace del correo abre una confirmación (GET sin mutación, seguro frente a prefetch y escáneres) y el POST aplica la baja. Tokens HMAC separados por propósito y ligados al email (mismo `BOOKING_TOKEN_SECRET`, dominio `newsletter:`).
- **Respuesta anti-enumeración**: alta nueva y email ya confirmado responden igual; el email no se refleja en la respuesta ni en los logs.
- **Suscriptores históricos**: la auto-migración los conserva `confirmed` con `consent_version='legacy'` y `consent_at NULL`; no se fabrica evidencia de consentimiento.
