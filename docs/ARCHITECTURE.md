# Arquitectura — USGAR Hotels

## Diagrama de Capas

```
┌──────────────────────────────────────────────────────────┐
│  BROWSER                                                  │
│  (Astro static pages + client-side JS)                   │
└────────────────────┬─────────────────────────────────────┘
                     │ fetch('/api/...')
                     ▼
┌──────────────────────────────────────────────────────────┐
│  src/services/    (Frontend Service Layer)                │
│  httpClient.ts → bookingService.ts                       │
└────────────────────┬─────────────────────────────────────┘
                     │ HTTP (Vite proxy en dev)
                     ▼
┌──────────────────────────────────────────────────────────┐
│  public/index.php  (Front Controller)                    │
│  Router → Middleware Pipeline → Action class             │
└────────────────────┬─────────────────────────────────────┘
                     │ __invoke(Request)
                     ▼
┌──────────────────────────────────────────────────────────┐
│  app/Features/*/Actions/  (ADR Pattern)                  │
│  Una clase por endpoint. SRP estricto.                   │
└────────────────────┬─────────────────────────────────────┘
                     │ usa Ports (interfaces)
                     ▼
┌──────────────────────────────────────────────────────────┐
│  app/Features/Shared/                                    │
│  ├── Ports/     (PmsPort, PaymentGatewayPort, Channel)   │
│  └── Adapters/  (QloApp, MercadoPago, Channex)           │
└────────────────────┬─────────────────────────────────────┘
                     │ HTTP/SQL/XML
                     ▼
┌──────────────────────────────────────────────────────────┐
│  SERVICIOS EXTERNOS                                      │
│  QloApps (PMS) │ Mercado Pago │ Channex │ MySQL          │
└──────────────────────────────────────────────────────────┘
```

## Regla de Oro

> **El frontend NUNCA llama servicios externos directamente.**
> Todo pasa por `/api/` → el backend actúa como proxy seguro que protege credenciales.

## Mapa de Zonas de Riesgo

### 🟢 ZONA SEGURA — Editar libremente

Archivos que no afectan la lógica de negocio ni los contratos API.

| Directorio | Contenido |
|-----------|-----------|
| `src/components/` | Componentes visuales Astro |
| `src/pages/` (excepto book.astro) | Páginas estáticas |
| `src/layouts/` | Layouts |
| `src/styles/` | CSS / Tailwind |
| `src/assets/` | Imágenes y assets |
| `src/i18n/` | Traducciones |
| `src/content/` | Content Collections JSON |
| `src/data/` | Datos estáticos TS (rooms.ts, settings.ts) |
| `src/utils/` | Helpers frontend |

### 🟡 ZONA DE INTERFAZ — Entender contrato API antes de editar

Archivos que conectan frontend con backend. Cambiar uno requiere verificar el otro.

| Archivo | Conecta con |
|---------|------------|
| `src/services/bookingService.ts` | Todos los endpoints de Booking y Rooms |
| `src/services/httpClient.ts` | Capa de transporte base |
| `src/services/contracts/` | Contratos TypeScript que deben coincidir con PHP |
| `src/pages/book.astro` | Consume bookingService para reservas |
| `src/pages/login.astro` | Consume endpoints de Auth |
| `src/utils/auth-client.ts` | Consume `/api/auth/me` y `/api/auth/logout` |

###  ZONA CRÍTICA — No tocar sin justificación

Cambiar estos archivos puede romper toda la aplicación.

| Archivo/Directorio | Razón |
|-------------------|-------|
| `app/Core/` (todo) | Framework base: Router, Request, Response, Middleware, Events, Config |
| `app/Features/Shared/Ports/` | Interfaces que los Adapters implementan — romper = romper todas las integraciones |
| `app/Features/Shared/Adapters/` | Conectan con servicios externos reales (dinero real) |
| `app/Features/Webhooks/` | Reciben confirmaciones de pago — si fallan, se pierden ventas |
| `app/Features/Shared/RoomTypeRegistry.php` | Fuente única de verdad para mapeo de habitaciones |
| `public/index.php` | Registro de rutas y entry point — toda la API depende de este archivo |
| `.env` | Credenciales de producción |

## Flujo de una Reserva

```
1. Usuario busca disponibilidad
   book.astro → bookingService.getAvailableRooms()
   → GET /api/rooms → GetRoomsAction → QloAppAdapter.getAvailability()
   → QloApps MySQL (SQL directo)

2. Usuario inicia reserva
   book.astro → bookingService.createHoldAndPreference()
   → POST /api/booking → CreateBookingAction
   → QloAppAdapter.createTemporaryHold() [bloqueo 15 min]
   → MercadoPagoAdapter.createPaymentPreference()
   → ProvisionalBookingRepository.create() [persiste en MySQL local]
   → Redirige a Mercado Pago checkout

3. Mercado Pago notifica pago exitoso
   → POST /api/webhook → HandleMercadoPagoWebhookAction
   → MercadoPagoAdapter.verifyPayment()
   → QloAppAdapter.confirmBooking()
   → ChannexAdapter.syncBooking()
   → ProvisionalBookingRepository.updateStatus('CONFIRMED')

4. Cron limpia carritos expirados
   → POST /api/cron/cleanup → CleanExpiredCartsAction
   → ProvisionalBookingRepository.findExpired()
   → QloAppAdapter.releaseHold()
```
