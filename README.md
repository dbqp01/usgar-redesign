# USGAR Hotels — usgarhoteles.com

Sitio transaccional del hotel boutique Usgar (San Pedro, Cusco, Perú): reservas directas, 4 idiomas (en/es/fr/pt), pagos USD. Frontend Astro 7 estático + API PHP nativa (monolito ADR).

> **Para agentes:** lee primero `AGENTS.md` (estructura y cómo modificar). **Migraciones en curso:** `docs/MIGRATION_PLAN.md` (QloApps Channel Manager pendiente; migración de PMS cancelada — nos quedamos con QloApps).

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
| `npm run build` | Build estático + copia `app/`, `vendor/`, `.env` a `dist/` | Deploy |

## Deploy (Hostinger)

1. `npm run build`
2. Subir `dist/` al hosting (PHP + MySQL; Composer no existe en prod).
3. Verificar `.env` con las credenciales de producción.
4. Configurar el dominio para que apunte a `dist/public/` (entry: `index.php` + `.htaccess`).

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

`src/` (Astro) → consume `/api/*` → `public/index.php` → `app/Features/<X>/Actions` (ADR, DI PSR-11) → `Ports/Adapters` → QloApps (PMS, XML), MercadoPago (pagos USD, webhooks). Channel manager (QloApps CM, Webkul): activación pendiente de pago — ver `docs/CHANNEL_MANAGER_SETUP.md`. Detalle por capa en `AGENTS.md`.

## Stack

Astro 7 · Tailwind CSS 4 · GSAP · Leaflet · Lenis · PHP 8 (PDO/MySQL, sin framework) · MercadoPago · QloApps (PMS; Channel Manager Webkul pendiente de pago) · hybridauth · Playwright · Vitest · PHPStan
