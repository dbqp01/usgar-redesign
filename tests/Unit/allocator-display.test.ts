import { describe, it, expect } from 'vitest';
import { allocateRooms } from '../../src/features/booking/roomAllocator';

// Caracterización (auditoría 2026-08-11): con los datos reales de rooms.json
// baseOccupancy == maxGuests en las 4 habitaciones => el cargo por huésped
// extra (extraGuestCharge=30) NUNCA aplica: el total mostrado == precio
// por habitación que cobra el backend (pricePerNight * nights).
const REAL_ROOMS = [
  { slug: 'doble-superior', displayName: 'Double Superior', baseOccupancy: 2, maxCapacity: 2, extraGuestCharge: 30, pricePerNight: 90, inventory: 8 },
  { slug: 'matrimonial', displayName: 'Matrimonial', baseOccupancy: 2, maxCapacity: 2, extraGuestCharge: 30, pricePerNight: 95, inventory: 3 },
  { slug: 'triple-standar', displayName: 'Triple', baseOccupancy: 3, maxCapacity: 3, extraGuestCharge: 30, pricePerNight: 120, inventory: 3 },
  { slug: 'familiar-superior', displayName: 'Familiar', baseOccupancy: 7, maxCapacity: 7, extraGuestCharge: 30, pricePerNight: 150, inventory: 3 },
];

describe('allocator display == backend charge', () => {
  it('extras totales siempre 0 con los datos reales (baseOccupancy == maxGuests)', () => {
    for (const guests of [1, 2, 3, 4, 5, 6, 7]) {
      const options = allocateRooms({ guests, nights: 3, rooms: REAL_ROOMS, availability: null });
      for (const opt of options) {
        expect(opt.extrasTotal, `guests=${guests} ${opt.rooms.map((r) => r.room.slug).join('+')}`).toBe(0);
      }
    }
  });

  it('el total de la opción de 1 habitación == pricePerNight * nights (lo que cobra el backend)', () => {
    const options = allocateRooms({ guests: 7, nights: 3, rooms: REAL_ROOMS, availability: null });
    const familiar = options.find((o) => o.rooms.length === 1 && o.rooms[0].room.slug === 'familiar-superior');
    expect(familiar?.total).toBe(150 * 3);
  });
});
