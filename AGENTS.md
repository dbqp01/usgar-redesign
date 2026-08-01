# AGENTS.md — USGAR Hotels

Guía funcional para agentes: qué es este proyecto, dónde está cada cosa y cómo modificarlo sin romperlo. **Léela completa antes de tocar código.**

## Qué es

Sitio web transaccional del hotel boutique Usgar (San Pedro, Cusco, Perú): reservas directas en 4 idiomas (en/es/fr/pt, default `en`), pagos en USD, sincronización de inventario con PMS y OTAs.

**Stack real (verificado en código, no asumas otra cosa):**

- Frontend: **Astro 7** estático + Tailwind CSS 4 + GSAP + Leaflet + Lenis
- Backend: **PHP 8 nativo** — monolito modular ADR (Action → Domain → Ports/Adapters), DI container PSR-11, PDO/MySQL. Sin framework.
- Pagos: **MercadoPago** (USD, Checkout API/Custom Checkout) — webhooks en `app/Features/Webhooks/`
- PMS: **QloApps** (`QloAppAdapter`, API XML) | Channel manager: **Channex** (`ChannexAdapter`)
- Auth: sesiones propias + hybridauth (social login)
- Deploy: **Hostinger** compartido (PHP+MySQL, sin Composer en prod): `npm run build` copia `app/`, `vendor/` y `.env` a `dist/`

## Migraciones EN CURSO (importante)

Hay un plan de migración pendiente: MercadoPago→TAB, Channex→Nobeds, QloApps→Filament PHP. **Ver `docs/MIGRATION_PLAN.md`. Nada de eso está hecho.** No implementes pasos del plan por tu cuenta: el código actual sigue siendo MP+Channex+QloApps. Si el usuario pide algo del plan, consulta el archivo y trabaja solo lo que marque.

## Estructura (dónde está cada cosa)

```
src/                          Frontend Astro
  pages/                      Rutas públicas (book.astro = flujo de reserva, login, my-bookings…)
  components/ layouts/        UI (Tailwind + GSAP)
  i18n/                       en.json es.json fr.json pt.json + utils.ts (claves de traducción)
  data/ content/              Datos de contenido: rooms, services, attractions, reviews, settings
  services/ utils/            Lógica frontend (fetch al /api, helpers)
app/                          Backend PHP
  Core/                       Request, Response, Database (PDO), Container (PSR-11), Validator,
                              Middleware, Config, Logger, HttpException, BookingStatus
  Features/<Feature>/         Módulos: Auth, Booking, Cron, Health, Rooms, Shared, Webhooks
    Actions/                  Clases ADR invocables (ruta → action)
    Domain/                   Repositorios, Listeners (ej. ConfirmQloAppsOrderListener)
    Shared/                   Adapters/ (QloApp, Channex, MercadoPago) + Ports/ (interfaces)
public/                       Entry PHP (.htaccess + index.php) + estáticos
scripts/                      dev.js (dev:all), tests, auditorías (seguridad/SEO)
tests/                        api-harness.php (tests PHP) + playwright.config.ts (E2E)
docs/                         Documentación técnica (API_REGISTRY, ARCHITECTURE, MIGRATION_PLAN…)
```

## Cómo modificar (procedimiento estándar)

### Frontend (Astro)
1. Cambios de texto: `src/i18n/<locale>.json` — SIEMPRE las 4 locales (fr y pt caen a en/es vía fallback en `astro.config.mjs`).
2. Datos de negocio (habitaciones, servicios): `src/content/*.json` o `src/data/*.ts` — revisa cuál consume el componente.
3. UI: componentes en `src/components`; estilos Tailwind v4 (CSS-first, no config de Tailwind v3).
4. Validar con `npm run check` (astro check + TS).

### Backend (PHP ADR) — PATRÓN OBLIGATORIO
1. **Nunca instancies dependencias dentro de una action**: recíbelas por constructor (el DI `Container` las inyecta).
2. **Acceso a externos SIEMPRE vía Ports/Adapters**: si necesitas QloApps/Channex/MP, usa la interfaz (`PmsPortInterface`, `PaymentGatewayPortInterface`), nunca el adapter directo.
3. Nueva feature = nuevo `app/Features/<Nombre>/Actions/<Verbo><Sustantivo>Action.php` + ruta en el router (busca dónde se registran: `app/Core` + `public/index.php`).
4. SQL: preparado (PDO), nunca concatenado. Inputs validados con `App\Core\Validator`.
5. Errores: `HttpException` con códigos HTTP; en producción no exponer stack traces.

### Base de datos
- MySQL vía PDO (`app/Core/Database.php`); las consultas a QloApps pasan por `QloAppAdapter` (schema PrestaShop: `ps_*`).
- Cambios de schema: revisar si QloApps lo gestiona; no alterar tablas de QloApps directamente sin confirmar.

### Tests y verificación (antes de declarar "listo")
1. `npm run check` — types + astro.
2. `php tests/api-harness.php` (o `npm run test:php`) — contrato de la API.
3. `npm run audit:security` + `npm run audit:seo` si tocas endpoints/SEO.
4. E2E: `npx playwright test` si el cambio toca flujos de usuario (book, login).

### Deploy (Hostinger)
- `npm run build` → `dist/` listo para subir (incluye app/, vendor/, .env).
- El hook `pre-commit` comprime `.mp4`/imágenes staged (requiere ffmpeg + `scripts/compress-images.js`).

## Reglas imperativas

- **Docs-first**: antes de escribir código con una librería, consulta su documentación (Astro → `astro-docs`, librerías → `context7`, patrones → `tavily`). No asumas APIs de memoria.
- **Pensamiento secuencial** para problemas no triviales (arquitectura, debugging, decisiones).
- **Zero hardcoding**: credenciales/URLs/precios → `.env` o BD. Jamás literales en código.
- **No tocar generados**: `graphify-out/`, `dist/`, `node_modules/`, `vendor/` (a mano), `*.map`. Si algo es regenerable, no se edita ni se commitea.
- **Commits**: pequeños, por tema, prefijo `feat:` / `fix:` / `chore:` / `docs:`. No mega-commits.
- **Duda → preguntar**: si el pedido del usuario choca con el plan de migración o la arquitectura, pregunta antes de codear.
