import { describe, it, expect } from 'vitest';
import { allocateRooms } from '../../src/features/booking/roomAllocator';

// Caracterización (2026-08-12): datos reales de rooms.json sincronizados con
// QloApps (qlo_htl_room_type.max_guests = 3/3/4/8). Regla del negocio: toda
// habitacion admite +1 persona sobre su ocupancia base (base = max - 1) con
// cargo extraGuestCharge=30/noche. El allocator debe mostrar exactamente lo
// que el backend cobra (display == charged).
const REAL_ROOMS = [
  { slug: 'doble-superior', displayName: 'Double Superior', baseOccupancy: 2, maxCapacity: 3, extraGuestCharge: 30, pricePerNight: 90, inventory: 8 },
  { slug: 'matrimonial', displayName: 'Matrimonial', baseOccupancy: 2, maxCapacity: 3, extraGuestCharge: 30, pricePerNight: 95, inventory: 3 },
  { slug: 'triple-standar', displayName: 'Triple', baseOccupancy: 3, maxCapacity: 4, extraGuestCharge: 30, pricePerNight: 120, inventory: 3 },
  { slug: 'familiar-superior', displayName: 'Familiar', baseOccupancy: 7, maxCapacity: 8, extraGuestCharge: 30, pricePerNight: 150, inventory: 3 },
];

describe('allocator display == backend charge', () => {
  it('extras = 0 cuando los huespedes no superan la ocupancia base', () => {
    for (const guests of [1, 2]) {
      const options = allocateRooms({ guests, nights: 3, rooms: REAL_ROOMS, availability: null });
      for (const opt of options) {
        expect(opt.extrasTotal, `guests=${guests} ${opt.rooms.map((r) => r.room.slug).join('+')}`).toBe(0);
      }
    }
  });

  it('extras = 30/noche SOLO para el huesped adicional (regla +1 persona)', () => {
    const options = allocateRooms({ guests: 3, nights: 3, rooms: REAL_ROOMS, availability: null });
    const matri = options.find((o) => o.rooms.length === 1 && o.rooms[0].room.slug === 'matrimonial');
    expect(matri?.rooms[0].guests).toBe(3);
    expect(matri?.extrasTotal).toBe(30 * 3); // 1 huesped extra x 3 noches
    expect(matri?.roomTotal).toBe(95 * 3);
    expect(matri?.total).toBe(95 * 3 + 30 * 3);
  });

  it('no genera opciones con huespedes sobre la capacidad maxima (max = base + 1)', () => {
    const options = allocateRooms({ guests: 4, nights: 1, rooms: REAL_ROOMS, availability: null });
    // Doble/Matrimonial (max 3) no pueden tomar 4 huespedes solas; Familiar (max 8) si.
    expect(options.some((o) => o.rooms.length === 1 && o.rooms[0].room.slug === 'familiar-superior')).toBe(true);
    expect(options.some((o) => o.rooms.length === 1 && o.rooms[0].room.slug === 'doble-superior')).toBe(false);
  });

  it('el total de la opción de 1 habitación == (pricePerNight * nights) + extras (lo que cobra el backend)', () => {
    const options = allocateRooms({ guests: 8, nights: 3, rooms: REAL_ROOMS, availability: null });
    const familiar = options.find((o) => o.rooms.length === 1 && o.rooms[0].room.slug === 'familiar-superior');
    expect(familiar?.total).toBe(150 * 3 + 30 * 3); // 7 base + 1 extra
  });
});
