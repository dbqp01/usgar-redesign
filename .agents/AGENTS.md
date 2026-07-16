# USGAR Hotels — San Pedro, Cusco, Perú

> Web transaccional para un hotel real con ventas activas en Booking y TripAdvisor.
> Referencia visual: https://hotelmonasteriosanpedro.com (pero mejorado en rendimiento y diseño).
> **Manual de marca completo**: ver [BRAND.md](C:/Users/akim/Desktop/migracion a php/.agents/BRAND.md) — fuente canónica de colores, tipografía, contenido y reglas de diseño.

## Hotel

- **Nombre:** USGAR Hotels (un solo hotel activo en San Pedro, Cusco)
- **Tipo:** Hotel boutique con **4 tipos de habitación**
- **Público:** Turistas internacionales que visitan Cusco/Machu Picchu
- **Idiomas:** Inglés (principal) y Español

## Tech Stack

1. **Framework:** Astro v5 (`output: 'static'`) con `@astrojs/node`. Usar `export const prerender = false` en rutas API.
2. **Estilos:** Tailwind CSS v4 para maquetación, animaciones y tema dual (claro/oscuro).
3. **Imágenes:** Usar `<Image />` de `astro:assets` para comprimir fotos profesionales (~30MB → ~300KB WebP) en build time.
4. **Transiciones:** Usar `ClientRouter` de `astro:transitions` (NO ViewTransitions, fue renombrado en v5).
5. **Mapa:** OpenStreetMap con Leaflet (NO Google Maps).
6. **Deploy:** GitHub → **Hostinger** (hosting compartido) → usgarhoteles.com

## Integraciones y Seguridad

1. **Capa Segura (PHP API):** Toda llamada a Channex, QloApps o Mercado Pago va por el backend en PHP (`public/api/`) para no exponer claves ni credenciales en el cliente (Astro).
2. **Modo Mock:** Si no hay claves de API en el archivo `.env`, se deben usar implementaciones mock realistas en `public/api/rooms.php` y los scripts de Channex.
3. **Comisiones:** QloApps es 100% libre de comisiones (0% comisiones). Las únicas comisiones del flujo son las de procesamiento de Mercado Pago.
4. **Flujo de Reserva con Bloqueo Temporal (15 mins):**
   - Al iniciar la reserva, se crea un carrito (`Cart`) en QloApps que bloquea/retiene la habitación físicamente por 15 minutos para evitar sobreventas (Hold).
   - Si no se completa la compra en 15 minutos, la sesión expira y el carrito de QloApps se libera.
   - En caso de que el pago en Mercado Pago sea **rechazado**, el frontend redirige al usuario a la página de fallo y el backend PHP extiende la vida del carrito de QloApps por **15 minutos adicionales** para permitirle al cliente probar otra tarjeta o cargar fondos sin perder su selección.

## Infraestructura de Producción y Arquitectura Multi-hotel

1. **Hosting:** Hostinger (hosting compartido, sin VPS).
2. **Arquitectura Multi-hotel (Multitienda):**
   - El dominio principal es `hotelesusgar.com`. Cada hotel se hospeda en su propio subdominio (ej: `cusco.hotelesusgar.com`, `arequipa.hotelesusgar.com`) con un frontend independiente en Astro.
   - QloApps está centralizado en `cms.hotelesusgar.com` y gestiona múltiples hoteles usando la función de multitienda (cada hotel se mapea a un `id_shop` o `id_hotel` en la base de datos).
3. **Channel Manager:** Channex. Sincroniza disponibilidad en tiempo real con Booking y TripAdvisor. Se activa tras confirmación de orden en QloApps.
4. **Pasarela de Pago:** Mercado Pago (token de sandbox activo).
5. **Fuente de verdad:** QloApps (`cms.hotelesusgar.com`) es la fuente de verdad absoluta para tarifas, disponibilidad y habitaciones. Nada debe estar hardcodeado en el código frontend.

## Diseño y Estética

> **Fuente canónica de diseño → [BRAND.md] (C:/Users/akim/Desktop/migracion a php/.agents/BRAND.md)**

### Tema
- Dual: claro y oscuro con detección automática del sistema operativo
- Toggle manual en navbar
- Persiste en localStorage

### Paleta de Colores (Opción 3 — del logo)
| Familia | Color Principal | Hex | Uso |
|---|---|---|---|
| **Morados** | Morado Oscuro | `#4A3056` | Primary, encabezados, CTAs |
| | Morado Medio | `#9360AC` | Hover, elementos activos |
| **Amarillos** | Amarillo Base | `#EACA1C` | Secondary, CTAs secundarios |
| | Dorado Oscuro | `#B09815` | Precios, estrellas |
| **Verdes** | Verde Pino | `#065952` | Tertiary, botones de reserva |
| | Turquesa | `#0CB2A3` | Enlaces, detalles visuales |

> Ver paleta completa de 15 colores + neutros en BRAND.md §3

### Tipografía
- **Títulos:** A Akhin Tahun (fallback: Playfair Display, serif)
- **Cuerpo:** Montserrat (fallback: Outfit, sans-serif)
- **Logo "HOTELS":** Kravitz Extra Thermal
- Archivos: `original-assets/tipografia/AkhirTahun.woff2` y `KRAVITZ_.woff2`

### Animaciones
- Scroll: fade-in y slide-up suaves al aparecer elementos
- Navegación: View Transitions entre páginas (deslizamiento elegante)
- Hover: micro-animaciones en cards, botones, galería
- Filosofía: **"Ligero y suave"** — nunca brusco ni exagerado
- Accesibilidad: `prefers-reduced-motion` desactiva todo

## Estructura de Páginas

### Home (/)
1. Hero con video/slideshow (Patio → Recepción → Matrimonial → Cusco)
2. Booking Widget flotante superpuesto sobre el hero
3. Marquee de reseñas
4. Sección "Nosotros" (Propósito, Misión, Visión, 8 Valores)
5. Habitaciones con cards (4 tipos) → link a página individual
6. Grid de servicios (18 servicios del hotel)
7. Sección "Explora Cusco" (atracciones cercanas, SEO)
8. FAQ
9. Mapa OpenStreetMap
10. Footer con contacto, redes, mapa

### Habitación Individual (/rooms/[slug])
- Galería inmersiva (máximo **4 fotos** + video tour)
- Descripción, amenidades, precio
- CTA de reserva

### Reservas/Checkout (/book)
- Resumen de selección
- Formulario de datos del huésped
- Integración Stripe o Mercado Pago (mock)

### Explora Cusco (/explore)
- Atracciones cercanas con distancias
- Fotos y descripciones
- SEO optimizado

### Contacto (/contact)
- Formulario, WhatsApp, email, teléfono
- Cómo llegar + traslado aeropuerto

## Habitaciones (4 tipos)

| Habitación | Precio | Camas | Fotos | Videos |
|---|---|---|---|---|
| Matrimonial Superior | $90 USD | King/Queen | 12 (usar 4) | 4 |
| Doble Superior | $90 USD | 2 dobles | 16 (usar 4) | 4 |
| Triple Estándar | $120 USD | 3 individuales | 0 ⚠️ | 0 |
| Familiar Superior | $150 USD | 3 dobles + 1 individual | 0 ⚠️ | 0 |

> **Máximo 4 fotos por habitación.** La habitación "Quadruple Superior" fue descontinuada.

## Servicios Confirmados (18 del hotel + 11 amenidades)

**Hotel:** Wi-Fi gratuito, desayuno buffet (6-9am), cafetería (hasta 22h), oxígeno de cortesía, bebidas calientes de cortesía, lavandería (costo), traslado (costo), tienda souvenirs, no fumadores, custodia maletas (gratis), recepción 24h, personal bilingüe, info turística, tours, limpieza diaria, cambio de moneda.

**Check-in:** 12:00 hrs | **Check-out:** 10:30 hrs

**Habitación:** Baño privado, amenities, agua caliente 24h, secadora, kit infusiones, armario, escritorio+silla, TV cable, teléfono, caja seguridad, calefactor.

## UI Components

- **Navbar:** Transparente sobre hero → sólida con glassmorphism al scroll. Logo izquierda, menú + idioma + dark/light toggle + botón "Reservar". Iconos mínimo 24px, tap target 44×44px.
- **Booking Widget:** Barra flotante sobre hero. Check-in, check-out, huéspedes, tipo habitación, botón buscar.
- **Room Cards:** Foto con overlay, nombre, precio desde X/noche, hover zoom suave. Máximo 4 fotos.
- **Galería:** Fullscreen inmersivo (lightbox + swipe entre fotos) con video tour integrado.
- **WhatsApp:** Botón flotante esquina inferior derecha con pulso sutil.
- **Mapa:** OpenStreetMap/Leaflet con marcador personalizado del hotel.

## Audio/Video

- Videos se reproducen muteados por defecto
- Audio solo se activa si el usuario lo enciende
- NO usar soundtracks como música de fondo
- Video del hero: secuencia Patio → Recepción → Matrimonial → Cusco

## Rendimiento (Prioridad Alta)

- Objetivo: Lighthouse > 90 en todas las categorías
- Astro `<Image />` para compresión automática
- Lazy loading para imágenes y videos
- HTML estático donde sea posible

## SEO (Prioridad Alta)

- Schema.org structured data para Hotel (check-in 12:00, check-out 10:30, 4 habitaciones)
- Meta tags por página, sitemap XML
- URLs descriptivas (`/rooms/doble-superior`)
- Hreflang para ES/EN
- Sección "Explora Cusco" para keywords turísticos

## Assets (Rutas en el proyecto)

- Logos: `original-assets/USGAR LOGO/` (7 variantes PNG)
- Tipografías: `original-assets/tipografia/` (AkhirTahun.woff2, KRAVITZ_.woff2)
- Fotos generales: `original-assets/FOTOS/` (fotos + videos del hotel)
- Fotos por habitación: `original-assets/` (carpetas por nombre de habitación)
- SVGs de servicios: `original-assets/svg's/` (11 iconos)
- Videos: `public/videos/` y `original-assets/VIDEOS youtube/`

## Pipeline de Desarrollo

Antes de trabajar, verifica el estado actual del proyecto:

1. **Scaffolding** ✅ si existe `astro.config.mjs`
2. **UI Components** ✅ si existen componentes en `src/components/`
3. **Integraciones** ✅ si existen `src/services/` y `src/pages/api/`
4. **Testing** ✅ si `npm run build` pasa sin errores
5. **Deploy** ✅ si hay commits en el repo

Siempre verifica qué etapas ya están completas antes de continuar.
**Siempre consulta BRAND.md antes de cambiar colores, tipografía o contenido.**
