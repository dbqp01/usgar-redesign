import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { SplitText } from 'gsap/SplitText';

// Hero headline word-reveal: words illuminate progressively as the user scrolls.
// Words start dim (opacity .15) and light up with a stagger scrubbed by scroll.
// Desktop-only (hero text is small on mobile; scrub feels odd).
export function initHeroTextReveal(): gsap.Context {
  return gsap.context(() => {
    const headline = document.getElementById('hero-title-reveal');
    if (!headline) return;

    const split = new SplitText(headline, { type: 'words', autoSplit: false });
    const words = split.words;
    if (!words || words.length === 0) return;

    gsap.fromTo(
      words,
      { opacity: 0.15, yPercent: 8 },
      {
        opacity: 1,
        yPercent: 0,
        stagger: 0.08,
        ease: 'none',
        scrollTrigger: {
          trigger: document.getElementById('hero'),
          start: 'top top',
          end: '+=70%',
          scrub: 0.6,
        },
      }
    );
  });
}
