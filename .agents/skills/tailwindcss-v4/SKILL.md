---
name: tailwindcss-v4
description: Guidelines for Tailwind CSS v4 CSS-first design system. Covers @theme directive, custom design tokens, CSS variables, utility-first structure, and integration with @tailwindcss/vite. Use when editing styles, tailwind custom tokens, global.css, or styling UI elements.
license: MIT
metadata:
  version: "4.0.0"
  author: "Antigravity Dev Experience"
---

# Tailwind CSS v4 Design & Styling Best Practices

Tailwind CSS v4 uses a **CSS-first** configuration workflow. The traditional `tailwind.config.js` is replaced by configuring the build pipeline directly in your main CSS file (`src/styles/global.css`) using the `@theme` directive.

---

## 1. Core Syntax & Imports

To load Tailwind v4, import it at the top of your global CSS file:

```css
@import "tailwindcss";
```

All custom tokens, theme extends, and utilities are declared below this import.

---

## 2. Defining Custom Design Tokens (@theme)

Use the `@theme` directive to define or override colors, fonts, breakpoints, animations, and transitions. All values specified under `@theme` are automatically generated as CSS Custom Properties (CSS variables) and utility classes.

### Example configuration matching USGAR Hotels guidelines:

```css
@import "tailwindcss";

@theme {
  /* --- Brand Colors (Opción 3 — Logo) --- */
  --color-primary-dark: #4A3056;    /* Morado Oscuro: Encabezados, CTAs */
  --color-primary-medium: #9360AC;  /* Morado Medio: Hover, activos */
  
  --color-secondary-base: #EACA1C;  /* Amarillo Base: CTAs secundarios */
  --color-secondary-gold: #B09815;  /* Dorado Oscuro: Precios, estrellas */
  
  --color-tertiary-pino: #065952;   /* Verde Pino: Botones de reserva */
  --color-tertiary-turquesa: #0CB2A3; /* Turquesa: Detalles, links */

  /* --- Typography --- */
  --font-display: "A Akhin Tahun", "Playfair Display", serif;
  --font-sans: "Montserrat", "Outfit", sans-serif;
  --font-logo: "Kravitz Extra Thermal", sans-serif;

  /* --- Smooth Micro-Animations --- */
  --animate-smooth-fade: fade-in 0.4s cubic-bezier(0.3, 0, 0, 1) forwards;
  --animate-slide-up: slide-up 0.5s cubic-bezier(0.2, 0, 0, 1) forwards;
}

@keyframes fade-in {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes slide-up {
  from { transform: translateY(1.5rem); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
```

### Usage in HTML/Astro:
```html
<h1 class="font-display text-primary-dark dark:text-primary-medium animate-smooth-fade">
  USGAR Hotels
</h1>
<button class="bg-tertiary-pino hover:bg-primary-medium text-white px-6 py-3 font-sans transition-all duration-300">
  Reservar
</button>
```

---

## 3. Dark Mode & Media Queries
* Tailwind v4 uses standard media queries or class-based dark mode depending on settings. 
* By default, write `dark:bg-slate-900` classes to target dark mode.
* To support system preference and toggle:
  ```css
  /* Check html.dark class or system media */
  :root {
    color-scheme: light;
  }
  html.dark {
    color-scheme: dark;
  }
  ```

---

## 4. Key Rules
- **No hardcoded inline values for theme constants:** Always define new color families or spacing values under `@theme` in `global.css` so they remain reusable and maintainable.
- **Micro-animations:** Always support accessibility using `motion-safe:` prefixes or disable custom animations for users with `prefers-reduced-motion` settings:
  ```css
  @media (prefers-reduced-motion: reduce) {
    * {
      animation-duration: 0.01ms !important;
      animation-iteration-count: 1 !important;
      transition-duration: 0.01ms !important;
      scroll-behavior: auto !important;
    }
  }
  ```
