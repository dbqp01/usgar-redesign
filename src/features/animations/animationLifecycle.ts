import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

// MatchMedia wraps a Context internally (GSAP docs) — both expose revert().
type ContextEntry = { name: string; ctx: gsap.Context | gsap.MatchMedia };

const registry: Map<string, ContextEntry> = new Map();

export function register(name: string, ctx: gsap.Context | gsap.MatchMedia): void {
  if (registry.has(name)) {
    registry.get(name)!.ctx.revert();
  }
  registry.set(name, { name, ctx });
}

export function cleanupAll(): void {
  registry.forEach((entry) => {
    entry.ctx.revert();
  });
  registry.clear();
  ScrollTrigger.clearScrollMemory();
}

function isPage(path: string, pattern: string): boolean {
  // Solo la raíz exacta de la locale (/es, /es/) es home; /es/rooms no lo es.
  return path === `/${pattern}` || path === `/${pattern}/`;
}

export function isHomePage(): boolean {
  if (typeof window === 'undefined') return false;
  const path = window.location.pathname;
  return path === '/' || isPage(path, 'es') || isPage(path, 'fr') || isPage(path, 'pt');
}

declare global {
  interface Window {
    __usgarLifecycleInit?: boolean;
  }
}

export function initLifecycle(): void {
  if (typeof window === 'undefined') return;
  // Defensive idempotency guard: SmoothScroll.astro calls this from a bundled
  // script (once per document), so today this registers once — keep it that
  // way even if a second caller ever appears.
  if (window.__usgarLifecycleInit) return;
  window.__usgarLifecycleInit = true;

  document.addEventListener('astro:before-preparation', () => {
    cleanupAll();
  });

  document.addEventListener('astro:after-swap', () => {
    window.scrollTo(0, 0);
  });
}
