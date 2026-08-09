import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

export function initExploreAtlas(root: HTMLElement): () => void {
  const listeners: Array<{ item: HTMLElement; type: string; handler: () => void }> = [];
  const context = gsap.context((scope) => {
    const preview = root.querySelector<HTMLElement>('[data-atlas-preview-media]');
    const name = root.querySelector<HTMLElement>('[data-atlas-name]');
    const meta = root.querySelector<HTMLElement>('[data-atlas-meta]');
    const position = root.querySelector<HTMLElement>('[data-atlas-position]');
    const category = root.querySelector<HTMLElement>('[data-atlas-preview] .text-secondary');
    const slides = Array.from(root.querySelectorAll<HTMLElement>('[data-atlas-slide]'));
    const items = Array.from(root.querySelectorAll<HTMLElement>('[data-atlas-item]'));

    if (!preview || !name || !meta || !position || !category || !items.length || !slides.length) return;

    let activeIndex = 0;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const activate = scope.add('activate', (item: HTMLElement, index: number) => {
      if (index === activeIndex) return;

      items.forEach((candidate, i) => {
        const isActive = i === index;
        candidate.setAttribute('aria-current', isActive ? 'true' : 'false');
        candidate.classList.toggle('is-active', isActive);
      });

      name.textContent = item.dataset.previewName || '';
      meta.textContent = item.dataset.previewMeta || '';
      position.textContent = item.dataset.previewPosition || '';
      category.textContent = item.dataset.previewCategory || '';

      const currentSlide = slides[activeIndex];
      const nextSlide = slides[index];

      if (currentSlide && nextSlide) {
        currentSlide.classList.replace('opacity-100', 'opacity-0');
        currentSlide.classList.replace('z-10', 'z-0');
        currentSlide.classList.add('pointer-events-none');

        nextSlide.classList.replace('opacity-0', 'opacity-100');
        nextSlide.classList.replace('z-0', 'z-10');
        nextSlide.classList.remove('pointer-events-none');
      }

      activeIndex = index;
    });

    items.forEach((item, index) => {
      const onPointerEnter = () => activate(item, index);
      const onFocus = () => activate(item, index);
      item.addEventListener('pointerenter', onPointerEnter);
      item.addEventListener('focus', onFocus);
      listeners.push(
        { item, type: 'pointerenter', handler: onPointerEnter },
        { item, type: 'focus', handler: onFocus },
      );
    });

    if (!reduceMotion) {
      const revealTargets = [root.querySelector('[data-atlas-preview]'), ...items].filter(Boolean) as HTMLElement[];
      gsap.set(revealTargets, { autoAlpha: 0, y: 30 });
      const revealBatch = scope.add('revealBatch', (batch: Element[]) => gsap.to(batch, {
        autoAlpha: 1,
        y: 0,
        duration: 0.8,
        ease: 'power3.out',
        stagger: 0.05,
        overwrite: true,
      }));

      ScrollTrigger.batch(revealTargets, {
        start: 'top 86%',
        once: true,
        onEnter: (batch) => revealBatch(batch),
      });
    }
  }, root);

  return () => {
    listeners.forEach(({ item, type, handler }) => item.removeEventListener(type, handler));
    context.revert();
  };
}
