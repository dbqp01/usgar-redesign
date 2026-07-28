import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

/**
 * USGAR Hotels — Interactive Scroll Story Engine
 * Maneja las animaciones inmersivas de scroll (Pinning, Scrubbing, Parallax 3D y Trazo SVG)
 * optimizadas para Astro v7 ViewTransitions.
 */
export class ScrollStoryEngine {
  /**
   * Limpia y destruye todas las instancias previas de ScrollTrigger
   * Evita memory leaks y desalineacion de coordenadas al navegar con ViewTransitions.
   */
  public static cleanup(): void {
    if (typeof window === 'undefined') return;
    ScrollTrigger.getAll().forEach(trigger => trigger.kill());
  }

  /**
   * Inicializa la narrativa interactiva completa en la pagina principal
   */
  public static initHomePageStory(): void {
    if (typeof window === 'undefined') return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    // Destruir triggers existentes antes de re-inicializar
    this.cleanup();

    // 1. Hero Pin & Parallax Depth Shrink
    this.initHeroStory();

    // 2. Room Explorer Pinned Horizontal Slider
    this.initRoomsStory();

    // 3. Heritage Inca Path SVG Stroke Drawing
    this.initHeritageStory();

    // 4. Floating Magnets & Cards Parallax
    this.initParallaxCards();

    // 5. 3D Mouse Tilt Interactive Layers
    this.initMouseTilt();

    // 6. GSAP Velocity-Driven Scroll Marquee
    this.initVelocityMarquee();

    // 7. Section reveals handled by CSS .animate-on-scroll + IntersectionObserver
    // (GSAP reveals removed — they conflict with CSS transition system)

    // Refresh triggers despues de layout render
    ScrollTrigger.refresh();
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

    // GSAP Ticker continuo
    const updateMarquee = () => {
      currentX -= baseSpeed;
      if (currentX <= -totalWidth) {
        currentX += totalWidth;
      } else if (currentX >= 0) {
        currentX -= totalWidth;
      }
      gsap.set(track, { x: currentX });
    };

    gsap.ticker.add(updateMarquee);

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
   * 5. 3D Mouse Tilt for Interactive Image Containers
   */
  private static initMouseTilt(): void {
    const tiltContainers = document.querySelectorAll('[data-tilt-container]');

    tiltContainers.forEach((container) => {
      const layers = container.querySelectorAll('[data-tilt-layer]');
      if (!layers.length) return;

      const setters = Array.from(layers).map((layer) => {
        const factor = parseFloat(layer.getAttribute('data-tilt-layer') || '1');
        return {
          xTo: gsap.quickTo(layer, 'x', { duration: 0.5, ease: 'power2.out' }),
          yTo: gsap.quickTo(layer, 'y', { duration: 0.5, ease: 'power2.out' }),
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
      };

      container.addEventListener('mousemove', handleMouseMove);
      container.addEventListener('mouseleave', handleMouseLeave);
    });
  }

  /**
   * 1. Hero Section Pinning & Depth Shrink
   */
  private static initHeroStory(): void {
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
    heroTl.to(heroMedia, {
      scale: 0.93,
      borderRadius: '24px',
      opacity: 0.65,
      ease: 'none'
    }, 0);

    if (heroContent) {
      heroTl.to(heroContent, {
        y: -60,
        opacity: 0.2,
        ease: 'none'
      }, 0);
    }
  }

  /**
   * 2. Pinned Horizontal Rooms Showcase
   */
  private static initRoomsStory(): void {
    const roomsSection = document.getElementById('rooms-interactive-story');
    const track = document.getElementById('rooms-track');

    if (!roomsSection || !track) return;

    const totalWidth = track.scrollWidth - window.innerWidth;
    if (totalWidth <= 0) return;

    const roomsTl = gsap.timeline({
      scrollTrigger: {
        trigger: roomsSection,
        start: 'top top',
        end: () => `+=${totalWidth + 400}`,
        pin: true,
        scrub: 1,
        anticipatePin: 1,
        invalidateOnRefresh: true,
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
        { scale: 0.94, opacity: 0.85 },
        {
          scale: 1,
          opacity: 1,
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
