# USGAR Hotels — hotelesusgar.com

Sitio transaccional del hotel boutique Usgar (San Pedro, Cusco, Perú): reservas directas, 4 idiomas (en/es/fr/pt), pagos USD. Frontend Astro 7 estático + API PHP nativa (monolito ADR).

> **Para agentes:** lee primero `AGENTS.md` (estructura y cómo modificar). **Migraciones en curso:** `docs/MIGRATION_PLAN.md` (QloApps Channel Manager pendiente; migración de PMS cancelada — nos quedamos con QloApps).

## Quickstart

Requisitos: Node ≥ 22, PHP ≥ 8.2 (con pdo_mysql, xml, mbstring), Composer (solo para vendor), MySQL.

```bash
npm install
composer install          # vendor/ (en prod Hostinger no existe: el build lo copia)
cp .env.example .env      # credenciales reales (BD, MP, QloApps, Channex)
npm run dev:all           # Astro en :4321 + PHP API en :8000 (proxy /api)
```

## Comandos

| Comando | Qué hace | Cuándo |
|---|---|---|
| `npm run dev:all` | Entorno completo (Astro + PHP API + proxy `/api`) | Desarrollo diario |
| `npm run dev` / `dev:php` | Solo frontend / solo API | Ajustes puntuales |
| `npm run check` | astro check + TypeScript | Antes de cada commit frontend |
| `npm run test:php` | Tests de contrato API (`tests/api-harness.php`) | Tras tocar backend |
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

## Arquitectura en una línea

`src/` (Astro) → consume `/api/*` → `public/index.php` → `app/Features/<X>/Actions` (ADR, DI PSR-11) → `Ports/Adapters` → QloApps (PMS, XML), Channex (channel manager), MercadoPago (pagos USD, webhooks). Ver `AGENTS.md` para el detalle por capa.

## Stack

Astro 7 · Tailwind CSS 4 · GSAP · Leaflet · Lenis · PHP 8 (PDO/MySQL, sin framework) · MercadoPago · QloApps · Channex · hybridauth · Playwright · Vitest · PHPStan
