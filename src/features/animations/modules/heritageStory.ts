import { gsap } from 'gsap';

export function initHeritageStory(): gsap.Context {
  return gsap.context(() => {
    const heritageSec = document.getElementById('heritage-section');
    const svgPath = document.getElementById('heritage-svg-path') as SVGPathElement | null;

    if (!heritageSec || !svgPath) return;

    const pathLength = svgPath.getTotalLength();
    svgPath.style.strokeDasharray = `${pathLength}`;
    svgPath.style.strokeDashoffset = `${pathLength}`;

    // One-shot: animar strokeDashoffset con scrub repinta el SVG entero en
    // cada frame de scroll (coste alto en movil). Se dibuja una vez al entrar.
    gsap.to(svgPath, {
      strokeDashoffset: 0,
      duration: 1.4,
      ease: 'power2.out',
      scrollTrigger: {
        trigger: heritageSec,
        start: 'top 70%',
        once: true,
      }
    });
  });
}
