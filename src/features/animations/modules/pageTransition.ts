import gsap from 'gsap';

// src/features/animations/modules/pageTransition.ts
// Cinematic curtain overlay between View Transitions.
// before-preparation: curtain rises (covers screen) with brand monogram.
// after-swap: curtain exits upward revealing the new page.
// No image prefetching (perf): solid brand panel + gold line.

let overlay: HTMLElement | null = null;
let overlayCtx: gsap.Context | null = null;

function createOverlay(): void {
  if (overlay) overlay.remove();
  overlay = document.createElement('div');
  overlay.className = 'page-transition-overlay';
  overlay.innerHTML = `
    <div class="page-transition-brand">
      <span class="page-transition-word">USGAR</span>
      <span class="page-transition-line" aria-hidden="true"></span>
    </div>
  `;
  document.body.appendChild(overlay);

  overlayCtx = gsap.context(() => {
    gsap.set(overlay, { yPercent: 100 });
    gsap.to(overlay, { yPercent: 0, duration: 0.35, ease: 'power4.in' });
    gsap.fromTo(
      '.page-transition-line',
      { scaleX: 0 },
      { scaleX: 1, duration: 0.45, delay: 0.1, ease: 'power3.out' }
    );
  });
}

function exitOverlay(): void {
  if (!overlay) return;
  overlayCtx?.revert();

  const el = overlay;
  overlay = null;
  overlayCtx = null;

  gsap.to(el, {
    yPercent: -100,
    duration: 0.55,
    ease: 'power4.inOut',
    onComplete: () => el.remove(),
  });
}

export function initPageTransitions(): void {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  if (!('startViewTransition' in document)) return;

  document.addEventListener('astro:before-preparation', createOverlay);
  document.addEventListener('astro:after-swap', exitOverlay, { once: true });
}
