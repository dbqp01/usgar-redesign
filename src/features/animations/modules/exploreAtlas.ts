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
    const items = Array.from(root.querySelectorAll<HTMLElement>('[data-atlas-item]'));
    const initialImage = root.querySelector<HTMLImageElement>('[data-atlas-preview-image]');

    if (!preview || !name || !meta || !position || !category || !initialImage || !items.length) return;

    let currentImage = initialImage;

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const activate = scope.add('activate', (item: HTMLElement) => {
      const nextSrc = item.dataset.previewSrc;
      if (!nextSrc || nextSrc === currentImage?.currentSrc || nextSrc === currentImage?.src) return;

      items.forEach((candidate) => candidate.setAttribute('aria-current', candidate === item ? 'true' : 'false'));
      items.forEach((candidate) => candidate.classList.toggle('is-active', candidate === item));

      name.textContent = item.dataset.previewName || '';
      meta.textContent = item.dataset.previewMeta || '';
      position.textContent = item.dataset.previewPosition || '';
      category.textContent = item.dataset.previewCategory || '';

      const nextImage = currentImage.cloneNode(true) as HTMLImageElement;
      const previousImage = currentImage;
      nextImage.removeAttribute('srcset');
      nextImage.removeAttribute('sizes');
      nextImage.dataset.atlasPreviewImage = 'true';
      nextImage.src = nextSrc;
      nextImage.alt = item.dataset.previewName || '';
      preview.appendChild(nextImage);

      if (reduceMotion) {
        currentImage.remove();
        currentImage = nextImage;
        return;
      }

      gsap.fromTo(nextImage,
        { autoAlpha: 0, scale: 1.06, clipPath: 'inset(0 100% 0 0)' },
        { autoAlpha: 1, scale: 1, clipPath: 'inset(0 0% 0 0)', duration: 0.7, ease: 'power3.out' },
      );
      gsap.to(currentImage, {
        autoAlpha: 0,
        duration: 0.45,
        ease: 'power2.out',
        onComplete: () => previousImage.remove(),
      });
      currentImage = nextImage;
    });

    items.forEach((item) => {
      const onPointerEnter = () => activate(item);
      const onFocus = () => activate(item);
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
