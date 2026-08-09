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
  availabilityMap: Record<string, number> | null;
  selecting: boolean;
  processing: boolean;
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
  availabilityMap: null,
  selecting: false,
  processing: false,
  error: null,
};

export function createWizardStore(initial?: Partial<WizardState>): WizardStore {
  let state: WizardState = { ...DEFAULT_STATE, ...initial };
  const bus = new EventTarget();

  const notify = () => {
    bus.dispatchEvent(new Event('change'));
  };

  return {
    getState: () => state,
    setState: (patch) => {
      state = { ...state, ...patch };
      notify();
    },
    subscribe: (fn) => {
      const handler = () => fn(state);
      bus.addEventListener('change', handler);
      return () => bus.removeEventListener('change', handler);
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

export const wizardStore = createWizardStore();
