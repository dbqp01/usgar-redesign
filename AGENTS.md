# AGENTS.md — USGAR Hotels

Guía funcional para agentes: qué es este proyecto, dónde está cada cosa y cómo modificarlo sin romperlo. **Léela completa antes de tocar código.**

## Qué es

Sitio web transaccional del hotel boutique Usgar (San Pedro, Cusco, Perú): reservas directas en 4 idiomas (en/es/fr/pt, default `en`), pagos en USD (cobro PEN vía MercadoPago), sincronización de inventario con PMS (QloApps).

**Stack real (verificado en código, no asumas otra cosa):**

- Frontend: **Astro 7 estático** (`output: 'static'`) + Tailwind CSS 4 (vía `@tailwindcss/vite`) + GSAP + Leaflet + Lenis. i18n routing oficial (`astro.config.mjs`: default `en`, fallback de páginas fr→en, pt→es con `fallbackType: 'redirect'`; fallback de claves de traducción en `src/i18n/utils.ts`).
- Backend: **PHP 8 nativo** (sin framework) — monolito modular ADR (Action → Domain → Ports/Adapters), DI PSR-11 (`App\Core\Container`, autowiring por Reflection), PDO/MySQL. Entry point único: `public/index.php`.
- Pagos: **MercadoPago Checkout API** (Custom Checkout, cardForm) — webhooks en `app/Features/Webhooks/`; SDK oficial `mercadopago/dx-php` v3 con parches propios (idempotency case + timeout total, ver `composer.json` extra.patches).
- PMS: **QloApps** (`QloAppAdapter`, webservice XML `https://cms.usgarhoteles.com/api` + SQL directo PDO a tablas `qlo_*`). Channel manager: **QloApps Channel Manager** (Webkul) — sin código en el repo, activación pendiente de pago (ver `docs/PMS.md` §D y `docs/ROADMAP.md`).
- Auth: JWT propio HMAC-SHA256 en cookie HttpOnly (`SessionService`) + OAuth social vía hybridauth 3 (Google/Microsoft/Facebook, solo si hay credenciales en `.env`).
- Deploy: **Hostinger compartido, integración Node.js GitHub** — push a `main` → pull + `npm install` + `npm run build` → sirve `dist/`. Sin FTP, sin rama `build`, sin Composer en prod (el build copia `vendor/` a `dist/`). Detalle: `docs/DEPLOYMENT.md`.

## Fuentes de verdad (docs centralizadas)

Toda la documentación vive en `docs/` — no crees archivos nuevos sin revisar si ya existe la fuente:

| Archivo | Contenido | Cuándo actualizar |
|---|---|---|
| `docs/ARCHITECTURE.md` | Capas, zonas de riesgo, flujo de reserva completo, multi-hotel | Al cambiar la estructura o el flujo |
| `docs/API.md` | Contrato completo de la API (23 endpoints), envelope de errores, env vars | Al tocar `public/index.php` o un Action |
| `docs/DEPLOYMENT.md` | Deploy Hostinger, cron jobs, hooks git, secretos | Al cambiar build/deploy/crons |
| `docs/PMS.md` | Operación QloApps: tutorial del dueño, inventario (BLOQUEANTE), tarifa no reembolsable, webservice, channel manager, Postman | Al tocar la integración PMS |
| `docs/SECURITY.md` | Modelo de seguridad verificado + pendientes | Al cambiar auth/webhooks/headers |
| `docs/ROADMAP.md` | Estado de migraciones + backlog priorizado de mejoras (P1-P3) | Al cerrar un ítem |
| `docs/STATE.md` | Handoff/estado del backend (auditorías, evidencia) | Al cerrar un trabajo de backend |
| `docs/DECISIONS.md` | Decisiones de arquitectura vivas | Al tomar una decisión |

## Estructura

```
src/                          Frontend Astro
  pages/                      Rutas públicas: index, rooms, rooms/[slug], book.astro,
                              book/success, explore, explore/[slug], gallery, contact,
                              login, my-bookings, profile + copias es/ fr/ pt/
  components/                 UI por dominio: booking/ (wizard 3 pasos), navbar/, room/,
                              contact/, profile/, common/ (Preloader, CustomCursor…), ui/
  features/                   Lógica TS: booking/ (wizardStore, calendar, roomAllocator),
                              animations/ (engine + módulos GSAP), ui/toast
  services/                   bookingService.ts (única capa de fetch al /api)
  i18n/                       en.json es.json fr.json pt.json + utils.ts (fallbackChain)
  content/ + data/            Contenido duplicado por dominio (rooms, services, faq…):
                              verifica CUÁL consume el componente antes de editar
  layouts/                    Layout.astro (único)
app/                          Backend PHP
  Core/                       Request, Response, Database (PDO), Container (PSR-11), Validator,
                              Middleware, Config, Logger, HttpException, Router, RateLimiter,
                              BookingStatus, BookingHoldToken, PriceCalculator, Events/
  Features/<Feature>/         Módulos: Auth, Booking, Contact, Cron, Health, Newsletter,
                              Rooms, Shared, Webhooks
    Actions/                  Clases ADR invocables (ruta → action)
    Domain/                   Repositorios (ProvisionalBookingRepository), Listeners
                              (ConfirmQloAppsOrderListener), Events (BookingPaidEvent)
    Shared/                   Adapters/ (QloApp, MercadoPago) + Ports/ (interfaces) +
                              DiscountResolver, RoomTypeRegistry
public/                       index.php (front controller + registro de rutas) + .htaccess
cron/                         process_outbox.php, reconcile_payments.php (CLI, invocan actions)
scripts/                      dev.js (dev:all), tests, auditorías (seguridad/SEO), sync stock
tests/                        api-harness.php (contrato booking) + phpunit (Unit/) + e2e/ (Playwright)
docs/                         Documentación centralizada (ver tabla de fuentes)
```

## Cómo modificar (procedimiento estándar)

### Frontend (Astro)
1. Cambios de texto: `src/i18n/<locale>.json` — SIEMPRE las 4 locales (fr y pt caen a en/es vía fallback).
2. Datos de negocio (habitaciones, servicios): `src/content/*.json` o `src/data/*.ts` — revisa cuál consume el componente (duplicación conocida, ver `docs/ROADMAP.md` F-6).
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

1. `npm run check` — types + astro.
2. `npm run test:php` (= `php scripts/run-exhaustive-tests.php`) — suite exhaustiva de integración + unit del backend.
3. `php tests/api-harness.php` — contrato del flujo de reserva vía curl (`POST /api/booking`, casos de validación).
4. `npm run audit:security` + `npm run audit:seo` si tocas endpoints/SEO.
5. E2E: `npx playwright test` si el cambio toca flujos de usuario (book, login).
6. PHPStan: `composer analyse` (nivel 6, baseline en `phpstan-baseline.neon`).

## Deploy (Hostinger) — SOLO rama main, build automático

- **Hostinger mira ÚNICAMENTE la rama `main`** (integración Node.js GitHub; docs oficiales: `docs.hostinger.com/node.js/github`). Cada `git push origin main` → Hostinger hace pull + `npm install` + `npm run build` y sirve `dist/` (config hPanel: branch `main` · build `build` · output `dist` · Node 22).
- El workflow `.github/workflows/deploy-build-branch.yml` está **DISABLED** (plan DEPLOY-B2 nunca activado) — ignorarlo. La rama remota `origin/build` existe con contenido viejo pero **Hostinger no la usa**.
- Nunca sugerir subir `dist/` por FTP/File Manager ni hablar de la rama `build`. Único flujo: commit → `git push origin main` → esperar el build de Hostinger → https://usgarhoteles.com refleja el cambio.
- Env vars de prod: hPanel → Environment variables (escribe `.builds/config/.env`); fallback `.env` un nivel arriba de `public_html` (`app/Core/Config.php::loadEnv`). El `.env` **no** va en `dist/` (el postbuild lo elimina).
- El hook `pre-commit` comprime `.mp4`/imágenes staged (requiere ffmpeg + `scripts/compress-images.js`; ver `docs/DEPLOYMENT.md` §Hooks).

## Reglas imperativas

- **Docs-first**: antes de escribir código con una librería, consulta su documentación (Astro → `astro-docs`, librerías → `context7`, patrones → `tavily`). No asumas APIs de memoria.
- **Pensamiento secuencial** para problemas no triviales (arquitectura, debugging, decisiones).
- **Zero hardcoding**: credenciales/URLs/precios → `.env` o BD. Jamás literales en código.
- **No tocar generados**: `graphify-out/`, `dist/`, `node_modules/`, `vendor/` (a mano), `*.map`. Si algo es regenerable, no se edita ni se commitea.
- **Commits**: pequeños, por tema, prefijo `feat:` / `fix:` / `chore:` / `docs:`. No mega-commits. NO push a main sin OK explícito del usuario.
- **MCPs OBLIGATORIOS en todos los agentes** (regla del usuario, incluye subagentes): todo agente que ejecute tareas DEBE usar los MCPs (context7 para docs de librerías, chrome-devtools para verificación visual/rendimiento, mysql para datos, mercadopago-mcp para el flujo MP, astro-docs, tavily) y DEBE reportar en su reporte qué MCPs usó y qué verificó con ellos. Si un MCP esencial no está disponible → BLOCKED, nunca trabajar sin verificación real.
- **Duda → preguntar**: si el pedido del usuario choca con el plan de migración o la arquitectura (ver `docs/ROADMAP.md`), pregunta antes de codear.

## Principios de ingeniería (reglas de senior dev)

Aplicar en orden; detente en la primera que aplique. Las reglas 1 y 7 del original se suavizan a propósito (el original casi borró una tabla de producción del autor).

1. **Sin capas de compatibilidad especulativas**: borra caminos obsoletos directo, pero NUNCA sin antes verificar dependencias y con `git` limpio y reversible. No añadas migraciones/fallbacks "por si acaso". Cambios de schema: coordinar con QloApps (ver sección BD).
2. **Implementación más simple que satisfaga la necesidad actual**: sin abstracción preventiva, sin interfaces para un solo uso futuro.
3. **Build the thinnest end-to-end slice first**: un corte vertical mínimo funcionando antes que infraestructura; nunca reemplaces código que funciona con complejidad a medias.
4. **Componentes modulares**: separación de concerns (el ADR del proyecto ya lo impone: Action → Domain → Ports/Adapters).
5. **Preferir librerías maduras**: no reescribas lo que una librería estable ya resuelve (compatible con "dependencia mínima" del deploy Hostinger).
6. **Revisar dependencias existentes** antes de añadir paquetes nuevos: revisa `composer.json`/`package.json` y qué ya importa el código antes de instalar algo.
7. **Decisiones de arquitectura a largo plazo**: rechaza hacks "lo cambiamos luego" para cosas que cruzan capas o la BD; documenta en `docs/DECISIONS.md` (patrón ADR del proyecto).
8. **Estudia cómo lo resolvieron productos maduros**: usa patrones probados (PrestaShop/QloApps, estándares del sector) antes de inventar.

## Herramientas de calidad disponibles (instaladas 2026-08-04)

- **Semgrep MCP** (`semgrep` en MCPs): SAST local y gratuito — auditor de seguridad estándar. Usa `semgrep mcp` para escanear el diff/código.
- **open-code-review (Alibaba)**: `ocr delegate preview` + `ocr delegate rule <file>` para revisión de código independiente en modo delegación (sin API key extra).
- **Ponytail**: plugin OpenCode activo — sigue la "ladder" YAGNI antes de generar código (¿existe? ¿stdlib? ¿dependencia instalada? → solo entonces el mínimo que funciona). Niveles `/ponytail lite|full|ultra`.
- **Skills de diseño**: `design-taste-frontend` (anti-slop, landing/redesign — NO usar en el booking wizard) y `modlens` (visión para imágenes: requiere `GEMINI_API_KEY`).
- **codebase-memory-mcp**: NO instalar como MCP — duplica `graphify` (ya configurado). Si se quiere comparar, usar su CLI standalone.
