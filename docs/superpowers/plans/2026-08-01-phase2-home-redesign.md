# Fase 2.5 Home Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rediseñar el home de USGAR: grid editorial de habitaciones (reemplaza abanico 3D), fix sombras Explore, reseñas con logos SVG, showroom AUKA, servicios brutalistas + Also Included profesional, mapa Leaflet estático brutal con CTA a Google Maps, y footer con Follow Us limpio + barra "As Featured In".

**Architecture:** Componentes Astro autocontenidos (1 archivo por sección) + módulos GSAP en `src/features/animations/modules/` siguiendo el patrón existente (contexto GSAP, cleanup en `astro:before-preparation`, reduce-motion off). El mapa usa Leaflet con **import dinámico** (chunk aparte, carga solo al entrar en viewport) con tiles CARTO dark gratis. Datos ya existen en `data/rooms.ts`, `data/services.ts`, `data/reviews.ts`, `content/settings/settings.json`.

**Tech Stack:** Astro 7.1, Tailwind 4 (CSS-first), GSAP 3.15 + ScrollTrigger + SplitText, Leaflet (bajo demanda), i18n propio (`useTranslations`, 4 locales en JSON).

## Global Constraints

- i18n: TODA clave nueva debe añadirse a los 4 JSON (`en/es/fr/pt`) — editar con la tool edit, JAMÁS con regex de PowerShell (corrompe JSON).
- IDs de sección preservados para anclas: `#rooms`, `#gastronomy`, `#services`, `#map-section`, `#velocity-marquee-section`.
- Verificación: `npx astro check` (0 errores) + `npm run build` (108 páginas).
- Commits pequeños por tema con prefijo `feat:`/`fix:`; no mega-commits.
- Respetar `prefers-reduced-motion` (los módulos GSAP retornan sin animar).
- Cleanup de GSAP en `astro:before-preparation` (patrón existente en ExploreCarousel/CuscoMap).
- No tocar generados: dist/, node_modules/, vendor/.
- Regla del usuario: los carruseles NUNCA pausan en hover.

---

### Task 1: Fix sombras laterales ExploreCarousel

**Files:**
- Modify: `src/components/ExploreCarousel.astro:46-51`

**Interfaces:**
- Consumes: nada nuevo
- Produces: sección `#explore-magazine` con fades pegadas a los bordes de la columna de contenido

- [ ] **Step 1: Entender el bug**

El track tiene `pl-6 sm:pl-[max(1.5rem,calc((100vw-1400px)/2))]` (alinea la primera tarjeta con `max-w-[1400px]`), pero las fades están ancladas al contenedor full-width → en viewports ≥1400px queda un hueco entre la fade y la primera tarjeta.

- [ ] **Step 2: Mover las fades a la columna de contenido**

En `ExploreCarousel.astro`, reemplazar el bloque de fades (líneas 47-48) para que vivan DENTRO de un wrapper alineado al `max-w-[1400px]`:

```astro
<!-- Fades pegadas a la columna de contenido (no al viewport) -->
<div class="absolute inset-y-0 left-1/2 -translate-x-1/2 w-full max-w-[1400px] pointer-events-none z-20">
  <div class="absolute inset-y-0 left-0 w-20 sm:w-32 bg-gradient-to-r from-surface-light dark:from-surface-dark to-transparent"></div>
  <div class="absolute inset-y-0 right-0 w-20 sm:w-32 bg-gradient-to-l from-surface-light dark:from-surface-dark to-transparent"></div>
</div>
```

Eliminar las dos divs originales. El track conserva su `pl-*` (las fades ahora lo cubren simétricamente).

- [ ] **Step 3: Verificar**

Run: `npx astro check`
Expected: 0 errores

Run: `npm run build`
Expected: 108 pages, Complete!

- [ ] **Step 4: Commit**

```bash
git add src/components/ExploreCarousel.astro
git commit -m "fix: explore carousel edge fades aligned to content column"
```

---

### Task 2: Grid editorial de habitaciones (reemplaza abanico 3D)

**Files:**
- Create: `src/components/RoomsEditorialGrid.astro`
- Delete: `src/components/RoomsFanCarousel.astro`
- Delete: `src/features/animations/modules/roomsFanCarousel.ts`
- Modify: `src/pages/index.astro:10,139`

**Interfaces:**
- Consumes: `rooms` de `../data/rooms` (id, slug, name[lang], pricePerNight, maxGuests, amenities, amenityLabels, photoFolder), imágenes en `src/assets/rooms/<photoFolder>/`
- Produces: `<RoomsEditorialGrid />` (sin props; lee `Astro.currentLocale` internamente como los demás componentes)

- [ ] **Step 1: Crear RoomsEditorialGrid.astro**

Patrón bento asimétrico: hero (Doble) `lg:col-span-2 lg:row-span-2`, 3 tarjetas estándar. Estructura:

```astro
---
import { Image } from 'astro:assets';
import { getRelativeLocaleUrl } from 'astro:i18n';
import SectionHeader from './ui/SectionHeader.astro';
import { rooms } from '../data/rooms';
import { useTranslations, type Locale } from '../i18n/utils';

const lang = (Astro.currentLocale || 'en') as Locale;
const t = useTranslations(lang);

const imgGlob = import.meta.glob<{ default: ImageMetadata }>('../assets/rooms/**/*.jpg', { eager: true });

const gridRooms = rooms.slice(0, 4);
const sized = gridRooms.map((room, i) => ({
  room,
  hero: i === 0,
  img: imgGlob[`../assets/rooms/${room.photoFolder}/1.jpg`]?.default
    || imgGlob[`../assets/rooms/${room.photoFolder}/main.jpg`]?.default
    || Object.values(imgGlob).find((m) => m)?.default,
}));
---
```

Antes de codear, verificar la ruta real de imágenes con: `Get-ChildItem src/assets/rooms` y ajustar el patrón del glob y el nombre de archivo (1.jpg vs main.jpg).

Markup (por tarjeta):
- `<a href={getRelativeLocaleUrl(lang, `rooms/${room.slug}`)}>` con `class="group relative overflow-hidden rounded-3xl border border-black/5 dark:border-white/10"` + clases hero `lg:col-span-2 lg:row-span-2` vs estándar.
- `<Image src={img} alt={room.name[lang]} width={hero ? 1200 : 800} height={hero ? 900 : 600} format="webp" loading={hero ? 'eager' : 'lazy'} class="absolute inset-0 w-full h-full object-cover transition-transform duration-[1.5s] group-hover:scale-105" />`
- Overlay `bg-gradient-to-t from-black/90 via-black/35 to-transparent`
- Contenido inferior: `micro-label` con capacidad (`${room.maxGuests}` + t('rooms.guests')) · precio `$XX / night` (i18n: verificar claves existentes `rooms.*` en en.json), título `font-display text-3xl sm:text-4xl` (hero) / `text-2xl` (resto), 2 amenities (primeros 2 de `room.amenities` mapeados con `room.amenityLabels[id]?.[lang]`), CTA `t('rooms.viewRoom')` o similar existente.
- Título hero con `group-hover:text-secondary transition-colors` (patrón magazine).

Grid: `<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 grid-rows-auto gap-5 sm:gap-7">` — hero `lg:col-span-2` en md debe ocupar `md:col-span-2`.

- [ ] **Step 2: Actualizar index.astro**

```astro
import RoomsEditorialGrid from "../components/RoomsEditorialGrid.astro";
// ...
<RoomsEditorialGrid />
```

Eliminar el import de `RoomsFanCarousel` y su uso. Ajustar el comentario del bloque a `INTERACTIVE ROOMS — Editorial Grid`.

- [ ] **Step 3: Eliminar archivos del abanico**

`git rm src/components/RoomsFanCarousel.astro src/features/animations/modules/roomsFanCarousel.ts`

Verificar con grep que no queda ninguna referencia a `RoomsFanCarousel` ni `roomsFanCarousel` ni `rooms-fan-stage` ni `room-modal`:
`rg -l "RoomsFanCarousel|roomsFanCarousel|rooms-fan-stage|room-modal" src/`

- [ ] **Step 4: Verificar i18n**

Buscar claves de habitaciones existentes: `Select-String -Path src/i18n/en.json -Pattern '"rooms"' -Context 0,15`. Usar las claves existentes (`rooms.viewRoom` / `rooms.perNight` / `rooms.guests` o las que existan); si falta alguna, añadirla a los 4 JSON con la tool edit.

- [ ] **Step 5: Verificar**

Run: `npx astro check` → 0 errores
Run: `npm run build` → 108 pages

- [ ] **Step 6: Commit**

```bash
git add src/components/RoomsEditorialGrid.astro src/pages/index.astro src/i18n/*.json
git commit -m "feat: editorial rooms grid replaces 3D fan carousel"
```

---

### Task 3: ReviewMarquee — cartas con logos SVG oficiales

**Files:**
- Modify: `src/components/ReviewMarquee.astro`
- Create: `src/components/icons/PlatformLogos.astro` (componente de logos reutilizable: TripAdvisor, Booking, Google, Expedia — acepta `platform` y clases)

**Interfaces:**
- Consumes: `reviews` de `../data/reviews` (name, country, rating, text[lang], date[lang]); `t` de i18n
- Produces: `<PlatformLogo platform="tripadvisor|booking|google|expedia" class="..." />`

- [ ] **Step 1: Crear PlatformLogos.astro**

Cuatro SVGs inline (fill currentColor, viewBox 24x24) para poder teñirlos con `text-*`:
- **tripadvisor**: usar el path ya existente en `Footer.astro:164` (búho circular).
- **booking**: usar el path existente en `Footer.astro:169` (la B).
- **google**: trazar el G multicolor con fills propios (4 colores: #4285F4 #34A853 #FBBC05 #EA4335) — icono "G" de Google, viewBox 24x24, path estándar simplificado.
- **expedia**: la "E" de Expedia (path simplificado del logo 2023+, o la E con sombra — usar path oficial simplificado de la flecha).

Prop signature: `const { platform, class: cls = 'w-5 h-5' } = Astro.props;`

Usar `set:html` NO; escribir los SVGs como condicionales normales (patrón del Footer).

- [ ] **Step 2: Rediseñar la card de reseña**

En el map de reviews (línea 59-121), reemplazar:
- El badge de texto `{platform.name}` (línea 79-81) → `<PlatformLogo platform={platform.id} class="w-7 h-7" />` + label pequeño `{platform.label}` debajo (o al lado).
- Mantener: comilla serif, estrellas doradas, cita, divider, avatar+nombre.
- Añadir: `{review.country}` y `{review.date[lang]}` en el footer (línea 108-115): reemplazar `{platform.label}` por fila `nombre · país` y `{review.date[lang]}` en `text-[10px] uppercase tracking-widest`.

`platforms` array pasa a: `[{ id: 'tripadvisor', name: 'TRIPADVISOR', label: 'VERIFIED TRIPADVISOR REVIEW' }, ...]` (4 entradas, se mantiene el ciclo por índice).

- [ ] **Step 3: Barra de métricas superior con logos**

En la barra (líneas 29-47), reemplazar los `<span>TRIPADVISOR</span>` de texto por `<PlatformLogo platform="tripadvisor" class="w-4 h-4" />` + nombre; mantener las notas (5.0/5.0, 9.6/10, 4.9/5.0) y el número de reviews. Colores: Google con sus 4 colores nativos (no text-tint); TA/Booking/Expedia `text-text-secondary-light dark:text-text-secondary-dark` con hover color de marca (TA #34E0A1, Booking #003580 → hover).

- [ ] **Step 4: Verificar**

Run: `npx astro check` → 0 errores; `npm run build` → 108 pages

- [ ] **Step 5: Commit**

```bash
git add src/components/icons/PlatformLogos.astro src/components/ReviewMarquee.astro
git commit -m "feat: review cards with official platform SVG logos + country/date"
```

---

### Task 4: AUKA RESTOBAR — Showroom gastronómico editorial

**Files:**
- Modify: `src/components/GastronomySection.astro` (refactor visual)
- Create: `src/features/animations/modules/gastronomyShowroom.ts`

**Interfaces:**
- Consumes: `culinaryOffers` (ya en el componente: id, title[lang], schedule, metric, metricLabel, desc[lang], image)
- Produces: `initGastronomyShowroom(container: HTMLElement): () => void` (contexto GSAP + cleanup; respeta reduce-motion en el caller)

- [ ] **Step 1: Refactor del layout en GastronomySection.astro**

Reemplazar el grid bento (líneas 75-112) por:

```astro
<div id="gastronomy-showroom" class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12 items-stretch">
  <!-- Foto grande (crossfade) -->
  <div class="relative rounded-3xl overflow-hidden border border-black/5 dark:border-white/10 aspect-[4/3] lg:aspect-auto lg:min-h-[520px]">
    {culinaryOffers.map((offer, i) => (
      <div class="absolute inset-0 showroom-slide" data-slide={i} style={i === 0 ? '' : 'display:none'}>
        <Image src={offer.image} alt={offer.title[lang]} width={1200} height={900} format="webp" loading="lazy" class="w-full h-full object-cover" />
        <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
        <div class="absolute bottom-5 left-5 px-4 py-2 rounded-full bg-black/45 backdrop-blur-md border border-white/10 text-white text-[11px] font-mono tracking-[0.15em]">
          {offer.metric} — {offer.metricLabel[lang]}
        </div>
      </div>
    ))}
  </div>

  <!-- Lista numerada -->
  <div class="flex flex-col justify-center">
    {culinaryOffers.map((offer, i) => (
      <button
        type="button"
        class="showroom-row group text-left border-b border-black/10 dark:border-white/10 py-7 first:border-t transition-colors"
        data-row={i}
      >
        <div class="flex items-baseline gap-4">
          <span class="font-mono text-xs text-secondary">{String(i + 1).padStart(2, '0')}</span>
          <h3 class="font-display text-2xl sm:text-4xl text-text-primary-light dark:text-white group-hover:text-secondary transition-colors">
            {offer.title[lang]}
          </h3>
          <span class="ml-auto text-xs font-mono uppercase tracking-widest text-text-secondary-light dark:text-text-secondary-dark">{offer.schedule}</span>
        </div>
        <p class="showroom-desc mt-3 text-sm text-text-secondary-light dark:text-text-secondary-dark font-light max-w-lg opacity-0 max-h-0 overflow-hidden transition-all duration-500 group-hover:opacity-100 group-hover:max-h-40 group-hover:mt-3">
          {offer.desc[lang]}
        </p>
      </button>
    ))}
  </div>
</div>
```

Mantener SectionHeader (número 04, AUKA RESTOBAR). El crossfade de imagen lo maneja el módulo TS (Step 2).

- [ ] **Step 2: Crear gastronomyShowroom.ts**

```ts
import { gsap } from 'gsap';

export function initGastronomyShowroom(container: HTMLElement): () => void {
  const ctx = gsap.context(() => {
    const slides = Array.from(container.querySelectorAll<HTMLElement>('.showroom-slide'));
    const rows = Array.from(container.querySelectorAll<HTMLElement>('.showroom-row'));

    const show = (index: number) => {
      slides.forEach((slide, i) => {
        gsap.to(slide, { autoAlpha: i === index ? 1 : 0, duration: 0.45, ease: 'power2.out', immediateRender: false });
      });
    };

    rows.forEach((row, i) => {
      row.addEventListener('mouseenter', () => show(i));
      row.addEventListener('focus', () => show(i));
    });
  }, container);

  return () => ctx.revert();
}
```

Nota: `autoAlpha` requiere `visibility` inicial correcto — el slide 0 debe tener `style="visibility:visible;opacity:1"` y los demás `style="display:none"` se eliminan: en su lugar usar `class="absolute inset-0 showroom-slide"` con `gsap.set(slides[i], { autoAlpha: i === 0 ? 1 : 0 })` al inicio. Ajustar el HTML del Step 1: todos los slides visibles con autoAlpha 0 excepto el primero (quitar `style={i === 0 ? '' : 'display:none'}` y poner inline style autoAlpha en el módulo).

- [ ] **Step 3: Script del componente**

En el `<script>` de GastronomySection.astro (agregar si no existe):

```js
import { initGastronomyShowroom } from '../features/animations/modules/gastronomyShowroom';

let cleanup = null;
function initShowroom() {
  const root = document.getElementById('gastronomy-showroom');
  if (!root) return;
  cleanup?.();
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  cleanup = initGastronomyShowroom(root);
}
document.addEventListener('astro:page-load', initShowroom);
document.addEventListener('astro:before-preparation', () => { cleanup?.(); cleanup = null; }, { once: true });
```

- [ ] **Step 4: Verificar**

Run: `npx astro check` → 0 errores; `npm run build` → 108 pages

- [ ] **Step 5: Commit**

```bash
git add src/components/GastronomySection.astro src/features/animations/modules/gastronomyShowroom.ts
git commit -m "feat: AUKA restobar gastronomic showroom with crossfade"
```

---

### Task 5: Our Services brutalista + Also Included profesional

**Files:**
- Modify: `src/components/ServiceGrid.astro` (refactor completo del cuerpo)

**Interfaces:**
- Consumes: `highlights` (ya en el archivo: 4 servicios con title/desc/img/icon), `services` de `../data/services`, `t('global.alsoIncluded')`
- Produces: sección `#services` con filas numeradas + fila compacta de extras

- [ ] **Step 1: Reemplazar el grid bento por filas numeradas**

En `ServiceGrid.astro`, reemplazar el bloque del grid (líneas 76-110) por:

```astro
<!-- Lista tipográfica brutalista -->
<div class="divide-y divide-black/10 dark:divide-white/10 border-y border-black/10 dark:border-white/10">
  {highlights.map((item, i) => (
    <div class="group flex items-center gap-6 sm:gap-10 py-8 sm:py-10 transition-colors">
      <span class="font-mono text-xs sm:text-sm text-secondary w-8 flex-shrink-0">{String(i + 1).padStart(2, '0')}</span>
      <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-2xl bg-white dark:bg-surface-card-dark border border-black/10 dark:border-white/10 flex items-center justify-center flex-shrink-0 text-secondary transition-all duration-500 group-hover:bg-secondary group-hover:text-white" set:html={item.icon}></div>
      <div class="flex-1 min-w-0">
        <h3 class="font-display text-2xl sm:text-4xl text-text-primary-light dark:text-white group-hover:text-secondary transition-colors">{item.title[lang]}</h3>
        <p class="text-sm text-text-secondary-light dark:text-text-secondary-dark font-light mt-1 max-w-2xl opacity-0 max-h-0 overflow-hidden transition-all duration-500 group-hover:opacity-100 group-hover:max-h-24 group-hover:mt-2">{item.desc[lang]}</p>
      </div>
      <svg class="w-6 h-6 flex-shrink-0 text-text-secondary-light dark:text-text-secondary-dark opacity-0 -translate-x-2 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
      </svg>
    </div>
  ))}
</div>
```

(El `set:html={item.icon}` ya existe en el archivo — los `item.icon` son strings SVG con `w-6 h-6 text-white`; ajustar esas clases dentro del icono o envolver para que el color lo herede el contenedor: quitar `text-white` de los iconos en `highlights` — cambiar a `w-6 h-6` simple, el color viene del padre `text-secondary` / hover `text-white`.)

- [ ] **Step 2: Rediseñar "Also Included" (filas 112-139)**

Reemplazar las 6 tarjetitas por una fila tipográfica compacta:

```astro
<div class="mt-12 rounded-3xl border border-black/10 dark:border-white/10 bg-white dark:bg-surface-card-dark p-8 sm:p-10">
  <div class="flex items-center gap-4 mb-8">
    <h3 class="font-display text-2xl font-bold text-text-primary-light dark:text-white">{t('global.alsoIncluded')}</h3>
    <span class="flex-1 h-px bg-black/10 dark:bg-white/10"></span>
    <span class="font-mono text-[10px] uppercase tracking-[0.3em] text-text-secondary-light dark:text-text-secondary-dark">06</span>
  </div>
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-10 gap-y-5">
    {services.filter(s => !['breakfast', 'oxygen', 'tours'].includes(s.id)).slice(0, 6).map((service, i) => (
      <div class="flex items-center gap-3 text-text-primary-light dark:text-white">
        <span class="font-mono text-[10px] text-secondary w-5 flex-shrink-0">{String(i + 1).padStart(2, '0')}</span>
        <span class="w-4 h-px bg-secondary flex-shrink-0"></span>
        <span class="font-body text-sm font-medium truncate">{service.name[lang]}</span>
      </div>
    ))}
  </div>
</div>
```

Mantener los iconos SVG del Step 2 anterior NO es necesario — la fila numerada es tipográfica pura (más brutalista). Eliminar el bloque de `iconSvg` (líneas 121-129) y el map con tarjetitas.

- [ ] **Step 3: Verificar**

Run: `npx astro check` → 0 errores; `npm run build` → 108 pages

- [ ] **Step 4: Commit**

```bash
git add src/components/ServiceGrid.astro
git commit -m "feat: brutalist numbered services list + professional also-included row"
```

---

### Task 6: Mapa Leaflet estático brutal + CTA Google Maps

**Files:**
- Create: `src/components/LocationMap.astro`
- Create: `src/features/animations/modules/locationMap.ts` (lazy loader + init)
- Delete: `src/components/CuscoMap.astro`
- Delete: `src/features/animations/modules/cuscoMap.ts`
- Modify: `src/pages/index.astro` (import + uso)
- Modify: `src/pages/contact.astro` (import + uso, si usa CuscoMap)
- Modify: `src/i18n/en.json`, `es.json`, `fr.json`, `pt.json` (claves `map.directions`, reemplazar `map.addressHotel` si cambia)
- Verify: `leaflet` en package.json → si falta, `npm i leaflet`

**Interfaces:**
- Consumes: `siteSettings.latitude/longitude`, `t('map.*')`, Leaflet desde npm
- Produces: `<LocationMap />` (sin props); `initLocationMap(container: HTMLElement, lat: number, lng: number): Promise<() => void>` — carga Leaflet dinámicamente y monta el mapa estático

- [ ] **Step 1: Verificar dependencia Leaflet**

Run: `Select-String -Path package.json -Pattern '"leaflet"'`
- Si NO existe: `npm i leaflet`
- Si existe: continuar

- [ ] **Step 2: Crear locationMap.ts (lazy loader)**

```ts
let leafletPromise: Promise<typeof import('leaflet')> | null = null;

export async function initLocationMap(
  container: HTMLElement,
  lat: number,
  lng: number,
  zoom = 16
): Promise<() => void> {
  const L = await (leafletPromise ??= import('leaflet'));

  const map = L.map(container, {
    center: [lat, lng],
    zoom,
    dragging: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    touchZoom: false,
    zoomControl: false,
    keyboard: false,
    attributionControl: true,
  });

  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
    subdomains: 'abcd',
  }).addTo(map);

  const icon = L.divIcon({
    className: 'usgar-marker',
    html: '<div class="usgar-marker-core"></div>',
    iconSize: [36, 36],
    iconAnchor: [18, 18],
  });

  L.marker([lat, lng], { icon }).addTo(map);

  return () => { map.remove(); };
}
```

(Estilos `.usgar-marker` en el componente Step 3.)

- [ ] **Step 3: Crear LocationMap.astro**

Estructura (mantener `id="map-section"` para el ancla):

```astro
<section id="map-section" class="py-12 sm:py-16 px-4 bg-surface-card-light dark:bg-surface-dark text-text-primary-light dark:text-white relative overflow-hidden transition-colors duration-500">
  <div class="max-w-[1400px] mx-auto relative z-10 animate-on-scroll">
    <div class="text-center mb-12 space-y-3">
      <span class="tag-dash-title block">{t('map.sectionLabel')}</span>
      <h2 class="font-display text-4xl sm:text-5xl lg:text-7xl ...">{t('map.title')}</h2>
      <p class="...">{t('map.description')}</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-8 items-stretch">
      <!-- Mapa estático -->
      <div class="relative rounded-3xl overflow-hidden border border-black/5 dark:border-white/10 min-h-[420px] bg-[#17141C]">
        <div id="location-map" class="absolute inset-0"></div>
        <!-- Overlay brutal: esquinas + coordenadas -->
        <div class="absolute top-4 left-4 pointer-events-none">
          <span class="font-mono text-[10px] tracking-[0.3em] text-white/70 bg-black/40 backdrop-blur-md px-3 py-1.5 rounded-full border border-white/10">-13.5215° S · 71.9848° O</span>
        </div>
        <div class="absolute top-4 right-4 pointer-events-none font-mono text-[10px] tracking-[0.3em] text-white/70 bg-black/40 backdrop-blur-md px-3 py-1.5 rounded-full border border-white/10">CUSCO · PE</div>
        <!-- CTA Directions -->
        <a
          href={`https://www.google.com/maps/dir/?api=1&destination=${siteSettings.latitude},${siteSettings.longitude}`}
          target="_blank"
          rel="noopener noreferrer"
          class="absolute bottom-5 left-5 inline-flex items-center gap-2 btn-primary px-6 py-3 rounded-xl text-xs font-bold uppercase tracking-widest"
        >
          {t('map.directions')}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
        </a>
      </div>

      <!-- Columna info: dirección + distancias -->
      <div class="flex flex-col justify-center gap-6">
        <p class="font-mono text-xs uppercase tracking-[0.3em] text-secondary">{t('map.addressPrefix')}</p>
        <h3 class="font-display text-3xl sm:text-4xl">{t('map.addressHotel')}</h3>
        <div class="divide-y divide-black/10 dark:divide-white/10 border-y border-black/10 dark:border-white/10">
          <div class="flex justify-between py-4"><span class="text-sm text-text-secondary-light dark:text-text-secondary-dark">{t('map.marketLabel')}</span><span class="font-mono text-xs text-text-primary-light dark:text-white">{t('map.walkTime')} · 6 min</span></div>
          <div class="flex justify-between py-4"><span class="text-sm text-text-secondary-light dark:text-text-secondary-dark">{t('map.plazaLabel')}</span><span class="font-mono text-xs text-text-primary-light dark:text-white">{t('map.walkTime')} · 8 min</span></div>
        </div>
        <p class="text-sm text-text-secondary-light dark:text-text-secondary-dark font-light">{siteSettings.address[lang]}</p>
      </div>
    </div>
  </div>
</section>
```

Script del componente (lazy load con IntersectionObserver):

```js
import { initLocationMap } from '../features/animations/modules/locationMap';

let cleanup = null;
function initMap() {
  const el = document.getElementById('location-map');
  if (!el) return;
  cleanup?.();
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const lat = parseFloat(el.dataset.lat || '-13.521528');
  const lng = parseFloat(el.dataset.lng || '-71.984886');
  const io = new IntersectionObserver(async (entries) => {
    if (!entries[0].isIntersecting) return;
    io.disconnect();
    cleanup = await initLocationMap(el, lat, lng);
  }, { rootMargin: '400px' });
  io.observe(el);
}
document.addEventListener('astro:page-load', initMap);
document.addEventListener('astro:before-preparation', () => { cleanup?.(); cleanup = null; }, { once: true });
```

Pasar lat/lng vía `data-lat`/`data-lng` en el div (evitar interpolación en JS).

CSS del marcador (agregar al `<style>` del componente o al global `src/styles/global.css`):

```css
.usgar-marker-core {
  width: 26px; height: 26px;
  background: #D4AF37;
  border: 3px solid #fff;
  transform: rotate(45deg);
  box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.35);
}
```

- [ ] **Step 4: i18n — claves del mapa**

En los 4 JSON (`en/es/fr/pt`), dentro del bloque `map`: añadir `"directions"`:
- en: `"Get Directions"` · es: `"Cómo llegar"` · fr: `"Itinéraire"` · pt: `"Como chegar"`

Verificar claves existentes con `Select-String -Path src/i18n/en.json -Pattern '"map"' -Context 0,20`; conservar `sectionLabel/title/description/hotelLabel/here/marketLabel/plazaLabel/walkTime/addressPrefix/addressHotel` (las usa este componente). Si `here` ya no se usa, se puede conservar (sin daño) o limpiar.

Editar SOLO con la tool edit (nunca PowerShell regex sobre JSON).

- [ ] **Step 5: Reemplazar CuscoMap en index y contact**

En `index.astro`: `import LocationMap from "../components/LocationMap.astro";` + `<LocationMap />` (donde estaba `<CuscoMap />`).
En `contact.astro`: igual (verificar con `Select-String -Path src/pages/contact.astro -Pattern 'CuscoMap'`).

`git rm src/components/CuscoMap.astro src/features/animations/modules/cuscoMap.ts`

Verificar: `rg -l "CuscoMap|cuscoMap|cusco-illustrated-map|map-route|map-point|map-pulse" src/` → sin resultados.

- [ ] **Step 6: Verificar**

Run: `npx astro check` → 0 errores; `npm run build` → 108 pages.
Verificar chunk de Leaflet separado: `Get-ChildItem dist/_astro -Filter "*.js" | Where-Object { $_.Name -match 'leaflet' }` — debe existir un chunk con leaflet (puede no llamarse 'leaflet' por el hash; buscar en el HTML: `Select-String -Path dist/index.html -Pattern 'leaflet'` NO debe aparecer (no precarga); el chunk se carga bajo demanda).

- [ ] **Step 7: Commit**

```bash
git add src/components/LocationMap.astro src/features/animations/modules/locationMap.ts src/pages/index.astro src/pages/contact.astro src/i18n/en.json src/i18n/es.json src/i18n/fr.json src/i18n/pt.json package.json package-lock.json
git commit -m "feat: brutal static Leaflet map (CARTO dark) with Google Maps directions CTA"
```

---

### Task 7: Footer — Follow Us limpio + barra "As Featured In"

**Files:**
- Modify: `src/components/Footer.astro`
- Modify: `src/i18n/en.json`, `es.json`, `fr.json`, `pt.json` (clave `footer.asFeaturedIn`; retirar `certificationsLabel`)

**Interfaces:**
- Consumes: `socialLinks` de settings (platform: instagram/facebook/tripadvisor/booking), `tripadvisorUrl`/`bookingUrl`
- Produces: Follow Us solo con IG/FB; barra inferior "AS FEATURED IN" con logos TA + Booking

- [ ] **Step 1: Filtrar Follow Us a IG + FB**

En `Footer.astro`, en el bloque social (líneas 143-174), filtrar:

```astro
{socialLinks.filter(s => s.icon === 'instagram' || s.icon === 'facebook').map(social => ( ... ))}
```

Mantener los condicionales de IG/FB (las ramas tripadvisor/booking del map quedan muertas — eliminarlas junto con los dos `<svg>` de TA/Booking si quedarían sin uso; verificar si `PlatformLogos` del Task 3 los hace redundantes: usar `<PlatformLogo platform="tripadvisor" class="w-5 h-5" />` en la barra inferior en su lugar).

- [ ] **Step 2: Barra "As Featured In"**

Reemplazar el bloque certificaciones (líneas 181-200):

```astro
<div class="border-t border-black/10 dark:border-white/10 pt-8 mt-8 mb-6">
  <div class="flex flex-wrap items-center justify-center gap-x-12 gap-y-6">
    <span class="text-[10px] font-mono uppercase tracking-[0.3em] text-text-secondary-light/70 dark:text-text-secondary-dark/70">
      {t('footer.asFeaturedIn')}
    </span>
    <a href={tripadvisorUrl} target="_blank" rel="noopener noreferrer" class="group flex items-center gap-2 opacity-70 hover:opacity-100 transition-opacity">
      <PlatformLogo platform="tripadvisor" class="w-7 h-7 text-text-secondary-light dark:text-white" />
      <span class="font-display font-bold text-lg tracking-tight text-text-secondary-light dark:text-white">TripAdvisor</span>
    </a>
    <a href={bookingUrl} target="_blank" rel="noopener noreferrer" class="group flex items-center gap-2 opacity-70 hover:opacity-100 transition-opacity">
      <PlatformLogo platform="booking" class="w-7 h-7 text-text-secondary-light dark:text-white" />
      <span class="font-display font-bold text-lg tracking-tight text-text-secondary-light dark:text-white">Booking.com</span>
    </a>
  </div>
</div>
```

Importar `PlatformLogo` en el frontmatter del Footer.

- [ ] **Step 3: i18n — `footer.asFeaturedIn` en 4 locales**

Reemplazar `footer.certificationsLabel` → `footer.asFeaturedIn`:
- en: `"As Featured In"` · es: `"Como Aparecemos En"` · fr: `"Comme Présenté Dans"` · pt: `"Como Aparecemos Em"`

Editar los 4 JSON con la tool edit (buscar la línea `certificationsLabel` en cada uno y renombrar + traducir). Verificar que `Footer.astro` no referencie más `certificationsLabel`.

- [ ] **Step 4: Verificar**

Run: `npx astro check` → 0 errores; `npm run build` → 108 pages
Verificar: `rg -l "certificationsLabel" src/` → sin resultados.

- [ ] **Step 5: Commit**

```bash
git add src/components/Footer.astro src/i18n/en.json src/i18n/es.json src/i18n/fr.json src/i18n/pt.json
git commit -m "feat: footer follow-us socials only + as-featured-in trust bar with SVG logos"
```

---

### Task 8: Verificación final + STATE.md

**Files:**
- Modify: `docs/refactoring/STATE.md`

- [ ] **Step 1: Verificación completa**

Run: `npx astro check` → 0 errores
Run: `npm run build` → 108 pages, Complete!
Run: `git status` → limpio salvo lo commiteado

- [ ] **Step 2: Greps de limpieza**

```bash
rg -l "RoomsFanCarousel|roomsFanCarousel|CuscoMap|cuscoMap|certificationsLabel" src/   # → vacío
rg -l "MapSection|InteractiveRoomsSection|ExploreShowcase" src/                        # → vacío (fases previas)
```

- [ ] **Step 3: Actualizar STATE.md**

Marcar Fase 2.5 completa en `docs/refactoring/STATE.md`: grid editorial habitaciones, reseñas con logos, showroom AUKA, servicios brutalistas, mapa Leaflet estático brutal (CARTO dark + CTA GMaps, carga bajo demanda), footer As Featured In. Actualizar métricas (CSS final, chunk Leaflet bajo demanda).

- [ ] **Step 4: Commit final**

```bash
git add docs/refactoring/STATE.md
git commit -m "docs: phase 2.5 complete - state handoff + metrics"
```
