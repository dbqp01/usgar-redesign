# Design — Fase 2.5: Home Redesign (habitaciones, reseñas, AUKA, servicios, mapa, footer)

Fecha: 2026-08-01 · Estado: aprobado por el usuario

## Contexto

Fase 2 entregó home con abanico 3D de habitaciones, carrusel magazine en Explore, marquee de reseñas, bento de fotos en AUKA/Services, mapa SVG ilustrado y footer con newsletter. El usuario rechaza: abanico 3D, mapa SVG (se ve extraño), bento repetido en AUKA/Services, cartas de reseñas (quiere logos SVG reales), sombras del carrusel Explore mal posicionadas, y duplicación de TripAdvisor/Booking en Follow Us + barra inferior. Aprobado: grid editorial para habitaciones, mapa estático brutal (Leaflet + CARTO dark, sin interacción, CTA a Google Maps), patrones distintos para AUKA y Services, y barra "As Featured In" brutal para los logos.

## Alcance (solo home; páginas internas = Fase 3 separada)

### 1. Habitaciones → Grid editorial premium (reemplaza abanico 3D)
- Bento asimétrico 4 tarjetas: hero grande (Doble, col-span-2 row-span-2) + 3 estándar (Matrimonial, Triple, Familiar).
- Foto full-bleed webp (eager en hero), hover: zoom + reveal precio/noche, capacidad, 2 amenities, CTA "Reservar" → `/book?roomType=<id>`.
- Datos: `data/rooms.ts` (id, name[lang], pricePerNight, maxGuests, amenities, amenityLabels, photoFolder + imágenes). Sin JS nuevo: CSS hover + `animate-on-scroll`.
- Eliminar: `RoomsFanCarousel.astro`, `features/animations/modules/roomsFanCarousel.ts`. Actualizar `index.astro`.
- Componente nuevo: `src/components/RoomsEditorialGrid.astro`.

### 2. ExploreCusco → fix sombras laterales
- Bug: fades ancladas al viewport pero el track tiene `pl-[max(1.5rem,calc((100vw-1400px)/2))]` → hueco entre fade y primera tarjeta en pantallas ≥1400px.
- Fix: anclar las 2 fades a los bordes de la columna de contenido (max-w-[1400px]) dentro de un wrapper que también contenga el padding; o mover el padding al contenedor exterior con las fades dentro. 0 JS.

### 3. Reseñas → carrusel mantenido + cartas con logos SVG oficiales
- Mantener velocity marquee (sin pausa en hover, regla del usuario).
- Cards: logo SVG oficial de plataforma en cabecera (TripAdvisor búho, Booking ●B, Google G, Expedia E) + cita serif + footer avatar inicial + nombre + país + fecha + estrellas doradas.
- Barra de métricas superior con los mismos logos SVG (monocromo → color en hover, estética editorial).
- Fuente: `data/reviews.ts` (name, country, rating, text[lang], date[lang]); plataforma asignada por índice (ciclo TA/Booking/Google/Expedia).

### 4. AUKA RESTOBAR → Showroom gastronómico editorial
- Layout 2 columnas: foto grande izquierda (cambia en crossfade GSAP) + lista numerada 01/02/03 a la derecha (Buffet, Mates, Cafetería — datos ya en GastronomySection).
- Hover fila → crossfade de imagen (gsap.to autoAlpha, ~0.4s) + fila activa destacada (borde/color secundario) + muestra schedule + metric.
- Módulo: `features/animations/modules/gastronomyShowroom.ts` (GSAP, contexto, cleanup, reduce-motion off).
- Datos: `culinaryOffers` (título[lang], schedule, metric, metricLabel, desc[lang], imagen) — mantener estructura, refactor visual.
- Componente: refactor `GastronomySection.astro`.

### 5. Our Services → Lista tipográfica brutalista + Also Included profesional
- 4 filas numeradas 01/02/03/04 full-width: icono lineal grande + nombre[lang] + desc[lang] (oculta, reveal al hover con despliegue) + flecha.
- Eliminar grid de fotos repetidas. Datos: `highlights` actuales (breakfast/oxygen/tours/comfort) con iconos SVG ya definidos.
- "Also Included": fila tipográfica compacta "01 WiFi · 02 Agua caliente · 03 Calefacción · 04 TV · 05 Lavandería · 06 Transfer" con iconos lineales pequeños; título `global.alsoIncluded`; más limpia que las 6 tarjetitas.
- Componente: refactor `ServiceGrid.astro` (o renombrar a `ServicesSection.astro` si el archivo queda irreconocible — mantener nombre para no tocar import en index).

### 6. Mapa → Leaflet estático brutal + CTA Google Maps
- Tiles: CARTO `dark_all` (`https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png`), gratis sin key, atribución "© OpenStreetMap © CARTO".
- Modo estático: `dragging:false, scrollWheelZoom:false, doubleClickZoom:false, touchZoom:false, zoomControl:false, keyboard:false`.
- Overlay brutal: marcador custom divIcon (rombo dorado con anillo), overlay de coordenadas tipográficas (-13.5215° S, 71.9848° O) y etiquetas de calles.
- CTA "GET DIRECTIONS →" → `https://www.google.com/maps/dir/?api=1&destination=<lat>,<lng>` (target _blank). i18n nuevo `map.directions` en 4 locales.
- Perf: `import('leaflet')` dinámico cuando la sección entra en viewport (IntersectionObserver) + `leaflet/dist/leaflet.css` también dinámico (import css en el módulo dinámico). Evita afectar LCP/bundle inicial.
- Reemplazar: `CuscoMap.astro` → `LocationMap.astro`; `cuscoMap.ts` → `locationMap.ts` (init + lazy loader).
- Verificar `leaflet` en package.json (si se removió el paquete en Fase 2, reinstalar: `npm i leaflet`).

### 7. Footer → Follow Us limpio + barra "As Featured In" brutal
- Follow Us: SOLO Instagram + Facebook (filtrar socialLinks por plataforma).
- Barra inferior: label "AS FEATURED IN" (nueva key `footer.asFeaturedIn`, reemplaza `certificationsLabel` en 4 locales) + logos SVG grandes TripAdvisor + Booking.com (monocromo, hover color), enlazados a `tripadvisorUrl`/`bookingUrl` de settings.
- La sección de Reviews ya exhibe las métricas — la barra inferior queda como press logos (estética brutal).

### 8. Fuera de alcance (Fase 3)
Páginas internas (rooms, explore, gallery, book, login, my-bookings, profile, contact) → spec/plan propio.

## Criterios de éxito
- `npx astro check`: 0 errores; `npm run build`: 108 páginas OK.
- Sin `RoomsFanCarousel` ni `CuscoMap` en el código; Leaflet solo se carga bajo demanda en el mapa (verificar en red: chunk aparte).
- i18n completo en 4 locales para toda clave nueva (asFeaturedIn, map.directions, y cualquier otra).
- Sin regresión: engine de animaciones intacto, IDs de sección (rooms/services/gastronomy/map-section) preservados para anclas.
- Commits pequeños por tema (`feat:`/`fix:`), prefijo y mensaje descriptivo; no mega-commits.

## Riesgos
- Leaflet dinámico: asegurar CSS cargado junto con el JS (import en el mismo chunk) o el mapa sale sin estilos.
- SVGs de logos (TA/Booking/Google/Expedia): trazar rutas SVG oficiales simplificadas; validar visualmente en dark y light.
- Crossfade de AUKA: respetar prefers-reduced-motion y limpiar contextos en astro:before-preparation.
