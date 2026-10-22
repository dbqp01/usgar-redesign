# AGENTS.md — USGAR Hotels

Guía funcional para agentes: qué es este proyecto, dónde está cada cosa y cómo modificarlo sin romperlo. **Léela completa antes de tocar código.**

## Reglas duras (NO son opcionales)

1. **DOC-first obligatorio**: antes de escribir CUALQUIER código, busca si la solución ya existe. Orden: (a) `docs/` del repo, (b) MCPs disponibles (context7, astro-docs, hostinger), (c) web_search/web_extract. No es una decisión — es un paso del flujo. Si existe herramienta o doc, úsala.
2. **Sin sobre-ingeniería**: la solución más corta que funciona y respeta las convenciones del repo. No abstracciones especulativas, no config para valores que no cambian. Si el repo ya tiene un patrón (ADR, Ports/Adapters, envelope de errores), reúsalo — no inventes uno paralelo.
3. **No reinventar**: verifica antes de asumir. El stack está verificado en este documento y en `docs/` — lee, no adivines. Cualquier duda sobre APIs externas (MercadoPago, QloApps) se resuelve consultando la documentación oficial en línea, no inventando el contrato.
4. **Regla del usuario**: si funciona, funciona. No "mejorar" código que no está roto sin petición explícita. Mantenimiento = tocar solo lo que el ticket pide.
5. **Fuentes de verdad**: `docs/` centraliza todo. No crees archivos nuevos sin revisar si la fuente ya existe.
6. **Pensamiento secuencial** para problemas no triviales (arquitectura, debugging, decisiones).
7. **Zero hardcoding**: credenciales/URLs/precios → `.env` o BD. Jamás literales en código.
8. **No tocar generados**: `dist/`, `node_modules/`, `*.map` — se regeneran, no se editan ni se commitean. **`vendor/` es la excepción: está versionado a propósito** (en prod no hay Composer y el build lo copia a `dist/`); no se edita a mano salvo los dos parches de `patches/`. Además `vendor/` se versiona **solo con las dependencias de producción** (composer.lock → `packages`): phpstan, phpunit y resto de dev se reinstalan con `composer install` y no se commitean. Si tocas dependencias, deja el árbol de prod con `composer install --no-dev` antes de commitear; el CI lo verifica con `scripts/check-vendor-prod.php`.
9. **Commits**: pequeños, por tema, prefijo `feat:` / `fix:` / `chore:` / `docs:`. No mega-commits. NO push a main sin OK explícito del usuario.
10. **Herramientas según el alcance**: consulta la doc oficial o Context7 para APIs de librerías; navegador para cambios visuales; herramientas de BD/pagos solo al verificar esos recorridos autorizados. Si falta una herramienta necesaria, continúa el trabajo independiente y reporta qué no pudiste verificar; no declares bloqueada una tarea local por un MCP ajeno a su alcance.
11. **Duda → preguntar**: si el pedido del usuario choca con el plan de migración o la arquitectura (ver `docs/ROADMAP.md`), pregunta antes de codear.

## Qué es

Sitio web transaccional del hotel boutique Usgar (San Pedro, Cusco, Perú): reservas directas en 4 idiomas (en/es/fr/pt, default `en`), pagos en USD (cobro PEN vía MercadoPago), sincronización de inventario con PMS (QloApps).

**Stack real (verificado en código, no asumas otra cosa):**

- Frontend: **Astro 7 estático** (`output: 'static'`) + Tailwind CSS 4 (vía `@tailwindcss/vite`) + GSAP + Leaflet + Lenis. i18n routing oficial (`astro.config.mjs`: default `en`, fallback de páginas fr→en, pt→es con `fallbackType: 'redirect'`; fallback de claves de traducción en `src/i18n/utils.ts`).
- Backend: **PHP 8 nativo** (sin framework) — monolito modular ADR (Action → Domain → Ports/Adapters), DI PSR-11 (`App\Core\Container`, autowiring por Reflection), PDO/MySQL. Entry point único: `public/index.php`.
- Pagos: **MercadoPago Checkout API** (Custom Checkout, cardForm) — webhooks en `app/Features/Webhooks/`; SDK oficial `mercadopago/dx-php` v3 con parches propios (idempotency case + timeout total, ver `composer.json` extra.patches).
- PMS: **QloApps** (`QloAppAdapter`, webservice XML `https://cms.usgarhoteles.com/api` + SQL directo PDO a tablas `qlo_*`). Channel manager: **QloApps Channel Manager** (Webkul) — sin código en el repo, activación pendiente de pago (ver `docs/PMS.md` §D y `docs/ROADMAP.md`).
- Auth: JWT HS256 (firebase/php-jwt) en cookie HttpOnly (`SessionService`) + OAuth social vía hybridauth 3 (Google/Microsoft/Facebook, solo si hay credenciales en `.env`).
- Deploy: **Hostinger compartido, integración Node.js GitHub** — push a `main` → pull + `npm install` + `npm run build` → sirve `dist/`. Sin FTP, sin rama `build`, sin Composer en prod (el build copia `vendor/` a `dist/`). Detalle: `docs/DEPLOYMENT.md`.

## Fuentes de verdad (docs centralizadas)

Toda la documentación vive en `docs/` — no crees archivos nuevos sin revisar si ya existe la fuente:

| Archivo | Contenido | Cuándo actualizar |
|---|---|---|
| `docs/ARCHITECTURE.md` | Capas, zonas de riesgo, flujo de reserva completo, multi-hotel | Al cambiar la estructura o el flujo |
| `docs/API.md` | Contrato completo de la API (33 endpoints), envelope de errores, env vars | Al tocar `public/index.php` o un Action |
| `docs/DEPLOYMENT.md` | Deploy Hostinger, cron jobs, hooks git, secretos | Al cambiar build/deploy/crons |
| `docs/PMS.md` | Operación QloApps: tutorial del dueño, inventario (BLOQUEANTE), tarifa no reembolsable, webservice, channel manager, Postman | Al tocar la integración PMS |
| `docs/SECURITY.md` | Modelo de seguridad verificado + pendientes | Al cambiar auth/webhooks/headers |
| `docs/ROADMAP.md` | **Solo lo que sigue abierto**: pendientes del dueño, deuda técnica, activación del channel manager | Al cerrar un ítem (bórralo de aquí) |
| `docs/STATE.md` | **Estado actual del backend**: qué está desplegado y verificado, decisiones vigentes, pendiente real | Al cerrar un trabajo de backend |
| `docs/DECISIONS.md` | Decisiones de arquitectura vivas | Al tomar una decisión |
| `docs/README.md` | Índice de la documentación: qué leer para qué + fechas de verificación | Al crear/mover un doc |
| `docs/archive/` | **Histórico**: bitácora de sesiones y diagnósticos ya resueltos. No describe el sistema actual | Al cerrar un diagnóstico largo |
| `docs/specs/` | **Specs SDD**: requisitos EARS por feature (fuente de verdad del comportamiento). `docs/specs/README.md` = plantilla + reglas + índice | **ANTES de codear** una feature nueva; en el mismo commit si cambia un comportamiento |

**Regla de la documentación:** los docs vigentes describen **el estado actual**, no
la historia. Lo que se intentó, falló o se diagnosticó con fecha va a
`docs/archive/`, no a `STATE.md`/`PMS.md`. Si un doc contradice al código,
**manda el código** y se corrige el doc en el mismo commit.

## Estructura

```
src/                          Frontend Astro
  pages/                      index, rooms, rooms/[slug], book, book/success, explore,
                              explore/[slug], gallery, contact, login, my-bookings,
                              profile, panel, privacy, terms, 404 + copias es/ fr/ pt/
  components/                 UI por dominio: booking/ (wizard 3 pasos: fechas+habitación,
                              huéspedes, pago), navbar/, room/, contact/, profile/,
                              common/ (Preloader, CustomCursor…), ui/, icons/ + secciones
                              de landing (Hero, Footer, Gallery…)
  features/                   Lógica TS: booking/ (wizardStore, calendar, roomAllocator,
                              bookingStatus), animations/ (engine + módulos GSAP), ui/toast
  services/                   bookingService.ts (única capa de fetch al /api)
  utils/                      auth-client, paymentErrors/Status, holdCountdown, csrfToken,
                              getUser, logout, format, helpers, debounce, ogImage
  i18n/                       en.json es.json fr.json pt.json + utils.ts (fallbackChain)
  content/                    Datos del sitio, un JSON por dominio (about, explore, faq,
                              reviews, rooms, services, settings)
  data/                       Carga tipada sobre esas colecciones (getCollection /
                              getEntry) + lista propia (legal)
  assets/                     Imágenes y fuentes que importa Astro
  styles/                     global.css (Tailwind v4, CSS-first)
  layouts/                    Layout.astro (único)
app/                          Backend PHP
  Core/                       Request, Response, Database (PDO), Container (PSR-11), Validator,
                              Middleware, Config, Logger, HttpException, Router, RateLimiter,
                              BookingStatus, BookingHoldToken, PriceCalculator, Events/
  Features/<Feature>/         Módulos: Auth, Booking, Contact, Cron, Health, Newsletter,
                              Panel, Reviews, Rooms, Shared, Webhooks
    Actions/                  Clases ADR invocables (ruta → action)
    Domain/                   Repositorios (ProvisionalBookingRepository), Listeners
                              (ConfirmQloAppsOrderListener), Events (BookingPaidEvent)
    Shared/                   Adapters/ (QloApp, MercadoPago) + Ports/ (interfaces) +
                              DiscountResolver, RoomTypeRegistry
public/                       index.php (front controller + registro de rutas), .htaccess,
                              assets/ (fotos de habitaciones), favicon, robots.txt, og-image
cron/                         process_outbox.php, reconcile_payments.php, cleanup.php
                              (CLI, invocan actions)
scripts/                      dev.js (dev:all), tests, auditorías (seguridad/SEO/CSP), sync stock
tests/                        api-harness.php (contrato booking) + phpunit (Unit/) + e2e/ (Playwright)
docs/                         Documentación centralizada (ver tabla de fuentes)
```

## Cómo modificar (procedimiento estándar)

### Frontend (Astro)
1. Cambios de texto: `src/i18n/<locale>.json` — SIEMPRE las 4 locales (fr y pt caen a en/es vía fallback).
2. Datos de negocio (habitaciones, servicios): edita el JSON de `src/content/<dominio>/`; los componentes los reciben ya tipados por `src/data/<dominio>.ts` (cargador sobre la colección). El catálogo de servicios es un solo JSON: `group: "hotel" | "room"` separa las dos listas (`hotelServices`, `roomServices`) y `src/data/services.ts` las deriva. Excepción (lista propia, no derivada de una colección): `src/data/legal.ts` (texto legal en/es).
3. UI: componentes en `src/components`; estilos Tailwind v4 (CSS-first, sin config v3).
4. Validar con `npm run check` (astro check + TS).

### Backend (PHP ADR) — PATRÓN OBLIGATORIO
1. **Nunca instancies dependencias dentro de una action**: recíbelas por constructor (el DI `Container` las inyecta).
2. **Acceso a externos SIEMPRE vía Ports/Adapters**: usa `PmsPortInterface` / `PaymentGatewayPortInterface`, nunca el adapter directo.
3. Nueva feature = nuevo `app/Features/<Nombre>/Actions/<Verbo><Sustantivo>Action.php` + ruta en `public/index.php` + actualizar `docs/API.md`.
4. SQL: preparado (PDO), nunca concatenado. Inputs validados con `App\Core\Validator`.
5. Errores: `HttpException` con códigos HTTP; en producción no exponer stack traces.
6. Estado del backend y decisiones: actualizar `docs/STATE.md` / `docs/DECISIONS.md`.

### Base de datos
- MySQL vía PDO; las consultas a QloApps pasan por `QloAppAdapter` (schema PrestaShop: `qlo_*`).
- Tablas propias que se auto-crean: `provisional_bookings`, `event_outbox`, `users`, `newsletter_subscribers`, `processed_payments`, `room_locks` (ver `ensureTablesExist` en repos).
- Cambios de schema: revisar si QloApps lo gestiona; no alterar tablas de QloApps directamente sin confirmar.

## Tests y verificación (antes de declarar "listo")

Elige checks según los archivos y contratos afectados. Una revisión superficial no exige auditar toda la aplicación ni contactar servicios externos.

| Cambio | Verificación |
|---|---|
| Helpers/estado/i18n TypeScript o sus tests | `npm run test:unit` (Vitest sin watch) + `npm run check` |
| Componentes, estilos o configuración Astro | `npm run check` + `npm run build`; E2E focalizado si cambia comportamiento de usuario |
| Backend PHP | PHPUnit focalizado + `composer analyse`; contratos HTTP cuando cambie una ruta/action |
| Endpoints/SEO | Auditoría existente correspondiente, acotada al cambio |
| Solo documentación | Verificar comandos/rutas contra manifiestos y código, sin instalar ni ejecutar toda la suite |

`npm test` conserva el chequeo Astro y la integración PHP, y añade Vitest. El CI (`.github/workflows/build.yml`) corre Vitest, PHPUnit, la integración HTTP, PHPStan, `astro check`, el build y las auditorías (seguridad, SEO/schema, contenido, CSP); no omitas una suite para ocultar un fallo ni elimines aserciones para obtener verde.

**Aislamiento primero:** `composer test` descubre todo `tests/Unit`, incluidos casos que cargan `.env`, consultan pagos sandbox y escriben en BD. Los harness (`api-harness`, `user-flow`, workers y stress tests) requieren revisar fixtures/destino y un entorno dedicado. `npm run test:php` sí está aislado: arranca su servidor con un `.env` de pruebas sin credenciales (`ENV_FILE`, honored solo en dev/CLI), así que no toca la BD, Mercado Pago ni SMTP — el propio script lo verifica (`database: offline` y 503 sin PMS). El nombre Unit, `PHP_TESTING` o un token `TEST-` no acreditan aislamiento. Para una tarea local usa casos sin servicios externos; pruebas que mutan BD/PMS/pagos requieren autorización específica y destino de pruebas verificado. Nunca cargues credenciales de producción para pasarlas.

Reporta los comandos ejecutados, resultados y lo que quedó sin verificar. Un build, un grafo estático o mocks no prueban el flujo real de pagos.

Playwright usa la configuración versionada y `npm run test:e2e -- <spec>` para casos focalizados; `--list` no debe descubrir Vitest. Su servidor es Astro local en :4321; los specs con API sin mocks necesitan PHP local de pruebas además del frontend.

## Deploy (Hostinger) — SOLO rama main, build automático

- **Hostinger mira ÚNICAMENTE la rama `main`** (integración Node.js GitHub; docs oficiales: `docs.hostinger.com/node.js/github`). Cada `git push origin main` → Hostinger hace pull + `npm install` + `npm run build` y sirve `dist/` (config hPanel: branch `main` · build `build` · output `dist` · Node 22).
- Solo existe un workflow: `.github/workflows/build.yml` (CI). La rama remota `origin/build` tiene contenido viejo, pero **Hostinger no la usa**: el deploy es `main` → pull + build.
- Nunca sugerir subir `dist/` por FTP/File Manager ni hablar de la rama `build`. Único flujo: commit → `git push origin main` → esperar el build de Hostinger → https://usgarhoteles.com refleja el cambio.
- Env vars de prod: hPanel → **Despliegues → Ajustes → Variables de entorno → «Guardar»** (escribe `hbuilds/config/.env` en la raíz del dominio; el efecto es inmediato, sin redeploy). La página «Sitios web → Variables de entorno» **no** reescribe el archivo. En producción `Config::envCandidates` lee **solo** ese archivo (sin fallback a la raíz ni a `public_html`), así que rotar claves es editar la tabla y «Guardar». El `.env` **no** va en `dist/` (el postbuild lo elimina). Detalle: `docs/DEPLOYMENT.md`.
- El hook `pre-commit` comprime `.mp4`/imágenes staged (requiere ffmpeg + `scripts/compress-images.js`; ver `docs/DEPLOYMENT.md` §Hooks).

## Principios de ingeniería (reglas de senior dev)

Aplicar en orden; detente en la primera que aplique. Se suavizan a proposito las
que en su version original casi borraban una tabla de produccion del autor.

1. **Sin capas de compatibilidad especulativas**: borra caminos obsoletos directo, pero NUNCA sin antes verificar dependencias y con `git` limpio y reversible. No añadas migraciones/fallbacks "por si acaso". Cambios de schema: coordinar con QloApps (ver sección BD).
2. **Build the thinnest end-to-end slice first**: un corte vertical mínimo funcionando antes que infraestructura; nunca reemplaces código que funciona con complejidad a medias.
3. **Componentes modulares**: separación de concerns (el ADR del proyecto ya lo impone: Action → Domain → Ports/Adapters).
4. **Preferir librerías maduras**: no reescribas lo que una librería estable ya resuelve (compatible con "dependencia mínima" del deploy Hostinger).
5. **Revisar dependencias existentes** antes de añadir paquetes nuevos: revisa `composer.json`/`package.json` y qué ya importa el código antes de instalar algo.
6. **Decisiones de arquitectura a largo plazo**: rechaza hacks "lo cambiamos luego" para cosas que cruzan capas o la BD; documenta en `docs/DECISIONS.md` (patrón ADR del proyecto).
7. **Estudia cómo lo resolvieron productos maduros**: usa patrones probados (PrestaShop/QloApps, estándares del sector) antes de inventar.