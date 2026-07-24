# USGAR Hotels — Cusco, Perú

Sitio web transaccional para turistas internacionales. Reservas directas con Mercado Pago y sincronización de inventario con OTAs vía Channex.

## Stack
- **Frontend:** Astro v7 (estático), Tailwind CSS v4, Leaflet
- **Backend:** PHP 8 nativo (Monolito Modular, patrón ADR)
- **Server:** Hostinger compartido (PHP + MySQL, sin Composer en prod)
- **Payments:** Mercado Pago (USD)
- **PMS:** QloApps (API XML)
- **Channel Manager:** Channex

## Commands
- Dev: `npm run dev` (Astro + Vite proxy → localhost:8000)
- PHP server: `php -S localhost:8000 -t public`
- Build: `npm run build`
- Lint PHP: `vendor/bin/phpstan analyse`
- Tests: `vendor/bin/phpunit`

## Project Map
- `app/` — Backend PHP completo
- `app/Core/` — Router, Request, Response, Config, Middleware, Events (🔴 NO TOCAR)
- `app/Features/` — Vertical slices: Auth, Booking, Rooms, Webhooks, Cron, Health
- `app/Features/Shared/` — Ports (interfaces) + Adapters (QloApps, MercadoPago, Channex)
- `src/` — Frontend Astro exclusivamente
- `src/services/` — Capa de conexión frontend → backend API (httpClient, bookingService)
- `src/services/contracts/` — Interfaces TypeScript (IBookingService, IHttpClient)
- `public/` — Document Root: index.php (entry point PHP) + .htaccess
- `docs/` — API_REGISTRY, ARCHITECTURE, HARNESS

## Non-Obvious Patterns
- Entry point: `public/index.php` → `Router` → `Action` class (una por endpoint)
- Cada endpoint es una clase PHP invocable `__invoke(Request): void` (ADR, no MVC)
- El frontend NUNCA llama servicios externos directo; siempre pasa por `/api/`
- QloApps usa API XML (no JSON). El adapter en Shared/ traduce
- Bloqueo temporal de 15 min al iniciar reserva (`ProvisionalBookingRepository`). Webhook de MP confirma
- Autenticación vía JWT en cookie HttpOnly (`usgar_session`)
- Room slugs canónicos: `matrimonial`, `doble-superior`, `triple-standar`, `familiar-superior`
- Fuente única de slugs: `app/Features/Shared/RoomTypeRegistry.php`
- Autoloader PSR-4 propio (sin Composer en prod): `app/Core/Autoloader.php`
- Adaptadores implementan Ports (interfaces) en `Shared/Ports/` para ser intercambiables

## Boundaries

### ✅ Allowed
- Editar componentes, páginas, layouts, estilos, i18n en `src/`
- Agregar/modificar Actions en `app/Features/`
- Ejecutar tests, lint, build
- Leer archivos y listar directorios

### ⚠️ Ask first
- Modificar Ports/Interfaces en `app/Features/Shared/Ports/`
- Cambiar estructura de DB
- Modificar Adapters (conectan con servicios externos reales)
- Instalar/remover dependencias npm o PHP

### 🚫 Never
- Hardcodear precios, slugs, tokens, emails, IDs de Channex
- Exponer credenciales — todo vía `.env`
- Modificar `vendor/`, `dist/`, `node_modules/`
- Tocar `app/Core/` sin justificación arquitectónica

## Key Files
- `public/index.php` — API entry point y registro de rutas
- `app/Features/Shared/RoomTypeRegistry.php` — Mapeo centralizado de habitaciones
- `src/services/bookingService.ts` — Cliente de reservas frontend
- `src/services/httpClient.ts` — Cliente HTTP base
- `.env` / `.env.example` — Variables de entorno requeridas
- `docs/API_REGISTRY.md` — Catálogo completo de endpoints
- `.agents/BRAND.md` — Identidad visual y de marca