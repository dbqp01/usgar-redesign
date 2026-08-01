# STATE — Rediseño Awwwards (multi-sesión)

Última actualización: 2026-08-01

## Hecho
- Refactor animaciones completo (anterior): `src/features/animations/` modular (lifecycle, engine, 7 módulos).
- Calidad de imágenes: config sharp subida (webp 90, avif 85, jpeg 85), `quality` hardcodeados eliminados de RoomCard/RoomDetail, video hero con preload auto + src directo + poster 1920px.
- Spec de diseño aprobada: `docs/superpowers/specs/2026-08-01-awwwards-redesign-design.md`.
- Plan Fase 1: `docs/superpowers/plans/2026-08-01-phase1-foundation.md`.
- BD verificada: QloApps vacía (0 unidades en qlo_htl_room_information, max_guests=2 en 4 tipos) → semi-funcional, NO fuente de verdad.

## En curso
- Fase 1 — Fundación (Task 1..9 del plan).

## Siguiente
- Fase 2 — Home (hero reveal, abanico 3D + modal, carrusel magazine, collage Instagram, footer + mapa SVG + newsletter + certificaciones).
- Fase 3 — Internas (rooms grid editorial, explore con fotos, gallery).
- Fase 4 — Wizard reserva (calendario custom + disponibilidad, `roomAllocator.ts`, pago MP).
- Migraciones futuras (NO ejecutar): Channex→Nobeds, QloApps→otro CMS/PMS.

## Métricas baseline (pre-Fase 1)
- CSS: 120.8 KB (Layout.93UjSouH.css)
- Leaflet JS: 145.2 KB en home
- JPGs 300+ KB en dist (01-doble 320 KB, 01-triple 258 KB)
- Hero video: 720p (1280x720) — re-encodear a 1080p+ pendiente (asset, no código)
