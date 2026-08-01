// src/features/booking/wizardStore.ts
// Estado compartido del wizard de reserva (TS puro, sin librerias).
// Un solo store por pagina: se crea en el shell de book.astro y se pasa
// a los componentes de paso.

import type { AllocationOption } from './roomAllocator';

export type WizardStep = 1 | 2 | 3;

export interface WizardState {
  step: WizardStep;
  checkIn: string;
  checkOut: string;
  guests: number;
  roomType: string | null;
  allocation: AllocationOption | null;
  options: AllocationOption[] | null;
  selecting: boolean;
  error: string | null;
}

export interface WizardStore {
  getState(): WizardState;
  setState(patch: Partial<WizardState>): void;
  subscribe(fn: (s: WizardState) => void): () => void;
  next(): void;
  back(): void;
}

const DEFAULT_STATE: WizardState = {
  step: 1,
  checkIn: '',
  checkOut: '',
  guests: 2,
  roomType: null,
  allocation: null,
  options: null,
  selecting: false,
  error: null,
};

export function createWizardStore(initial?: Partial<WizardState>): WizardStore {
  let state: WizardState = { ...DEFAULT_STATE, ...initial };
  const listeners = new Set<(s: WizardState) => void>();

  const notify = () => {
    for (const fn of listeners) fn(state);
  };

  return {
    getState: () => state,
    setState: (patch) => {
      state = { ...state, ...patch };
      notify();
    },
    subscribe: (fn) => {
      listeners.add(fn);
      return () => listeners.delete(fn);
    },
    next: () => {
      if (state.step < 3) {
        state = { ...state, step: (state.step + 1) as WizardStep };
        notify();
      }
    },
    back: () => {
      if (state.step > 1) {
        state = { ...state, step: (state.step - 1) as WizardStep };
        notify();
      }
    },
  };
}

// Singleton compartido por los componentes del wizard de /book.
export const wizardStore = createWizardStore();
