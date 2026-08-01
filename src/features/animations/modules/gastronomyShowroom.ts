import { gsap } from 'gsap';

// AUKA Restobar — gastronomic showroom: hovering a numbered row
// crossfades the large feature image to that offer's photo.
export function initGastronomyShowroom(container: HTMLElement): () => void {
  const ctx = gsap.context(() => {
    const slides = Array.from(container.querySelectorAll<HTMLElement>('.showroom-slide'));
    const rows = Array.from(container.querySelectorAll<HTMLElement>('.showroom-row'));

    if (slides.length === 0) return;

    gsap.set(slides, { autoAlpha: 0 });
    gsap.set(slides[0], { autoAlpha: 1 });

    const show = (index: number) => {
      slides.forEach((slide, i) => {
        gsap.to(slide, {
          autoAlpha: i === index ? 1 : 0,
          duration: 0.45,
          ease: 'power2.out',
          immediateRender: false,
        });
      });
    };

    rows.forEach((row, i) => {
      row.addEventListener('mouseenter', () => show(i));
      row.addEventListener('focus', () => show(i));
    });
  }, container);

  return () => ctx.revert();
}
