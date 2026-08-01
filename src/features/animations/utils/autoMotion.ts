import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

// src/features/animations/utils/autoMotion.ts
// Shared continuous-motion engine (the "reviews marquee" pattern):
// - GSAP ticker drives position at a base speed (never pauses on hover)
// - Scroll velocity injects a decaying boost (dynamic, scroll-driven feel)
// - IntersectionObserver pauses ONLY when offscreen (perf)
// - transform-only (GPU), quickSetter, zero layout thrash

export interface AutoMotionOptions {
  baseSpeed?: number;      // px per frame (positive)
  direction?: 1 | -1;      // 1 = forward (x-), -1 = backward
  velocityFactor?: number; // scroll velocity -> px boost
  velocityThreshold?: number;
  decay?: number;          // boost decay per frame
  seamless?: boolean;      // wrap at half scrollWidth (marquee loop)
}

const DEFAULTS: Required<Omit<AutoMotionOptions, 'seamless'>> & { seamless: boolean } = {
  baseSpeed: 1,
  direction: 1,
  velocityFactor: 0.0025,
  velocityThreshold: 50,
  decay: 0.92,
  seamless: true,
};

export function createAutoMotion(
  track: HTMLElement,
  container: HTMLElement,
  options: AutoMotionOptions = {}
): () => void {
  const opts = { ...DEFAULTS, ...options };

  const halfWidth = track.scrollWidth / 2;
  if (halfWidth <= 0) return () => {};

  const setX = gsap.quickSetter(track, 'x', 'px');
  let currentX = 0;
  let boost = 0;
  let tickerRunning = false;

  const update = () => {
    currentX -= opts.baseSpeed * opts.direction;
    if (opts.seamless) {
      if (currentX <= -halfWidth) currentX += halfWidth;
      else if (currentX >= 0) currentX -= halfWidth;
    }
    setX(currentX);
  };

  const decayBoost = () => {
    if (Math.abs(boost) > 0.1) {
      currentX -= boost * opts.direction;
      boost *= opts.decay;
    } else {
      boost = 0;
    }
  };

  const start = () => {
    if (tickerRunning) return;
    gsap.ticker.add(update);
    gsap.ticker.add(decayBoost);
    tickerRunning = true;
  };

  const stop = () => {
    if (!tickerRunning) return;
    gsap.ticker.remove(update);
    gsap.ticker.remove(decayBoost);
    tickerRunning = false;
  };

  start();

  let observer: IntersectionObserver | null = null;
  if (typeof IntersectionObserver !== 'undefined') {
    observer = new IntersectionObserver(
      ([entry]) => (entry.isIntersecting ? start() : stop()),
      { rootMargin: '200px' }
    );
    observer.observe(container);
  }

  const st = ScrollTrigger.create({
    trigger: container,
    start: 'top bottom',
    end: 'bottom top',
    onUpdate: (self) => {
      const velocity = self.getVelocity();
      if (Math.abs(velocity) > opts.velocityThreshold) {
        boost = velocity * opts.velocityFactor;
      }
    },
  });

  return () => {
    stop();
    observer?.disconnect();
    st.kill();
  };
}
