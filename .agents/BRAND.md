# USGAR Hotels — Manual de Marca Oficial

> **Fuente canónica** de identidad visual, contenido institucional y reglas de diseño.
> Cualquier agente o humano que trabaje en este proyecto **DEBE** consultar este documento antes de modificar UI, textos o estilos.
> Última actualización: 12 julio 2026

---

## 1. Identidad del Hotel

| Campo | Valor |
|---|---|
| **Nombre comercial** | USGAR Hotels |
| **Ubicación** | San Pedro, Cusco, Perú |
| **Tipo** | Hotel boutique |
| **Público objetivo** | Turistas internacionales (Cusco/Machu Picchu) |
| **Idiomas** | Inglés (principal) y Español |
| **Habitaciones** | 4 tipos de habitación |

---

## 2. Logotipo

### Isotipo
Símbolo en forma de **"U" estilizada** con arcos concéntricos.

### Tipografías del Logo
| Elemento | Fuente | Archivo |
|---|---|---|
| **"USGAR"** | A Akhin Tahun (bold, caja alta) | `original-assets/tipografia/AkhirTahun.woff2` |
| **"HOTELS"** | Kravitz Extra Thermal (serif extendida) | `original-assets/tipografia/KRAVITZ_.woff2` |

### Variantes Disponibles
| Variante | Archivo fuente | Uso |
|---|---|---|
| Color completo | `original-assets/USGAR LOGO/Logo usgar.png` | Fondos claros, materiales impresos |
| Morado | `original-assets/USGAR LOGO/Logo usgar morado.png` | **Dark mode**, fondos oscuros |
| Amarillo | `original-assets/USGAR LOGO/Logo usgar amarillo.png` | Acentos, materiales especiales |
| Negro | `original-assets/USGAR LOGO/Logo usgar negro.png` | Fondos claros, documentos formales |
| Verde | `original-assets/USGAR LOGO/Logo usgar verde.png` | Materiales eco/sostenibilidad |
| Isotipo color | `original-assets/USGAR LOGO/Logo usgar isotipo.png` | Favicon, espacios reducidos |
| Isotipo negro | `original-assets/USGAR LOGO/Logo usgar isotipo negro.png` | Favicon alternativo |

### Reglas de Aplicación
- **Fondo claro (Light Mode)**: Logo color completo o negro, texto oscuro
- **Fondo oscuro (Dark Mode / Hero / Footer)**: Logo morado, texto blanco `#FFFFFF`
- **Nunca** distorsionar proporciones del isotipo
- **Nunca** usar el logo sobre fondos que reduzcan contraste

---

## 3. Paleta de Colores Oficial (Opción 3)

### Morados (Familia Primaria)
| Nombre | Hex | Token CSS | Uso en UI |
|---|---|---|---|
| Morado Oscuro | `#4A3056` | `--color-primary` | Encabezados, botones primarios, fondos de contraste |
| Morado Medio | `#9360AC` | `--color-primary-light` | Elementos activos, hover en botones primarios |
| Morado Suave | `#A980BD` | `--color-purple-soft` | Bordes, iconos decorativos |
| Morado Pastel | `#D4BFDE` | `--color-purple-pastel` | Fondos secundarios de tarjetas |
| Morado Ultra Claro | `#E9DFEE` | `--color-purple-bg` | Fondo general de páginas o bloques de texto |

### Amarillos / Dorados (Familia Secundaria)
| Nombre | Hex | Token CSS | Uso en UI |
|---|---|---|---|
| Dorado Oscuro | `#B09815` | `--color-secondary-dark` | Destacados de precios, estrellas de calificación |
| Amarillo Base | `#EACA1C` | `--color-secondary` | Botones CTA secundarios |
| Amarillo Suave | `#F2DF77` | `--color-secondary-light` | Fondos de avisos o promociones |
| Crema Activo | `#F7EAA4` | `--color-cream-active` | Sombreados de inputs |
| Crema Fondo | `#FBF4D2` | `--color-surface-light` | Fondos de cajas de testimonios |

### Verdes / Turquesas (Familia Terciaria)
| Nombre | Hex | Token CSS | Uso en UI |
|---|---|---|---|
| Verde Pino | `#065952` | `--color-tertiary` | Texto de éxito, botones de reserva directa |
| Turquesa | `#0CB2A3` | `--color-tertiary-light` | Enlaces dinámicos, detalles visuales |
| Verde Menta | `#6DD1C8` | `--color-mint` | Iconos de servicios |
| Verde Pastel | `#9EE0DA` | `--color-green-pastel` | Bordes de tarjetas de habitaciones |
| Verde Ultra Claro | `#CEF0ED` | `--color-green-bg` | Fondos de tarjetas de servicios |

### Neutros y Superficies
| Nombre | Hex | Token CSS | Uso |
|---|---|---|---|
| Texto primario (light) | `#333333` | `--color-text-primary-light` | Texto general modo claro |
| Texto primario (dark) | `#FAFAF9` | `--color-text-primary-dark` | Texto general modo oscuro |
| Texto secundario (light) | `#57534E` | `--color-text-secondary-light` | Subtextos modo claro |
| Texto secundario (dark) | `#D4BFDE` | `--color-text-secondary-dark` | Subtextos modo oscuro (morado pastel) |
| Surface dark | `#1C1917` | `--color-surface-dark` | Fondo principal modo oscuro |
| Card dark | `#2B1D33` | `--color-surface-card-dark` | Tarjetas en modo oscuro (tinte morado) |
| Card light | `#FFFFFF` | `--color-surface-card-light` | Tarjetas en modo claro |
| Primary Dark (hover) | `#351C42` | `--color-primary-dark` | Hover intenso sobre botones primarios |
| Tertiary Dark | `#04403B` | `--color-tertiary-dark` | Hover sobre elementos terciarios |

---

## 4. Tipografía Web

### Fuentes

| Rol | Fuente | Fallback | Archivo | Uso |
|---|---|---|---|---|
| **Display / Títulos** | A Akhin Tahun | Playfair Display, serif | `AkhirTahun.woff2` | `h1`–`h4`, títulos destacados, `.brand-title` |
| **Cuerpo / UI** | Montserrat | Outfit, sans-serif | Google Fonts | Párrafos, botones, inputs, navegación |
| **Logo "HOTELS"** | Kravitz Extra Thermal | serif | `KRAVITZ_.woff2` | Solo en el logotipo |

### Pesos Recomendados
- **Títulos**: 400 (normal), 600 (semibold), 700 (bold)
- **Cuerpo**: 300 (light), 400 (normal), 500 (medium), 600 (semibold), 700 (bold)

### Tokens CSS
```css
--font-display: 'A Akhin Tahun', 'Playfair Display', serif;
--font-body: 'Montserrat', 'Outfit', sans-serif;
```

### Aplicación HTML
```css
body { font-family: var(--font-body); }
h1, h2, h3, h4 { font-family: var(--font-display); }
```

---

## 5. Contenido Institucional

### Propósito
> Crear experiencias memorables que permitan a cada viajero descubrir la esencia de Cusco, brindando un servicio cálido, personalizado y de excelencia que haga de cada estadía un recuerdo inolvidable.

### Misión
> Brindar una experiencia de hospedaje única en el corazón de Cusco, ofreciendo un servicio personalizado, cálido y de alta calidad que combine confort, hospitalidad y la riqueza de la cultura local. Nos comprometemos a superar las expectativas de nuestros huéspedes mediante una atención excepcional, un equipo humano apasionado y prácticas de turismo sostenible que generen recuerdos inolvidables.

### Visión
> Ser el hotel referente de Cusco, manteniendo la esencia de nuestra cultura. Aspiramos a ser la primera elección de los viajeros que buscan excelencia, calidez y un servicio personalizado, distinguiéndonos por nuestra hospitalidad, innovación y compromiso con la sostenibilidad. Buscamos crear un impacto positivo en nuestros huéspedes, colaboradores y comunidad, promoviendo un turismo responsable que valore y preserve el patrimonio cultural y natural del Cusco.

### Valores de Marca (8)

| Valor | Descripción |
|---|---|
| **Hospitalidad** | Recibimos a cada huésped con calidez, amabilidad y un trato cercano, haciendo que se sienta como en casa desde su llegada. |
| **Excelencia** | Buscamos la mejora continua para ofrecer un servicio de alta calidad que supere las expectativas de nuestros huéspedes. |
| **Autenticidad** | Compartimos la riqueza cultural de Cusco a través de experiencias genuinas que conectan a nuestros visitantes con la identidad local. |
| **Respeto** | Actuamos con integridad y consideración hacia nuestros huéspedes, colaboradores, proveedores, la comunidad y el medio ambiente. |
| **Compromiso** | Trabajamos con responsabilidad, dedicación y pasión para garantizar una experiencia memorable en cada estancia. |
| **Sostenibilidad** | Promovemos un turismo responsable mediante prácticas que contribuyen a la conservación del entorno natural y el patrimonio cultural. |
| **Trabajo en equipo** | Fomentamos la colaboración, la comunicación y el apoyo mutuo para brindar un servicio eficiente y de excelencia. |
| **Innovación** | Incorporamos nuevas ideas y soluciones que mejoran continuamente la experiencia de nuestros huéspedes y la calidad de nuestros servicios. |

---

## 6. Habitaciones (4 tipos)

> **IMPORTANTE**: Solo existen **4 tipos de habitación**. La habitación "Quadruple Superior" fue descontinuada y debe eliminarse del código.

| # | Nombre Comercial | Precio/Noche | Camas | Max Huéspedes | Descripción (ES) |
|---|---|---|---|---|---|
| 1 | **Habitación Matrimonial Superior** | $90.00 USD | King-size o Queen | 2 | Refugio romántico con cama king-size o Queen con textiles artesanales y atmósfera acogedora. Ideal para parejas explorando las maravillas de Cusco. |
| 2 | **Habitación Doble Superior** | $90.00 USD | 2 camas dobles | 2 | Amplia habitación con dos cómodas camas dobles con cálida iluminación ambiental. Perfecta para amigos o colegas viajando juntos. |
| 3 | **Habitación Triple Estándar** | $120.00 USD | 3 individuales | 3 | Habitación cómoda y práctica con tres camas individuales. Excelente valor para grupos pequeños o familias cortas que exploran Cusco. |
| 4 | **Habitación Familiar Superior** | $150.00 USD | 3 dobles + 1 individual | 7 | Nuestra habitación más amplia, diseñada para familias o grupo de amigos. Cuenta con 3 camas dobles y una individual, espacio para todos después de un día de aventuras. |

### Fotos por Habitación
- **Máximo 4 fotos** por habitación
- Orden obligatorio: 1. Habitación completa → 2. Detalles/decoración → 3. Camas → 4. Baño/extras
- Usar `<Image />` de `astro:assets` para compresión automática

### Fotos Disponibles
| Habitación | Fotos disponibles | Videos | Estado |
|---|---|---|---|
| Doble Superior | 16 (seleccionar 4) | 4 |  Con material |
| Matrimonial | 12 (seleccionar 4) | 4 |  Con material |
| Familiar Superior | 0 | 0 | ️ Usar fotos genéricas |
| Triple Estándar | 0 | 0 | ️ Usar fotos genéricas |

---

## 7. Servicios

### Servicios Generales del Hotel (18)

| # | Servicio | Detalle |
|---|---|---|
| 1 | Desayuno buffet | 6:00 am – 9:00 am |
| 2 | Check-in | 12:00 hrs |
| 3 | Check-out | 10:30 hrs |
| 4 | Conexión Wi-Fi | Gratuita en todo el hotel |
| 5 | Cafetería | Abierta hasta las 22:00 hrs |
| 6 | Oxígeno de cortesía | Esencial para aclimatación en Cusco |
| 7 | Estación de bebidas calientes | Mates tradicionales, café — cortesía |
| 8 | Servicio de lavandería | Con costo adicional |
| 9 | Servicio de traslado | Con costo adicional |
| 10 | Tienda de souvenirs | Local |
| 11 | Habitaciones no fumadores | 100% |
| 12 | Custodia de maletas | Sin costo |
| 13 | Recepción 24h | Siempre disponible |
| 14 | Personal bilingüe | Español / Inglés |
| 15 | Información turística | Guías y mapas |
| 16 | Tours | Machu Picchu, Valle Sagrado, etc. |
| 17 | Servicio de limpieza | Diario en habitaciones |
| 18 | Cambio de moneda | En recepción |

### Amenidades en la Habitación (11)

| # | Amenidad |
|---|---|
| 1 | Baño privado con ducha |
| 2 | Amenities para el baño |
| 3 | Agua caliente 24 horas |
| 4 | Secadora de cabello |
| 5 | Kit de infusiones de cortesía |
| 6 | Armario |
| 7 | Escritorio con silla |
| 8 | TV con cable |
| 9 | Teléfono |
| 10 | Caja de seguridad |
| 11 | Calefactor |

---

## 8. Video del Hero (Página Principal)

### Secuencia Obligatoria del Video/Slideshow
1. **Patio del hotel** (ambiente principal)
2. **Recepción** (calidez en la bienvenida)
3. **Habitación Matrimonial** (referencia de calidad)
4. **Cusco / Plaza** (contexto local)

### Reglas de Audio/Video
- Videos se reproducen **muteados por defecto**
- Audio solo se activa si el **usuario lo enciende**
- **NO** usar soundtracks como música de fondo
- Videos de carpeta: `original-assets/FOTOS/` y `public/videos/`

---

## 9. UI Components — Especificaciones

### Navbar
- Transparente sobre hero → sólida con **glassmorphism** al scroll
- Logo izquierda: variante según tema (morado en dark, color en light)
- Menú: links + idioma (EN/ES) + dark/light toggle + botón **"Reservar"**
- Iconos: **tamaño mínimo 24px**, tap target mínimo **44×44px** en móviles

### Botones
- **Primary**: `bg-[#4A3056]` → hover `bg-[#351C42]`, texto blanco
- **Secondary CTA**: `bg-[#EACA1C]` → hover `bg-[#B09815]`
- Padding: `12px 28px`, border-radius: `8px`
- Font: Montserrat, 600 weight
- Transición: `background-color 0.3s ease`

### Room Cards
- Foto con overlay, nombre, precio "Desde $X/noche"
- Hover: zoom suave en la foto
- **Máximo 4 fotos** por habitación

### Galería (Página de Habitación)
- Lightbox fullscreen con swipe entre fotos
- Video tour integrado (muteado por defecto)

### Booking Widget
- Barra flotante superpuesta sobre el hero
- Campos: Check-in, Check-out, Huéspedes, Tipo habitación, Botón buscar

### WhatsApp
- Botón flotante esquina inferior derecha
- Pulso sutil animado

### Mapa
- **OpenStreetMap con Leaflet** (NO Google Maps)
- Marcador personalizado del hotel

### Footer
- Fondo oscuro (`#2B1D33` o `#1C1917`)
- Logo variante morado
- Contacto, enlaces rápidos, redes sociales
- Copyright + "Hecho por MarcaRed"

---

## 10. Tema Dual (Light/Dark)

### Detección
1. Verificar preferencia guardada en `localStorage`
2. Si no existe, detectar `prefers-color-scheme` del sistema operativo
3. Toggle manual en navbar (persiste en localStorage)

### Modo Claro
- Fondo página: `#FBF4D2` (Crema) o blanco
- Fondo tarjetas: `#FFFFFF`
- Texto principal: `#333333`
- Texto secundario: `#57534E`

### Modo Oscuro
- Fondo página: `#1C1917`
- Fondo tarjetas: `#2B1D33` (tinte morado)
- Texto principal: `#FAFAF9`
- Texto secundario: `#D4BFDE` (morado pastel)

---

## 11. Animaciones

### Filosofía
> **"Ligero y suave"** — nunca brusco ni exagerado

### Scroll
- `fade-in` y `slide-up` suaves al aparecer elementos
- IntersectionObserver con threshold 0.1
- Stagger children con delay escalonado (0.1s por hijo)

### Navegación
- View Transitions entre páginas via `ClientRouter` de Astro

### Hover
- Micro-animaciones en cards, botones, galería
- `hover:-translate-y-0.5` para efecto de elevación

### Accesibilidad
- `@media (prefers-reduced-motion: reduce)` desactiva todas las animaciones

---

## 12. SEO

- Schema.org structured data para `Hotel`
- Meta tags descriptivos por página
- Sitemap XML vía `@astrojs/sitemap`
- URLs descriptivas: `/rooms/matrimonial`, `/rooms/doble-superior`
- **Hreflang** para ES/EN (`x-default` = EN)
- Sección "Explora Cusco" para keywords turísticos
- Open Graph + Twitter Cards

### Schema.org — Valores Correctos
```json
{
  "checkinTime": "12:00",
  "checkoutTime": "10:30",
  "numberOfRooms": 4,
  "priceRange": "$$"
}
```

---

## 13. Pagos

- **Integración**: Stripe o Mercado Pago (vía API Routes de Astro)
- **Modo Mock**: Si no hay claves en `.env`, usar implementaciones mock
- **Seguridad**: Toda llamada va por `src/pages/api/` — nunca exponer claves al cliente
- **Webhook**: Responde `200 OK` rápido y procesa en segundo plano

---

## 14. Estructura de Páginas

### Home (/)
1. Hero con video/slideshow (Patio → Recepción → Matrimonial → Cusco)
2. Booking Widget flotante superpuesto
3. Marquee de reseñas
4. Sección "Nosotros" (Propósito, Misión, Visión, Valores)
5. Habitaciones con cards (4 tipos) → link a página individual
6. Grid de servicios
7. Explora Cusco (preview de 4 atracciones)
8. FAQ
9. Mapa OpenStreetMap
10. Footer

### Habitación Individual (/rooms/[slug])
- Galería inmersiva (máx 4 fotos + video tour)
- Descripción, amenidades, precio
- CTA de reserva

### Reservas (/book)
- Resumen de selección
- Formulario de datos del huésped
- Integración de pago

### Explora Cusco (/explore)
- Atracciones cercanas con distancias y tiempos
- SEO optimizado

### Contacto (/contact)
- Formulario de contacto
- Canales directos (WhatsApp, email, teléfono)
- Cómo llegar + traslado

---

## 15. Correcciones Pendientes en el Código

> **ADVERTENCIA**: Estos son los cambios que **deben aplicarse** al código actual para alinear con este manual.

### Alta Prioridad
- [ ] **Eliminar habitación Quadruple Superior** de `src/data/rooms.ts`
- [ ] **Corregir check-in/check-out** en schema.org de `index.astro` (12:00 / 10:30)
- [ ] **Actualizar descripciones de habitaciones** según DOCX (ej: "camas dobles" no "camas individuales" en Doble Superior; "3 dobles + 1 individual" en Familiar)
- [ ] **Agregar servicios faltantes** a `src/data/services.ts` (oxígeno, bebidas calientes, souvenirs, custodia maletas, recepción 24h, bilingüe, info turística, limpieza, cambio de moneda)
- [ ] **Copiar tipografías** de `original-assets/tipografia/` a `public/fonts/` o `src/assets/fonts/`
- [ ] **Configurar @font-face** con rutas correctas a `AkhirTahun.woff2` y `KRAVITZ_.woff2`
- [ ] **Agregar paleta completa** al `global.css` (faltan: morado suave, morado pastel, morado ultra claro, crema activo, verde menta, verde pastel, verde ultra claro)
- [ ] **Limitar fotos a 4** por habitación en componente `RoomDetail.astro`
- [ ] **Horario de desayuno**: 6:00 am – 9:00 am (del DOCX oficial)

### Media Prioridad
- [ ] **Mover textos institucionales** de AboutSection.astro a archivos i18n (`en.json` / `es.json`)
- [ ] **Agregar amenidades de habitación** como lista separada (armario, escritorio, amenities baño, etc.)
- [ ] **Verificar orden del video/slideshow** del hero: Patio → Recepción → Matrimonial → Cusco
- [ ] **Ajustar camas** en rooms.ts: Doble Superior = "2 camas dobles" (no "2 single beds"), Familiar = "3 dobles + 1 individual"

### Baja Prioridad
- [ ] Integrar SVGs personalizados de `original-assets/svg's/` en lugar de emojis para servicios
- [ ] Optimizar variantes de logo (convertir PNG → WebP para web)
- [ ] Agregar logo isotipo como favicon alternativo

---

## 16. Tokens CSS / Tailwind v4 — Referencia Completa

```css
@theme {
  /* === MORADOS (Primaria) === */
  --color-primary: #4A3056;
  --color-primary-light: #9360AC;
  --color-primary-dark: #351C42;
  --color-purple-soft: #A980BD;
  --color-purple-pastel: #D4BFDE;
  --color-purple-bg: #E9DFEE;

  /* === AMARILLOS (Secundaria) === */
  --color-secondary: #EACA1C;
  --color-secondary-light: #F2DF77;
  --color-secondary-dark: #B09815;
  --color-cream-active: #F7EAA4;

  /* === VERDES (Terciaria) === */
  --color-tertiary: #065952;
  --color-tertiary-light: #0CB2A3;
  --color-tertiary-dark: #04403B;
  --color-mint: #6DD1C8;
  --color-green-pastel: #9EE0DA;
  --color-green-bg: #CEF0ED;

  /* === SUPERFICIES === */
  --color-surface-light: #FBF4D2;
  --color-surface-dark: #1C1917;
  --color-surface-card-light: #FFFFFF;
  --color-surface-card-dark: #2B1D33;

  /* === TEXTO === */
  --color-text-primary-light: #333333;
  --color-text-primary-dark: #FAFAF9;
  --color-text-secondary-light: #57534E;
  --color-text-secondary-dark: #D4BFDE;

  /* === TIPOGRAFÍA === */
  --font-display: 'A Akhin Tahun', 'Playfair Display', serif;
  --font-body: 'Montserrat', 'Outfit', sans-serif;
}
```

---

*Este documento es la fuente de verdad. Si hay conflicto entre este manual y cualquier otro archivo, **este manual prevalece**.*
