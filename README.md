# USGAR Hotels — hotelesusgar.com

Sitio web transaccional del hotel boutique **Usgar** (San Pedro, Cusco, Perú) para reservas directas de turistas internacionales, con pagos en USD vía MercadoPago, sincronización de inventario con QloApps (PMS) y Channex (channel manager). Sitio en 4 idiomas: `en` (default), `es`, `fr`, `pt`.

## Stack

| Capa | Tecnología |
|---|---|
| Frontend | Astro 7 (estático), Tailwind CSS 4, GSAP, Leaflet, Lenis, Lucide |
| Backend | PHP 8 nativo — monolito modular **ADR** (Action → Domain → Ports/Adapters), DI container PSR-11, PDO/MySQL |
| Pagos | MercadoPago (USD) — Checkout API (Custom Checkout), webhooks de pago |
| PMS | QloApps vía `QloAppAdapter` (API XML) |
| Channel manager | Channex vía `ChannexAdapter` + `SyncChannexBookingListener` |
| Auth | Sesiones propias + social login (hybridauth) |
| Deploy | Hostinger compartido (PHP + MySQL, sin Composer en prod) |

## Estructura

```
src/       Frontend Astro: pages, components, layouts, i18n (en/es/fr/pt), data, content
app/       Backend PHP: Core (Request, Response, Database, Container, Middleware…) + Features/
app/Features/  Auth, Booking, Cron, Health, Rooms, Shared (Adapters/Ports), Webhooks
public/    Entry point PHP (.htaccess + index.php)
scripts/   Dev, tests exhaustivos, auditorías (seguridad, SEO)
tests/     Tests PHP (api-harness) + Playwright (playwright.config.ts)
docs/      Documentación técnica (API_REGISTRY, ARCHITECTURE, HARNESS, multi-hotel)
vendor/    Dependencias Composer (NO editar a mano; no se instala en prod)
```

## Comandos

```bash
npm run dev:all       # Entorno completo: Astro (4321) + PHP API (8000) con proxy /api
npm run dev           # Solo frontend Astro
npm run dev:php       # Solo PHP API server (localhost:8000, public/index.php)
npm run build         # Build estático; postbuild copia app/, vendor/ y .env a dist/ (Hostinger)
npm run check         # TypeScript/astro check
npm run test          # astro check + tests PHP exhaustivos (scripts/run-exhaustive-tests.php)
npm run audit:security # Auditoría de seguridad (PHP)
npm run audit:seo     # Auditoría SEO/schema (Node)
```

## Flujo de reserva (actual)

1. `POST /api/booking` (`CreateBookingAction`): valida payload (normaliza `roomSlug`→`id_room_type`, `guestDetails`→campos planos), crea **bloqueo temporal en QloApps** y devuelve `cart_id`, `access_token`, `gateway_price`, `mp_public_key` — **sin** `init_point` (Custom Checkout).
2. Frontend inicializa el SDK de MercadoPago en el cliente y cobra con esos datos.
3. Webhook de MercadoPago (`HandleMercadoPagoWebhookAction`) confirma el pago → confirma la orden en QloApps y sincroniza con Channex.
4. `ProcessPaymentAction` + repositorio de reservas provisionales cubren el ciclo intermedio.

## Reglas de trabajo (obligatorias)

- **Docs-first**: consultar MCPs de documentación antes de tocar código (Astro → astro-docs; librerías → context7; patrones → tavily).
- **Pensamiento secuencial** antes de problemas no triviales.
- **Zero hardcoding**: credenciales y config via `.env` (`dist/` recibe una copia en build). Nunca literales en código.
- **Prepared statements** para SQL; inputs sanitizados; errores sin stack traces internos al cliente.
- **No trackear generados**: `graphify-out/`, `dist/`, `node_modules/`, `*.map` están en `.gitignore`. Si algo nuevo es regenerable, va al ignore, no al repo.
- Commits pequeños por tema (`feat:`, `fix:`, `chore:`), nunca mega-commits mezclados.
- El hook `pre-commit` comprime `.mp4`/imágenes staged automáticamente (requiere ffmpeg + node `scripts/compress-images.js`).

## Estado de migración pendiente

- [x] Backend: MercadoPago Custom Checkout (sin `init_point`)
- [ ] Frontend: inicializar SDK MP en cliente con los datos del backend (ver `task.md`)
