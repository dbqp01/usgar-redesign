# STATE — Rediseño Awwwards (multi-sesión)

Última actualización: 2026-08-01 (Fase 2.5 completa, QA pendiente)

## Hecho
- **FASE 1 COMPLETA (Foundation)**: dark mode corregido (primary-soft lavanda + regla global), tipografía editorial (fluid-h1 7.5rem, section-number, micro-label, SectionHeader), cursor dual-layer con kill-switch, marquee tipográfico, transición de página cinematográfica, refactor RoomDetail (RoomGallery/RoomAmenities/RoomBookingSidebar) y index (Hero/ExploreShowcase extraídos), perf audit (quality hardcodeados fuera).
- **FASE 2 COMPLETA (Home v1)**: motor autoMotion compartido (ticker continuo, sin pausa hover, boost scroll, drag), hero word-reveal SplitText, abanico 3D habitaciones, carrusel magazine explore, Instagram collage, footer wordmark + newsletter PHP→BD, mapa SVG ilustrado (luego REEMPLAZADO en 2.5).
- **FASE 2.5 COMPLETA (Home v2 — fixes + redesign aprobado)**:
  - **Habitaciones**: abanico 3D ELIMINADO → `RoomsEditorialGrid.astro` (bento asimétrico: hero doble col-span-2 + 3 tarjetas; hover reveal precio/capacidad/amenities + CTA; −421/+116 líneas). Eliminados RoomsFanCarousel.astro + roomsFanCarousel.ts.
  - **Explore**: fix fades laterales — la izquierda cubre viewport→columna (`w-[max(5rem,calc((100vw-1400px)/2+5rem))]`), la derecha pegada al borde. Ya no hay hueco en ≥1400px.
  - **Reseñas**: `icons/PlatformLogos.astro` (TA/Booking/Google/Expedia SVG oficiales; Google con sus 4 colores) + cards con logo + país + fecha; barra de métricas con logos.
  - **AUKA RESTOBAR**: showroom gastronómico — foto grande con crossfade GSAP (`gastronomyShowroom.ts`) + filas numeradas 01/02/03 con hover reveal.
  - **Services**: lista brutalista numerada 01-04 full-width (icono lineal, desc despliegue hover) + "Also Included" tipográfico profesional (01 WiFi · 02 … · 06). Eliminado bento de fotos repetidas.
  - **Mapa**: `LocationMap.astro` + `locationMap.ts` — Leaflet estático (dragging/zoom OFF) con tiles CARTO dark_all (gratis sin key), marcador rombo dorado, overlays de coordenadas, CTA "Get Directions" → Google Maps `dir/?api=1&destination=lat,lng`. **Leaflet con import dinámico**: chunk separado `leaflet-src.*.js` (145.2KB), 0 refs en index.html — carga solo al entrar en viewport. Eliminados CuscoMap.astro + cuscoMap.ts.
  - **Footer**: Follow Us SOLO IG/FB (filtrado por plataforma); barra inferior "As Featured In" (`footer.asFeaturedIn` en 4 locales) con logos SVG TA/Booking grandes, hover opacidad.
  - API_REGISTRY actualizado con /api/newsletter.
- Spec + plan: `docs/superpowers/specs/2026-08-01-phase2-home-redesign-design.md`, `docs/superpowers/plans/2026-08-01-phase2-home-redesign.md`.

## En curso
- QA visual Fase 1+2+2.5 con Playwright (pactado con el usuario — saltamos Chrome DevTools MCP que falló; el usuario propondrá cuándo).

## Siguiente
- Fase 3 — Internas (rooms grid editorial, explore con fotos, gallery auditar) + páginas restantes (book, login, my-bookings, profile, contact) — spec/plan propio pendiente.
- Fase 4 — Wizard reserva (calendario custom + disponibilidad, `roomAllocator.ts`, pago MP).
- Migraciones futuras (NO ejecutar): Channex→Nobeds, QloApps→otro CMS/PMS.
- Limpieza repo: quitar `.agents/skills/gsap-*` y `preview.log` del control de versiones (entraron en un commit con git add -A).

## Métricas
### Baseline (pre-Fase 1)
- CSS: 120.8 KB | Leaflet JS: 145.2 KB en home | JPGs 300+ KB | Hero video 720p

### Post-Fase 2.5 (2026-08-01)
- CSS: ~125 KB (Layout.BLTvGYRA.css + estilos nuevos de grid/showroom/mapa)
- Leaflet: chunk separado bajo demanda (145.2 KB), 0 precarga en index.html
- astro check: 0 errores | build: 108 páginas OK
