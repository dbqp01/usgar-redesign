// src/features/booking/roomAllocator.ts
// Motor de asignacion de habitaciones (TS puro): individual + combinaciones
// multi-habitacion, ordenadas por precio total, respetando inventario y
// disponibilidad API. Sin dependencias.

export interface AllocatableRoom {
  slug: string;
  displayName: string;
  baseOccupancy: number;
  maxCapacity: number;
  extraGuestCharge: number;
  pricePerNight: number;
  inventory: number;
}

export interface AllocationRoomEntry {
  room: AllocatableRoom;
  guests: number;
}

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
  /**
   * Disponibilidad EFECTIVA del rango por habitación (slugs con qty > 0).
   * Contrato: la validación DÍA A DÍA es del caller (BookingCalendarStep filtra
   * con calendarAvailability antes de llamar) — este motor trata el valor como
   * la cantidad disponible para TODO el rango y no puede detectar días
   * intermedios agotados por sí solo.
   */
  availability?: Record<string, number> | null;
}

function canTake(room: AllocatableRoom, guests: number, availability: Record<string, number> | null | undefined): boolean {
  if (guests < 1 || guests > room.maxCapacity) return false;
  if (room.inventory < 1) return false;
  if (availability !== null && availability !== undefined && (availability[room.slug] ?? 0) < 1) return false;
  return true;
}

function costOf(room: AllocatableRoom, guests: number, nights: number): { roomTotal: number; extrasTotal: number } {
  const extras = Math.max(0, guests - room.baseOccupancy) * room.extraGuestCharge * nights;
  return { roomTotal: room.pricePerNight * nights, extrasTotal: extras };
}

function optionKey(entries: AllocationRoomEntry[]): string {
  return entries
    .map((e) => `${e.room.slug}:${e.guests}`)
    .sort()
    .join('+');
}

export function allocateRooms(input: AllocateInput): AllocationOption[] {
  const { guests, nights, rooms, availability } = input;
  const options: AllocationOption[] = [];
  const seen = new Set<string>();

  const push = (entries: AllocationRoomEntry[]) => {
    const key = optionKey(entries);
    if (seen.has(key)) return;
    seen.add(key);
    let roomTotal = 0;
    let extrasTotal = 0;
    for (const e of entries) {
      const c = costOf(e.room, e.guests, nights);
      roomTotal += c.roomTotal;
      extrasTotal += c.extrasTotal;
    }
    options.push({ rooms: entries, nights, roomTotal, extrasTotal, total: roomTotal + extrasTotal, bestPrice: false });
  };

  for (const room of rooms) {
    if (canTake(room, guests, availability)) push([{ room, guests }]);
  }

  for (let i = 0; i < rooms.length; i++) {
    for (let j = i; j < rooms.length; j++) {
      const a = rooms[i];
      const b = rooms[j];
      if (a.inventory < 1 || b.inventory < 1) continue;
      if (i === j && a.inventory < 2) continue;
      if (availability !== null && availability !== undefined) {
        if ((availability[a.slug] ?? 0) < (i === j ? 2 : 1)) continue;
        if ((availability[b.slug] ?? 0) < 1) continue;
      }
      const minG1 = Math.max(1, guests - b.maxCapacity);
      const maxG1 = Math.min(a.maxCapacity, guests - 1);
      for (let g1 = minG1; g1 <= maxG1; g1++) {
        const g2 = guests - g1;
        if (g2 < 1 || g2 > b.maxCapacity) continue;
        push([{ room: a, guests: g1 }, { room: b, guests: g2 }]);
      }
    }
  }

  options.sort((x, y) => x.total - y.total);
  if (options.length > 0) options[0].bestPrice = true;
  return options;
}
