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

  it('ninguna habitacion excede maxCapacity y todas cubren al grupo', () => {
    const opts = allocateRooms({ guests: 3, nights: 1, rooms: [matri] });
    expect(opts.length).toBeGreaterThan(0);
    for (const o of opts) {
      expect(o.rooms.reduce((s, r) => s + r.guests, 0)).toBe(3);
      for (const r of o.rooms) expect(r.guests).toBeLessThanOrEqual(r.room.maxCapacity);
    }
  });

  it('combinación de 2 habitaciones para grupo grande (4 huéspedes)', () => {
    const opts = allocateRooms({ guests: 4, nights: 1, rooms: [doble, matri] });
    expect(opts[0].bestPrice).toBe(true);
    expect(opts[0].rooms).toHaveLength(2);
    expect(opts[0].rooms.every((r) => r.room.slug === 'matrimonial')).toBe(true);
    expect(opts[0].total).toBe(140);
    const mixta = opts.find((o) => new Set(o.rooms.map((r) => r.room.slug)).size === 2);
    expect(mixta).toBeDefined();
    expect(mixta!.rooms.reduce((s, r) => s + r.guests, 0)).toBe(4);
    expect(mixta!.total).toBe(80 + 70);
  });

  it('dedupe permutaciones: doble+matri == matri+doble', () => {
    const opts = allocateRooms({ guests: 4, nights: 1, rooms: [doble, matri] });
    const combos = opts.filter((o) => o.rooms.length === 2);
    const keys = combos.map((o) => o.rooms.map((r) => `${r.room.slug}:${r.guests}`).sort().join('+'));
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
