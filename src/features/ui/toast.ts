// src/features/ui/toast.ts
// Notificaciones estilo Sonner: top-center, glass, iconos por tipo,
// auto-dismiss, cierre manual, aria-live, animacion GSAP con
// prefers-reduced-motion. Sin dependencias de UI.

import { gsap } from 'gsap';

export type ToastType = 'success' | 'error' | 'warning' | 'info';

export interface ToastOptions {
  title: string;
  description?: string;
  duration?: number;
  action?: { label: string; onClick: () => void };
}

const ICONS: Record<ToastType, string> = {
  success:
    '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>',
  error:
    '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>',
  warning:
    '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/></svg>',
  info:
    '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
};

const COLORS: Record<ToastType, string> = {
  success: 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20',
  error: 'text-red-500 bg-red-500/10 border-red-500/20',
  warning: 'text-amber-500 bg-amber-500/10 border-amber-500/20',
  info: 'text-primary bg-primary/10 border-primary/20',
};

const MAX_VISIBLE = 3;
let root: HTMLElement | null = null;
const active: HTMLElement[] = [];

function ensureRoot(): HTMLElement {
  if (root && document.body.contains(root)) return root;
  root = document.createElement('div');
  root.id = 'toast-root';
  root.setAttribute('aria-live', 'polite');
  root.setAttribute('role', 'status');
  root.className =
    'fixed top-6 left-1/2 -translate-x-1/2 z-[100] flex flex-col items-center gap-2 w-[calc(100vw-2rem)] max-w-sm pointer-events-none';
  document.body.appendChild(root);
  return root;
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function dismiss(el: HTMLElement): void {
  const idx = active.indexOf(el);
  if (idx === -1) return;
  active.splice(idx, 1);
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const done = () => el.remove();
  if (reduceMotion) {
    done();
    return;
  }
  gsap.to(el, { autoAlpha: 0, y: -10, scale: 0.97, duration: 0.25, ease: 'power2.in', onComplete: done });
}

function toast(type: ToastType, options: ToastOptions): void {
  const container = ensureRoot();
  while (active.length >= MAX_VISIBLE) dismiss(active[0]);

  const el = document.createElement('div');
  el.setAttribute('role', type === 'error' ? 'alert' : 'status');
  el.className =
    'pointer-events-auto relative w-full rounded-2xl border bg-white/95 dark:bg-neutral-900/95 backdrop-blur-xl shadow-2xl shadow-black/10 dark:shadow-black/40 px-4 py-3.5 pr-10 data-[toast]:block';
  el.dataset.toast = type;

  const actionHtml = options.action
    ? `<button type="button" data-toast-action class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:text-primary-dark transition-colors">${escapeHtml(options.action.label)}</button>`
    : '';

  el.innerHTML = `
    <div class="flex items-start gap-3">
      <span class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center border ${COLORS[type]}">
        ${ICONS[type]}
      </span>
      <div class="min-w-0 flex-1 pt-0.5">
        <p class="text-[13px] font-semibold text-text-primary-light dark:text-white leading-snug">${escapeHtml(options.title)}</p>
        ${options.description ? `<p class="text-xs text-text-secondary-light dark:text-text-secondary-dark mt-0.5 leading-snug">${escapeHtml(options.description)}</p>` : ''}
        ${actionHtml}
      </div>
    </div>
    <button type="button" data-toast-close aria-label="Dismiss notification" class="absolute top-3 right-3 w-6 h-6 rounded-full flex items-center justify-center text-stone-400 hover:text-stone-600 dark:hover:text-stone-200 hover:bg-black/5 dark:hover:bg-white/10 transition-colors cursor-pointer">
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  `;
  container.appendChild(el);
  active.push(el);

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduceMotion) {
    gsap.fromTo(el, { autoAlpha: 0, y: -14, scale: 0.96 }, { autoAlpha: 1, y: 0, scale: 1, duration: 0.35, ease: 'power3.out' });
  }

  const duration = options.duration ?? (type === 'error' ? 6000 : type === 'warning' ? 5000 : 4000);
  const timer = window.setTimeout(() => dismiss(el), duration);

  el.querySelector('[data-toast-close]')?.addEventListener('click', () => {
    window.clearTimeout(timer);
    dismiss(el);
  });
  el.querySelector('[data-toast-action]')?.addEventListener('click', () => {
    window.clearTimeout(timer);
    options.action?.onClick();
    dismiss(el);
  });
}

export const toastApi = {
  success: (options: ToastOptions) => toast('success', options),
  error: (options: ToastOptions) => toast('error', options),
  warning: (options: ToastOptions) => toast('warning', options),
  info: (options: ToastOptions) => toast('info', options),
};
