# STATE — Rediseño Awwwards (multi-sesión)

Última actualización: 2026-08-01 (Fase 1 completada)

## Hecho
- **FASE 1 COMPLETA (Foundation)**:
  - Dark mode corregido: token `--color-primary-soft #A78BBD` + regla global `.dark .text-primary → primary-soft` + `.btn-primary` con gradiente dark + `.border-tint`.
  - Tipografía editorial: `fluid-h1` clamp(3rem,8vw,7.5rem), `.section-number` outline (01-07 en home), `.micro-label` coordenadas en hero, componente `SectionHeader`.
  - Cursor dual-layer (dot instantáneo + ring con lag, hover expande, kill-switch `settings.customCursor` → `data-cursor` en html).
  - Marquee tipográfico CSS puro (zero JS): `TypographicMarquee.astro` ×2 en home + i18n marquee1-4 en 4 locales.
  - Transición de página cinematográfica: `pageTransition.ts` (curtain morado + monograma USGAR + línea dorada, before-preparation/after-swap, reduce-motion off).
  - Refactor RoomDetail (863→~570 líneas): `room/RoomGallery` (slider+lightbox), `room/RoomAmenities`, `room/RoomBookingSidebar` — IDs intactos.
  - Refactor index (750→~145 líneas): `Hero.astro` (video+slideshow+toggle+preload poster propio) y `ExploreShowcase.astro` extraídos.
  - Perf: `quality={70}` eliminado de gallery; hardcode "5 disponibles" → estado neutro i18n con fade-in cuando el polling responde (`book.checkingAvailability`/`book.available`).
- Refactor animaciones modular previo (lifecycle/engine/7 módulos) y calidad de imágenes previa (webp90/avif85) siguen vigentes.
- Spec + plan: `docs/superpowers/specs/2026-08-01-awwwards-redesign-design.md`, `docs/superpowers/plans/2026-08-01-phase1-foundation.md`.
- BD verificada: QloApps vacía → semi-funcional, NO fuente de verdad.

## En curso
- QA visual pendiente de Fase 1 con Chrome DevTools (MCP no conectado en la sesión de implementación — el usuario tiene Chrome DevTools).

## Siguiente
- Fase 2 — Home (hero reveal por palabras con SplitText, abanico 3D habitaciones + modal accesible, carrusel magazine explore, collage Instagram + CTA, footer wordmark + **mapa SVG ilustrado reemplaza Leaflet** + newsletter PHP→BD + certificaciones logos reales).
- Fase 3 — Internas (rooms grid editorial, explore con fotos, gallery).
- Fase 4 — Wizard reserva (calendario custom + disponibilidad, `roomAllocator.ts`, pago MP).
- Migraciones futuras (NO ejecutar): Channex→Nobeds, QloApps→otro CMS/PMS.

## Métricas
### Baseline (pre-Fase 1)
- CSS: 120.8 KB | Leaflet JS: 145.2 KB en home | JPGs 300+ KB | Hero video 720p

### Post-Fase 1 (2026-08-01)
- CSS: 123.2 KB (Layout.BrED67lr.css, +2.4 KB de estilos nuevos; crítico inlined por critters, sin duplicación entre locales)
- Leaflet: intacto (145.2 KB) hasta el mapa SVG de Fase 2
- astro check: 0 errores | build: 108 páginas OK
