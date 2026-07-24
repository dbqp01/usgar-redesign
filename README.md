# USGAR Hotels — San Pedro, Cusco

Repositorio oficial del sitio web transaccional de **USGAR Hotels** en Cusco, Perú. Arquitectura híbrida: **Astro v7** (frontend estático) + **PHP 8** (Front Controller API ADR con DI Container PSR-11) + **QloApps** (PMS) + **Channex** (channel manager).

**Sitio en producción:** [https://usgarhoteles.com](https://usgarhoteles.com)  
**PMS / Back-Office:** [https://cms.usgarhoteles.com](https://cms.usgarhoteles.com)

---

## 1. Arquitectura General

```text
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
| **PHP API** | Front Controller proxy seguro (ADR + DI Container PSR-11) | `public/index.php` → `src/Features/` |
| **QloApps** | PMS — gestión de habitaciones, reservas, inventario local | `https://cms.usgarhoteles.com` |
| **Channex** | Channel Manager — Sincronización en tiempo real con OTAs | API REST `api.channex.io` |
| **Mercado Pago** | Pasarela de pagos | API REST & Webhooks IPN |
| **Hostinger** | Hosting compartido (PHP nativo + MySQL `srv909.hstgr.io`) | Panel hPanel |

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
│   ├── .htaccess                # Redirecciones Apache, reglas CSP y cabeceras de seguridad
│   ├── favicon.svg              # Favicon
│   └── fonts/ & videos/         # Fuentes locales y recursos multimedia
├── scripts/                     # Scripts de administración, pruebas y migraciones
│   ├── create_processed_payments_table.sql # DDL para tabla de idempotencia en Hostinger MySQL
│   ├── test_endpoints.php       # Script de verificación de endpoints
│   ├── dev.js                   # Script para ejecutar entorno local completo
│   └── seed-database.php        # Inicialización de tablas y datos
├── src/                         # Código fuente Astro & PHP Core (Vertical Slicing / ADR)
│   ├── Core/                    # Framework PHP Core (Container PSR-11, Router, Middleware, Events)
│   │   └── Events/              # EventDispatcher, EventInterface, ListenerInterface
│   ├── Features/                # Dominios de Negocio (Vertical Slicing / Action-Domain-Responder)
│   │   ├── Auth/                # Autenticación de usuarios y panel de huéspedes
│   │   ├── Booking/             # Lógica de reservas, bloqueos, repositorio y eventos
│   │   │   ├── Actions/         # CreateBookingAction, ExtendHoldAction, GetBookingStatusAction
│   │   │   └── Domain/          # Events, Listeners y ProvisionalBookingRepository
│   │   ├── Cron/                # Tareas programadas (CleanExpiredCartsAction)
│   │   ├── Health/              # HealthCheckAction
│   │   ├── Rooms/               # GetRoomsAction
│   │   ├── Shared/              # Adapters (QloApps, MercadoPago, Channex) y Ports
│   │   └── Webhooks/            # HandleMercadoPagoWebhookAction, HandleChannexWebhookAction
│   ├── Models/                  # Modelos de datos SQL (ProvisionalBooking, User)
│   ├── Services/                # Servicios PHP backend (AuthService, SessionService, ChannexRoomMapper)
│   ├── frontend/                # Clientes y servicios TypeScript para Astro
│   │   └── services/            # bookingService.ts, httpClient.ts
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

## 3. Refactorizaciones y Mejoras Arquitectónicas Recientes

### A. Inyección de Dependencias PSR-11 (DI Container)
- Implementación de `src/Core/Container.php` con soporte de *autowiring* dinámico vía Reflection API en PHP 8.
- Integración en `src/Core/Router.php` y `public/index.php` para instanciar Clases-Acción y Servicios sin acoplamiento manual.

### B. Resiliencia e Idempotencia FinTech (Mercado Pago & Hostinger MySQL)
- Creación de la tabla `processed_payments` mediante `scripts/create_processed_payments_table.sql` para registrar atómicamente cada `payment_id` procesado.
- Implementación del método pesimista `getByCartIdForUpdate()` (`SELECT ... FOR UPDATE`) en `src/Features/Booking/Domain/ProvisionalBookingRepository.php`.
- Remoción de `sendEarlyResponse()` en `HandleMercadoPagoWebhookAction.php`. Si la API de QloApps cae, el sistema actualiza la reserva en MySQL local a `manual_review` en menos de 5 milisegundos para alertar a administración sin perder la transacción de Mercado Pago.

### C. Arquitectura Orientada a Eventos (Event-Driven System)
- Emisión desacoplada de `BookingPaidEvent.php` en `src/Features/Booking/Domain/Events/`.
- Suscripción de `ConfirmQloAppsOrderListener.php` (confirmación en QloApps PMS) y `SyncChannexBookingListener.php` (sincronización con Channex Channel Manager).

### D. Contratos DTO Adaptativos & Cero Hardcoding
- Normalización adaptativa de payloads en `CreateBookingAction.php` para aceptar tanto contratos planos legacy como el formato estructurado enviador por Astro (`roomSlug`/`guestDetails`).
- Eliminación de fallbacks hardcodeados de desarrollo (`USGAR_SECURE_TOKEN_SECRET_DEV_ONLY` y `USGAR_CRON_SECRET_DEV_ONLY`). Exigencia obligatoria de `BOOKING_TOKEN_SECRET` y `CRON_SECRET` desde el archivo `.env`.

### E. Seguridad Web en Hostinger Apache
- Inyección de políticas `Content-Security-Policy`, `X-Frame-Options` y `Referrer-Policy` en `public/.htaccess` y `src/Core/Middleware.php`.

---

## 4. Endpoints de la API REST (`public/index.php`)

| Método | Endpoint | Descripción |
|---|---|---|
| `GET` | `/api/health` | Estado de salud de la API y conectividad de servicios |
| `GET` | `/api/rooms` | Consulta de disponibilidad e inventario en tiempo real |
| `POST` | `/api/booking` | Crear reserva temporal (Hold de 15 min) y preferencia Mercado Pago |
| `POST` | `/api/extend-hold` | Extender el bloqueo temporal de habitación por 15 min adicionales |
| `GET` | `/api/booking-status` | Consultar estado de una reserva (Requiere `access_token`) |
| `POST` | `/api/webhook` | Webhook de confirmación de pago IPN de Mercado Pago |
| `POST` | `/api/webhook-mercado-pago` | Alias de Webhook de confirmación de pago para entornos locales |
| `POST` | `/api/webhook/channex` | Webhook entrante de Channex (sincronización de reservas OTA) |
| `POST` | `/api/cron/cleanup` | Limpieza de bloqueos temporales expirados (Cron) |
| `GET` | `/api/auth/login` | Inicio de sesión OAuth/GUEST |
| `POST` | `/api/auth/register` | Registro de nuevos usuarios |
| `POST` | `/api/auth/login-email` | Inicio de sesión por correo electrónico |
| `GET` | `/api/auth/me` | Obtener perfil de usuario autenticado |
| `POST` | `/api/auth/logout` | Cierre de sesión de usuario |
| `GET` | `/api/user/bookings` | Consultar historial de reservas del usuario |

---

## 5. Pautas de Desarrollo

### Fuente de Verdad
- **Diseño y marca:** `.agents/BRAND.md`
- **Precios y habitaciones:** QloApps (`cms.usgarhoteles.com`) y Channex (`api.channex.io`)
- **Reglas técnicas y seguridad:** `.agents/RULES.md` y `.agents/AGENTS.md`

### Habitaciones Oficiales y Capacidades Dinámicas

| Habitación | Slug Local | Ocupación Channex |
|---|---|---|
| Habitación Doble Superior | `doble-superior` | Hasta 4 personas |
| Habitación Familiar Superior | `familiar-superior` | Hasta 7 personas |
| Habitación Matrimonial Superior | `matrimonial` | Hasta 3 personas |
| Habitación Triple Estándar | `triple-standar` | Hasta 3 personas |

> [!NOTE]
> Todos los precios y capacidades máximas (`max_guests`) se obtienen dinámicamente desde el backend; no existen valores hardcodeados en el código.

---

## 6. Entorno de Desarrollo y Variables de Entorno (.env)

### Variables Mandatorias para Producción
Asegúrese de configurar las siguientes variables de entorno en su servidor de producción (`.env`):

```ini
# Base de Datos MySQL Hostinger
DB_HOST=srv909.hstgr.io
DB_PORT=3306
DB_USER=u941268346_usgar
DB_PASS=tu_password
DB_NAME=u941268346_QloApp

# Seguridad y Webhooks
BOOKING_TOKEN_SECRET=token_secreto_para_firmar_tokens_de_reserva
MERCADO_PAGO_WEBHOOK_SECRET=token_secreto_para_validar_webhooks_de_mp
CHANNEX_WEBHOOK_SECRET=token_secreto_para_validar_webhooks_de_channex
CRON_SECRET=token_secreto_para_firma_hmac_y_cron_cleanup

# Integraciones Channex
CHANNEX_API_KEY=tu_api_key_channex
CHANNEX_PROPERTY_ID=tu_property_id_channex
```

> [!IMPORTANT]
> **Seguridad de Tokens:** Si `BOOKING_TOKEN_SECRET`, `MERCADO_PAGO_WEBHOOK_SECRET` o `CRON_SECRET` no están configurados en producción, las acciones rechazarán las peticiones con estado `401 Unauthorized` / `500 Internal Error` para proteger la infraestructura y la privacidad de los clientes.

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
npm run build           # Compilación estática de Astro para producción
```

---

## 7. Despliegue en Hostinger Shared Hosting

1. Ejecutar el script SQL `scripts/create_processed_payments_table.sql` en phpMyAdmin dentro del panel de Hostinger.
2. Compilar el sitio estático Astro: `npm run build`.
3. Subir el contenido generado en el directorio `dist/` a la raíz del hosting (`public_html`).
4. El archivo `public/index.php` actuará como Front Controller en PHP nativo para atender la API REST en `/api/*`.
