---
name: "brand-auditor"
description: "Auditoría visual y de marca del sitio USGAR Hotels. Verifica alineamiento completo con BRAND.md: paleta de 15 colores + neutros en CSS, tipografías A Akhin Tahun y Montserrat cargadas correctamente, tema dual light/dark, logos según contexto, contenido institucional exacto (misión, visión, 8 valores), 18 servicios + 11 amenidades, 4 habitaciones con precios correctos, animaciones suaves, SEO con Schema.org."
---

# Auditoría Visual y de Marca — USGAR Hotels

## Propósito

Verificar que CADA elemento visual y de contenido del sitio web esté alineado
con [BRAND.md](../../BRAND.md), que es la FUENTE DE VERDAD CANÓNICA.

## MCPs Requeridos

- **filesystem** — Para leer CSS, componentes, layouts
- **sequential-thinking** — Para comparar metódicamente BRAND.md vs código
- **context7** — Para verificar Tailwind v4 y Astro best practices
- **playwright** (opcional) — Para tomar screenshots y verificar visualmente

## Procedimiento: Leer BRAND.md Primero

**ANTES DE CUALQUIER OTRA COSA:** Lee `.agents/BRAND.md` completo.
Extrae de ahí todos los valores canónicos que vas a verificar.

## Checklist de Verificación

### 1. Paleta de Colores (BRAND.md §3)

Leer `src/styles/global.css` y buscar el bloque `@theme { ... }`.
Verificar que CADA uno de estos 22 tokens CSS existe con el hex correcto:

**Morados:**
- [ ] `--color-primary: #4A3056`
- [ ] `--color-primary-light: #9360AC`
- [ ] `--color-primary-dark: #351C42`
- [ ] `--color-purple-soft: #A980BD`
- [ ] `--color-purple-pastel: #D4BFDE`
- [ ] `--color-purple-bg: #E9DFEE`

**Amarillos:**
- [ ] `--color-secondary: #EACA1C`
- [ ] `--color-secondary-light: #F2DF77`
- [ ] `--color-secondary-dark: #B09815`
- [ ] `--color-cream-active: #F7EAA4`

**Verdes:**
- [ ] `--color-tertiary: #065952`
- [ ] `--color-tertiary-light: #0CB2A3`
- [ ] `--color-tertiary-dark: #04403B`
- [ ] `--color-mint: #6DD1C8`
- [ ] `--color-green-pastel: #9EE0DA`
- [ ] `--color-green-bg: #CEF0ED`

**Superficies:**
- [ ] `--color-surface-light: #FBF4D2`
- [ ] `--color-surface-dark: #1C1917`
- [ ] `--color-surface-card-light: #FFFFFF`
- [ ] `--color-surface-card-dark: #2B1D33`

**Texto:**
- [ ] `--color-text-primary-light: #333333`
- [ ] `--color-text-primary-dark: #FAFAF9`
- [ ] `--color-text-secondary-light: #57534E`
- [ ] `--color-text-secondary-dark: #D4BFDE`

**Tipografía:**
- [ ] `--font-display: 'A Akhin Tahun', 'Playfair Display', serif`
- [ ] `--font-body: 'Montserrat', 'Outfit', sans-serif`

### 2. Tipografías (BRAND.md §4)

- [ ] Existe `@font-face` para `A Akhin Tahun` apuntando a `AkhirTahun.woff2`
- [ ] El archivo `public/fonts/AkhirTahun.woff2` existe
- [ ] Montserrat se carga desde Google Fonts o localmente
- [ ] Los `<h1>`-`<h4>` usan `font-display` (A Akhin Tahun)
- [ ] El body usa `font-body` (Montserrat)
- [ ] Buscar en componentes: ¿alguien usa `font-family: 'Playfair Display'` o `'Outfit'` directamente? Reportar.

### 3. Tema Dual (BRAND.md §10)

- [ ] Existe script de detección de tema (localStorage → prefers-color-scheme)
- [ ] Toggle de tema en Navbar
- [ ] Clase `.dark` se agrega al `<html>`
- [ ] Modo claro usa fondo `#FBF4D2` o blanco
- [ ] Modo oscuro usa fondo `#1C1917`
- [ ] Tarjetas en dark mode usan `#2B1D33` (tinte morado)

### 4. Logos (BRAND.md §2)

- [ ] Light mode: logo color completo o negro
- [ ] Dark mode / Hero / Footer: logo morado
- [ ] Favicon: isotipo del logo
- [ ] Los archivos de logo existen en `src/assets/` o `public/`

### 5. Contenido Institucional (BRAND.md §5)

Leer `src/components/AboutSection.astro` o los archivos i18n:
- [ ] Propósito coincide EXACTAMENTE con BRAND.md §5
- [ ] Misión coincide EXACTAMENTE
- [ ] Visión coincide EXACTAMENTE
- [ ] 8 Valores listados (Hospitalidad, Excelencia, Autenticidad, Respeto, Compromiso, Sostenibilidad, Trabajo en equipo, Innovación)

### 6. Servicios (BRAND.md §7)

Leer `src/data/services.ts`:
- [ ] Existen los 18 servicios del hotel
- [ ] Existen las 11 amenidades de habitación
- [ ] Horario desayuno: 6:00am – 9:00am
- [ ] Horario cafetería: hasta 22:00
- [ ] Check-in: 12:00 hrs
- [ ] Check-out: 10:30 hrs

### 7. Habitaciones (BRAND.md §6)

- [ ] Solo 4 tipos (no Quadruple)
- [ ] Máximo 4 fotos por habitación
- [ ] Precios correctos en UI ($90, $90, $120, $150)
- [ ] Familiar Superior: max 7 huéspedes, 3 dobles + 1 individual

### 8. Componentes UI (BRAND.md §9)

- [ ] Navbar: glassmorphism al scroll, icons ≥ 24px, tap target ≥ 44×44px
- [ ] Botón Primary: bg `#4A3056`, hover `#351C42`, texto blanco
- [ ] Botón Secondary: bg `#EACA1C`, hover `#B09815`
- [ ] Room Cards: hover zoom suave
- [ ] WhatsApp: botón flotante bottom-right con pulso
- [ ] Mapa: OpenStreetMap con Leaflet (NO Google Maps)
- [ ] Footer: fondo oscuro, logo morado, "Hecho por MarcaRed"

### 9. Animaciones (BRAND.md §11)

- [ ] Scroll: fade-in y slide-up
- [ ] `@media (prefers-reduced-motion: reduce)` desactiva animaciones
- [ ] Filosofía: "ligero y suave" — nada brusco

### 10. SEO (BRAND.md §12)

- [ ] Schema.org con check-in 12:00, check-out 10:30, 4 habitaciones
- [ ] Sitemap XML vía `@astrojs/sitemap`
- [ ] Hreflang para EN/ES
- [ ] Open Graph y Twitter Cards

## Formato de Reporte

Crear un artifact `audit_brand_results.md` con:

```markdown
# Auditoría Visual — Resultados
Fecha: [fecha]

## Alineamiento con BRAND.md

| Sección | Estado | Detalles |
|---|---|---|
| Paleta de colores | ✅/⚠️/❌ | ... |
| Tipografías | ✅/⚠️/❌ | ... |
| Tema dual | ✅/⚠️/❌ | ... |
| ... | ... | ... |

## Capturas de Pantalla (si playwright disponible)
[screenshots]

## Acciones Requeridas
- [ ] [acción 1]
```
