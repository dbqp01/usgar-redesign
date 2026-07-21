Voy a investigar el repositorio para identificar los errores y las reglas de desarrollo.


## Recuento de Errores Completos - Implementación pt/fr

He identificado **6 errores críticos** en la implementación de los idiomas portugués (pt) y francés (fr) del repositorio. A continuación el análisis detallado:

---

### ERROR 1: Expresión Regular Mal Formada en Navbar.astro

**Ubicación:** `src/components/Navbar.astro` línea ~15

**Problema:**
```javascript
let cleanPath = currentPath.replace(/^/(es|fr|pt)(/|$)/, '/');
```

La regex tiene errores de sintaxis: faltan escapes para las barras diagonales.

**Solución (conforme a RULES.md - Simplicidad y Robustez):**
```javascript
let cleanPath = currentPath.replace(/^\/(es|fr|pt)(\/|$)/, '/');
```

---

### ERROR 2: Inconsistencia en FallbackChain entre Configuraciones

**Ubicación:** 
- `astro.config.mjs` (fallback routing)
- `src/i18n/utils.ts` (fallback runtime)

**Problema:**
```javascript
// astro.config.mjs - Define UN solo fallback
fallback: {
  fr: 'en',
  pt: 'es'
}

// utils.ts - Define MÚLTIPLES fallbacks
const fallbackChain = {
  fr: ['en', 'es'],  // Inconsistente
  pt: ['es', 'en']   // Inconsistente
};
```

**Solución (conforme a RULES.md - SRP y Coherencia):**

Opción A - Usar solo el sistema de Astro (recomendado):
```javascript
// astro.config.mjs
fallback: {
  'fr': 'en',
  'pt': 'es'
}
```
Y en `utils.ts` simplificar:
```typescript
const fallbackChain: Record<Locale, Locale[]> = {
  en: [],
  es: ['en'],
  fr: ['en'],
  pt: ['es', 'en']
};
```

---

### ERROR 3: Tipos TypeScript Incompletos en utils.ts

**Ubicación:** `src/i18n/utils.ts`

**Problema:**
```typescript
const dictionaries: Record = { ... }
const fallbackChain: Record = { ... }
```

Faltan los parámetros genéricos del tipo `Record`.

**Solución (conforme a RULES.md - Tipado Estricto):**
```typescript
import type { TranslationDictionary } from './types';

const dictionaries: Record<Locale, TranslationDictionary> = {
  en, es, fr, pt,
};

const fallbackChain: Record<Locale, Locale[]> = {
  en: ['es'],
  es: ['en'],
  fr: ['en', 'es'],
  pt: ['es', 'en'],
};
```

---

### ERROR 4: Textos Hardcodeados en InteractiveRoomsSection.astro

**Ubicación:** `src/components/InteractiveRoomsSection.astro`

**Problema:** Viola el principio DRY (RULES.md punto 4):
```astro
{lang === 'es' ? 'NUESTRO REFUGIO' : lang === 'fr' ? 'NOTRE REFUGE' : ...}
{lang === 'es' ? 'Explora nuestros espacios...' : lang === 'fr' ? 'Explorez...' : ...}
```

**Solución (conforme a RULES.md - DRY y Abstracción):**

Agregar claves faltantes en los JSON de i18n:

```json
// src/i18n/es.json, en.json, fr.json, pt.json
"rooms": {
  "sanctuaryTitle": {
    "es": "NUESTRO REFUGIO",
    "en": "OUR SANCTUARY",
    "fr": "NOTRE REFUGE",
    "pt": "NOSSO REFÚGIO"
  },
  "sanctuaryDescription": {
    "es": "Explora nuestros espacios...",
    "en": "Explore our spaces...",
    "fr": "Explorez nos espaces...",
    "pt": "Explore nossos espaços..."
  }
}
```

Y en el componente:
```astro
<h2>{t('rooms.sanctuaryTitle')}</h2>
<p>{t('rooms.sanctuaryDescription')}</p>
```

---

### ERROR 5: Errores de Sintaxis en InteractiveRoomsSection.astro

**Ubicación:** `src/components/InteractiveRoomsSection.astro`

**Problema A - Imagen mal formateada:**
```astro
![{room.name[lang]]({room.photo})
```

**Solución:**
```astro
<img src={room.photo.src} alt={room.name[lang]} />
```

**Problema B - URL incompleta:**
```astro
{getRelativeLocaleUrl(lang,)}
```

**Solución:**
```astro
{getRelativeLocaleUrl(lang, `rooms/${room.slug}`)}
```

---

### ERROR 6: Claves Faltantes en Archivos JSON de i18n

**Ubicación:** Todos los archivos `src/i18n/*.json`

**Problema:** Al comparar las estructuras de `es.json` vs `pt.json` vs `fr.json`, se identifican claves que existen en unos pero no en otros, causando fallbacks innecesarios.

**Verificación Necesaria:**

Comparar todas las claves principales:
- `hero.cta`
- `booking.guest` vs `booking.guests_plural`
- `rooms.viewAll`
- `services.description`
- `reviews.sectionLabel`
- `auth.*` (todas las claves de autenticación)

**Solución (conforme a RULES.md - Robustez):**

Crear un script de validación en `scripts/validate-i18n.js`:
```javascript
import en from '../src/i18n/en.json' assert { type: 'json' };
import es from '../src/i18n/es.json' assert { type: 'json' };
import fr from '../src/i18n/fr.json' assert { type: 'json' };
import pt from '../src/i18n/pt.json' assert { type: 'json' };

function getKeys(obj, prefix = '') {
  let keys = [];
  for (const key in obj) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (typeof obj[key] === 'object' && !Array.isArray(obj[key])) {
      keys = keys.concat(getKeys(obj[key], path));
    } else {
      keys.push(path);
    }
  }
  return keys;
}

const enKeys = getKeys(en);
const locales = { es, fr, pt };

for (const [lang, data] of Object.entries(locales)) {
  const langKeys = getKeys(data);
  const missing = enKeys.filter(k => !langKeys.includes(k));
  const extra = langKeys.filter(k => !enKeys.includes(k));
  
  if (missing.length > 0) {
    console.error(`[${lang}] Faltan ${missing.length} claves:`, missing);
  }
  if (extra.length > 0) {
    console.warn(`[${lang}] Claves extra:`, extra);
  }
}
```

---

## Resumen de Soluciones Priorizadas

| Prioridad | Error | Complejidad | Impacto |
|-----------|-------|-------------|---------|
| **P0** | Regex Navbar | Baja | Crítico - rompe navegación |
| **P0** | Sintaxis InteractiveRooms | Baja | Crítico - rompe renderizado |
| **P1** | Fallback inconsistente | Media | Alto - comportamiento impredecible |
| **P1** | Tipos TypeScript | Baja | Medio - errores de compilación |
| **P2** | Textos hardcodeados | Alta | Medio - mantenibilidad |
| **P2** | Claves faltantes | Media | Bajo - fallbacks funcionan |

---

## Plan de Implementación Recomendado

1. **Fase 1 (Crítico - 30 min):** Corregir errores P0
2. **Fase 2 (Importante - 1h):** Unificar fallbackChain y corregir tipos
3. **Fase 3 (Mejora - 2h):** Migrar textos hardcodeados al sistema i18n
4. **Fase 4 (Validación - 30 min):** Ejecutar script de validación y corregir claves faltantes

Todos los cambios siguen los principios de **RULES.md**: arquitectura SOLID, simplicidad resiliente (KISS/YAGNI) y abstracción equilibrada (DRY).