// src/features/booking/calendar.ts
// Grid de meses y helpers de rango (TS puro). El estado "seleccionado/rango"
// lo calcula el componente del calendario; aqui viven las funciones puras.

export interface CalendarDay {
  date: string;
  inMonth: boolean;
  past: boolean;
}

export function formatISODate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

export function nightsBetween(checkIn: string, checkOut: string): number {
  const a = new Date(checkIn + 'T00:00:00');
  const b = new Date(checkOut + 'T00:00:00');
  return Math.round((b.getTime() - a.getTime()) / 86_400_000);
}

export function clampRange(
  checkIn: string,
  checkOut: string,
  maxNights = 30
): { checkIn: string; checkOut: string } {
  let start = checkIn;
  let end = checkOut;
  if (nightsBetween(start, end) < 1) {
    const tmp = start;
    start = end;
    end = tmp;
  }
  if (nightsBetween(start, end) > maxNights) {
    const d = new Date(start + 'T00:00:00');
    d.setDate(d.getDate() + maxNights);
    end = formatISODate(d);
  }
  return { checkIn: start, checkOut: end };
}

export function buildMonthGrid(year: number, month: number, today?: string): CalendarDay[] {
  const todayIso = today ?? formatISODate(new Date());
  const first = new Date(year, month, 1);
  const offset = first.getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const cells: CalendarDay[] = [];

  for (let i = 0; i < 42; i++) {
    const dayNum = i - offset + 1;
    const inMonth = dayNum >= 1 && dayNum <= daysInMonth;
    const date = inMonth
      ? formatISODate(new Date(year, month, dayNum))
      : '';
    cells.push({
      date,
      inMonth,
      past: inMonth ? date < todayIso : false,
    });
  }
  return cells;
}
