---
name: html-modern
description: Coding guidelines for modern, semantic HTML5. Covers layout hierarchy, SEO tags, accessibility (accessibility landmarks, tap targets), and Schema.org JSON-LD structured data. Use when building page templates, layout components, navigation bars, or metadata headers.
license: MIT
metadata:
  version: "1.0.0"
  author: "Antigravity Dev Experience"
---

# Modern HTML5, SEO, and Accessibility Guidelines

Maintain clean, semantic markup that is optimized for search engine indexing (SEO) and screen readers (a11y).

---

## 1. Semantic Structure
Always use descriptive HTML5 tags instead of nested generic `<div>` blocks:
- Use `<header>` and `<footer>` for page wraps.
- Use `<nav>` for main menus.
- Use `<main>` for primary page content (one per page).
- Use `<section>` for logical content groupings.
- Use `<article>` for independent components (like rooms or reviews).

```html
<header>
  <nav aria-label="Navegación Principal">
    <!-- Navbar content -->
  </nav>
</header>
<main id="main-content">
  <section id="hero" aria-labelledby="hero-title">
    <h1 id="hero-title">USGAR Hotels</h1>
  </section>
</main>
```

---

## 2. Accessibility (a11y) & Interactive Elements
- **Tap Targets:** Interactive elements (buttons, links, inputs) must have a minimum tap target size of **44x44px** to ensure mobile friendliness.
- **Labels:** Every input field must have an associated `<label>` or `aria-label`:
  ```html
  <label for="checkin-date">Fecha de Ingreso</label>
  <input type="date" id="checkin-date" name="checkin" required />
  ```
- **Images:** All images must include descriptive `alt` tags. Use `alt=""` for decorative assets.

---

## 3. SEO & Heading Hierarchy
- Ensure there is exactly **one `<h1>` tag** per page.
- Maintain logical heading depth (H1 $\rightarrow$ H2 $\rightarrow$ H3 $\rightarrow$ H4). Never skip levels for styling purposes.
- Include descriptive `<title>` and `<meta name="description">` tags on every page.

---

## 4. Schema.org JSON-LD Structured Data
Embed rich microdata in the `<head>` of your layouts (specifically for hotels and booking websites) using JSON-LD:

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Hotel",
  "name": "USGAR Hotels",
  "description": "Hotel boutique exclusivo en San Pedro, Cusco, Perú.",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "San Pedro",
    "addressLocality": "Cusco",
    "addressCountry": "PE"
  },
  "checkinTime": "12:00:00",
  "checkoutTime": "10:30:00",
  "priceRange": "$$"
}
</script>
```
