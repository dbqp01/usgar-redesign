import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { SplitText } from 'gsap/SplitText';

gsap.registerPlugin(ScrollTrigger, SplitText);

/**
 * USGAR Hotels — Interactive Scroll Story Engine
 * Maneja las animaciones inmersivas de scroll (Pinning, Scrubbing, Parallax 3D y Trazo SVG)
 * optimizadas para Astro v7 ViewTransitions.
 *
 * GSAP Best Practices Applied:
 * - gsap.matchMedia() for responsive breakpoints + prefers-reduced-motion
 * - autoAlpha instead of plain opacity for fade-in/out
 * - gsap.ticker.remove() for proper ticker cleanup
 * - will-change cleanup after animation completes
 * - No borderRadius scrub (causes repaints)
 * - ScrollTrigger.batch with onLeaveBack for re-entry
 */
export class ScrollStoryEngine {
  private static ctx: gsap.Context | null = null;
  private static tickerCallbacks: Array<(time: number, deltaTime: number, frame: number) => void> = [];

  /**
   * Limpia y destruye todas las instancias previas de ScrollTrigger
   * Evita memory leaks y desalineacion de coordenadas al navegar con ViewTransitions.
   */
  public static cleanup(): void {
    if (typeof window === 'undefined') return;

    // Remove all ticker callbacks registered by this engine
    this.tickerCallbacks.forEach(cb => gsap.ticker.remove(cb));
    this.tickerCallbacks = [];

    if (this.ctx) {
      this.ctx.revert();
      this.ctx = null;
    } else {
      ScrollTrigger.getAll().forEach(trigger => trigger.kill());
    }
  }

  /**
   * Inicializa la narrativa interactiva completa en la pagina principal.
   * Solo debe llamarse en la pagina home.
   */
  public static initHomePageStory(): void {
    if (typeof window === 'undefined') return;

    // Destruir triggers existentes antes de re-inicializar
    this.cleanup();

    // Use gsap.matchMedia() for responsive animations and prefers-reduced-motion
    const mm = gsap.matchMedia();

    this.ctx = gsap.context(() => {
      mm.add(
        {
          isDesktop: '(min-width: 1024px)',
          isMobile: '(max-width: 1023px)',
          reduceMotion: '(prefers-reduced-motion: reduce)',
          noReduceMotion: '(prefers-reduced-motion: no-preference)',
        },
        (context) => {
          const { isDesktop, isMobile, reduceMotion } = context.conditions!;

          // Skip ALL animations if user prefers reduced motion
          if (reduceMotion) {
            // Still run reveals but instant (no animation)
            this.initGlobalRevealsInstant();
            return;
          }

          // 1. Hero Pin & Parallax Depth Shrink
          this.initHeroStory(isDesktop!);

          // 2. Room Explorer Pinned Horizontal Slider (Desktop only)
          if (isDesktop) {
            this.initRoomsStory();
          }

          // 3. Heritage Inca Path SVG Stroke Drawing
          this.initHeritageStory();

          // 4. Floating Magnets & Cards Parallax (Desktop only for perf)
          if (isDesktop) {
            this.initParallaxCards();
          }

          // 5. 3D Mouse Tilt Interactive Layers (Desktop only)
          if (isDesktop) {
            this.initMouseTilt();
          }

          // 6. GSAP Velocity-Driven Scroll Marquee
          this.initVelocityMarquee();

          // 7. Global Scroll Reveals
          this.initGlobalReveals();

          // Refresh triggers despues de layout render
          ScrollTrigger.refresh();
        }
      );
    });
  }

  /**
   * Inicializa solo las animaciones globales de reveal.
   * Puede llamarse desde cualquier pagina (no solo home).
   */
  public static initPageReveals(): void {
    if (typeof window === 'undefined') return;

    // Destruir triggers existentes antes de re-inicializar
    this.cleanup();

    const mm = gsap.matchMedia();

    this.ctx = gsap.context(() => {
      mm.add('(prefers-reduced-motion: no-preference)', () => {
        this.initGlobalReveals();
        ScrollTrigger.refresh();
      });

      mm.add('(prefers-reduced-motion: reduce)', () => {
        this.initGlobalRevealsInstant();
      });
    });
  }

  /**
   * 6. Velocity-Driven Infinite Scroll Marquee
   * El scroll acelera el flujo horizontal (scroll down -> izquierda, scroll up -> derecha)
   * sin detenerse al posar el cursor para evitar la sensacion de "lag".
   */
  private static initVelocityMarquee(): void {
    const track = document.getElementById('velocity-marquee-track');
    const container = document.getElementById('velocity-marquee-section');

    if (!track || !container) return;

    // Duplicado continuo para loop infinito suave
    const totalWidth = track.scrollWidth / 2;
    if (totalWidth <= 0) return;

    let baseSpeed = 1;
    let currentX = 0;

    // Optimizacion: quickSetter en lugar de gsap.set
    const setX = gsap.quickSetter(track, 'x', 'px');

    // GSAP Ticker continuo — stored for cleanup
    const updateMarquee = () => {
      currentX -= baseSpeed;
      if (currentX <= -totalWidth) {
        currentX += totalWidth;
      } else if (currentX >= 0) {
        currentX -= totalWidth;
      }
      setX(currentX);
    };

    gsap.ticker.add(updateMarquee);
    this.tickerCallbacks.push(updateMarquee);

    // Dynamic ScrollTrigger Velocity Observer
    ScrollTrigger.create({
      trigger: container,
      start: 'top bottom',
      end: 'bottom top',
      onUpdate: (self) => {
        const velocity = self.getVelocity();
        // Direccion e impulso de velocidad segun movimiento de scroll
        if (Math.abs(velocity) > 50) {
          const impulse = velocity * 0.0025;
          currentX -= impulse;

          // Animacion suave de desaceleracion de vuelta a la velocidad base
          gsap.to({ speed: impulse }, {
            speed: 0,
            duration: 0.8,
            ease: 'power2.out',
          });
        }
      }
    });
  }

  /**
   * 7. Global Reveals con ScrollTrigger.batch
   * Maneja las animaciones de entrada de forma elegante y sincronizada
   */
  private static initGlobalReveals(): void {
    const elements = gsap.utils.toArray('.animate-on-scroll') as HTMLElement[];
    if (!elements.length) return;

    ScrollTrigger.batch(elements, {
      interval: 0.1,
      batchMax: 5,
      start: 'top 85%',
      onEnter: (batch) => {
        gsap.to(batch, {
          autoAlpha: 1,
          y: 0,
          stagger: 0.15,
          duration: 0.8,
          ease: 'power3.out',
          overwrite: true,
          onComplete: function() {
            // Cleanup will-change after animation completes
            (this.targets() as HTMLElement[]).forEach((el: HTMLElement) => {
              el.style.willChange = 'auto';
              el.classList.add('visible');
            });
          }
        });
      },
      onLeaveBack: (batch) => {
        // Reset elements when scrolling back above viewport for re-entry animation
        gsap.set(batch, { autoAlpha: 0, y: 40 });
        batch.forEach((el: any) => {
          el.style.willChange = 'transform, opacity';
          el.classList.remove('visible');
        });
      }
    });
  }

  /**
   * Instant reveals for prefers-reduced-motion users
   */
  private static initGlobalRevealsInstant(): void {
    const elements = gsap.utils.toArray('.animate-on-scroll') as HTMLElement[];
    elements.forEach((el) => {
      gsap.set(el, { autoAlpha: 1, y: 0 });
      el.style.willChange = 'auto';
      el.classList.add('visible');
    });
  }

  /**
   * 5. 3D Mouse Tilt for Interactive Image Containers
   */
  private static initMouseTilt(): void {
    const tiltContainers = document.querySelectorAll('[data-tilt-container]');

    tiltContainers.forEach((container) => {
      const layers = container.querySelectorAll('[data-tilt-layer]');
      if (!layers.length) return;

      const setters = Array.from(layers).map((layer) => {
        const el = layer as HTMLElement;
        el.style.willChange = 'transform'; // GPU layer promotion
        const factor = parseFloat(el.getAttribute('data-tilt-layer') || '1');
        return {
          xTo: gsap.quickTo(el, 'x', { duration: 0.5, ease: 'power2.out' }),
          yTo: gsap.quickTo(el, 'y', { duration: 0.5, ease: 'power2.out' }),
          factor
        };
      });

      const handleMouseMove = (e: Event) => {
        const mouseEvent = e as MouseEvent;
        const rect = (container as HTMLElement).getBoundingClientRect();
        const relX = (mouseEvent.clientX - rect.left) / rect.width - 0.5;
        const relY = (mouseEvent.clientY - rect.top) / rect.height - 0.5;

        setters.forEach(({ xTo, yTo, factor }) => {
          xTo(relX * 30 * factor);
          yTo(relY * 30 * factor);
        });
      };

      const handleMouseLeave = () => {
        setters.forEach(({ xTo, yTo }) => {
          xTo(0);
          yTo(0);
        });
        // Cleanup will-change on mouse leave
        layers.forEach((layer) => {
          (layer as HTMLElement).style.willChange = 'auto';
        });
      };

      const handleMouseEnter = () => {
        layers.forEach((layer) => {
          (layer as HTMLElement).style.willChange = 'transform';
        });
      };

      container.addEventListener('mousemove', handleMouseMove);
      container.addEventListener('mouseleave', handleMouseLeave);
      container.addEventListener('mouseenter', handleMouseEnter);
    });
  }

  /**
   * 1. Hero Section Pinning & Depth Shrink
   */
  private static initHeroStory(isDesktop: boolean): void {
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

    // Escala del video/slideshow disminuye sutilmente dando profundidad de entrada al hotel
    // Note: borderRadius removed from scrub — it causes repaints every frame.
    // Apply rounded corners statically via CSS class instead.
    heroTl.to(heroMedia, {
      scale: isDesktop ? 0.93 : 0.96,
      autoAlpha: 0.65,
      ease: 'none'
    }, 0);

    // SplitText for Hero Title
    const title = heroSec.querySelector('h1');
    const subtitle = heroSec.querySelector('p');
    const buttons = heroSec.querySelector('.flex-col.sm\\:flex-row');

    if (title && subtitle && buttons) {
      // Remover clases de CSS manuales si existieran
      title.classList.remove('animate-fade-in');
      subtitle.classList.remove('animate-slide-up');
      buttons.classList.remove('animate-slide-up');
      (subtitle as HTMLElement).style.animation = 'none';
      (buttons as HTMLElement).style.animation = 'none';

      // Configurar estado inicial con autoAlpha (manages visibility:hidden)
      gsap.set([subtitle, buttons], { autoAlpha: 0, y: 30 });

      const splitTitle = new SplitText(title, { type: 'words,chars' });
      gsap.set(splitTitle.chars, { autoAlpha: 0, y: 40 });

      // Coreografia: Preloader -> Hero Text
      const isFirstLoad = !sessionStorage.getItem('usgar_loaded');
      const entryTl = gsap.timeline();

      if (isFirstLoad) {
        // Animacion del Preloader solo en primera visita
        const preloader = document.getElementById('cinematic-preloader');
        const preloaderText = document.getElementById('preloader-text');
        const preloaderLine = document.getElementById('preloader-line');

        if (preloader && preloaderText && preloaderLine) {
          entryTl.to(preloaderText, {
            y: 0,
            autoAlpha: 1,
            duration: 1,
            ease: 'power3.out'
          })
          .to(preloaderLine, {
            scaleX: 1,
            duration: 0.8,
            ease: 'power3.inOut'
          }, '-=0.5')
          .to(preloader, {
            yPercent: -100,
            duration: 1.2,
            ease: 'power4.inOut',
            delay: 0.5
          })
          .set(preloader, { display: 'none' }); // Cleanup visual

          // Marcar como cargado para futuras navegaciones SPA
          sessionStorage.setItem('usgar_loaded', 'true');
        }
      }

      // Animacion de entrada premium (Hero)
      entryTl.to(splitTitle.chars, {
        duration: 1.2,
        autoAlpha: 1,
        y: 0,
        stagger: 0.04,
        ease: 'power3.out',
      }, isFirstLoad ? '-=0.5' : '0')
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
    }

    if (heroContent) {
      heroTl.to(heroContent, {
        y: -100,
        autoAlpha: 0,
        ease: 'none'
      }, 0);
    }
  }

  /**
   * 2. Pinned Horizontal Rooms Showcase
   * Desktop only — on mobile the rooms are displayed as a vertical list
   */
  private static initRoomsStory(): void {
    const roomsSection = document.getElementById('rooms-interactive-story');
    const track = document.getElementById('rooms-track');

    if (!roomsSection || !track) return;

    const totalWidth = track.scrollWidth - window.innerWidth;
    if (totalWidth <= 0) return;

    // will-change for horizontal scroll — cleaned up at end
    (track as HTMLElement).style.willChange = 'transform';

    const roomsTl = gsap.timeline({
      scrollTrigger: {
        trigger: roomsSection,
        start: 'top top',
        end: () => `+=${totalWidth + 400}`,
        pin: true,
        scrub: 1,
        anticipatePin: 1,
        invalidateOnRefresh: true,
        onLeave: () => {
          // Cleanup will-change after scroll section ends
          (track as HTMLElement).style.willChange = 'auto';
        },
        onEnterBack: () => {
          (track as HTMLElement).style.willChange = 'transform';
        }
      }
    });

    roomsTl.to(track, {
      x: () => -totalWidth,
      ease: 'none'
    });

    // Animacion incremental de las tarjetas durante la traslacion horizontal
    const cards = track.querySelectorAll('.room-story-card');
    cards.forEach((card) => {
      gsap.fromTo(card,
        { scale: 0.94, autoAlpha: 0.85 },
        {
          scale: 1,
          autoAlpha: 1,
          duration: 0.5,
          scrollTrigger: {
            trigger: card,
            containerAnimation: roomsTl,
            start: 'left center',
            end: 'right center',
            scrub: true,
          }
        }
      );
    });
  }

  /**
   * 3. Heritage & Culture SVG Path Tracing
   */
  private static initHeritageStory(): void {
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
  }

  /**
   * 4. Floating Micro-Parallax & Interactive Badges
   */
  private static initParallaxCards(): void {
    const parallaxElements = document.querySelectorAll('[data-story-parallax]');

    parallaxElements.forEach((el) => {
      const speed = parseFloat(el.getAttribute('data-story-parallax') || '0.15');
      gsap.to(el, {
        y: () => -100 * speed,
        ease: 'none',
        scrollTrigger: {
          trigger: el,
          start: 'top bottom',
          end: 'bottom top',
          scrub: true,
        }
      });
    });
  }

}
