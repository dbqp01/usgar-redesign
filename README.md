# USGAR Hotels — San Pedro, Cusco

Repositorio oficial del sitio web transaccional de **USGAR Hotels** en Cusco, Perú. Arquitectura híbrida: **Astro v5** (frontend estático) + **PHP** (backend API) + **QloApps** (PMS) + **Channex** (channel manager).

**Sitio en producción:** [https://sanpedro.hotelesusgar.com](https://sanpedro.hotelesusgar.com)

---

## 1. Arquitectura General

```
┌─────────────────────────────────────────────────────────────┐
│                    USUARIO (Browser)                         │
│                                                              │
│  Astro Static HTML  ←──  Hostinger (hosting compartido)      │
│         │                                                    │
│         ▼                                                    │
│  fetch('/api/...')  →  PHP Backend (public/api/)             │
│         │                  │           │          │          │
│         │                  ▼           ▼          ▼          │
│         │             QloApps    Channex API   Mercado Pago  │
│         │          (cms.usgar    (Channel      (Pagos)       │
│         │           hoteles.com)  Manager)                   │
│         │                  │           │                     │
│         │                  ▼           ▼                     │
│         │            MySQL DB    Booking.com                 │
│         │                        TripAdvisor                 │
└─────────────────────────────────────────────────────────────┘
```

### Roles de cada sistema

| Sistema | Rol | URL/Endpoint |
|---|---|---|
| **Astro v5** | Frontend estático (HTML, CSS, JS) | `https://sanpedro.hotelesusgar.com` |
| **PHP Backend** | API proxy segura para integraciones | `public/api/*.php` |
| **QloApps** | PMS — gestión de habitaciones, reservas, inventario | `cms.hotelesusgar.com` |
| **Channex** | Channel Manager — Sincroniza disponibilidad con OTAs | API REST |
| **Mercado Pago** | Pasarela de pagos | API REST |
| **Hostinger** | Hosting compartido (NO VPS) | Panel hPanel |

---

## 2. Estructura de Directorios

```text
├── .agents/                     # Customizaciones de agentes de IA
│   ├── AGENTS.md                # Reglas técnicas y especificaciones
│   ├── BRAND.md                 # Manual de marca canónico (FUENTE DE VERDAD)
│   └── skills/                  # Skills especializados para agentes
│       ├── code_auditor/        # Auditoría lógica del codebase
│       ├── brand_auditor/       # Auditoría visual y de marca
│       ├── security_auditor/    # Auditoría de seguridad
│       ├── channex_integration/ # Integración con Channex
│       ├── hotel_ui_designer/   # Diseño UI premium
│       ├── image_optimizer/     # Optimización de imágenes
│       └── mercadopago_checkout/# Checkout con Mercado Pago
├── .github/
│   └── workflows/
│       └── build.yml            # CI: build automático en cada push
├── public/                      # Archivos estáticos
│   ├── api/                     # Backend PHP
│   │   ├── channex/             # Channex: disponibilidad, reservas, sync
│   │   │   ├── availability.php # GET disponibilidad de habitaciones
│   │   │   ├── booking.php      # POST crear reserva
│   │   │   ├── booking-detail.php # GET detalle de reserva
│   │   │   └── ChannexSync.php  # Clase: push booking a Channex
│   │   ├── qloapp/              # QloApps: lectura y escritura
│   │   │   ├── QloAppReader.php # Leer habitaciones/precios de QloApps DB
│   │   │   └── QloAppWriter.php # Crear carritos y confirmar órdenes
│   │   ├── db.php               # Conexión MySQL + helpers JSON
│   │   ├── rooms.php            # Datos de habitaciones (mock/fallback)
│   │   ├── create-preference.php # Crear preferencia Mercado Pago
│   │   └── webhook-mercado-pago.php # Webhook de confirmación de pago
│   ├── fonts/                   # Tipografías corporativas locales
│   └── videos/                  # Videos del hero y video tours
├── src/                         # Código fuente Astro
│   ├── assets/                  # Imágenes procesadas en build time
│   ├── components/              # Componentes UI (Navbar, Footer, etc.)
│   ├── data/                    # Datos estáticos TypeScript
│   ├── i18n/                    # Localización (en.json, es.json)
│   ├── layouts/                 # Plantillas base con Schema.org
│   ├── pages/                   # Páginas y enrutamiento
│   ├── services/                # Servicios TypeScript (helpers)
│   ├── styles/                  # Estilos globales (global.css)
│   └── utils/                   # Funciones de utilidad
├── astro.config.mjs             # Configuración de Astro
├── router.php                   # Enrutador para PHP dev server local
├── package.json                 # Dependencias Node.js
└── tsconfig.json                # Configuración TypeScript
```

---

## 3. Flujo de Reserva

```
1. Usuario busca disponibilidad
   → Frontend fetch('/api/channex/availability?checkin=...&checkout=...')
   → PHP verifica Channex API (o mock si no hay API key)
   → Retorna habitaciones disponibles con precios

2. Usuario selecciona habitación y llena formulario
   → Frontend POST '/api/channex/booking' con datos del huésped
   → PHP crea carrito en QloApps (vía QloAppWriter)
   → Retorna bookingId (= QloApps cartId)

3. Usuario procede al pago
   → Frontend POST '/api/create-preference' con bookingId
   → PHP crea preferencia en Mercado Pago
   → Redirige al checkout de Mercado Pago

4. Pago completado
   → Mercado Pago envía webhook a '/api/webhook-mercado-pago'
   → PHP verifica pago, confirma orden en QloApps
   → PHP sincroniza con Channex (bloquea inventario en OTAs)
```

---

## 4. Pautas de Desarrollo

### ⚠️ Fuente de Verdad
- **Diseño y marca:** [.agents/BRAND.md](.agents/BRAND.md)
- **Precios y habitaciones:** QloApps en producción, `rooms.php` como fallback mock
- **Reglas técnicas:** [.agents/AGENTS.md](.agents/AGENTS.md)

### 🏨 Habitaciones (4 tipos oficiales)

| Habitación | Precio | Camas | Max Huéspedes |
|---|---|---|---|
| Matrimonial Superior | $90 USD | King/Queen | 2 |
| Doble Superior | $90 USD | 2 dobles | 2 |
| Triple Estándar | $120 USD | 3 individuales | 3 |
| Familiar Superior | $150 USD | 3 dobles + 1 individual | 7 |

> La habitación "Cuádruple Superior" fue **descontinuada**. Si la encuentras en el código, elimínala.

> [!WARNING]
> Si modificas datos de habitaciones en `src/data/rooms.ts`, sincroniza los mismos datos en `public/api/rooms.php`.

### 🌐 Internacionalización (i18n)
- Inglés (principal) y Español
- **NO** usar condicionales inline `{lang === 'es' ? ... : ...}`
- Usar archivos `src/i18n/en.json` y `src/i18n/es.json` con función `t('clave')`
- Rutas en español bajo `src/pages/es/`

### 🎨 Estilos
- **Tailwind CSS v4** con configuración CSS en `src/styles/global.css`
- Tema dual (Light/Dark) con clase `.dark` en `<html>`
- Paleta: morados, amarillos, verdes corporativos (ver BRAND.md §3)

### 🔒 Seguridad
- Toda llamada a APIs externas va por `public/api/` — nunca exponer claves al cliente
- `.env` contiene credenciales y **nunca se sube al repo** (está en `.gitignore`)
- Modo mock automático si faltan claves de API

---

## 5. Entorno de Desarrollo Local

### Requisitos
- Node.js 20+
- PHP 8.1+ (para el backend local)

### Comandos

```bash
# 1. Instalar dependencias
npm install

# 2. Iniciar Astro (puerto 4321)
npm run dev

# 3. Iniciar PHP dev server (puerto 8000) — en otra terminal
npm run dev:php
```

> Astro redirige automáticamente `/api/*` a `localhost:8000` vía proxy en `astro.config.mjs`.

### Compilar para Producción

```bash
npm run build
```

El compilado se genera en `dist/`. Los archivos PHP de `public/api/` se copian a `dist/api/` automáticamente.

---

## 6. Deploy (Hostinger)

El sitio está en **Hostinger** (hosting compartido, NO VPS).

### Estructura en Hostinger
- **Dominio principal:** `hotelesusgar.com` (Subdominio: `sanpedro.hotelesusgar.com` → archivos de Astro `dist/`)
- **Subdominio:** `cms.hotelesusgar.com` → QloApps (PMS, back-office del cliente)

### Proceso de deploy actual
1. Ejecutar `npm run build` localmente
2. Subir `dist/` a Hostinger vía FTP/SFTP o Git deploy
3. Los archivos PHP (`dist/api/`) se ejecutan directamente en Hostinger (PHP nativo)

### CI (GitHub Actions)
El workflow en `.github/workflows/build.yml` verifica automáticamente que `npm run build` compile sin errores en cada push. No hace deploy automático (por ahora).

---

## 7. Visión Futura: PMS Dinámico

> [!IMPORTANT]
> **Nada debe estar hardcodeado.** El cliente necesita poder:
> - Modificar precios de habitaciones
> - Agregar o eliminar habitaciones
> - Editar contenido de páginas
> - Todo desde el back-office de QloApps sin tocar código

**Plan:**
1. QloApps será la **fuente de verdad** para habitaciones, precios y disponibilidad
2. `rooms.php` y `rooms.ts` serán solo **fallback/mock** cuando QloApps no esté disponible
3. El frontend consumirá datos dinámicos de QloApps vía `QloAppReader.php`
4. Variables globales en CSS (`global.css`) y datos centralizados en `src/data/` para consistencia

---

## 8. Guía para Agentes de IA

Si eres un agente interactuando con este repositorio:

1. **Lee primero** [.agents/BRAND.md](.agents/BRAND.md) y [.agents/AGENTS.md](.agents/AGENTS.md)
2. **Usa Context7** para verificar sintaxis de Astro v5, Tailwind CSS v4, o cualquier librería
3. **Usa Sequential Thinking** para razonar paso a paso en tareas complejas
4. **No subas cambios a ciegas:** Si encuentras incoherencias, consulta con el usuario
5. **Mantén los archivos limpios:** Elimina código obsoleto pero preserva comentarios de documentación
6. **Skills de auditoría disponibles:**
   - `code_auditor` — Auditoría lógica completa del codebase
   - `brand_auditor` — Verificación visual contra BRAND.md
   - `security_auditor` — Búsqueda de vulnerabilidades de seguridad

---

## 9. Integraciones Externas

| Servicio | Estado | API Key en .env | Modo sin key |
|---|---|---|---|
| QloApps | ✅ Instalado | `QLOAPP_API_KEY` | Mock (cart IDs ficticios) |
| Channex | ⚠️ Sin API key | `CHANNEX_API_KEY` | Mock (siempre disponible) |
| Mercado Pago | ✅ Sandbox activo | `MERCADO_PAGO_ACCESS_TOKEN` | Mock (redirect local) |
| MySQL (Hostinger) | ✅ Configurado | `DB_HOST/USER/PASS/NAME` | Fallback a JSON file |
