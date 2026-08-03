# STATE — Rediseño Awwwards (multi-sesión)

Última actualización: 2026-08-02 (Fase 0 COMPLETA — panel en producción, línea base documentada)

## Hecho
- **FASE 0 COMPLETA — Backend Laravel + Filament**:
  - Tasks 1-3 completas: caracterización del contrato API actual, scaffolding Laravel 12 en `backend/` con health endpoint, panel Filament v5 con multi-tenancy (Property).
  - **Task 4 completada (variante B, sin acceso al hosting)**: build de producción (`composer install --optimize-autoloader --no-dev` + config/route/view/event cache, `route:list` OK), `backend/README.md` con instrucciones de deploy Hostinger, template `.env.production.example`, `.htaccess` raíz → `public/`, y paquete `usgar-admin-deploy.zip` (18.8 MB, gitignored) listo para que el usuario lo suba a `public_html/admin`.
  - `usgar-admin-deploy.zip`: verificado — sin `.env`, sin `tests/`, sin logs; SÍ `vendor/` (53.6 MB, 13.979 archivos), caches de prod, `.env.production.example` y `.htaccess`.
  - **Task 5 completada (cierre)**: línea base de no-regresión verificada con evidencia real — `npm run check` 0/0/0 (136 archivos), `php tests/api-harness.php` contratos OK (5 rechazos 400 + happy path 200), `php backend/artisan test` 5 passed (7 assertions); no-regresión visual en Chrome DevTools (home LCP 1444ms/CLS 0.03, book LCP 1137ms/CLS 0.03, 0 errores de consola, emulación móvil 390x844 + Slow 4G + CPU 4x); línea base MySQL verificada (provisional_bookings operativa).
  - **Deploy (usuario)**: subdominio `admin.hotelesusgar.com` creado, BD MySQL nueva, zip subido a `public_html/admin`, `.env` desde el template, `key:generate` + `migrate --force` + `storage:link` + `optimize`, `make:filament-user`, cron `schedule:run`; verificación conjunta de `https://admin.hotelesusgar.com/admin/login`.

## Hecho
- **FASE 1 COMPLETA (Foundation)**: dark mode corregido (primary-soft lavanda + regla global), tipografía editorial (fluid-h1 7.5rem, section-number, micro-label, SectionHeader), cursor dual-layer con kill-switch, marquee tipográfico, transición de página cinematográfica, refactor RoomDetail (RoomGallery/RoomAmenities/RoomBookingSidebar) y index (Hero/ExploreShowcase extraídos), perf audit (quality hardcodeados fuera).
- **FASE 2 COMPLETA (Home v1)**: motor autoMotion compartido, hero word-reveal SplitText, carrusel magazine explore, Instagram collage, footer wordmark + newsletter PHP→BD, mapa SVG ilustrado (luego REEMPLAZADO en 2.5).
- **FASE 2.5 COMPLETA (Home v2)**: RoomsEditorialGrid (bento), atlas interactivo (ExploreCarousel), ServiceGrid escenas, LocationMap Leaflet CARTO, Footer wordmark + As Featured In, AUKA showroom, ReviewMarquee con logos, marquee separador.
- **FASE 3 COMPLETA (Internas)**:
  - rooms/index: grid editorial con Picture AVIF/WebP + filtros de capacidad conservados; CTA → `/book?roomType=`. Eliminado RoomCard.
  - explore: cards con foto full-bleed (atlas de `src/assets/explore/{id}.jpg` con fallback), hover reveal, filtros + búsqueda conservados; animación `exploreCards.ts`.
  - gallery: auditoría → Picture AVIF, header editorial SectionHeader; lightbox y filtros intactos.
  - contact: ya editorial (sin cambios necesarios, verificado ASCII).
  - login/my-bookings/profile: ya editorial; corregido texto no-ASCII (arrows/em dash/bullets corruptos).
- **FASE 4 COMPLETA (Wizard reserva `/book`)**:
  - `src/features/booking/`: `roomAllocator.ts` (9 unit tests), `wizardStore.ts` (singleton, 4 tests), `calendar.ts` (5 tests).
  - Componentes `src/components/booking/`: WizardProgress, BookingCalendarStep (dual-month desktop/single mobile, rango por clic, aria-label/aria-selected WAI-APG, disponibilidad API con degradación), AllocationStep (top 4, badge Best price, ?roomType + toggle alternativas, multi-room informativo), GuestStep (form extraído intacto + validación + autofill), PaymentStep (flujo MP existente intacto, arranca con formulario válido).
  - Inventario configurable `roomInventory` en settings.json (claves = slugs reales).
  - i18n `wizard.*` en 4 locales, sin acentos (validado por script: OK).
- Verificación Fases 3+4: `astro check` 0 errores | `vitest` 21/21 | build 108 páginas | E2E **16/16 chromium + 16/16 Mobile Chrome** (wizard-flow, internals, home-redesign, booking-flow, i18n-darkmode).
- Caza de fallas con docs: astro-docs (Picture/layout vs Tailwind 4: responsiveStyles OFF correcto; scripts bundle run-once → patrón page-load+before-preparation correcto), context7 GSAP (ScrollTrigger.batch/context/revert/autoAlpha correctos), tavily WAI-APG (aria-label/aria-selected añadidos al calendario).

## En curso
- QA visual final de Fases 3+4 en navegador real (Chrome DevTools MCP tras reinicio de OpenCode; Playwright ya cubre flujos críticos).

## Siguiente
- QA visual pendiente (arriba).
- `docs/refactoring/` al día; MIGRATION_PLAN.md sigue TODO (Nobeds, Filament, refactor MP — fuera de alcance).
- Limpieza repo: quitar `.agents/skills/gsap-*` y `preview.log` del control de versiones (entraron en un commit con git add -A).

## Métricas
### Post-Fase 2.5 (2026-08-01)
- CSS: ~125 KB (Layout.BLTvGYRA.css + estilos nuevos de grid/showroom/mapa)
- Leaflet: chunk separado bajo demanda (145.2 KB), 0 precarga en index.html
- astro check: 0 errores | build: 108 páginas OK

### Post-Fase 3+4 (2026-08-01)
- astro check: 0 errores | build: 108 páginas OK | vitest 21/21 | E2E 32/32 (16×2 proyectos)
- Wizard sin librerías nuevas: calendario + store + algoritmo en TS puro (~600 líneas)
