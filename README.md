# USGAR Hotels — San Pedro, Cusco

Repositorio oficial del sitio web transaccional de **USGAR Hotels** en Cusco, Perú. Arquitectura híbrida: **Astro v7** (frontend estático) + **PHP 8** (Front Controller API) + **QloApps** (PMS) + **Channex** (channel manager).

**Sitio en producción:** [https://usgarhoteles.com](https://usgarhoteles.com)
**PMS / Back-Office:** [https://cms.usgarhoteles.com](https://cms.usgarhoteles.com)

---

## 1. Arquitectura General

```
┌──────────────────────────────────────────────────────────────────┐
│                      USUARIO (Browser / App)                     │
│                                                                  │
│  Astro Static HTML  ←──  Hostinger (hosting compartido)          │
│         │                                                        │
│         ▼                                                        │
│  fetch('/api/...')  →  PHP Front Controller (public/index.php)   │
│         │                │            │            │             │
│         │                ▼            ▼            ▼             │
│         │           QloApps API    Channex API   Mercado Pago    │
│         │          (cms.usgar      (Channel      (Pagos)         │
│         │           hoteles.com)   Manager)                      │
│         │                │            │                          │
│         │                ▼            ▼                          │
│         │           MySQL DB     Booking.com                     │
│         │                        TripAdvisor / Airbnb            │
└──────────────────────────────────────────────────────────────────┘
```

### Roles de cada sistema

| Sistema | Rol | URL/Endpoint |
|---|---|---|
| **Astro v7** | Frontend estático (HTML, CSS, JS) | `https://usgarhoteles.com` |
| **PHP API** | Front Controller proxy seguro para integraciones | `public/index.php` → `src/Controllers/` |
| **QloApps** | PMS — gestión de habitaciones, reservas, inventario local | `https://cms.usgarhoteles.com` |
| **Channex** | Channel Manager — Sincronización en tiempo real con OTAs | API REST `api.channex.io` |
| **Mercado Pago** | Pasarela de pagos | API REST & Webhooks IPN |
| **Hostinger** | Hosting compartido (PHP nativo + MySQL) | Panel hPanel |

---

## 2. Estructura de Directorios

```text
├── .agents/                     # Customizaciones y reglas de agentes de IA
│   ├── AGENTS.md                # Reglas técnicas y especificaciones de arquitectura
│   ├── BRAND.md                 # Manual de marca canónico (FUENTE DE VERDAD)
│   ├── RULES.md                 # Reglas globales de codificación y seguridad
│   └── skills/                  # Skills especializados (auditoría, integraciones)
├── .github/
│   └── workflows/
│       └── build.yml            # CI: build automático en cada push
├── public/                      # Archivos estáticos
│   ├── index.php                # Front Controller PHP (Punto de entrada único API)
│   ├── .htaccess                # Redirecciones Apache y reglas de seguridad
│   ├── favicon.svg              # Favicon
│   └── fonts/ & videos/         # Fuentes locales y recursos multimedia
├── scripts/                     # Scripts de administración, pruebas y utilidades
│   ├── test_endpoints.php       # Script de verificación de endpoints (seguro)
│   ├── dev.js                   # Script para ejecutar entorno local completo
│   ├── seed-database.php        # Inicialización de tablas y datos
│   └── audit-performance.js     # Auditoría de rendimiento
├── src/                         # Código fuente Astro & PHP Core
│   ├── Controllers/             # Controladores API PHP (Booking, Room, Webhook, Cron)
│   ├── Core/                    # Framework PHP Core (Router, Middleware, Config, Logger)
│   ├── Models/                  # Modelos de datos SQL (ProvisionalBooking)
│   ├── Services/                # Servicios PHP (QloAppService, ChannexService, MercadoPago)
│   ├── components/              # Componentes Astro UI (Navbar, RoomCard, BookingWidget)
│   ├── content/                 # Colecciones de contenido (rooms.json, services.json)
│   ├── i18n/                    # Localización i18n (en.json, es.json)
│   ├── pages/                   # Páginas y enrutamiento Astro
│   └── styles/                  # Estilos globales Tailwind CSS v4 (global.css)
├── astro.config.mjs             # Configuración de Astro
├── package.json                 # Dependencias Node.js (Astro v7, Tailwind CSS v4)
└── tsconfig.json                # Configuración TypeScript
```

---

## 3. Endpoints de la API REST (`public/index.php`)

| Método | Endpoint | Descripción |
|---|---|---|
| `GET` | `/api/health` | Estado de salud de la API y conectividad de servicios |
| `GET` | `/api/rooms` | Consulta de disponibilidad e inventario en tiempo real |
| `POST` | `/api/booking` | Crear reserva temporal (Hold de 15 min) y preferencia Mercado Pago |
| `POST` | `/api/extend-hold` | Extender el bloqueo temporal de habitación por 15 min adicionales |
| `GET` | `/api/booking-status` | Consultar estado de una reserva (Requiere `access_token`) |
| `POST` | `/api/webhook` | Webhook de confirmación de pago IPN de Mercado Pago |
| `POST` | `/api/webhook/channex` | Webhook entrante de Channex (sincronización de reservas OTA) |
| `POST` | `/api/cron/cleanup` | Limpieza de bloqueos temporales expirados (Cron) |

---

## 4. Pautas de Desarrollo

### ⚠️ Fuente de Verdad
- **Diseño y marca:** [.agents/BRAND.md](.agents/BRAND.md)
- **Precios y habitaciones:** QloApps (`cms.usgarhoteles.com`) y Channex (`api.channex.io`)
- **Reglas técnicas y seguridad:** [.agents/RULES.md](.agents/RULES.md)

### 🏨 Habitaciones Oficiales y Capacidades Dinámicas

| Habitación | Slug Local | Ocupación Channex |
|---|---|---|
| Habitación Doble Superior | `doble-superior` | Hasta 4 personas |
| Habitación Familiar Superior | `familiar-superior` | Hasta 7 personas |
| Habitación Matrimonial Superior | `matrimonial` | Hasta 3 personas |
| Habitación Triple Estándar | `triple-standar` | Hasta 3 personas |

> [!NOTE]
> Todos los precios y capacidades máximas (`max_guests`) se obtienen **dinámicamente** desde el backend; no existen valores hardcodeados en el código.

---

## 5. Entorno de Desarrollo y Variables de Entorno (.env)

### Variables Mandatorias para Producción
Asegúrese de configurar las siguientes variables de entorno en su servidor de producción (`.env`):

```ini
# Seguridad y Webhooks
CHANNEX_WEBHOOK_SECRET=token_secreto_para_validar_webhooks_de_channex
CRON_SECRET=token_secreto_para_firma_hmac_y_cron_cleanup

# Integraciones Channex
CHANNEX_API_KEY=tu_api_key_channex
CHANNEX_PROPERTY_ID=tu_property_id_channex
CHANNEX_ROOM_MAP={"MATRIMONIAL":1,"DOBLE":2,"TRIPLE":3,"FAMILIAR":4}
```

> [!IMPORTANT]
> **Seguridad de Tokens:** Si `CHANNEX_WEBHOOK_SECRET` o `CRON_SECRET` no están configurados en producción, los controladores rechazarán las peticiones con estado `401 Unauthorized` / `500 Internal Error` para proteger la infraestructura y los datos de clientes (PII).

### Mantenimiento Programado (Cron Cleanup)
La tarea de limpieza de carritos expirados (`/api/cron/cleanup`) únicamente admite solicitudes HTTP `POST` autenticadas o ejecuciones locales por línea de comandos (CLI):
```bash
php public/index.php /api/cron/cleanup
```

### Comandos de Desarrollo y Pruebas

```bash
# 1. Instalar dependencias
npm install

# 2. Iniciar entorno completo (Astro en 4321 + PHP API en 8000)
npm run dev:all

# O ejecutar servidores individualmente:
npm run dev      # Astro dev server (http://localhost:4321)
npm run dev:php  # PHP Front Controller (http://localhost:8000)

# 3. Comandos de Pruebas y Auditoría Híbrida:
npm run check           # Verificación de tipos TypeScript y componentes Astro v7
npm run test:php        # Ejecutar suite de pruebas unitarias e integración de API PHP
npm run test            # Ejecutar verificación de tipos Astro + Pruebas PHP
npm run audit:security  # Escáner de auditoría de seguridad y cabeceras HTTP
```

---

## 6. Deploy (Hostinger)

1. Compilar el sitio estático Astro: `npm run build`
2. Subir el directorio `dist/` a la raíz de Hostinger (`public_html`).
3. El archivo `public/index.php` actuará como Front Controller en PHP nativo para atender la API REST en `/api/*`.

