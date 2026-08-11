# Auditoría Frontend — USGAR Hotels (usgar-redesign)

Fecha: 2026-08-10 · Rama: main (16 archivos modificados en working tree — NO tocados)
Método: skills dogfood (QA exploratorio), repo-hygiene-audit (peso/assets), codebase-inspection (LOC) + verificación con documentación (Astro Docs, MDN, web-platform-dx, Lenis, Leaflet, GSAP)
Alcance: src/ completo (108 páginas build, 166 archivos astro check), flujo de reserva end-to-end, 5 páginas navegadas, consola JS, bundles de producción.

---

## 1. Resumen ejecutivo

El frontend está **funcionalmente sólido**: 0 errores JS en toda la navegación, `astro check` limpio (0 errores, 0 warnings), build de 108 páginas en 29.7s, wizard de reserva de 3 pasos verificado end-to-end (fechas → tarifa → huésped → pago MP). La arquitectura de librerías es **correcta y minimalista** (sin React, sin lodash, sin axios): cada dependencia es la estándar de su categoría y las APIs nativas ya se usan donde existen (Intl, fetch+AbortSignal, IntersectionObserver, View Transitions, prefetch, dialog, prefers-reduced-motion).

**Un problema real de rendimiento domina todo lo demás: los 4 videos del hero pesan 150 MB (34–41 MB cada uno).** El resto del sitio (JS+CSS+HTML) pesa ~100 KB por página.

Prioridad: [P1] comprimir/eliminar videos del hero · [P2] 3 hallazgos menores · [P3] optimizaciones opcionales.

---

## 2. Funcionalidad — evidencia de QA (dogfood)

| Flujo | Resultado | Evidencia |
|---|---|---|
| Home → widget disponibilidad | OK | Redirige a `/book?checkin=2026-08-15&checkout=2026-08-16&guests=2` |
| Wizard paso 1: calendario | OK | `<dialog>` accesible, días pasados `disabled`, rango default, selección aplica y cierra |
| Wizard paso 1: tarifas | OK | 4 opciones (1×4 habitaciones + 2 combos), Standard/Non-Refundable, preselección automática, combos multi-habitación deshabilitados por diseño (`payable = !multi && !outOfStock`, AllocationStep.astro:141) |
| Wizard paso 2: validación | OK | Email inválido → banner "Please enter a valid email address." + bloqueo de avance (GuestStep.astro:254) |
| Wizard paso 3: pago | OK | Iframes de MercadoPago con validación nativa del SDK (Expiration/Security code "Required data"); campos autofill de sesión |
| Páginas: /rooms, /contact, /login, /explore, /gallery | OK | Sin errores JS en consola (55 mensajes, 0 errores) |
| Navegación | OK | View Transitions + prefetch funcionando (logs `[astro] Prefetching ...` con estrategia viewport) |
| Calidad de código | OK | `npm run check`: 0 errores / 0 warnings / 1 hint (scripts/dev.js) — 166 archivos |

### Hallazgos funcionales menores

1. **P2 — `<title>` duplicado en la home**: `index.astro:108` → `${t("hero.title")} - ${siteSettings.hotelName}` donde ambos valen "USGAR Hotels" → `<title>USGAR Hotels - USGAR Hotels</title>` (verificado en HTML servido). Fix: usar el tagline como title (ej. `San Pedro, Cusco - Your gateway to the Andes`) o cambiar la clave `hero.title`.
2. **P2 — Sin página 404 custom**: no existe `src/pages/404.astro` ni `404.html`. En Hostinger el visitante recibe el 404 genérico del servidor (sin nav ni marca). Fix: una `404.astro` con Layout (10 min).
3. **P3 — El error-banner conserva el mensaje anterior**: al ocultarse no limpia `#error-message`; si un error posterior no lo reescribe, el texto viejo queda (visible solo si se re-muestra). Fix: limpiar en `hideError()`.
4. **P3 — UX combos multi-habitación**: se muestran precios de opciones de 2 habitaciones que no son seleccionables (disabled). Decisión documentada en código ("patrón del IBE de referencia"), pero visualmente puede confundir. Evaluar ocultarlas o marcarlas como "solo consulta".

---

## 3. Rendimiento

### Bundle JS real (producción, dist/_astro, medido con gzip)

| Recurso | Raw | Gzip | Carga |
|---|---|---|---|
| gsap + ScrollTrigger + SplitText | 119 KB | 46 KB | Inmediata (todas las páginas) |
| SmoothScroll (Lenis + init) | 22 KB | 6.5 KB | Inmediata (desktop, no touch) |
| ClientRouter (View Transitions) | 13.6 KB | 4.6 KB | Inmediata |
| Leaflet (mapa) | 145 KB | 42 KB | **Lazy**: solo cuando el mapa entra en viewport (IntersectionObserver rootMargin 400px + `import()` dinámico) |
| PaymentStep (paso 3) | 88 KB | 27 KB | Solo en /book |
| CSS Layout (crítico inline por critters + diferido) | 145 KB | 21 KB | Crítico inline, resto lazy |

Veredicto: la segmentación es correcta. Total base por página ≈ 60 KB gz de JS — razonable. Leaflet y PaymentStep correctamente aislados del camino crítico.

### Problema P1 — Videos del hero: 150 MB

```
dist/videos/hero-1.mp4         41 MB
dist/videos/hero-2.mp4         40 MB
dist/videos/hero-mobile-1.mp4  35 MB
dist/videos/hero-mobile-2.mp4  34 MB
Total hero: 150 MB (el 90%+ del peso del sitio)
```

- El hero usa `<video autoplay muted playsinline preload="metadata" poster="...avif">` con rotación desktop/mobile (`data-video-parts`).
- `preload="metadata"` limita la precarga inicial, pero **autoplay fuerza la descarga completa del archivo al reproducirse**: ~40 MB por visita en desktop, ~34 MB en móvil.
- El público objetivo (viajeros en Cusco, mayormente LTE/roaming) recibe el golpe más fuerte. 150 MB de assets estáticos también afectan el build de Hostinger y el tamaño del repo.
- El fallback ya existe y está optimizado: `poster` AVIF (hero-slide-patio, ~1-2 MB) + sharp.

Opciones (ordenadas por impacto, con respaldo web.dev "Video best practices"):
1. **Recomendada**: hero estático — poster AVIF + animación CSS (Ken Burns / parallax). Cero descarga extra, mismo look. El poster ya está generado.
2. Si se mantiene video: comprimir a H.264/AV1 ≤ 3 Mbps (40 MB → ~3-6 MB por clip de 10-15s), y/o recortar duración.
3. Eliminar la rotación de 2 videos por viewport (mitad del peso).

### P3 — Optimizaciones menores

- **Preload de 6 fuentes en todas las páginas** (Montserrat latin+latin-ext, Playfair normal x2 + italic x2). La doc de Astro advierte: "Font preloading should be done sparingly... Preloading too many fonts can impact performance" (docs.astro.build/guides/fonts). El `italic` de Playfair y los `latin-ext` rara vez son above-the-fold. Fix: `preload` selectivo (solo variantes usadas arriba del fold).
- Las imágenes ya están bien: AVIF responsive con 13 variantes por foto (sharp, effort 6, quality 85-90), breakpoints 480/960/1600 — correcto según Astro Image docs.

---

## 4. Librerías vs APIs nativas (verificado con documentación)

### 4.1 GSAP + ScrollTrigger + SplitText — MANTENER

- **La alternativa nativa** (CSS scroll-driven animations: `animation-timeline: view()/scroll()`) tiene soporte incompleto: Chrome/Edge desde 115 (jul 2023), Safari 26 (sept 2025), **Firefox NO soportado** — "Baseline availability blocked since September 2025 by Firefox" (web-platform-dx.github.io/web-features-explorer/features/scroll-driven-animations; bugs Mozilla 1324602/1676779 abiertos). Uso real: ~5.2% de page loads (Chrome Platform Status).
- Un sitio de producción con tráfico internacional (Firefox ≈ 2.5-3% global, y crece en mercados emergentes) NO puede depender de scroll-driven CSS hoy.
- GSAP es la estándar de la industria (web.dev, GSAP docs) y el proyecto ya lo usa con buenas prácticas: `gsap.matchMedia()` + condiciones `prefers-reduced-motion`, módulos con `import()` bajo demanda (hero/parallax/tilt solo en home), lifecycle con revert en `astro:before-preparation`.
- El proyecto YA mezcla nativo donde conviene: IntersectionObserver (globalReveals, autoMotion, LocationMap), `Element.animate`/CSS para micro-animaciones, `prefers-reduced-motion`.

### 4.2 Lenis — MANTENER

- Doc oficial (lenis.darkroom.engineering): "turns native scroll into a silky, controllable experience... **under 4kb**", estándar de la industria (Netflix, Google, Rockstar en su showcase).
- El efecto que produce (inercia/momentum del scroll) **no existe de forma nativa**: `scroll-behavior: smooth` solo anima sin momentum; `scrollend` es solo un evento. No hay API de browser que lo replique.
- El proyecto lo desactiva en móvil/touch (`pointer: coarse` o <1024px) — patrón correcto según las guías de Lenis. Su bundle completo (con init + sync ScrollTrigger) pesa 6.5 KB gz.

### 4.3 Leaflet — MANTENER (con lazy-load ya correcto)

- Doc oficial (leafletjs.com): "the leading open-source JavaScript library for mobile-friendly interactive maps", ~42 KB, sin dependencias.
- **No existe API nativa de mapas en browsers.** Las alternativas (iframe de Google/OSM embed) no ofrecen markers custom, panTo animado, tooltips ni tema oscuro — todo lo que usa el diseño actual (LocationMap.astro).
- El bundle real (145 KB raw) es `leaflet-src` (módulo completo; Leaflet no es tree-shakeable), pero se carga **solo cuando el mapa entra en viewport** (IntersectionObserver + import dinámico) — la práctica recomendada.

### 4.4 i18n propio — CORRECTO (patrón documentado)

- Astro NO incluye sistema de traducción de claves: sus docs de i18n cubren routing/fallback de páginas (que el proyecto ya usa vía `astro:i18n` + `fallback` en astro.config.mjs) y remiten a "your own or a library" para las claves.
- `src/i18n/utils.ts` (60 líneas) implementa el fallback por clave (fr→en, pt→es→en) sin dependencias — cumple la regla "stdlib primero": evita astro-i18next/paraglide.

### 4.5 Otras decisiones — CORRECTAS

- `fetch` + `AbortSignal.timeout(10000)` nativo en bookingService (sin axios) — APIs nativas modernas.
- `Intl.DateTimeFormat`/`NumberFormat` en format.ts — nativo.
- `debounce` propio de 15 líneas — no existe nativo; correcto.
- `URLSearchParams`, `dialog`, `EventTarget` como bus del wizardStore — nativos.
- View Transitions (`ClientRouter` + `fade`) y prefetch (`prefetchAll: true`, `defaultStrategy: 'viewport'`) — funcionalidad nativa de Astro, configurados según sus docs (incluye el fallback a `fetch()` en Safari, que no soporta `<link rel="prefetch">`).
- `critters()` + `@playform/compress` — pipeline estándar (el orden critters→compress es el mandato del README de PlayForm).

**Conclusión de la sección**: no hay ninguna librería reemplazable por una API nativa equivalente hoy. El frontend ya es "nativo-first" (0 dependencias de utilidades, 3 librerías de UI/animación/mapas, todas estándar de la industria y lazy/correctamente segmentadas).

---

## 5. Priorización de acciones

| # | Severidad | Acción | Esfuerzo |
|---|---|---|---|
| 1 | **P1** | Video hero: reemplazar por poster AVIF + Ken Burns CSS, o comprimir a ≤3 Mbps / quitar rotación | 30-60 min |
| 2 | P2 | `<title>` home: `hero.title` → tagline (o `title: hero.title + hotelName` si cambia la clave) | 2 min |
| 3 | P2 | Crear `src/pages/404.astro` con Layout | 15 min |
| 4 | P3 | Preload de fuentes selectivo (solo variantes above-the-fold) | 10 min |
| 5 | P3 | Limpiar mensaje del error-banner al ocultar | 5 min |
| 6 | P3 | Evaluar visibilidad de combos multi-habitación no seleccionables | decisión |

No se modificó código en esta auditoría (working tree del usuario intacto). Los fixes están listos para aplicarse con ponytail si se autoriza.

## 6. Notas de verificación

- Skills aplicadas: dogfood (QA exploratorio, 5 fases), repo-hygiene-audit (peso de assets: videos/JS/CSS), codebase-inspection (LOC: 3,813 líneas en pages/features del frontend).
- Docs consultadas: Astro Docs vía MCP astro-docs (prefetch, Font/preload, image service), MDN + web-platform-dx (scroll-driven animations y soporte), Lenis docs, Leaflet docs, web.dev (video best practices, citado por Astro Font docs).
- Checks ejecutados: `npm run build` (108 páginas OK), `npm run check` (0 errores), QA browser (0 errores JS en 6 páginas + flujo de reserva completo).
- No verificados: pago real (requiere credenciales MP + backend; cubierto por tests E2E del repo y api-harness), login real (requiere backend).
