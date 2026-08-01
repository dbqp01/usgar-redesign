import { gsap } from 'gsap';

const PRELOADER_FALLBACK_MS = 4500;

export function initHeroStory(): gsap.Context {
  return gsap.context(() => {
    const mm = gsap.matchMedia();

    mm.add(
      {
        isDesktop: '(min-width: 768px)',
        isMobile: '(max-width: 767px)',
        reduceMotion: '(prefers-reduced-motion: reduce)',
      },
      (context) => {
        const { isDesktop, reduceMotion } = context.conditions!;
        if (reduceMotion) return;

        bootHero(isDesktop!);
      }
    );
  });
}

async function bootHero(isDesktop: boolean): Promise<void> {
  const heroSec = document.getElementById('hero');
  const heroMedia = document.getElementById('hero-video') || document.getElementById('hero-slideshow');
  const heroContent = heroSec?.querySelector('.relative.z-10');

  if (!heroSec || !heroMedia) return;

  const heroTl = gsap.timeline({
    scrollTrigger: {
      trigger: heroSec,
      start: 'top top',
      end: 'bottom top',
      scrub: 0.8,
      invalidateOnRefresh: true,
    }
  });

  heroTl.to(heroMedia, {
    scale: isDesktop ? 0.93 : 0.96,
    autoAlpha: 0.65,
    ease: 'none'
  }, 0);

  if (heroContent) {
    heroTl.to(heroContent, {
      y: -100,
      autoAlpha: 0,
      ease: 'none'
    }, 0);
  }

  const { SplitText } = await import('gsap/SplitText');
  gsap.registerPlugin(SplitText);

  const title = heroSec.querySelector('h1');
  const subtitle = heroSec.querySelector('p');
  const buttons = heroSec.querySelector('.flex-col.sm\\:flex-row');

  if (!title || !subtitle || !buttons) return;

  title.classList.remove('animate-fade-in');
  subtitle.classList.remove('animate-slide-up');
  buttons.classList.remove('animate-slide-up');
  (subtitle as HTMLElement).style.animation = 'none';
  (buttons as HTMLElement).style.animation = 'none';

  gsap.set([subtitle, buttons], { autoAlpha: 0, y: 30 });

  const splitTitle = new SplitText(title, { type: 'words,chars' });
  gsap.set(splitTitle.chars, { autoAlpha: 0, y: 40 });

  let heroEntryPlayed = false;

  const playHeroEntry = () => {
    if (heroEntryPlayed) return;
    heroEntryPlayed = true;

    gsap.timeline()
      .to(splitTitle.chars, {
        duration: 1.2,
        autoAlpha: 1,
        y: 0,
        stagger: 0.04,
        ease: 'power3.out',
      }, 0)
      .to(subtitle, {
        duration: 1.2,
        autoAlpha: 0.9,
        y: 0,
        ease: 'power3.out'
      }, '-=0.8')
      .to(buttons, {
        duration: 1.2,
        autoAlpha: 1,
        y: 0,
        ease: 'power3.out'
      }, '-=1');

    deferHeroVideoPlay();
  };

  if (sessionStorage.getItem('usgar_loaded')) {
    playHeroEntry();
  } else {
    window.addEventListener('usgar:preloader-done', playHeroEntry, { once: true });
    window.setTimeout(playHeroEntry, PRELOADER_FALLBACK_MS);
  }
}

function deferHeroVideoPlay(): void {
  const video = document.getElementById('hero-video') as HTMLVideoElement | null;
  if (!video) return;
  requestAnimationFrame(() => {
    video.play().catch(() => { /* autoplay blocked, acceptable */ });
  });
}
