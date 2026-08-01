# Fase 3 + Fase 4 — Internas y Wizard de Reserva: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Terminar la parte visual del rediseño: páginas internas editoriales (rooms, explore, gallery, contact, login, my-bookings, profile) y el wizard de reserva de 3 pasos en `/book` con calendario custom y algoritmo `roomAllocator`.

**Architecture:** Wizard = componentes por paso + store TS puro en `src/features/booking/` (sin librerías nuevas). Internas = reuso del lenguaje editorial de la home (`SectionHeader`, `Picture`, GSAP `ScrollTrigger.batch`). El pago MP existente se mueve a `PaymentStep` sin tocar backend.

**Tech Stack:** Astro 7.1 (static), Tailwind CSS 4, GSAP 3.15 + ScrollTrigger, vitest 3 (tests/Unit), Playwright (tests/e2e). Referencia de `<Picture>`: `src/components/RoomsEditorialGrid.astro`.

## Global Constraints

- **Sin acentos** en texto visible (regla global del usuario; rompen la tipografía display).
- **Sin librerías nuevas** (no-go del spec: solo GSAP/ScrollTrigger/SplitText/Lenis existentes; calendario hecho a mano).
- **No tocar backend de reserva** (createHold/processPayment/cardForm MP intactos; solo maquetado).
- i18n: claves nuevas en los 4 locales (fr→en, pt→es por fallback de `astro.config.mjs:55-58`), top-level keys idénticos (test `tests/Unit/i18n.test.ts`).
- Imágenes: `Picture` de `astro:assets` con `formats={['avif','webp']}` (config global `astro.config.mjs:23-34`).
- Animaciones: GSAP con `gsap.context()` y `prefers-reduced-motion` → visible sin animar (patrón `src/features/animations/modules/exploreAtlas.ts`).
- Verificación por tarea: `npx astro check` + `npx vitest run` + `npm run build` (no empeorar línea base) + E2E cuando aplique.
- Commits pequeños con prefijo `feat:` / `fix:` / `docs:` / `chore:`.

---

### Task 1: roomAllocator.ts (algoritmo de habitaciones, TDD)

**Files:**
- Create: `src/features/booking/roomAllocator.ts`
- Test: `tests/Unit/roomAllocator.test.ts`

**Interfaces:**
- Consumes: nada (TS puro, sin imports del proyecto).
- Produces (usado por Task 2 store, Task 11 AllocationStep):

```ts
export interface AllocatableRoom {
  slug: string;
  displayName: string;
  baseOccupancy: number;
  maxCapacity: number;
  extraGuestCharge: number;
  pricePerNight: number;
  inventory: number;
}

export interface AllocationRoomEntry { room: AllocatableRoom; guests: number; }

export interface AllocationOption {
  rooms: AllocationRoomEntry[];
  nights: number;
  roomTotal: number;
  extrasTotal: number;
  total: number;
  bestPrice: boolean;
}

export interface AllocateInput {
  guests: number;
  nights: number;
  rooms: AllocatableRoom[];
  availability?: Record<string, number> | null; // slug -> unidades disponibles (null = API caída)
}

export function allocateRooms(input: AllocateInput): AllocationOption[];
```

- [ ] **Step 1: escribir el test (8 casos)**

`tests/Unit/roomAllocator.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { allocateRooms } from '../../src/features/booking/roomAllocator';
import type { AllocatableRoom } from '../../src/features/booking/roomAllocator';

const doble: AllocatableRoom = { slug: 'doble-superior', displayName: 'Doble', baseOccupancy: 2, maxCapacity: 3, extraGuestCharge: 30, pricePerNight: 80, inventory: 8 };
const matri: AllocatableRoom = { slug: 'matrimonial', displayName: 'Matrimonial', baseOccupancy: 2, maxCapacity: 2, extraGuestCharge: 30, pricePerNight: 70, inventory: 6 };
const triple: AllocatableRoom = { slug: 'triple-superior', displayName: 'Triple', baseOccupancy: 3, maxCapacity: 4, extraGuestCharge: 30, pricePerNight: 95, inventory: 4 };
const fam: AllocatableRoom = { slug: 'familiar-superior', displayName: 'Familiar', baseOccupancy: 4, maxCapacity: 7, extraGuestCharge: 30, pricePerNight: 140, inventory: 2 };
const ROOMS = [doble, matri, triple, fam];

describe('allocateRooms', () => {
  it('1 huésped: opciones individuales ordenadas por total, primera con bestPrice', () => {
    const opts = allocateRooms({ guests: 1, nights: 2, rooms: ROOMS });
    expect(opts[0].bestPrice).toBe(true);
    expect(opts[0].total).toBe(Math.min(80, 70, 95, 140) * 2);
    expect(opts[0].rooms).toHaveLength(1);
  });

  it('recargo por huésped extra sobre baseOccupancy', () => {
    const opts = allocateRooms({ guests: 3, nights: 1, rooms: [doble] });
    expect(opts[0].total).toBe(80 + 30);
    expect(opts[0].extrasTotal).toBe(30);
  });

  it('no permite exceder maxCapacity', () => {
    const opts = allocateRooms({ guests: 3, nights: 1, rooms: [matri] });
    expect(opts).toHaveLength(0);
  });

  it('combinación de 2 habitaciones para grupo grande (4 huéspedes)', () => {
    const opts = allocateRooms({ guests: 4, nights: 1, rooms: [doble, matri] });
    const combo = opts.find((o) => o.rooms.length === 2);
    expect(combo).toBeDefined();
    expect(combo!.rooms.reduce((s, r) => s + r.guests, 0)).toBe(4);
    expect(combo!.total).toBe(80 + 70);
  });

  it('dedupe permutaciones: doble+matri == matri+doble', () => {
    const opts = allocateRooms({ guests: 4, nights: 1, rooms: [doble, matri] });
    const combos = opts.filter((o) => o.rooms.length === 2);
    const keys = combos.map((o) => o.rooms.map((r) => r.room.slug).sort().join('+'));
    expect(new Set(keys).size).toBe(keys.length);
  });

  it('respeta inventario: stock 0 excluye la habitación', () => {
    const sinStock: AllocatableRoom = { ...doble, inventory: 0 };
    const opts = allocateRooms({ guests: 2, nights: 1, rooms: [sinStock, matri] });
    expect(opts.every((o) => o.rooms.every((r) => r.room.slug !== 'doble-superior'))).toBe(true);
  });

  it('respeta disponibilidad API: agotado excluye', () => {
    const opts = allocateRooms({ guests: 2, nights: 1, rooms: [doble, matri], availability: { 'doble-superior': 0 } });
    expect(opts.every((o) => o.rooms.every((r) => r.room.slug !== 'doble-superior'))).toBe(true);
  });

  it('availability null (API caída) no excluye nada', () => {
    const opts = allocateRooms({ guests: 2, nights: 1, rooms: [doble], availability: null });
    expect(opts.length).toBeGreaterThan(0);
  });

  it('2 habitaciones iguales requieren inventario >= 2', () => {
    const uno: AllocatableRoom = { ...doble, inventory: 1 };
    const opts = allocateRooms({ guests: 4, nights: 1, rooms: [uno] });
    expect(opts.every((o) => o.rooms.length === 1)).toBe(true);
  });
});
```

- [ ] **Step 2: correr test y verificar que falla**

Run: `npx vitest run tests/Unit/roomAllocator.test.ts`
Expected: FAIL (módulo no existe).

- [ ] **Step 3: implementar `allocateRooms`**

Lógica (TS puro):
1. `bestFit(room, guests)`: descarta si `guests > maxCapacity` o `inventory === 0` o (availability !== null && `availability[slug] ?? 0` < 1).
2. Opciones individuales: cada habitación con `bestFit(room, guests)` → `{ rooms: [{room, guests}], nights, roomTotal: room.pricePerNight*nights, extrasTotal: max(0, guests - baseOccupancy) * extraGuestCharge * nights, total }`.
3. Combinaciones de 2: pares `(i, j)` con `i <= j`; requisito `inventory >= 2` si `i === j` y bestFit para cada una; partición de huéspedes: `g1` desde `max(1, guests - rooms[j].maxCapacity)` hasta `min(rooms[i].maxCapacity, guests - 1)`, con `g2 = guests - g1`, y `g1 >= 1 && g2 >= 1`. Costo = suma de ambos (roomTotal + extras).
4. Dedupe: key canónica `slugs.sort().join('+') + ':' + g1` (la partición distinta sí es opción distinta).
5. Orden por `total` asc; `bestPrice = true` solo en `opts[0]`.
6. Retornar todas; la UI toma top 4.

- [ ] **Step 4: correr test y verificar que pasa**

Run: `npx vitest run tests/Unit/roomAllocator.test.ts`
Expected: PASS 9/9.

- [ ] **Step 5: commit**

```bash
git add src/features/booking/roomAllocator.ts tests/Unit/roomAllocator.test.ts
git commit -m "feat: roomAllocator algorithm with inventory and availability"
```

---

### Task 2: wizardStore.ts (estado del wizard, TDD)

**Files:**
- Create: `src/features/booking/wizardStore.ts`
- Test: `tests/Unit/wizardStore.test.ts`

**Interfaces:**
- Consumes: `AllocationOption` de Task 1.
- Produces (usado por Task 11):

```ts
export type WizardStep = 1 | 2 | 3;

export interface WizardState {
  step: WizardStep;
  checkIn: string;        // ISO yyyy-mm-dd o ''
  checkOut: string;
  guests: number;
  roomType: string | null; // slug prefiltrado desde /book?roomType=X
  allocation: AllocationOption | null;
  selecting: boolean;      // true mientras se pide disponibilidad
  error: string | null;    // mensaje de degradación/error de disponibilidad
}

export interface WizardStore {
  getState(): WizardState;
  setState(patch: Partial<WizardState>): void;
  subscribe(fn: (s: WizardState) => void): () => void;
  next(): void;
  back(): void;
}

export function createWizardStore(initial?: Partial<WizardState>): WizardStore;
```

- [ ] **Step 1: escribir el test**

```ts
import { describe, it, expect } from 'vitest';
import { createWizardStore } from '../../src/features/booking/wizardStore';

describe('createWizardStore', () => {
  it('estado inicial por defecto', () => {
    const s = createWizardStore();
    expect(s.getState().step).toBe(1);
    expect(s.getState().checkIn).toBe('');
    expect(s.getState().guests).toBe(2);
  });

  it('setState notifica a suscriptores', () => {
    const s = createWizardStore();
    let seen: unknown = null;
    const unsub = s.subscribe((st) => { seen = st; });
    s.setState({ guests: 3 });
    expect((seen as { guests: number }).guests).toBe(3);
    unsub();
  });

  it('next avanza y back retrocede dentro de 1..3', () => {
    const s = createWizardStore();
    s.next(); expect(s.getState().step).toBe(2);
    s.next(); expect(s.getState().step).toBe(3);
    s.next(); expect(s.getState().step).toBe(3);
    s.back(); expect(s.getState().step).toBe(2);
    s.back(); expect(s.getState().step).toBe(1);
    s.back(); expect(s.getState().step).toBe(1);
  });

  it('initial parcial es respetado', () => {
    const s = createWizardStore({ checkIn: '2026-08-10', step: 2 });
    expect(s.getState().checkIn).toBe('2026-08-10');
    expect(s.getState().step).toBe(2);
  });
});
```

- [ ] **Step 2: correr y verificar que falla**

Run: `npx vitest run tests/Unit/wizardStore.test.ts`
Expected: FAIL (módulo no existe).

- [ ] **Step 3: implementar** — store cerrado con `state` interno, `setState` hace merge y notifica a todos los suscriptores, `next()/back()` clamp a `[1,3]`.

- [ ] **Step 4: correr y verificar PASS**

- [ ] **Step 5: commit**

```bash
git add src/features/booking/wizardStore.ts tests/Unit/wizardStore.test.ts
git commit -m "feat: wizard store with step navigation and subscription"
```

---

### Task 3: calendar.ts (grid de meses y rango, TDD)

**Files:**
- Create: `src/features/booking/calendar.ts`
- Test: `tests/Unit/calendar.test.ts`

**Interfaces:**
- Consumes: nada.
- Produces (usado por Task 11 BookingCalendarStep):

```ts
export interface CalendarDay {
  date: string;    // ISO yyyy-mm-dd
  inMonth: boolean; // false = celda vacía de otro mes
  past: boolean;
}

export function buildMonthGrid(year: number, month: number, today?: string): CalendarDay[];
export function nightsBetween(checkIn: string, checkOut: string): number;
export function clampRange(checkIn: string, checkOut: string, maxNights?: number): { checkIn: string; checkOut: string };
export function formatISODate(d: Date): string;
```

Decisión (spec vs forma de la API): la API de disponibilidad responde por RANGO (`bookingService.getAvailableRooms(checkIn, checkOut)` devuelve `RoomAvailability[]`, no por día — `src/services/contracts/IBookingService.ts`). Por tanto el estado "agotado" es a nivel de rango (banner + bloqueo al validar), no por día. `buildMonthGrid` marca solo `past`; el estado seleccionado/rango lo calcula el componente con `nightsBetween` y `clampRange`.

- [ ] **Step 1: escribir el test**

```ts
import { describe, it, expect } from 'vitest';
import { buildMonthGrid, nightsBetween, clampRange, formatISODate } from '../../src/features/booking/calendar';

describe('calendar', () => {
  it('buildMonthGrid: 42 celdas y días pasados marcados', () => {
    const days = buildMonthGrid(2026, 7, '2026-08-10'); // agosto 2026
    expect(days).toHaveLength(42);
    expect(days.filter((d) => d.inMonth).length).toBe(31);
    const past = days.find((d) => d.date === '2026-08-05');
    expect(past?.past).toBe(true);
    const future = days.find((d) => d.date === '2026-08-15');
    expect(future?.past).toBe(false);
  });

  it('nightsBetween: diferencia correcta', () => {
    expect(nightsBetween('2026-08-10', '2026-08-13')).toBe(3);
    expect(nightsBetween('2026-08-13', '2026-08-10')).toBe(-3);
  });

  it('clampRange: invierte fechas si checkOut <= checkIn', () => {
    const r = clampRange('2026-08-13', '2026-08-10');
    expect(r).toEqual({ checkIn: '2026-08-10', checkOut: '2026-08-13' });
  });

  it('clampRange: limita a maxNights (30 por defecto)', () => {
    const r = clampRange('2026-08-01', '2026-09-15');
    expect(nightsBetween(r.checkIn, r.checkOut)).toBeLessThanOrEqual(30);
  });

  it('formatISODate: yyyy-mm-dd local', () => {
    expect(formatISODate(new Date(2026, 7, 5))).toBe('2026-08-05');
  });
});
```

- [ ] **Step 2: correr y verificar que falla**

- [ ] **Step 3: implementar** — `buildMonthGrid` (primer día `new Date(year, month, 1)`, offset `getDay()`, 42 celdas, `past = date < today` comparando ISO strings), `nightsBetween` (diff / 86_400_000, redondeo), `clampRange` (swap + límite), `formatISODate` (local, no UTC — usar getFullYear/getMonth/getDate).

- [ ] **Step 4: correr y verificar PASS**

- [ ] **Step 5: commit**

```bash
git add src/features/booking/calendar.ts tests/Unit/calendar.test.ts
git commit -m "feat: calendar month grid and range helpers"
```

---

### Task 4: Inventario configurable en settings.json

**Files:**
- Modify: `src/content/settings/settings.json`
- Modify: `src/data/settings.ts` (tipado)
- Modify: `src/data/rooms.ts` (helper `getRoomInventory()`)

**Interfaces:**
- Consumes: nada.
- Produces: `Record<string, number>` slug→stock (doble 8, matri 6, triple 4, familiar 2, según spec), consumido por Task 11 para construir `AllocatableRoom[].inventory`.

- [ ] **Step 1: añadir al JSON** (dentro del objeto raíz de `settings.json`, tras `"starRating"`):

```json
"roomInventory": {
  "doble-superior": 8,
  "matrimonial": 6,
  "triple-superior": 4,
  "familiar-superior": 2
}
```

- [ ] **Step 2: tipar en `src/data/settings.ts`** — añadir `roomInventory?: Record<string, number>` al interfaz que parsea el JSON y exportar `getRoomInventory(): Record<string, number>` con fallback al objeto anterior si la clave falta.

- [ ] **Step 3: verificar**

Run: `npx astro check`
Expected: 0 errores. Run: `npx vitest run` (paridad i18n intacta).

- [ ] **Step 4: commit**

```bash
git add src/content/settings/settings.json src/data/settings.ts
git commit -m "feat: configurable room inventory in settings.json"
```

---

### Task 5: i18n — claves del wizard y de las internas (4 locales)

**Files:**
- Modify: `src/i18n/en.json`, `src/i18n/es.json`, `src/i18n/fr.json`, `src/i18n/pt.json`
- Test: `tests/Unit/i18n.test.ts` (paridad de top-level keys)

**Interfaces:**
- Consumes: patrón de claves existente (`book.*`, `booking.*`).
- Produces: claves consumidas por Tasks 6-11.

- [ ] **Step 1: añadir en los 4 locales** (mismo top-level key en todos; valores sin acentos):

```json
"wizard": {
  "step1": "Dates & Rooms", "step2": "Guest Details", "step3": "Payment",
  "step1Label": "Stay", "step2Label": "Guest", "step3Label": "Pay",
  "stepOf": "Step {n} of 3",
  "calendarTitle": "Select your dates",
  "guestsLabel": "Guests",
  "prevMonth": "Previous month", "nextMonth": "Next month",
  "checkIn": "Check-in", "checkOut": "Check-out",
  "nights": "{n} nights",
  "availabilityChecking": "Checking availability...",
  "availabilityDegraded": "Live availability is temporarily unavailable. Showing full availability.",
  "rangeUnavailable": "No rooms available for these dates.",
  "alternatives": "View alternatives",
  "onlyThisRoom": "Selected room",
  "bestPrice": "Best price",
  "perNight": "/night",
  "roomsCount": "{n} rooms",
  "continue": "Continue", "back": "Back",
  "summary": "Your stay",
  "total": "Total",
  "extras": "Extra guest"
}
```

es: `{"step1":"Fechas y habitaciones","step2":"Datos del huesped","step3":"Pago","step1Label":"Estancia","step2Label":"Huesped","step3Label":"Pagar","stepOf":"Paso {n} de 3","calendarTitle":"Elige tus fechas","guestsLabel":"Huespedes","prevMonth":"Mes anterior","nextMonth":"Mes siguiente","checkIn":"Llegada","checkOut":"Salida","nights":"{n} noches","availabilityChecking":"Comprobando disponibilidad...","availabilityDegraded":"La disponibilidad en vivo no esta disponible. Mostrando disponibilidad completa.","rangeUnavailable":"No hay habitaciones disponibles para estas fechas.","alternatives":"Ver alternativas","onlyThisRoom":"Habitacion elegida","bestPrice":"Mejor precio","perNight":"/noche","roomsCount":"{n} habitaciones","continue":"Continuar","back":"Atras","summary":"Tu estancia","total":"Total","extras":"Huesped extra"}`

fr/pt: traducir (sin acentos) manteniendo las mismas claves; valores ausentes caen al fallback existente (`fr→en`, `pt→es`).

Claves adicionales de internas (mismo patrón, valores sin acentos):
- `explore.viewMore` ("Saber más" / "Learn more" / "En savoir plus" / "Saiba mais") — revisar si ya existe `explore.*`; si existe una clave equivalente, reutilizarla (no duplicar).
- `account.*`: `account.title` (Mi cuenta / My account / Mon compte / Minha conta), `account.loggedOut`, `account.signInPrompt`.
- `contact.*`: `contact.title` si no existe.
- `rooms.ctaBook` si no existe ("Reservar" / "Book now" / "Reserver" / "Reservar").

- [ ] **Step 2: verificar paridad**

Run: `npx vitest run tests/Unit/i18n.test.ts`
Expected: PASS (top-level keys iguales en 4 locales).

- [ ] **Step 3: verificar cero acentos en valores nuevos**

Run: `node -e "const fs=require('fs');for(const l of ['en','es','fr','pt']){const j=JSON.parse(fs.readFileSync('src/i18n/'+l+'.json','utf8'));const s=JSON.stringify(j);const bad=s.match(/[^\x00-\x7F]/g);console.log(l, bad?bad.join(','):'OK')}"`
Expected: OK en los 4.

- [ ] **Step 4: commit**

```bash
git add src/i18n/en.json src/i18n/es.json src/i18n/fr.json src/i18n/pt.json
git commit -m "feat: i18n keys for booking wizard and internal pages"
```

---

### Task 6: rooms/index — grid editorial con filtros

**Files:**
- Modify: `src/pages/rooms/index.astro`
- Modify: `src/components/RoomCard.astro` (solo si queda sin uso tras el cambio → retirarlo y borrar import; verificar con grep)
- Create: `src/features/animations/modules/roomsPageGrid.ts` (si la animación de la home no es reutilizable; patrón `roomsGallery.ts`)

**Interfaces:**
- Consumes: `SectionHeader` (`src/components/ui/SectionHeader.astro:4-12`, props `number/eyebrow/title/subtitle`), `Picture` de `astro:assets`, `rooms` de `src/data/rooms.ts:27`, i18n `rooms.*`.
- Produces: la página; botones `data-capacity-filter` (ya existen en `rooms/index.astro:54`) conservados.

- [ ] **Step 1: reemplazar el hero y el grid** — mantener filtros `data-capacity-filter` y el JS de filtrado actual (`rooms/index.astro:46-63` conserva la lógica), sustituir `RoomCard` por el grid editorial: paneles `grid md:grid-cols-2` con `Picture` (`formats={['avif','webp']}`, `pictureAttributes` para altura), overlay gradiente, franja inferior con número `01`-`04`, nombre, `baseOccupancy`/`maxGuests` y precio `pricePerNight`, CTA → `/book?roomType=${slug}`. Header con `SectionHeader` (`number="03"` o el de la sección, `eyebrow` de `rooms.sectionLabel`, título de `rooms.title`).
- [ ] **Step 2: animación de entrada** — batch reveal con `ScrollTrigger.batch` (patrón `src/features/animations/modules/roomsGallery.ts`), `motion-reduce` visible.
- [ ] **Step 3: limpiar `RoomCard`** si `grep -r "RoomCard" src/` queda sin usos → borrar archivo e import.
- [ ] **Step 4: verificar**

Run: `npx astro check` + `npm run build`
Expected: 0 errores; la página compila.

- [ ] **Step 5: commit**

```bash
git add src/pages/rooms/index.astro src/features/animations/modules/roomsPageGrid.ts
git commit -m "feat: editorial rooms grid with capacity filters"
```

---

### Task 7: explore — cards con foto

**Files:**
- Modify: `src/pages/explore.astro`
- Create: `src/features/animations/modules/exploreCards.ts` (si se anima; patrón `roomsGallery.ts`)

**Interfaces:**
- Consumes: `explore.json` (`src/content/explore/explore.json` — items con `image`, `name_*`, `category`), `Picture`, i18n `explore.*`.
- Produces: grid de cards con foto.

- [ ] **Step 1: rediseñar la card** (`explore.astro:110` actual) — `Picture` full-bleed arriba (AVIF/WebP), categoría en micro-label, nombre display, hover reveal (nombre sube, CTA "Saber más" aparece, gold underline), link a `/explore/{slug}`. Filtros por categoría (`data-category-filter`, `explore.astro:80`) y búsqueda (`explore-search`, `explore.astro:97`) conservados.
- [ ] **Step 2: animación** — batch reveal con stagger (patrón `roomsGallery.ts`), `motion-reduce` visible.
- [ ] **Step 3: verificar** — `npx astro check` + `npm run build`.
- [ ] **Step 4: commit**

```bash
git add src/pages/explore.astro
git commit -m "feat: explore cards with photos and hover reveal"
```

---

### Task 8: gallery — auditoría editorial

**Files:**
- Modify: `src/pages/gallery.astro`

**Interfaces:**
- Consumes: `Image`/`Picture` de `astro:assets` (ya usa `Image` hoy, `gallery.astro:4`), lightbox existente (`gallery.astro:149-240`).
- Produces: grid editorial + lightbox conservado.

- [ ] **Step 1: auditar** — revisar `gallery.astro` completo (12247 bytes): el grid (`gallery.astro:80-100`) y lightbox ya existen. Cambios: `Image` → `Picture` con `formats={['avif','webp']}` para las que hoy no lo usan; header con `SectionHeader`; espaciado estándar (`pb-24 px-4 max-w-7xl mx-auto`).
- [ ] **Step 2: verificar** — `npx astro check` + `npm run build`.
- [ ] **Step 3: commit**

```bash
git add src/pages/gallery.astro
git commit -m "feat: gallery editorial audit with avif picture"
```

---

### Task 9: contact — editorial

**Files:**
- Modify: `src/pages/contact.astro`

**Interfaces:**
- Consumes: `SectionHeader`, i18n `contact.*` (o las claves existentes de la página), `settings.json` (coordenadas/horarios ya usados).
- Produces: página editorial, form conservado (endpoint actual intacto).

- [ ] **Step 1: rediseñar** — header editorial (`SectionHeader`), formulario con inputs `surface-card` + focus gold (patrón de `book.astro:287-330` actual: `bg-surface-card-light dark:bg-surface-card-dark border rounded-xl`), bloques de contacto (dirección/email/WhatsApp de `settings.json`), mapa si existe con el estilo de `LocationMap`. Sin features nuevas.
- [ ] **Step 2: verificar** — `npx astro check` + `npm run build`.
- [ ] **Step 3: commit**

```bash
git add src/pages/contact.astro
git commit -m "feat: editorial contact page"
```

---

### Task 10: login / my-bookings / profile — editorial

**Files:**
- Modify: `src/pages/login.astro`, `src/pages/my-bookings.astro`, `src/pages/profile.astro`

**Interfaces:**
- Consumes: `SectionHeader`, i18n existente de esas páginas, `StatusBanner` (`src/components/ui/StatusBanner.astro`).
- Produces: páginas rediseñadas; funcionalidad idéntica (auth, bookings list, profile form).

- [ ] **Step 1: login** — header editorial + card de login centrada con el lenguaje surface-card, botón primary gold (gradiente dark existente), conservar social login (hybridauth) tal cual. Estados de error con `StatusBanner`.
- [ ] **Step 2: my-bookings** — header editorial + lista de reservas con cards surface-card (conservar datos/acciones actuales), estado vacío editorial.
- [ ] **Step 3: profile** — header editorial + formulario rediseñado (campos actuales), botones conservados.
- [ ] **Step 4: verificar** — `npx astro check` + `npm run build`.
- [ ] **Step 5: commit**

```bash
git add src/pages/login.astro src/pages/my-bookings.astro src/pages/profile.astro
git commit -m "feat: editorial account pages (login, my-bookings, profile)"
```

---

### Task 11: Wizard UI — componentes por paso + shell book.astro

**Files:**
- Create: `src/components/booking/WizardProgress.astro`
- Create: `src/components/booking/BookingCalendarStep.astro`
- Create: `src/components/booking/AllocationStep.astro`
- Create: `src/components/booking/GuestStep.astro`
- Create: `src/components/booking/PaymentStep.astro`
- Create: `src/features/animations/modules/bookingCalendar.ts`
- Modify: `src/pages/book.astro` (shell + wire)

**Interfaces:**
- Consumes: `createWizardStore` (Task 2), `buildMonthGrid/nightsBetween/clampRange` (Task 3), `allocateRooms` (Task 1), `getRoomInventory` (Task 4), `bookingService.getAvailableRooms` (`src/services/bookingService.ts:24`), `rooms` (`src/data/rooms.ts:27`), i18n `wizard.*` (Task 5). El payload de reserva actual de `book.astro` (createHold → `mp-payment-form`) NO cambia.
- Produces: flujo completo en `/book` con `data-*` hooks para E2E: `data-wizard-step`, `data-calendar-day`, `data-allocation-option`, `data-allocator-next`, `data-guest-next`, `data-payment-submit`.

- [ ] **Step 1: `WizardProgress.astro`** — 3 marcadores numerados (01/02/03) con estado activo/completado, animación gold, `aria-current="step"`, conecta con `wizard.*` i18n.
- [ ] **Step 2: `BookingCalendarStep.astro`** — `is:client` mount; estado local `{ viewYear, viewMonth, hover }`; dual-month `md:` (mes actual + siguiente), single mobile; grid de días desde `buildMonthGrid`; `aria-label` por día; clic: primera fecha = checkIn, segunda = checkOut (swap via `clampRange`); estados visuales: pasado (disabled, line-through), seleccionado (gold), rango (fill suave); al completar rango: `setState({ checkIn, checkOut })`, query `getAvailableRooms(checkIn, checkOut)` → `setState({ allocation: res.success ? allocateRooms({...}) : allocateRooms({..., availability: null }), selecting: false, error: res.success ? null : t('wizard.availabilityDegraded') })`; botón continuar (`data-allocator-next`, disabled si sin rango o `selecting`). Módulo `bookingCalendar.ts` para la animación de días (fade/scale stagger, `motion-reduce` off).
- [ ] **Step 3: `AllocationStep.astro`** — desde `/book?roomType=X`: `roomType` inicial del store; si existe, mostrar esa habitación + toggle "Ver alternativas" (`data-toggle-alternatives`) que despliega el resto. Lista top 4 de `allocateRooms` con desglose: habitación(es) + guests, `{n} nights`, extras, total, badge "Mejor precio" en `bestPrice`, radio de selección → `setState({ allocation })`; total en vivo en resumen sticky; continuar (`data-allocator-next`) → `next()`.
- [ ] **Step 4: `GuestStep.astro`** — extraer el form de datos actual de `book.astro:257-378` (name/email/phone, airport transfer + cálculo de horario) a este componente SIN cambiar IDs ni payload; `data-guest-next` valida (Validación de campos requeridos + email regex) y `next()`.
- [ ] **Step 5: `PaymentStep.astro`** — mover el bloque `payment-section` + `mp-payment-form` actual (`book.astro:380-...`) intacto (IDs `mp-payment-form`, `error-banner`, flow createHold existente); solo maquetación editorial (surface-card, resumen del total del store).
- [ ] **Step 6: shell `book.astro`** — conservar: query params (`roomType`, `checkin/checkout` → seed del store), sección de pasos con transición GSAP entre pasos (fade/slide, `motion-reduce` off), `WizardProgress` arriba, resumen sticky en desktop. Eliminar el selector visual de habitaciones viejo (sustituido por AllocationStep) y las secciones duplicadas.
- [ ] **Step 7: verificar** — `npx astro check` + `npm run build` + `npx vitest run`.
- [ ] **Step 8: commit**

```bash
git add src/components/booking src/features/animations/modules/bookingCalendar.ts src/pages/book.astro
git commit -m "feat: 3-step booking wizard with custom calendar and allocator"
```

---

### Task 12: E2E — wizard + internas

**Files:**
- Create: `tests/e2e/wizard-flow.spec.ts`
- Create: `tests/e2e/internals.spec.ts` (rooms grid + explore cards)

**Interfaces:**
- Consumes: `data-*` hooks de Task 11, patrón de `tests/e2e/home-redesign.spec.ts` (seed `sessionStorage` en `beforeEach` para saltar el preloader).

- [ ] **Step 1: `wizard-flow.spec.ts`** — casos: (a) paso 1 muestra calendario y los días pasados deshabilitados; (b) seleccionar rango → aparece opción de habitación con precio y badge en la de mejor precio; (c) avanzar a Guest y Payment pasos (validación de email bloquea, luego pasa); (d) `/book?roomType=matrimonial` preselecciona y muestra solo esa + toggle alternativas. Selectores: `data-wizard-step`, `data-calendar-day[data-past]`, `data-allocation-option`, `data-guest-next`, `data-payment-submit`.
- [ ] **Step 2: `internals.spec.ts`** — (a) `/rooms` renderiza 4 tarjetas con imagen y filtro `data-capacity-filter` filtra; (b) `/explore` cards con `<picture>` y búsqueda filtra; (c) `/gallery` grid con imágenes; (d) `/contact` form visible.
- [ ] **Step 3: correr**

Run: `npx playwright test tests/e2e/wizard-flow.spec.ts tests/e2e/internals.spec.ts --project=chromium`
Expected: PASS. Repetir `--project="Mobile Chrome"`.

- [ ] **Step 4: commit**

```bash
git add tests/e2e/wizard-flow.spec.ts tests/e2e/internals.spec.ts
git commit -m "test: e2e for booking wizard and internal pages"
```

---

### Task 13: Verificación final + caza de fallas con docs + STATE.md

**Files:**
- Modify: `docs/refactoring/STATE.md`, `docs/refactoring/DECISIONS.md`

- [ ] **Step 1: línea base completa** — `npx astro check` (0 errores), `npx vitest run` (todos los unit), `npm run build` (108+ páginas OK), `npx playwright test` completo (chromium + Mobile Chrome).
- [ ] **Step 2: caza de fallas con documentación (MCP obligatorios)**:
  - `astro-docs` — `<Picture>`/`getImage` usos correctos (formats, fallback, layout con Tailwind 4: NO activar `image.responsiveStyles` porque el proyecto estiliza manualmente).
  - `context7` (`/websites/gsap`) — `gsap.context`, `ScrollTrigger.batch`, `prefers-reduced-motion` correctos en los módulos nuevos; revisar `will-change`/perf.
  - `context7` (`/websites/tailwindcss/v4` o resolución propia) — clases usadas en componentes nuevos (grid, aspect-ratio, dark:).
  - `tavily` — disponibilidad/mejores prácticas de calendarios accesibles (ARIA grid) si aplica.
  - Aplicar correcciones que la doc revele; re-correr `astro check` + tests.
- [ ] **Step 3: actualizar `docs/refactoring/STATE.md`** (Fase 3 + 4 completas: hecho/en curso/siguiente) y `DECISIONS.md` (decisión rango-vs-día del calendario, inventario en settings.json).
- [ ] **Step 4: commit final**

```bash
git add docs/refactoring/STATE.md docs/refactoring/DECISIONS.md
git commit -m "docs: fase 3+4 complete - state handoff"
```

---

## Self-review del plan vs spec

- Spec "rooms/index editorial + filtros" → Task 6. "explore cards con foto" → Task 7. "gallery auditoría" → Task 8. "contact" → Task 9. "account pages rediseño completo" → Task 10. "wizard 3 pasos con progreso" → Task 11. "calendario custom" → Task 3+11. "roomAllocator" → Task 1. "inventario configurable" → Task 4. "i18n 4 locales" → Task 5. "verificación + caza de fallas con docs" → Task 13. "MP intacto" → Global Constraints + Task 11 Steps 4-5.
- Sin placeholders: todos los steps tienen contenido concreto (tests, comandos, rutas, hooks data-*).
- Consistencia de tipos: `AllocationOption`/`AllocatableRoom` definidos en Task 1 y usados por Tasks 2 y 11; `WizardState`/`WizardStore` en Task 2; `CalendarDay`/`nightsBetween` en Task 3; `getRoomInventory` en Task 4.
