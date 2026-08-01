# STATE — Rediseño Awwwards (multi-sesión)

Última actualización: 2026-08-01 (Fase 2 implementada, QA pendiente)

## Hecho
- **FASE 1 COMPLETA (Foundation)**: dark mode corregido (primary-soft lavanda + regla global), tipografía editorial (fluid-h1 7.5rem, section-number, micro-label, SectionHeader), cursor dual-layer con kill-switch, marquee tipográfico, transición de página cinematográfica, refactor RoomDetail (RoomGallery/RoomAmenities/RoomBookingSidebar) y index (Hero/ExploreShowcase extraídos), perf audit (quality hardcodeados fuera).
- **FASE 2 COMPLETA (Home)**:
  - Motor compartido `autoMotion.ts`: ticker GSAP continuo + boost por scroll + drag con inercia + pausa SOLO fuera de viewport. **Nunca pausa en hover** (regla del usuario). Reutilizado por: velocityMarquee (reviews), marquee tipográfico, carrusel magazine.
  - Hero text-reveal por palabras (SplitText + ScrollTrigger scrub) — pedido del cliente.
  - **RoomsFanCarousel**: abanico 3D (4 tarjetas rotando en perspectiva), autoplay continuo + drag + inercia + click → modal accesible `<dialog>` con amenities/CTA → /book?roomType=. Reemplaza el acordeón hover (borrado InteractiveRoomsSection + roomsStory.ts).
  - **ExploreCarousel**: carrusel magazine horizontal seamless (13 destinos con foto), autoplay + drag. Reemplaza el crossfade (borrado ExploreShowcase).
  - **InstagramSection**: collage orgánico 6 tiles + hover ícono IG + CTA Síguenos (URL desde settings) + reveal escalonado.
  - **CuscoMap**: mapa SVG ilustrado a mano (flat editorial, puntos pulso, rutas que se dibujan al scroll) → **Leaflet ELIMINADO del bundle (−145KB, 0 archivos en dist)**. Reemplaza MapSection en home y contact.
  - **Footer**: wordmark gigante, newsletter (form → `POST /api/newsletter` → tabla `newsletter_subscribers` vía `SubscribeNewsletterAction` ADR, validación + prepared statements), barra de certificaciones TripAdvisor/Booking con links de settings.
  - API_REGISTRY actualizado con /api/newsletter.
- Spec + plan: `docs/superpowers/specs/2026-08-01-awwwards-redesign-design.md`, `docs/superpowers/plans/2026-08-01-phase1-foundation.md`.

## En curso
- QA visual Fase 1+2 con Playwright (pactado con el usuario — saltamos Chrome DevTools MCP que falló; el usuario propondrá cuándo).

## Siguiente
- Fase 3 — Internas (rooms grid editorial, explore con fotos, gallery auditar).
- Fase 4 — Wizard reserva (calendario custom + disponibilidad, `roomAllocator.ts`, pago MP).
- Migraciones futuras (NO ejecutar): Channex→Nobeds, QloApps→otro CMS/PMS.
- Limpieza repo: quitar `.agents/skills/gsap-*` y `preview.log` del control de versiones (entraron en un commit con git add -A).

## Métricas
### Baseline (pre-Fase 1)
- CSS: 120.8 KB | Leaflet JS: 145.2 KB en home | JPGs 300+ KB | Hero video 720p

### Post-Fase 2 (2026-08-01)
- CSS: 124.9 KB (Layout.BLTvGYRA.css, +4.1 KB acumulados de estilos nuevos)
- **Leaflet: 0 archivos en dist** (−145.2 KB JS del home y contact)
- astro check: 0 errores | build: 108 páginas OK
