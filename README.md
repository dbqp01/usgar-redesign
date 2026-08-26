# USGAR Hotels — usgarhoteles.com

Sitio transaccional del hotel boutique Usgar (San Pedro, Cusco, Perú): reservas directas, 4 idiomas (en/es/fr/pt), pagos USD. Frontend Astro 7 estático + API PHP nativa (monolito ADR).

> **Para agentes:** lee primero `AGENTS.md` (estructura, reglas y fuentes de verdad). **Documentación centralizada en `docs/`** (8 archivos): arquitectura, API, deploy, PMS, seguridad, roadmap y decisiones — ver la tabla de fuentes en `AGENTS.md`.

## Quickstart

Requisitos: Node ≥ 22, PHP ≥ 8.2 (con pdo_mysql, xml, mbstring), Composer (solo para vendor), MySQL.

```bash
npm install
composer install          # vendor/ (en prod Hostinger no existe: el build lo copia)
cp .env.example .env      # credenciales reales (BD, MP, QloApps)
npm run dev:all           # Astro en :4321 + PHP API en :8000 (proxy /api)
```

## Comandos

| Comando | Qué hace | Cuándo |
|---|---|---|
| `npm run dev:all` | Entorno completo (Astro + PHP API + proxy `/api`) | Desarrollo diario |
| `npm run dev` / `dev:php` | Solo frontend / solo API | Ajustes puntuales |
| `npm run check` | astro check + TypeScript | Antes de cada commit frontend |
| `npm run test:php` | Suite exhaustiva integración + unit (`scripts/run-exhaustive-tests.php`) | Tras tocar backend |
| `php tests/api-harness.php` | Contrato del flujo de reserva vía curl (`POST /api/booking`) | Tras tocar booking |
| `npm run test` | check + test:php | Pre-merge |
| `npm run audit:security` | Auditoría seguridad PHP | Tras tocar endpoints |
| `npm run audit:seo` | Auditoría SEO/schema | Tras tocar páginas públicas |
| `npx playwright test` | E2E (book, login) | Cambios en flujos de usuario |
| `npm run build` | Build estático + copia `app/` y `vendor/` a `dist/` (el `.env` NO va en `dist/`) | Deploy |

## Deploy (Hostinger — automático desde GitHub)

Hostinger está conectado a GitHub (integración **Node.js web app**) y **solo mira la rama `main`**. No se sube nada manualmente; no existe rama `build` (el workflow `deploy-build-branch.yml` está desactivado y es historial).

1. `git push origin main` → Hostinger hace pull, `npm install` y `npm run build` desde cero, y sirve el resultado (unos minutos; historial y logs en hPanel → **Deployments**).
2. El `postbuild` (package.json) copia `app/` y `vendor/` a `dist/` (Composer no existe en prod) y elimina cualquier `dist/.env`. Astro vuelca `public/*` en la raíz de `dist/`, así que el entry PHP queda en `dist/index.php` + `dist/.htaccess`.
3. Config vigente en hPanel: branch `main` · build script `build` · output `dist` · Node **22** · package manager **npm** (Node fijado también en `engines` de package.json).

**Variables de entorno en producción** — nunca dentro de `public_html` ni de `dist/`:

- Fuente efectiva hoy: hPanel → **Environment variables** (la integración las escribe en `/home/<usuario>/domains/usgarhoteles.com/.builds/config/.env`).
- Fallback soportado por `app/Core/Config.php::loadEnv`: un `.env` **un nivel arriba de `public_html`**; si existe, tiene prioridad sobre las vars de hPanel.

## Credenciales de Mercado Pago

Las credenciales se obtienen en el panel de desarrolladores de Mercado Pago (Tus integraciones):

1. Ingresa a https://www.mercadopago.com/developers/panel/app y selecciona tu aplicacion.
2. **Pruebas** (sandbox): seccion *Pruebas* -> *Credenciales de prueba* (Public Key + Access Token).
3. **Produccion**: seccion *Produccion* -> *Credenciales de produccion* (activar antes completando los datos del negocio).

Configuralas en tu `.env` (nunca en el repo):

```
MERCADO_PAGO_ACCESS_TOKEN=<Access Token de la app>
PUBLIC_MERCADO_PAGO_PUBLIC_KEY=<Public Key de la app>
MERCADO_PAGO_WEBHOOK_SECRET=<secret del webhook de la app>
MERCADO_PAGO_CURRENCY=PEN
```

> Importante (doc MP vigente): el **prefijo del token NO define el entorno** — los Access Tokens de prueba y de produccion pueden empezar ambos con `APP_USR` (Checkout Pro/Orders crean la cuenta de prueba automaticamente con prefijo APP_USR) y el prefijo puede variar segun la solucion. El entorno lo define la app/panel. Por eso el deploy exige un **guard por allowlist de hashes** (no por prefijo).

**Guard de deploy (produccion):** antes de desplegar con `APP_ENV=production`, `php scripts/check-prod-env.php` verifica que el token este en la allowlist. Configurala en `.env.production` (archivo FUERA de git, solo hashes, nunca el token en claro):

```
MERCADO_PAGO_PROD_TOKEN_SHA256=<sha256 del Access Token de produccion>
```

Para generar el hash: `php -r "echo hash('sha256', getenv('MERCADO_PAGO_ACCESS_TOKEN'));"`. Si el token de produccion se rota o se expone, **renueva las credenciales desde el panel** (Produccion -> Credenciales -> Renovar; las antiguas quedan activas 12 horas) y actualiza el hash.

## Arquitectura en una línea

`src/` (Astro) → consume `/api/*` → `public/index.php` → `app/Features/<X>/Actions` (ADR, DI PSR-11) → `Ports/Adapters` → QloApps (PMS, XML), MercadoPago (pagos USD, webhooks). Channel manager (QloApps CM, Webkul): activación pendiente de pago — ver `docs/PMS.md` §D. Detalle por capa en `AGENTS.md` y `docs/ARCHITECTURE.md`.

## Stack

Astro 7.2.4 · Tailwind CSS 4 · GSAP · Leaflet · Lenis · PHP 8 (PDO/MySQL, non framework) · MercadoPago · QloApps (PMS; Channel Manager Webkul pendiente de pago) · hybridauth · Vitest · PHPStan
