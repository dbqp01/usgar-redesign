import { gsap } from 'gsap';

export function initHeritageStory(): gsap.Context {
  return gsap.context(() => {
    const heritageSec = document.getElementById('heritage-section');
    const svgPath = document.getElementById('heritage-svg-path') as SVGPathElement | null;

    if (!heritageSec || !svgPath) return;

    const pathLength = svgPath.getTotalLength();
    svgPath.style.strokeDasharray = `${pathLength}`;
    svgPath.style.strokeDashoffset = `${pathLength}`;

    gsap.to(svgPath, {
      strokeDashoffset: 0,
      ease: 'none',
      scrollTrigger: {
        trigger: heritageSec,
        start: 'top 70%',
        end: 'bottom 20%',
        scrub: 0.5,
      }
    });
  });
}
