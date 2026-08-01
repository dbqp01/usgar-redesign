# Phase 1 — Foundation (Awwwards Redesign) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corregir el modo oscuro, elevar la tipografía a nivel editorial, refinar motion global (cursor, transiciones de página, marquee), refactorizar componentes genéricos y auditar rendimiento — la base sobre la que se construyen las fases 2-4.

**Architecture:** Fase 1 del Enfoque A (Foundation-first). Todo es incremental sobre la arquitectura de animaciones ya refactorizada (`src/features/animations/`). No se toca backend de pagos ni se añaden librerías.

**Tech Stack:** Astro 7, Tailwind 4 (CSS-first), GSAP 3.15 + ScrollTrigger + SplitText, View Transitions (ClientRouter), sharp (astro:assets).

## Global Constraints

- Verificación por fase: `npx astro check` (0 errores) + `npm run build` + Chrome DevTools (start-chrome-debug.ps1). Sin Playwright.
- No cambiar fondos del dark mode (#121214 / #1A1A1E se mantienen).
- En dark: NUNCA `text-primary` sobre fondo oscuro → `text-primary-soft` (#A78BBD).
- Sin fuentes nuevas, sin librerías nuevas.
- i18n: claves nuevas en 4 locales.
- Commits pequeños por tema (`feat:`/`fix:`/`chore:`/`docs:`).
- No tocar generados (dist/, vendor/, node_modules/).

---

### Task 1: Tokens dark mode + auditoría de usos del morado

**Files:**
- Modify: `src/styles/global.css`
- Modify: componentes con `text-primary` sobre fondos oscuros (auditar con grep)

- [ ] **Step 1: Añadir tokens en `@theme` de `src/styles/global.css`**

```css
--color-primary-soft: #A78BBD;   /* lavanda legible sobre #121214 (AA) */
--color-primary-glow: #6B4F80;   /* gradiente botón dark: desde */
```

- [ ] **Step 2: Reglas de componente en global.css**

```css
.dark .btn-primary {
  background-image: linear-gradient(135deg, #6B4F80, #4A3163);
  box-shadow: 0 8px 24px -8px rgba(212, 191, 222, 0.25);
}
.dark .btn-primary:hover { box-shadow: 0 8px 32px -6px rgba(212, 191, 222, 0.4); }
.dark .border-tint { border-color: rgba(212, 191, 222, 0.12) !important; }
```

- [ ] **Step 3: Grep `text-primary` en componentes** y reemplazar en contextos dark (`dark:text-primary-soft`), manteniendo light intacto
- [ ] **Step 4: `npx astro check`** → 0 errores; **commit** `feat: dark mode tokens - lavender accents on dark surfaces`

### Task 2: Tipografía editorial

**Files:**
- Modify: `src/styles/global.css` (fluid scale + outline numbers + micro-labels)
- Modify: `src/layouts/Layout.astro` si hace falta (nada esperado)

- [ ] **Step 1: Escala display ampliada en global.css**

```css
.fluid-h1 { font-size: clamp(3rem, 8vw, 7.5rem); line-height: 0.95; letter-spacing: -0.02em; }
.section-number { -webkit-text-stroke: 1px var(--color-primary-soft); color: transparent; font-family: var(--font-display); }
.micro-label { font-family: ui-monospace, monospace; font-size: 0.7rem; letter-spacing: 0.3em; text-transform: uppercase; }
```

- [ ] **Step 2: Aplicar `.fluid-h1` al hero** (index.astro h1) y `.section-number` a secciones principales (home)
- [ ] **Step 3: `npx astro check`** → 0 errores; **commit** `feat: editorial display scale + section numbers`

### Task 3: Cursor refinado (regla: si no queda impecable → off)

**Files:**
- Modify: `src/components/common/CustomCursor.astro`

- [ ] **Step 1: Reemplazar dot simple por dot + ring con lag diferenciado**

Estructura: `<div id="custom-cursor"><span class="cursor-dot"></span><span class="cursor-ring"></span></div>`; dot sigue instantáneo (quickTo 0.15), ring con lag (quickTo 0.45); scale 3.5 + mezcla dorada al hover de interactivos; `data-cursor-hover` soportado; kill-switch `data-cursor-enabled="false"` en `<html>` lee settings; solo `(hover:hover) and (pointer:fine)` y sin reduced-motion (ya existe).
- [ ] **Step 2: `npx astro check`** → 0 errores
- [ ] **Step 3: Ver visual en Chrome DevTools** (mover sobre links); **commit** `feat: refined dual-layer custom cursor`

### Task 4: Marquee tipográfico divisor

**Files:**
- Create: `src/components/TypographicMarquee.astro` (prop `text`, acepta array de ítems)
- Modify: `src/pages/index.astro` (2 instancias: tras hero y antes de footer)

- [ ] **Step 1: Componente** — dos filas de ítems desplazadas con animación CSS existente (`marquee-scroll`) o GSAP `velocityMarquee` si es horizontal sin parar; texto grande display con separador ✦/•
- [ ] **Step 2: Integrar en home** con claves i18n (`global.marquee` array o string "SAN PEDRO • CUSCO • MACHU PICCHU")
- [ ] **Step 3: i18n 4 locales + `npx astro check` + build**; **commit** `feat: typographic marquee dividers`

### Task 5: Transición de página cinematográfica

**Files:**
- Create: `src/features/animations/modules/pageTransition.ts`
- Modify: `src/layouts/Layout.astro` (import en script; `transition:animate="none"` se mantiene en main)

- [ ] **Step 1: Módulo** — escucha `astro:before-preparation`: overlay panel (div fijo, z-90, fondo surface-card) se anima translateY(100%→0) con la imagen de la página destino (obtener del `data-astro-transition` snapshot o el `fetch` de la página — fallback: color primary puro); en `astro:after-swap` el panel sube y se destruye. Duración ~400 ms `power4.inOut`. Solo si `prefers-reduced-motion` no está activo.
- [ ] **Step 2: Registrar en `engine.ts`** (bootPageTransitions)
- [ ] **Step 3: Verificar en Chrome DevTools** navegando home↔rooms; **commit** `feat: cinematic page transition overlay`

### Task 6: Refactor RoomDetail.astro (864 líneas)

**Files:**
- Create: `src/components/room/RoomGallery.astro` (galería principal + lightbox + thumbnails)
- Create: `src/components/room/RoomAmenities.astro` (grid amenities desde `room.amenityLabels`)
- Create: `src/components/room/RoomBookingSidebar.astro` (precio, fechas, CTA → /book?roomType=)
- Modify: `src/pages/rooms/[slug].astro` (componer los 3)

- [ ] **Step 1: Leer `RoomDetail.astro` completo** y extraer galería (imágenes, estados activos, lightbox) sin cambiar comportamiento ni ids de interacción
- [ ] **Step 2: Extraer amenities** (usa RoomAmenitiesGrid? verificar) y **sidebar** (precio + CTA)
- [ ] **Step 3: `npx astro check` + build + ver habitación en DevTools** (interacciones intactas); **commit** `refactor: split RoomDetail into gallery/amenities/sidebar`

### Task 7: Refactor index.astro (742 líneas)

**Files:**
- Create: `src/components/Hero.astro` (video + slideshow + título + CTAs + coordenadas)
- Create: `src/components/ExploreShowcase.astro` (cinematic-showcase actual)
- Modify: `src/pages/index.astro` (usar componentes; mantener ids/scripts de animación)

- [ ] **Step 1: Extraer Hero** (video, slideshow, toggle audio, scroll indicator, título con clases nuevas) — conservar `hero-video`, `hero-slideshow`, `video-audio-toggle` y el script inline asociado movido al componente
- [ ] **Step 2: Extraer ExploreShowcase** (`#cinematic-showcase` + `initCinematicShowcase`) — se reemplazará en Fase 2 por carrusel magazine
- [ ] **Step 3: `npx astro check` + build + smoke en DevTools** (hero y showcase funcionan); **commit** `refactor: extract Hero and ExploreShowcase components`

### Task 8: Auditoría de rendimiento (CSS + imágenes + hardcodes)

**Files:**
- Modify: componentes con `quality={N}` restantes (grep) y tamaños
- Modify: `src/styles/global.css` si hay bloques duplicados

- [ ] **Step 1: Grep `quality={` y `format="jpg"`** → corregir a config global/avif
- [ ] **Step 2: Auditar CSS 120 KB** — buscar utilidades duplicadas por locale (comparar los CSS de dist) y bloques muertos
- [ ] **Step 3: `npm run build` + medir dist/_astro** (JS/CSS/imágenes); registrar números antes/después en `docs/refactoring/DECISIONS.md`; **commit** `perf: image formats audit + css dead-code pass`

### Task 9: Cierre de fase

- [ ] **Step 1: `npx astro check` + `npm run build`** (0 errores)
- [ ] **Step 2: Chrome DevTools trace** en home (móvil + desktop) — registrar LCP/CLS/INP en STATE.md
- [ ] **Step 3: Actualizar `docs/refactoring/STATE.md`** (hecho/en curso/siguiente) + **commit** `docs: phase 1 complete - state handoff`
