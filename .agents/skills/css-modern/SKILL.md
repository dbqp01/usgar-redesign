---
name: css-modern
description: Coding guidelines for modern CSS. Covers native CSS nesting, custom properties, container queries, logical properties, media features, and responsive layouts. Use when editing raw CSS stylesheets, configuring global styles, or building layout components.
license: MIT
metadata:
  version: "3.0.0"
  author: "Antigravity Dev Experience"
---

# Modern CSS Standards & Guidelines

Modern CSS (as of 2026) supports advanced native layout, scoping, nesting, and responsive behaviors directly in the browser without requiring preprocessors like Sass or Less.

---

## 1. Native CSS Nesting
* Native nesting is now fully standard. Avoid preprocessors and write nested CSS directly:
```css
.card {
  background-color: var(--color-surface);
  padding: 1.5rem;
  border-radius: 0.5rem;

  & .title {
    font-size: 1.25rem;
    color: var(--color-text-primary);
  }

  &:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  }
}
```

---

## 2. CSS Custom Properties (Variables)
* Use custom properties for theming (Dual Light/Dark mode).
* Declare them in `:root` and override under `.dark` selectors:
```css
:root {
  --color-bg: #ffffff;
  --color-text: #1a1a1a;
  --color-accent: #065952;
}

html.dark {
  --color-bg: #121212;
  --color-text: #f5f5f5;
  --color-accent: #0cb2a3;
}

body {
  background-color: var(--color-bg);
  color: var(--color-text);
}
```

---

## 3. Logical Properties & Values
* Use logical properties instead of physical ones to ensure layouts automatically support writing direction (LTR/RTL) and are more semantically clean:
  * `margin-inline` instead of `margin-left` + `margin-right`.
  * `padding-block` instead of `padding-top` + `padding-bottom`.
  * `border-inline-start` instead of `border-left`.

```css
.button {
  padding-block: 0.75rem;
  padding-inline: 1.5rem;
  margin-block-start: 1rem;
}
```

---

## 4. Container Queries (@container)
* Container queries allow components to adapt style rules based on the width of their parent container instead of the entire viewport:
```css
/* 1. Define the parent container */
.sidebar-wrapper {
  container-type: inline-size;
  container-name: card-container;
}

/* 2. Style the child based on container size */
@container card-container (min-width: 400px) {
  .responsive-card {
    display: flex;
    flex-direction: row;
    align-items: center;
  }
}
```
