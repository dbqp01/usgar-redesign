import { gsap } from 'gsap';

// Rotación de los 3 segmentos del hero con transición "wipe dorado" (GSAP
// clip-path) y elección de codec (AV1 si el navegador lo soporta, fallback H.264).
// Centralizado 2026-08-15: antes vivía en el <script> de Hero.astro; ahora es
// un módulo del engine de animaciones (patrón del repo: features/animations/modules).
//
// El hero es sticky (efecto "periódico" del dueño): el wipe solo se usa en la
// rotación de segmentos; la entrada del título la anima heroStory.ts.

const preloaded = new Set<string>();

function pickParts(video: HTMLVideoElement): string[] {
  const raw = JSON.parse(video.dataset.videoParts || '{"desktop":[],"mobile":[]}') as {
    desktop: string[];
    mobile: string[];
  };
  const list = window.matchMedia('(max-width: 767px)').matches ? raw.mobile : raw.desktop;
  const supportsAv1 = video.canPlayType('video/mp4; codecs="av01.0.04M.08"') !== '';
  return supportsAv1 ? list.map((p) => p.replace(/\.mp4$/, '.av1.mp4')) : list;
}

function preloadNext(src: string): void {
  if (preloaded.has(src)) return;
  preloaded.add(src);
  fetch(src, { mode: 'cors' }).catch(() => {
    /* precarga best-effort: si falla, el swap espera al buffering normal */
  });
}

export function initHeroVideo(): () => void {
  const video = document.getElementById('hero-video') as HTMLVideoElement | null;
  const toggleBtn = document.getElementById('video-audio-toggle') as HTMLElement | null;
  const mutedIcon = document.getElementById('muted-icon') as HTMLElement | null;
  const unmutedIcon = document.getElementById('unmuted-icon') as HTMLElement | null;
  const slideshow = document.getElementById('hero-slideshow') as HTMLElement | null;
  const wipe = document.getElementById('hero-wipe') as HTMLElement | null;
  const loading = document.getElementById('hero-video-loading') as HTMLElement | null;

  if (!video || !toggleBtn || !mutedIcon || !unmutedIcon || !slideshow) return () => {};

  // Narrowing: las constantes no-null sobreviven dentro de las closures
  // (startSlideshow, onended...) — el narrowing del guard no (pitfall del repo).
  const videoEl = video;
  const toggleBtnEl = toggleBtn;
  const slideshowEl = slideshow;

  let activeSlideshowInterval: ReturnType<typeof setInterval> | null = null;

  const slides = slideshowEl.querySelectorAll('[data-slide]');
  const parts = pickParts(videoEl);
  let partIndex = 0;
  let swapping = false;

  // Reset de estados visuales (navegación SPA: el DOM viejo puede llegar sucio)
  // opacity 0 -> 1 al primer frame reproducible: con opacity 0 el video NO es
  // candidato LCP (spec) — el <img> poster AVIF debajo (mismo frame, 128KB)
  // gana el LCP en vez del primer frame del MP4 (fix rendimiento 2026-08-16).
  videoEl.style.opacity = '0';
  videoEl.addEventListener('playing', () => { videoEl.style.opacity = ''; }, { once: true });
  slideshowEl.classList.remove('opacity-100');
  slides.forEach((slide) => {
    slide.classList.remove('opacity-100');
    slide.classList.add('opacity-0');
  });
  toggleBtnEl.classList.remove('opacity-0', 'pointer-events-none');
  toggleBtnEl.classList.add('opacity-100');
  videoEl.muted = true;
  toggleBtnEl.setAttribute('aria-pressed', 'false');
  mutedIcon.classList.remove('hidden');
  unmutedIcon.classList.add('hidden');

  // Transición entre segmentos: wipe dorado que barre la pantalla (clip-path),
  // swap de fuente a mitad del barrido, wipe de salida revelando el nuevo clip.
  // reduced-motion: swap directo sin wipe.
  function playPart(index: number): void {
    const src = parts[index];
    if (!src || swapping) return;
    swapping = true;

    const swapSource = () => {
      videoEl.src = src;
      videoEl.load();
      videoEl.play().catch(() => {
        /* autoplay bloqueado: aceptable */
      });
      const next = parts[(index + 1) % parts.length];
      if (next) preloadNext(next);
    };

    // Si el codec elegido (AV1) falla al decodificar en este navegador, saltar
    // al fallback H.264 del mismo segmento — nunca dejar el poster estático.
    // (Regresión 2026-08-15: "no veo los videos, queda una imagen de fondo".)
    videoEl.onerror = () => {
      if (!src.includes('.av1.')) return;
      const h264 = src.replace(/\.av1\.mp4$/, '.mp4');
      videoEl.src = h264;
      videoEl.load();
      videoEl.play().catch(() => {});
      swapping = false;
    };

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches || window.innerWidth < 768;
    if (!wipe || reduceMotion) {
      swapSource();
      swapping = false;
      return;
    }

    gsap
      .timeline({
        onComplete: () => {
          swapping = false;
        },
      })
      .set(wipe, { opacity: 1 })
      .fromTo(
        wipe,
        { clipPath: 'inset(0 0 100% 0)' },
        { clipPath: 'inset(0 0 0% 0)', duration: 0.7, ease: 'power2.inOut' },
      )
      .call(swapSource, undefined, 0.32)
      .to(wipe, { clipPath: 'inset(0 0 100% 0)', duration: 0.7, ease: 'power2.inOut' })
      .set(wipe, { opacity: 0 });
  }

  // Arranque retrasado (fix rendimiento 2026-08-16): el video NO se ve hasta
  // que el preloader termina (1ª visita) o hasta el load del documento
  // (visitas con sessionStorage) — mientras, el LCP lo resuelve el poster
  // AVIF (128KB) en lugar del primer frame del MP4 (2.8-4.3MB móvil, LCP
  // 6.3s en PSI). Cero cambio visual: cuando el preloader se va, el video
  // ya está en camino y el poster cubre el fondo durante el buffering.
  // RACE FIX (2026-08-16): en visitas con sessionStorage el preloader hace
  // done() (y dispatchea) DENTRO de page-load — podía ocurrir antes de
  // registrar el listener y el video nunca arrancaba. Ahora startOnce() con
  // guard cubre readyState-complete + load + preloader-done, idempotente.
  let started = false;
  function startOnce(): void {
    if (started) return;
    started = true;
    const start = () => {
      // Overlay "cargando video" (estilo YouTube): visible mientras el MP4
      // bufferiza; fade-out en el primer frame reproducible. Timeout de
      // seguridad 12s: si el autoplay esta bloqueado el poster queda y el
      // texto desaparece igual (nunca un spinner eterno).
      let hideTimeout = 0;
      function hideLoading(): void {
        window.clearTimeout(hideTimeout);
        if (!loading) return;
        loading.classList.remove('opacity-100');
        loading.classList.add('opacity-0');
      }
      const onPlaying = () => {
        hideLoading();
        preloadNext(parts[1 % parts.length]);
        videoEl.removeEventListener('playing', onPlaying);
      };
      if (loading) loading.classList.add('opacity-100');
      videoEl.addEventListener('playing', onPlaying);
      videoEl.addEventListener('error', hideLoading, { once: true });
      hideTimeout = window.setTimeout(hideLoading, 12000);
      if (parts.length > 0) {
        playPart(0);
      } else {
        videoEl.currentTime = 0;
        videoEl.play().catch(() => {});
      }
    };
    if (document.readyState === 'complete') {
      start();
    } else {
      window.addEventListener('load', start, { once: true });
    }
    if (window.__preloaderDone) {
      start();
    } else {
      window.addEventListener('usgar:preloader-done', start, { once: true });
    }
  }
  startOnce();

  // Toggle de sonido (asignación directa: no acumula listeners entre re-inits)
  toggleBtnEl.onclick = () => {
    videoEl.muted = !videoEl.muted;
    toggleBtnEl.setAttribute('aria-pressed', String(!videoEl.muted));
    mutedIcon.classList.toggle('hidden', !videoEl.muted);
    unmutedIcon.classList.toggle('hidden', videoEl.muted);
  };

  // Fin de segmento: siguiente clip (loop infinito). Sin segmentos → slideshow.
  videoEl.onended = () => {
    if (parts.length > 0) {
      partIndex = (partIndex + 1) % parts.length;
      playPart(partIndex);
    } else {
      videoEl.classList.remove('opacity-100');
      videoEl.classList.add('opacity-0');
      startSlideshow();
    }
  };

  function forceEager(slide: Element): void {
    requestAnimationFrame(() => {
      const img = slide.querySelector('img');
      if (!img) return;
      img.loading = 'eager';
      img.removeAttribute('loading');
      if (!img.complete || img.naturalWidth === 0) {
        const src = img.currentSrc || img.src;
        img.removeAttribute('srcset');
        img.src = src;
      }
    });
  }

  function startSlideshow(): void {
    slideshowEl.classList.remove('opacity-0');
    slideshowEl.classList.add('opacity-100');
    toggleBtnEl.classList.add('opacity-0', 'pointer-events-none');

    let currentSlide = 0;
    if (slides[0]) {
      forceEager(slides[0]);
      slides[0].classList.remove('opacity-0');
      slides[0].classList.add('opacity-100');
    }

    if (activeSlideshowInterval) clearInterval(activeSlideshowInterval);
    activeSlideshowInterval = setInterval(() => {
      if (slides[currentSlide]) {
        slides[currentSlide].classList.remove('opacity-100');
        slides[currentSlide].classList.add('opacity-0');
      }
      currentSlide++;
      if (currentSlide < slides.length) {
        if (slides[currentSlide]) {
          forceEager(slides[currentSlide]);
          slides[currentSlide].classList.remove('opacity-0');
          slides[currentSlide].classList.add('opacity-100');
        }
      } else {
        if (activeSlideshowInterval) clearInterval(activeSlideshowInterval);
        activeSlideshowInterval = null;
        slideshowEl.classList.remove('opacity-100');
        slideshowEl.classList.add('opacity-0');
        slides.forEach((slide) => {
          slide.classList.remove('opacity-100');
          slide.classList.add('opacity-0');
        });
        videoEl.classList.remove('opacity-0');
        videoEl.classList.add('opacity-100');
        toggleBtnEl.classList.remove('opacity-0', 'pointer-events-none');
        videoEl.currentTime = 0;
        videoEl.play().catch(() => {});
      }
    }, 4000);
  }

  return () => {
    if (activeSlideshowInterval) {
      clearInterval(activeSlideshowInterval);
      activeSlideshowInterval = null;
    }
  };
}
