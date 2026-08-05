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

Hay un plan de trabajo pendiente (ver `docs/MIGRATION_PLAN.md`). **Nada de eso está hecho.** No implementes pasos del plan por tu cuenta: el código actual sigue siendo MP+Channex+QloApps. Si el usuario pide algo del plan, consulta el archivo y trabaja solo lo que marque. Estado actual de las decisiones:
- **Pagos: MercadoPago SE MANTIENE** — no hay migración de pasarela (Stripe+LLC solo se reevaluará con datos de volumen).
- **Channel manager**: Channex → **QloApps Channel Manager** (pendiente, decisión 2026-08-04: $30/propiedad/mes con conexiones Booking/Expedia/Airbnb incluidas; sustituye la migración a Nobeds).
- **CMS/PMS**: **QloApps SE MANTIENE** — migración a Filament PHP cancelada (decisión 2026-08-04). El panel Laravel 12/Filament ya desplegado en admin.hotelesusgar.com queda fuera de alcance (pendiente: decidir su destino).
- **Refactorización completa del código**: pendiente (sección 4 del plan) — incluye limpiar residuos del flujo Checkout Pro de MercadoPago y la falta de comunicación entre capas.

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
backend/                      Panel admin Laravel 12 + Filament v5 (multi-tenant Property) — se
                              despliega como subaplicación en el subdominio admin.hotelesusgar.com
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
- **MCPs OBLIGATORIOS en todos los agentes** (regla del usuario, incluye subagentes): todo agente que ejecute tareas DEBE usar los MCPs (context7 para docs de librerías, chrome-devtools para verificación visual/rendimiento, mysql para datos, mercadopago-mcp para el flujo MP, astro-docs, tavily) y DEBE reportar en su reporte qué MCPs usó y qué verificó con ellos. Si un MCP esencial no está disponible → BLOCKED, nunca trabajar sin verificación real.
- **Duda → preguntar**: si el pedido del usuario choca con el plan de migración o la arquitectura, pregunta antes de codear.

## Principios de ingeniería (reglas de senior dev, adaptadas de Marcos Hernanz — Vercel)

Aplicar en orden; detente en la primera que aplique. Estas reglas son PARA ESTE REPO (producción con pagos/BD): las reglas 1 y 7 del original se suavizan a propósito (el original casi borró una tabla de producción del autor).

1. **Sin capas de compatibilidad especulativas**: borra caminos obsoletos directo, pero NUNCA sin antes verificar dependencias y con `git` limpio y reversible. No añadas migraciones/fallbacks "por si acaso". Cambios de schema: coordinar con QloApps (ver sección BD).
2. **Implementación más simple que satisfaga la necesidad actual**: sin abstracción preventiva, sin interfaces para un solo uso futuro.
3. **Build the thinnest end-to-end slice first**: un corte vertical mínimo funcionando antes que infraestructura; nunca reemplaces código que funciona con complejidad a medias.
4. **Componentes modulares**: separación de concerns (el ADR del proyecto ya lo impone: Action → Domain → Ports/Adapters).
5. **Preferir librerías maduras**: no reescribas lo que una librería estable ya resuelve (compatible con "dependencia mínima" del deploy Hostinger).
6. **Revisar dependencias existentes** antes de añadir paquetes nuevos: revisa `composer.json`/`package.json` y qué ya importa el código antes de instalar algo.
7. **Decisiones de arquitectura a largo plazo**: rechaza hacks "lo cambiamos luego" para cosas que cruzan capas o la BD; documenta en `docs/` (patrón ADR del proyecto).
8. **Estudia cómo lo resolvieron productos maduros**: usa patrones probados (PrestaShop/QloApps, Laravel/Filament del backend, estándares del sector) antes de inventar.

## Herramientas de calidad disponibles (instaladas 2026-08-04)

- **Semgrep MCP** (`semgrep` en MCPs): SAST local y gratuito — auditor de seguridad estándar. Usa `semgrep mcp` para escanear el diff/código. Sustituye el rol del auditor conectado (CodeScene).
- **open-code-review (Alibaba)**: `ocr delegate preview` + `ocr delegate rule <file>` para revisión de código independiente en modo delegación (sin API key extra — el agente hace el razonamiento). Plugin OpenCode instalado globalmente.
- **Ponytail**: plugin OpenCode activo — sigue la "ladder" YAGNI antes de generar código (¿existe? ¿stdlib? ¿dependencia instalada? → solo entonces el mínimo que funciona). Niveles `/ponytail lite|full|ultra|off`.
- **Skills de diseño**: `design-taste-frontend` (anti-slop, landing/redesign — NO usar en el booking wizard) y `modlens` (visión para imágenes: requiere `GEMINI_API_KEY` vía `modlens config set gemini-api.apiKey <key>`; provider ya fijado a `gemini-api`).
- **codebase-memory-mcp**: NO instalar como MCP — duplica `graphify` (ya configurado). Si se quiere comparar, usar su CLI standalone.
