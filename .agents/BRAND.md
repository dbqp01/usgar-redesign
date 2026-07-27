# USGAR Hotels — Manual de Marca Oficial

> **Fuente canonica** de identidad visual, contenido institucional y reglas de diseno.
> Cualquier agente o humano que trabaje en este proyecto **DEBE** consultar este documento antes de modificar UI, textos o estilos.
> Ultima actualizacion: 12 julio 2026

---

## 1. Identidad del Hotel

| Campo | Valor |
|---|---|
| **Nombre comercial** | USGAR Hotels |
| **Ubicacion** | San Pedro, Cusco, Peru |
| **Tipo** | Hotel boutique |
| **Publico objetivo** | Turistas internacionales (Cusco/Machu Picchu) |
| **Idiomas** | Ingles (principal) y Espanol |
| **Habitaciones** | 4 tipos de habitacion |

---

## 2. Logotipo

### Isotipo
Simbolo en forma de **"U" estilizada** con arcos concentricos.

### Tipografias del Logo
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

### Reglas de Aplicacion
- **Fondo claro (Light Mode)**: Logo color completo o negro, texto oscuro
- **Fondo oscuro (Dark Mode / Hero / Footer)**: Logo morado, texto blanco `#FFFFFF`
- **Nunca** distorsionar proporciones del isotipo
- **Nunca** usar el logo sobre fondos que reduzcan contraste

---

## 3. Paleta de Colores Oficial (Opcion 3)

### Morados (Familia Primaria)
| Nombre | Hex | Token CSS | Uso en UI |
|---|---|---|---|
| Morado Oscuro | `#4A3056` | `--color-primary` | Encabezados, botones primarios, fondos de contraste |
| Morado Medio | `#9360AC` | `--color-primary-light` | Elementos activos, hover en botones primarios |
| Morado Suave | `#A980BD` | `--color-purple-soft` | Bordes, iconos decorativos |
| Morado Pastel | `#D4BFDE` | `--color-purple-pastel` | Fondos secundarios de tarjetas |
| Morado Ultra Claro | `#E9DFEE` | `--color-purple-bg` | Fondo general de paginas o bloques de texto |

### Amarillos / Dorados (Familia Secundaria)
| Nombre | Hex | Token CSS | Uso en UI |
|---|---|---|---|
| Dorado Oscuro | `#B09815` | `--color-secondary-dark` | Destacados de precios, estrellas de calificacion |
| Amarillo Base | `#EACA1C` | `--color-secondary` | Botones CTA secundarios |
| Amarillo Suave | `#F2DF77` | `--color-secondary-light` | Fondos de avisos o promociones |
| Crema Activo | `#F7EAA4` | `--color-cream-active` | Sombreados de inputs |
| Crema Fondo | `#FBF4D2` | `--color-surface-light` | Fondos de cajas de testimonios |

### Verdes / Turquesas (Familia Terciaria)
| Nombre | Hex | Token CSS | Uso en UI |
|---|---|---|---|
| Verde Pino | `#065952` | `--color-tertiary` | Texto de exito, botones de reserva directa |
| Turquesa | `#0CB2A3` | `--color-tertiary-light` | Enlaces dinamicos, detalles visuales |
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
| Surface dark | `#1C1917` | `--color-surface-dark` | Fondo principal modo oscuro (Stone Dark) |
| Card dark | `#1C1C1C` | `--color-surface-card-dark` | Tarjetas en modo oscuro (Neutro oscuro para evitar fatiga visual) |
| Card light | `#FFFFFF` | `--color-surface-card-light` | Tarjetas en modo claro |
| Primary Dark (hover) | `#351C42` | `--color-primary-dark` | Hover intenso sobre botones primarios |
| Tertiary Dark | `#04403B` | `--color-tertiary-dark` | Hover sobre elementos terciarios |

---

## 4. Tipografia Web

### Fuentes

| Rol | Fuente | Fallback | Archivo | Uso |
|---|---|---|---|---|
| **Display / Titulos** | A Akhin Tahun | Playfair Display, serif | `AkhirTahun.woff2` | `h1`–`h4`, titulos destacados, `.brand-title` |
| **Cuerpo / UI** | Montserrat | Outfit, sans-serif | Google Fonts | Parrafos, botones, inputs, navegacion |
| **Logo "HOTELS"** | Kravitz Extra Thermal | serif | `KRAVITZ_.woff2` | Solo en el logotipo |

### Pesos Recomendados
- **Titulos**: 400 (normal), 600 (semibold), 700 (bold)
- **Cuerpo**: 300 (light), 400 (normal), 500 (medium), 600 (semibold), 700 (bold)

### Tokens CSS
```css
--font-display: 'A Akhin Tahun', 'Playfair Display', serif;
--font-body: 'Montserrat', 'Outfit', sans-serif;
```

### Aplicacion HTML
```css
body { font-family: var(--font-body); }
h1, h2, h3, h4 { font-family: var(--font-display); }
```

---

## 5. Contenido Institucional

### Proposito
> Crear experiencias memorables que permitan a cada viajero descubrir la esencia de Cusco, brindando un servicio calido, personalizado y de excelencia que haga de cada estadia un recuerdo inolvidable.

### Mision
> Brindar una experiencia de hospedaje unica en el corazon de Cusco, ofreciendo un servicio personalizado, calido y de alta calidad que combine confort, hospitalidad y la riqueza de la cultura local. Nos comprometemos a superar las expectativas de nuestros huespedes mediante una atencion excepcional, un equipo humano apasionado y practicas de turismo sostenible que generen recuerdos inolvidables.

### Vision
> Ser el hotel referente de Cusco, manteniendo la esencia de nuestra cultura. Aspiramos a ser la primera eleccion de los viajeros que buscan excelencia, calidez y un servicio personalizado, distinguiendonos por nuestra hospitalidad, innovacion y compromiso con la sostenibilidad. Buscamos crear un impacto positivo en nuestros huespedes, colaboradores y comunidad, promoviendo un turismo responsable que valore y preserve el patrimonio cultural y natural del Cusco.

### Valores de Marca (8)

| Valor | Descripcion |
|---|---|
| **Hospitalidad** | Recibimos a cada huesped con calidez, amabilidad y un trato cercano, haciendo que se sienta como en casa desde su llegada. |
| **Excelencia** | Buscamos la mejora continua para ofrecer un servicio de alta calidad que supere las expectativas de nuestros huespedes. |
| **Autenticidad** | Compartimos la riqueza cultural de Cusco a traves de experiencias genuinas que conectan a nuestros visitantes con la identidad local. |
| **Respeto** | Actuamos con integridad y consideracion hacia nuestros huespedes, colaboradores, proveedores, la comunidad y el medio ambiente. |
| **Compromiso** | Trabajamos con responsabilidad, dedicacion y pasion para garantizar una experiencia memorable en cada estancia. |
| **Sostenibilidad** | Promovemos un turismo responsable mediante practicas que contribuyen a la conservacion del entorno natural y el patrimonio cultural. |
| **Trabajo en equipo** | Fomentamos la colaboracion, la comunicacion y el apoyo mutuo para brindar un servicio eficiente y de excelencia. |
| **Innovacion** | Incorporamos nuevas ideas y soluciones que mejoran continuamente la experiencia de nuestros huespedes y la calidad de nuestros servicios. |

---

## 6. Habitaciones (4 tipos)

> **IMPORTANTE**: Solo existen **4 tipos de habitacion**. La habitacion "Quadruple Superior" fue descontinuada y debe eliminarse del codigo.

| # | Nombre Comercial | Precio/Noche | Camas | Max Huespedes | Descripcion (ES) |
|---|---|---|---|---|---|
| 1 | **Habitacion Matrimonial Superior** | $90.00 USD | King-size o Queen | 2 | Refugio romantico con cama king-size o Queen con textiles artesanales y atmosfera acogedora. Ideal para parejas explorando las maravillas de Cusco. |
| 2 | **Habitacion Doble Superior** | $90.00 USD | 2 camas dobles | 2 | Amplia habitacion con dos comodas camas dobles con calida iluminacion ambiental. Perfecta para amigos o colegas viajando juntos. |
| 3 | **Habitacion Triple Estandar** | $120.00 USD | 3 individuales | 3 | Habitacion comoda y practica con tres camas individuales. Excelente valor para grupos pequenos o familias cortas que exploran Cusco. |
| 4 | **Habitacion Familiar Superior** | $150.00 USD | 3 dobles + 1 individual | 7 | Nuestra habitacion mas amplia, disenada para familias o grupo de amigos. Cuenta con 3 camas dobles y una individual, espacio para todos despues de un dia de aventuras. |

### Fotos por Habitacion
- **Maximo 4 fotos** por habitacion
- Orden obligatorio: 1. Habitacion completa → 2. Detalles/decoracion → 3. Camas → 4. Bano/extras
- Usar `<Image />` de `astro:assets` para compresion automatica

### Fotos Disponibles
| Habitacion | Fotos disponibles | Videos | Estado |
|---|---|---|---|
| Doble Superior | 16 (seleccionar 4) | 4 |  Con material |
| Matrimonial | 12 (seleccionar 4) | 4 |  Con material |
| Familiar Superior | 0 | 0 |  Usar fotos genericas |
| Triple Estandar | 0 | 0 |  Usar fotos genericas |

---

## 7. Servicios

### Servicios Generales del Hotel (18)

| # | Servicio | Detalle |
|---|---|---|
| 1 | Desayuno buffet | 6:00 am – 9:00 am |
| 2 | Check-in | 12:00 hrs |
| 3 | Check-out | 10:30 hrs |
| 4 | Conexion Wi-Fi | Gratuita en todo el hotel |
| 5 | Cafeteria | Abierta hasta las 22:00 hrs |
| 6 | Oxigeno de cortesia | Esencial para aclimatacion en Cusco |
| 7 | Estacion de bebidas calientes | Mates tradicionales, cafe — cortesia |
| 8 | Servicio de lavanderia | Con costo adicional |
| 9 | Servicio de traslado | Con costo adicional |
| 10 | Tienda de souvenirs | Local |
| 11 | Habitaciones no fumadores | 100% |
| 12 | Custodia de maletas | Sin costo |
| 13 | Recepcion 24h | Siempre disponible |
| 14 | Personal bilingue | Espanol / Ingles |
| 15 | Informacion turistica | Guias y mapas |
| 16 | Tours | Machu Picchu, Valle Sagrado, etc. |
| 17 | Servicio de limpieza | Diario en habitaciones |
| 18 | Cambio de moneda | En recepcion |

### Amenidades en la Habitacion (11)

| # | Amenidad |
|---|---|
| 1 | Bano privado con ducha |
| 2 | Amenities para el bano |
| 3 | Agua caliente 24 horas |
| 4 | Secadora de cabello |
| 5 | Kit de infusiones de cortesia |
| 6 | Armario |
| 7 | Escritorio con silla |
| 8 | TV con cable |
| 9 | Telefono |
| 10 | Caja de seguridad |
| 11 | Calefactor |

---

## 8. Video del Hero (Pagina Principal)

### Secuencia Obligatoria del Video/Slideshow
1. **Patio del hotel** (ambiente principal)
2. **Recepcion** (calidez en la bienvenida)
3. **Habitacion Matrimonial** (referencia de calidad)
4. **Cusco / Plaza** (contexto local)

### Reglas de Audio/Video
- Videos se reproducen **muteados por defecto**
- Audio solo se activa si el **usuario lo enciende**
- **NO** usar soundtracks como musica de fondo
- Videos de carpeta: `original-assets/FOTOS/` y `public/videos/`

---

## 9. UI Components — Especificaciones

### Navbar
- Transparente sobre hero → solida con **glassmorphism** al scroll
- Logo izquierda: variante segun tema (morado en dark, color en light)
- Menu: links + idioma (EN/ES) + dark/light toggle + boton **"Reservar"**
- Iconos: **tamano minimo 24px**, tap target minimo **44×44px** en moviles

### Botones
- **Primary**: `bg-[#4A3056]` → hover `bg-[#351C42]`, texto blanco
- **Secondary CTA**: `bg-[#EACA1C]` → hover `bg-[#B09815]`
- Padding: `12px 28px`, border-radius: `8px`
- Font: Montserrat, 600 weight
- Transicion: `background-color 0.3s ease`

### Room Cards
- Foto con overlay, nombre, precio "Desde $X/noche"
- Hover: zoom suave en la foto
- **Maximo 4 fotos** por habitacion

### Galeria (Pagina de Habitacion)
- Lightbox fullscreen con swipe entre fotos
- Video tour integrado (muteado por defecto)

### Booking Widget
- Barra flotante superpuesta sobre el hero
- Campos: Check-in, Check-out, Huespedes, Tipo habitacion, Boton buscar

### WhatsApp
- Boton flotante esquina inferior derecha
- Pulso sutil animado

### Mapa
- **OpenStreetMap con Leaflet** (NO Google Maps)
- Marcador personalizado del hotel

### Footer
- Fondo oscuro (`#2B1D33` o `#1C1917`)
- Logo variante morado
- Contacto, enlaces rapidos, redes sociales
- Copyright + "Hecho por MarcaRed"

---

## 10. Tema Dual (Light/Dark)

### Deteccion
1. Verificar preferencia guardada en `localStorage`
2. Si no existe, detectar `prefers-color-scheme` del sistema operativo
3. Toggle manual en navbar (persiste en localStorage)

### Modo Claro
- Fondo pagina: `#FBF4D2` (Crema) o blanco
- Fondo tarjetas: `#FFFFFF`
- Texto principal: `#333333`
- Texto secundario: `#57534E`

### Modo Oscuro
- Fondo pagina: `#1C1917`
- Fondo tarjetas: `#2B1D33` (tinte morado)
- Texto principal: `#FAFAF9`
- Texto secundario: `#D4BFDE` (morado pastel)

---

## 11. Animaciones

### Filosofia
> **"Ligero y suave"** — nunca brusco ni exagerado

### Scroll
- `fade-in` y `slide-up` suaves al aparecer elementos
- IntersectionObserver con threshold 0.1
- Stagger children con delay escalonado (0.1s por hijo)

### Navegacion
- View Transitions entre paginas via `ClientRouter` de Astro

### Hover
- Micro-animaciones en cards, botones, galeria
- `hover:-translate-y-0.5` para efecto de elevacion

### Accesibilidad
- `@media (prefers-reduced-motion: reduce)` desactiva todas las animaciones

---

## 12. SEO

- Schema.org structured data para `Hotel`
- Meta tags descriptivos por pagina
- Sitemap XML via `@astrojs/sitemap`
- URLs descriptivas: `/rooms/matrimonial`, `/rooms/doble-superior`
- **Hreflang** para ES/EN (`x-default` = EN)
- Seccion "Explora Cusco" para keywords turisticos
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

- **Integracion**: Stripe o Mercado Pago (via API Routes de Astro)
- **Modo Mock**: Si no hay claves en `.env`, usar implementaciones mock
- **Seguridad**: Toda llamada va por `src/pages/api/` — nunca exponer claves al cliente
- **Webhook**: Responde `200 OK` rapido y procesa en segundo plano

---

## 14. Estructura de Paginas

### Home (/)
1. Hero con video/slideshow (Patio → Recepcion → Matrimonial → Cusco)
2. Booking Widget flotante superpuesto
3. Marquee de resenas
4. Seccion "Nosotros" (Proposito, Mision, Vision, Valores)
5. Habitaciones con cards (4 tipos) → link a pagina individual
6. Grid de servicios
7. Explora Cusco (preview de 4 atracciones)
8. FAQ
9. Mapa OpenStreetMap
10. Footer

### Habitacion Individual (/rooms/[slug])
- Galeria inmersiva (max 4 fotos + video tour)
- Descripcion, amenidades, precio
- CTA de reserva

### Reservas (/book)
- Resumen de seleccion
- Formulario de datos del huesped
- Integracion de pago

### Explora Cusco (/explore)
- Atracciones cercanas con distancias y tiempos
- SEO optimizado

### Contacto (/contact)
- Formulario de contacto
- Canales directos (WhatsApp, email, telefono)
- Como llegar + traslado

---

## 15. Correcciones Pendientes en el Codigo

> **ADVERTENCIA**: Estos son los cambios que **deben aplicarse** al codigo actual para alinear con este manual.

### Alta Prioridad
- [ ] **Eliminar habitacion Quadruple Superior** de `src/data/rooms.ts`
- [ ] **Corregir check-in/check-out** en schema.org de `index.astro` (12:00 / 10:30)
- [ ] **Actualizar descripciones de habitaciones** segun DOCX (ej: "camas dobles" no "camas individuales" en Doble Superior; "3 dobles + 1 individual" en Familiar)
- [ ] **Agregar servicios faltantes** a `src/data/services.ts` (oxigeno, bebidas calientes, souvenirs, custodia maletas, recepcion 24h, bilingue, info turistica, limpieza, cambio de moneda)
- [ ] **Copiar tipografias** de `original-assets/tipografia/` a `public/fonts/` o `src/assets/fonts/`
- [ ] **Configurar @font-face** con rutas correctas a `AkhirTahun.woff2` y `KRAVITZ_.woff2`
- [ ] **Agregar paleta completa** al `global.css` (faltan: morado suave, morado pastel, morado ultra claro, crema activo, verde menta, verde pastel, verde ultra claro)
- [ ] **Limitar fotos a 4** por habitacion en componente `RoomDetail.astro`
- [ ] **Horario de desayuno**: 6:00 am – 9:00 am (del DOCX oficial)

### Media Prioridad
- [ ] **Mover textos institucionales** de AboutSection.astro a archivos i18n (`en.json` / `es.json`)
- [ ] **Agregar amenidades de habitacion** como lista separada (armario, escritorio, amenities bano, etc.)
- [ ] **Verificar orden del video/slideshow** del hero: Patio → Recepcion → Matrimonial → Cusco
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

  /* === TIPOGRAFIA === */
  --font-display: 'A Akhin Tahun', 'Playfair Display', serif;
  --font-body: 'Montserrat', 'Outfit', sans-serif;
}
```

---

*Este documento es la fuente de verdad. Si hay conflicto entre este manual y cualquier otro archivo, **este manual prevalece**.*
