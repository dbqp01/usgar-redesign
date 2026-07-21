El archivo está en mayúsculas: `.agents/RULES.md`. Voy a buscarlo junto con los archivos relevantes de i18n y la configuración de Astro.


## Recuento de errores y propuestas de solucion -- usgar-redesign (implementacion pt/fr)

Analisis basado en `.agents/RULES.md` y la revision exhaustiva del codigo fuente.

---

### ERROR 1 (Critico): RoomDetail.astro -- Hardcoding binario es/en en ~40 ubicaciones

**Archivo:** `src/components/RoomDetail.astro` (870 lineas)

El componente usa ternarios `lang==="es" ? "texto ES" : "texto EN"` en aproximadamente 40 lugares. Cuando `lang` es `fr` o `pt`, **siempre cae al branch ingles**. Ejemplos:

| Ubicacion | Codigo actual | Resultado para fr/pt |
|---|---|---|
| Breadcrumbs | `lang==="es"?"Inicio":"Home"` | "Home" (deberia: "Accueil"/"Inicio") |
| Volver | `lang==="es"?"Volver a habitaciones":"Back to rooms"` | "Back to rooms" |
| Descripcion | `lang==="es"?"Sobre la habitación":"About the room"` | "About the room" |
| Amenidades | `lang==="es"?"Servicios y comodidades":"Amenities & Comforts"` | "Amenities & Comforts" |
| Incluido | `lang==="es"?"Incluido sin costo extra":"Included at no extra cost"` | ingles |
| Tarifa | `lang==="es"?"Tarifa base":"Base rate"` | ingles |
| Capacidad | `lang==="es"?"Capacidad máxima":"Max capacity"` | ingles |
| Huespedes | `lang==="es"?"huéspedes":"guests"` | "guests" |
| Distribucion | `lang==="es"?"Distribución":"Bed configuration"` | ingles |
| CTA | `lang==="es"?"Reservar Habitación":"Book this Room"` | ingles |
| Garantia | `lang==="es"?"Garantía de mejor tarifa...":"Best rate guarantee..."` | ingles |
| Video | `lang==="es"?"Iniciar Video Tour":"Start Video Tour"` | ingles |
| Audio | `lang==="es"?"Sin sonido":"No sound"` | ingles |
| Zoom | `lang==="es"?"Expandir":"Zoom"` | "Zoom" |
| Schema | `unitText: lang==="es"?"persona":"person"` | "person" (deberia: "personne"/"pessoa") |

**Regla violada:** RULES.md #4 (DRY) y #1 (i18n correcto en Astro v7).

**Solucion:** Agregar claves faltantes a los 4 archivos JSON (`en.json`, `es.json`, `fr.json`, `pt.json`) bajo una nueva seccion `"room"`:

```json
"room": {
  "about": "About the room",
  "amenities": "Amenities & Comforts",
  "includedFree": "Included at no extra cost",
  "baseRate": "Base rate",
  "maxCapacity": "Max capacity",
  "guests": "guests",
  "bedConfig": "Bed configuration",
  "bookThisRoom": "Book this Room",
  "bestRateGuarantee": "Best rate guarantee when booking directly on our official website.",
  "visualExperience": "Visual Experience",
  "videoTourTitle": "Room Video Tour",
  "videoTourDesc": "Take a virtual walkthrough of the room and appreciate the details before your stay.",
  "startVideoTour": "Start Video Tour",
  "noSound": "No sound",
  "soundActive": "Sound active",
  "zoom": "Zoom",
  "expandPhoto": "Zoom photo",
  "backToRooms": "Back to rooms",
  "home": "Home",
  "unitText": "person"
}
```

Y en RoomDetail.astro reemplazar cada ternario por `t('room.about')`, `t('room.amenities')`, etc.

---

### ERROR 2 (Critico): RoomDetail.astro -- Enlaces de navegacion rotos para fr/pt

**Archivo:** `src/components/RoomDetail.astro`

```javascript
// Breadcrumbs - fr/pt van a "/" (ingles) en vez de "/fr" o "/pt"
href={lang==="es"?"/es":"/"}

// Volver a habitaciones - mismo problema
href={lang==="es"?"/es/#rooms":"/#rooms"}

// CTA de reserva - fr/pt van a "/book" en vez de "/fr/book" o "/pt/book"
href={`${lang==="es"?"/es/book":"/book"}?roomType=${room.slug}`}
```

**Regla violada:** RULES.md #1 (Astro v7 i18n).

**Solucion:** Usar `getRelativeLocaleUrl` de `astro:i18n`, que ya se usa correctamente en Navbar, Footer, RoomCard e InteractiveRoomsSection:

```astro
import { getRelativeLocaleUrl } from "astro:i18n";

// Breadcrumbs
href={getRelativeLocaleUrl(lang, '')}

// Volver
href={`${getRelativeLocaleUrl(lang, '')}#rooms`}

// CTA reserva
href={`${getRelativeLocaleUrl(lang, 'book')}?roomType=${room.slug}`}
```

---

### ERROR 3 (Alto): index.astro -- FAQ Schema.org ignora fr/pt

**Archivo:** `src/pages/index.astro`

```javascript
const faqSchema = {
  mainEntity: faqData.questions.map((faq) => ({
    name: lang==="es" ? faq.question_es : faq.question_en,
    acceptedAnswer: {
      text: lang==="es" ? faq.answer_es : faq.answer_en,
    },
  })),
};
```

El archivo `faq.json` **si tiene** `question_fr`, `question_pt`, `answer_fr`, `answer_pt`, pero el schema siempre usa `_en` para fr/pt. Esto genera datos estructurados incorrectos para SEO en frances y portugues.

**Regla violada:** RULES.md #4 (DRY) y #1 (i18n).

**Solucion:**

```javascript
const faqSchema = {
  mainEntity: faqData.questions.map((faq) => ({
    "@type": "Question",
    name: faq[`question_${lang}`] || faq.question_en,
    acceptedAnswer: {
      "@type": "Answer",
      text: faq[`answer_${lang}`] || faq.answer_en,
    },
  })),
};
```

---

### ERROR 4 (Alto): astro.config.mjs -- Propiedad `fallback` no es API valida de Astro v7

**Archivo:** `astro.config.mjs`

```javascript
i18n: {
  defaultLocale: 'en',
  locales: ['en','es','fr','pt'],
  routing: {
    prefixDefaultLocale: false,
    fallbackType: 'redirect'   // No es una propiedad estandar
  },
  fallback: {                  // No existe en la API i18n de Astro v7
    fr: 'en',
    pt: 'es'
  }
}
```

La propiedad `fallback` de nivel superior y `fallbackType` dentro de `routing` no forman parte de la API de i18n de Astro v7. Se ignoran silenciosamente o generan warnings. El fallback real lo maneja `utils.ts` con `fallbackChain`, pero la config es enganosa.

**Regla violada:** RULES.md #5 (KISS -- no codigo que no hace nada) y #7 (validar versiones vigentes).

**Solucion:**

```javascript
i18n: {
  defaultLocale: 'en',
  locales: ['en', 'es', 'fr', 'pt'],
  routing: {
    prefixDefaultLocale: false,
    redirectToDefaultLocale: true
  }
}
```

Eliminar la propiedad `fallback` inexistente. El fallback de traducciones ya esta correctamente manejado en `src/i18n/utils.ts`.

---

### ERROR 5 (Medio): RoomDetail.astro -- Script JS del video solo maneja es/en

**Archivo:** `src/components/RoomDetail.astro` (seccion `<script>`)

```javascript
const langCode = document.documentElement.lang || "en";
if (isMuted) {
  audioLabel.textContent = langCode === "es" ? "Sin sonido" : "No sound";
} else {
  audioLabel.textContent = langCode === "es" ? "Sonido activo" : "Sound active";
}
```

Para fr/pt muestra texto en ingles.

**Solucion:** Usar un mapa de traducciones en el script:

```javascript
const audioLabels = {
  es: { muted: "Sin sonido", active: "Sonido activo" },
  fr: { muted: "Sans son", active: "Son actif" },
  pt: { muted: "Sem som", active: "Som ativo" },
  en: { muted: "No sound", active: "Sound active" }
};
const labels = audioLabels[langCode] || audioLabels.en;
audioLabel.textContent = isMuted ? labels.muted : labels.active;
```

---

### ERROR 6 (Medio): Violacion sistematica de DRY -- Ternarios cuaternarios hardcodeados en componentes

**Archivos afectados:**
- `src/components/InteractiveRoomsSection.astro` (5+ ternarios cuaternarios)
- `src/components/FAQSection.astro` (3+ ternarios cuaternarios)
- `src/layouts/Layout.astro` (1 ternario cuaternario en skip-link)

Ejemplo de InteractiveRoomsSection:
```javascript
{lang==='es'?'NUESTRO REFUGIO':lang==='fr'?'NOTRE REFUGE':lang==='pt'?'NOSSO REFÚGIO':'OUR SANCTUARY'}
```

Esto funciona, pero cada nuevo idioma requiere modificar multiples componentes. Viola DRY (RULES.md #4) y SRP (RULES.md #2).

**Solucion:** Migrar todos estos textos a los archivos JSON i18n y usar `t('clave')`. Ejemplo:

```json
// en.json
"roomsSection": {
  "heading": "OUR SANCTUARY",
  "subheading": "Explore our spaces designed with elegance and comfort...",
  "viewDetails": "VIEW DETAILS",
  "tapHint": "Tap a room to see more"
}
```

---

### ERROR 7 (Medio): Claves i18n faltantes en fr.json y pt.json

Aunque `fr.json` y `pt.json` tienen buena cobertura de las claves existentes, **no tienen** las claves necesarias para RoomDetail.astro (seccion `"room"` propuesta en Error 1), ni para InteractiveRoomsSection, ni para FAQSection. Actualmente esos textos no pasan por el sistema i18n.

**Solucion:** Crear las secciones faltantes en los 4 archivos JSON como se detalla en Error 1 y Error 6.

---

### ERROR 8 (Bajo): Inconsistencia en cadenas de fallback

**astro.config.mjs:** `fallback: { fr: 'en', pt: 'es' }` (fr -> en, pt -> es)
**utils.ts:** `fallbackChain: { fr: ['en', 'es'], pt: ['es', 'en'] }` (fr -> en -> es, pt -> es -> en)

La config de Astro (aunque inactiva por Error 4) declara un fallback simple, mientras que utils.ts declara cadenas de dos niveles. No causa un bug actual, pero genera confusion.

**Solucion:** Unificar. Eliminar `fallback` de astro.config (Error 4) y documentar en utils.ts que la cadena de fallback canonica es la de `fallbackChain`.

---

### Resumen de prioridades

| # | Severidad | Error | Regla RULES.md |
|---|---|---|---|
| 1 | Critico | ~40 textos hardcodeados es/en en RoomDetail | #4 DRY, #1 i18n |
| 2 | Critico | Enlaces de navegacion rotos para fr/pt en RoomDetail | #1 i18n |
| 3 | Alto | FAQ Schema ignora fr/pt | #4 DRY, #1 i18n |
| 4 | Alto | Config i18n con propiedades inexistentes en Astro v7 | #5 KISS, #7 validar versiones |
| 5 | Medio | Script JS del video solo maneja es/en | #4 DRY |
| 6 | Medio | Ternarios cuaternarios hardcodeados en 3+ componentes | #4 DRY, #2 SRP |
| 7 | Medio | Claves i18n faltantes para nuevos textos | #4 DRY |
| 8 | Bajo | Inconsistencia en cadenas de fallback | #5 KISS |

La raiz del problema es una sola: **RoomDetail.astro se escribio con un patron binario es/en antes de que existieran fr/pt**, y al agregar los nuevos idiomas se crearon las paginas wrapper (`fr/rooms/[slug].astro`, `pt/rooms/[slug].astro`) y los JSON de traduccion, pero **nunca se refactorizo el componente interno** para usar el sistema `t()` de i18n. Los demas componentes (Navbar, Footer, RoomCard, BookingWidget) si se actualizaron correctamente con `getRelativeLocaleUrl` y `t()`.