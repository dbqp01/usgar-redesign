import { gsap } from 'gsap';
import { revealOnEnter } from './globalReveals';

export function initServicesScenes(root: HTMLElement): () => void {
  const scenes = gsap.utils.toArray('[data-service-scene]', root) as HTMLElement[];
  if (!scenes.length) return () => {};

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return () => {};

  return revealOnEnter(scenes, { y: 24, duration: 0.65, stagger: 0.08 });
}
