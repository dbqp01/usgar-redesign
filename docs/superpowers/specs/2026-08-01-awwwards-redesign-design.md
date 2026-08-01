# Awwwards-Level Redesign — Spec (aprobada 2026-08-01)

## Objetivo

Convertir usgarhoteles.com en una experiencia cinematográfica nivel Awwwards, manteniendo identidad Inca-Colonial (morado/dorado), con máximo rendimiento y un flujo de reserva rápido, estético e interactivo. Alcance: TODO el sitio (Enfoque A — Foundation-first).

## Decisiones confirmadas con el usuario

| Tema | Decisión |
|------|----------|
| Alcance | C — todo el sitio, por fases A (foundation → home → internas → wizard) |
| Modo claro | Es la referencia (se ve mejor). El oscuro se corrige sin tocar fondos |
| Capacidades | Todas permiten +1 persona con $30/noche: doble 2→3, matri 2→3, triple 3→4, familiar 7→8 (topes físicos innegociables) |
| Algoritmo | Opción 1: habitación individual + combinaciones multi-habitación, ordenadas por precio, top 3-4 con badge "Mejor precio" |
| Calendario | Custom estético + disponibilidad real (endpoint existente, degradación elegante) |
| Stock | Desconocido → inventario configurable en `settings.json` (doble 8, matri 6, triple 4, familiar 2). BD QloApps verificada VACÍA (0 unidades en `qlo_htl_room_information`, max_guests=2 en los 4 tipos) → NO es fuente de verdad, solo semi-funcional |
| Mapa | SVG ilustrado hecho a mano (nada de "AI look"), reemplaza Leaflet (−145 KB JS) |
| Certificaciones | Logos REALES TripAdvisor + Booking.com (respetando guías de uso) |
| Newsletter | Endpoint PHP (patrón ADR) → tabla `newsletter_subscribers` en BD propia |
| Cursor | Punto + anillo con lag; SOLO `(hover:hover) and (pointer:fine)`; off con reduced-motion; kill-switch en settings; si no queda impecable → se retira |
| Migraciones futuras | Channex→Nobeds, QloApps→otro CMS/PMS — SOLO registradas en ToDo, NO ejecutar |
| Verificación | `npx astro check` + `npm run build` + Chrome DevTools (start-chrome-debug.ps1 + protocolo). Sin Playwright |

## Fase 1 — Fundación

1. **Tokens dark mode**: `--color-primary-soft: #A78BBD` (lavanda AA sobre #121214). Regla dura: en dark nunca `text-primary` sobre fondo oscuro. Botones primary dark → gradiente `#6B4F80 → #4A3163` + glow dorado hover. Bordes dark `rgba(212,191,222,0.12)` en vez de `white/10`. Auditoría de usos.
2. **Tipografía editorial**: hero `clamp(3rem, 8vw, 7.5rem)`, números de sección outline (01…), micro-labels mono uppercase, itálicas Playfair de contraste. Sin fuentes nuevas.
3. **Motion global**: transición de página cinematográfica (panel + imagen destino, ~400ms, View Transitions + GSAP); text-reveal por palabras con scrub (SplitText ya presente); marquee tipográfico entre secciones.
4. **Perf**: quitar Leaflet del home (con el SVG map de Fase 2); AVIF/srcset correctos; preload primer slide; auditar CSS 120 KB; mantener polling disponibilidad 30 s con pausa en tab oculta.
5. **Refactor**: `RoomDetail.astro` (864 líneas) → `RoomGallery`/`RoomAmenities`/`RoomBookingSidebar`; extraer Hero y Showcase de `index.astro` (742 líneas); auditar ServiceGrid/GastronomySection/AboutSection/ReviewMarquee; quitar hardcoded "5 disponibles".

## Fase 2 — Home

- Hero: título gigante + reveal por palabras al scroll, micro-coordenadas, parallax video.
- Rooms: carrusel abanico 3D (drag desktop / swipe mobile scroll-snap), clic → modal accesible (dialog, focus trap, ESC) con resumen + amenities + CTA `/book?roomType=slug`.
- Explore: carrusel horizontal magazine (13 fotos existentes) + "Saber más" → `/explore/{id}`.
- Instagram: collage orgánico (assets existentes: patio, recepción, fachada, desayuno, san-pedro-market, moray…) + hover ícono IG + CTA "Síguenos" → instagram.com/hotelesusgar + reveal escalonado.
- Footer: wordmark grande, mapa SVG ilustrado Cusco (hotel, Plaza de Armas, Mercado San Pedro + distancias, animación de trazado), newsletter (form → PHP → BD), barra certificaciones logos reales.

## Fase 3 — Páginas internas

- rooms: grid editorial (card destacada + menores), filtros capacidad conservados, AVIF, CTA → /book prefiltrado.
- explore: cards con foto (hoy solo texto), hover reveal, filtros + búsqueda conservados.
- gallery: auditar y tratar.

## Fase 4 — Wizard reserva (3 pasos)

1. **Calendario custom**: dual-month desktop / single mobile, rango arrastre, estados (pasado/agotado/disponible/seleccionado), GSAP, bloquea sin disponibilidad (API + degradación).
2. **Algoritmo** (`src/features/booking/roomAllocator.ts`, TS puro): datos `baseOccupancy` + `maxCapacity` + `extraGuestCharge: 30`; genera 1 habitación con recargo + combinaciones multi-habitación; ordena por total; respeta inventario config y disponibilidad API; dedupe permutaciones; top 3-4 con desglose + badge "Mejor precio"; multi-selección con total en vivo. Desde /rooms con habitación elegida: solo esa + toggle "Ver alternativas".
3. **Pago rápido**: prefill auth, createHold + MP cardForm in-site, resumen sticky, errores inline.

## i18n

Todas las claves nuevas en 4 idiomas (en completo, es completo, fr/pt con fallback existente).

## No-go (prohibido)

- No cambiar fondo del modo oscuro (usuario probó tonalidades, no funciona).
- No añadir fuentes nuevas ni librerías (solo las existentes: GSAP/ScrollTrigger/SplitText/Lenis/Leaflet-a-eliminar).
- No implementar migraciones Channex→Nobeds / QloApps→CMS.
- No tocar el flujo de pago backend (createHold/processPayment siguen igual).
- No `quality` hardcodeados ni JPG 300 KB+: formatos avif/webp con config global (ya subida).
