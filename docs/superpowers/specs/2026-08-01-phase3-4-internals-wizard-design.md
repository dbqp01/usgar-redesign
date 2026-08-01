# Fase 3 + Fase 4 — Internas y Wizard de Reserva (design doc)

Fecha: 2026-08-01 | Estado: APROBADO por el usuario (alcance: Fase 3 + wizard visual; MP backend intacto)

## Objetivo

Terminar la parte visual del rediseño Awwwards: páginas internas (Fase 3) y el wizard de reserva de 3 pasos en `/book` (Fase 4), incluyendo el calendario custom y el algoritmo `roomAllocator.ts`. Las migraciones (Nobeds, Filament, pagos MP) quedan FUERA de este trabajo: el pago MercadoPago existente se mantiene tal cual (backend y cardForm actual).

## Decisiones confirmadas

| Tema | Decisión |
|------|----------|
| Alcance | Fase 3 completa + Fase 4 (wizard visual con algoritmo) |
| Wizard | 3 pasos con progreso visible: (1) fechas + habitaciones, (2) datos del huésped, (3) pago MP existente |
| Arquitectura wizard | Componentes por paso + store TS puro (no página por paso) |
| Cuentas | login, my-bookings, profile reciben rediseño completo, cero features nuevas |
| Pago | `createHold`/`processPayment`/cardForm MP siguen igual (no-go del spec original) |
| Stock | Inventario configurable NUEVO en `settings.json`: doble 8, matri 6, triple 4, familiar 2 (no existía; el spec lo mandaba y el código no lo tenía) |

## Patrón visual común (aplicado a todas las páginas)

- Headers editoriales: número de sección outline (01…), micro-label mono uppercase, título Playfair.
- Tarjetas `surface-card` con borde `black/5 dark:white/5`, hover gold `primary`.
- Imágenes `Picture` de astro:assets, formats `['avif','webp']`, AVIF effort 6 q85 (config global ya en astro.config.mjs:23-34).
- Animación GSAP `ScrollTrigger.batch` con `motion-reduce` → visible sin animar (patrón `src/features/animations/modules/`).
- Sin acentos en texto visible (regla global del usuario). i18n en 4 locales.

## Fase 3 — Páginas internas

1. **rooms/index**: grid editorial como `RoomsEditorialGrid` (home) con filtros de capacidad conservados (existen en `src/pages/rooms/index.astro:46-63`). CTA → `/book?roomType=<slug>` prefiltrado. `RoomCard` viejo se retira si queda sin uso.
2. **explore**: cards con foto (hoy solo texto) — `Picture` full-bleed, categoría, hover reveal del nombre + "Saber más" → `/explore/{slug}`. Filtros por categoría + búsqueda conservados (`src/pages/explore.astro:72-108`).
3. **gallery**: auditoría + editorial — grid existente con lightbox se conserva; pasar a `Picture`/AVIF y estandarizar espaciado.
4. **contact**: header editorial + form con inputs surface-card y focus gold. Sin features nuevas.
5. **login / my-bookings / profile**: lenguaje editorial (header con número, forms, estados vacío/error con paleta existente). Cero features nuevas.

## Fase 4 — Wizard de reserva (`/book`)

### Arquitectura

- `src/features/booking/` (nuevo módulo):
  - `wizardStore.ts` — store TS puro: `{ checkIn, checkOut, guests, selections, step, roomTypeFromUrl }`, métodos de mutación + suscripción (`subscribe(fn)` → unsubscribe). Sin librería de estado.
  - `calendar.ts` — generación de meses (dual desktop / single mobile), estados por día: pasado, agotado (sin disponibilidad), disponible, seleccionado, rango. Arrastre de rango (pointer events) + accesible (focus, arrow keys opcional, aria).
  - `roomAllocator.ts` — el algoritmo (abajo), incluye el cálculo de precios (noche × noches + recargos); no hay módulo de precios separado.
- Componentes en `src/components/booking/`:
  - `BookingCalendarStep.astro` + módulo de animación (GSAP fade/scale por día, motion-reduce off).
  - `AllocationStep.astro` — resultado del algoritmo: top 3-4 opciones con desglose (noche × noches, recargo extra, total), badge "Mejor precio", multi-selección con total en vivo. Desde `/book?roomType=X`: solo X + toggle "Ver alternativas".
  - `GuestStep.astro` — los datos del huésped actuales de `book.astro` (name/email/phone, airport-transfer opcional) refactorizados, sin cambiar el payload del backend.
  - `PaymentStep.astro` — el form `mp-payment-form` actual intacto (idempotente), solo maquetado dentro del paso 3.
  - `WizardProgress.astro` — indicador de 3 pasos con animación.
- `book.astro` se convierte en el shell: progreso + paso activo con transición GSAP (View Transitions ya configuradas en el proyecto).

### Calendario custom

- Dual-month en desktop, single en mobile.
- Disponibilidad: `bookingService.getAvailableRooms(checkIn, checkOut)` (existe, `src/services/bookingService.ts:24`). Degradación elegante: si la API falla (MISSING_CREDENTIALS o red), el calendario muestra todos los días futuros como disponibles y avisa con un banner sutil.
- Bloqueo: no permitir seleccionar sin noches válidas; check-out > check-in; máx. estancia 30 noches (configurable, constante en el módulo).
- Sin librería de calendario nueva (no-go del spec: solo GSAP/ScrollTrigger/SplitText/Lenis existentes).

### Algoritmo `roomAllocator.ts` (TS puro)

Entradas: `guests`, `checkIn`, `checkOut`, `rooms` (de `src/data/rooms.ts`), `inventory` (nuevo en settings.json), `availability` (API, nullable).

- Genera: (a) una sola habitación con recargo si `guests > baseOccupancy` (tope `maxCapacity` físico); (b) combinaciones de 2 habitaciones para cubrir el grupo; (c) descarta combos que excedan capacidad o inventario.
- Ordena por precio total (noche × noches + `extraGuestCharge` por extra × noches).
- Dedupe de permutaciones (matrimonial+doble == doble+matrimonial).
- Respeta inventario config (stock por habitación) y disponibilidad API cuando responde.
- Devuelve top 3-4 con desglose y flag `bestPrice`.
- Pure functions → unit tests en `tests/Unit/roomAllocator.test.ts`.

## i18n

Claves nuevas en 4 locales: wizard (pasos, calendario, combinaciones, badges, errores), explore (cards), contact, account pages. fr/pt con fallback existente (`src/i18n/utils.ts`).

## Verificación (contrato de no-regresión)

1. `npm run check` (astro check + TS) 0 errores.
2. Unit tests: `roomAllocator` (nuevos) + `i18n` (existentes) pasando.
3. `npm run build` OK.
4. E2E Playwright por paquete (patrón `tests/e2e/home-redesign.spec.ts`): rooms grid filtros, explore cards foto, wizard flujo completo (fechas → algoritmo → datos → pago visible), login/my-bookings sin regresión.
5. Caza de fallas final con context7 / astro-docs / tavily sobre: GSAP, Astro Picture, Tailwind v4, Leaflet, disponibilidad.

## No-go

- No migraciones (Nobeds/Filament/MP backend).
- No librerías nuevas.
- No features nuevas en account pages.
- No tocar payloads del backend de reserva.
- No acentos en texto visible.
