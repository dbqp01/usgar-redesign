import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

// RoomsFanCarousel — 3D fan/spread carousel.
// - Continuous autoplay (never pauses on hover) via gsap.ticker
// - Scroll velocity injects a decaying speed boost (same feel as reviews marquee)
// - Pointer drag with inertia (desktop + touch)
// - Click (no drag) opens the room modal
// - IntersectionObserver pauses only offscreen

const CARD_ANGLES = 4;
const AUTOPLAY_SPEED = 0.12;      // deg per frame
const DRAG_FACTOR = 0.35;
const CLICK_THRESHOLD_PX = 8;

export function initRoomsFanCarousel(stage: HTMLElement, onSelect: (slug: string) => void): () => void {
  const cards = Array.from(stage.querySelectorAll<HTMLElement>('[data-fan-card]'));
  if (cards.length === 0) return () => {};

  const cardAngle = 360 / cards.length;

  const applyTransform = (current: number) => {
    cards.forEach((card, i) => {
      const raw = (i * cardAngle + current) % 360;
      const angle = raw > 180 ? raw - 360 : raw; // -180..180, 0 = front
      const abs = Math.abs(angle);
      const scale = gsap.utils.mapRange(0, 120, 1, 0.82, abs);
      const opacity = gsap.utils.mapRange(0, 140, 1, 0.45, abs);
      gsap.set(card, {
        transform: `translate(-50%, -50%) rotateY(${angle}deg) translateZ(var(--fan-radius)) scale(${scale})`,
        opacity,
        zIndex: Math.round(100 - abs),
      });
    });
  };

  // Radii from CSS custom property (set responsively by the component)
  const getRadius = () => {
    const r = parseFloat(getComputedStyle(stage).getPropertyValue('--fan-radius')) || 320;
    return r;
  };
  gsap.set(stage, { perspective: 1200 });

  let current = 0;
  let boost = 0;
  let tickerRunning = false;
  let dragging = false;
  let lastPointerX = 0;
  let pointerVelocity = 0;
  let totalDrag = 0;
  let lastFrameAngle = 0;

  const update = () => {
    current += AUTOPLAY_SPEED + boost;
    if (boost > 0.001) boost *= 0.94;
    else boost = 0;
    applyTransform(current);
    lastFrameAngle = current;
  };

  const start = () => {
    if (tickerRunning) return;
    gsap.ticker.add(update);
    tickerRunning = true;
  };

  const stop = () => {
    if (!tickerRunning) return;
    gsap.ticker.remove(update);
    tickerRunning = false;
  };

  // --- Pointer drag (mouse + touch via Pointer Events) ---
  const onPointerDown = (e: PointerEvent) => {
    dragging = true;
    lastPointerX = e.clientX;
    pointerVelocity = 0;
    totalDrag = 0;
    stop();
  };

  const onPointerMove = (e: PointerEvent) => {
    if (!dragging) return;
    const dx = e.clientX - lastPointerX;
    lastPointerX = e.clientX;
    totalDrag += Math.abs(dx);
    pointerVelocity = dx;
    current += dx * DRAG_FACTOR;
    applyTransform(current);
  };

  const onPointerUp = () => {
    if (!dragging) return;
    dragging = false;
    // Inertia: carry pointer velocity as boost (decaying in update)
    boost = Math.abs(pointerVelocity) > 2 ? pointerVelocity * DRAG_FACTOR * 0.08 : 0;
    if (totalDrag > CLICK_THRESHOLD_PX) lastPointerX = -9999; // mark as drag, not click
    start();
  };

  stage.addEventListener('pointerdown', onPointerDown);
  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', onPointerUp);

  // Click (no meaningful drag) -> select
  stage.addEventListener('click', (e) => {
    const card = (e.target as HTMLElement).closest<HTMLElement>('[data-fan-card]');
    if (!card) return;
    if (totalDrag > CLICK_THRESHOLD_PX) {
      totalDrag = 0;
      return;
    }
    const slug = card.dataset.fanCard;
    if (slug) onSelect(slug);
  });

  // Scroll velocity boost
  const st = ScrollTrigger.create({
    trigger: stage,
    start: 'top bottom',
    end: 'bottom top',
    onUpdate: (self) => {
      const v = self.getVelocity();
      if (Math.abs(v) > 50) boost = v * 0.0006;
    },
  });

  // Pause only offscreen
  let observer: IntersectionObserver | null = null;
  if (typeof IntersectionObserver !== 'undefined') {
    observer = new IntersectionObserver(
      ([entry]) => (entry.isIntersecting ? start() : stop()),
      { rootMargin: '200px' }
    );
    observer.observe(stage);
  }

  applyTransform(current);
  start();

  return () => {
    stop();
    observer?.disconnect();
    st.kill();
    stage.removeEventListener('pointerdown', onPointerDown);
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    void getRadius;
    void CARD_ANGLES;
  };
}
