# Arquitectura — USGAR Hotels

Fuente de verdad de capas, flujos y zonas de riesgo. Verificada contra el código (2026-10-20). El contrato de la API vive en `docs/API.md`; el deploy en `docs/DEPLOYMENT.md`.

## Diagrama de capas

```
┌──────────────────────────────────────────────────────────┐
│  BROWSER                                                  │
│  (Astro static + JS de cliente: wizard, GSAP, Leaflet)    │
└────────────────────┬─────────────────────────────────────┘
                     │ fetch('/api/...') (mismo origen; Vite proxy en dev)
                     ▼
┌──────────────────────────────────────────────────────────┐
│  src/services/bookingService.ts  (única capa de fetch)    │
└────────────────────┬─────────────────────────────────────┘
                     │ HTTP
                     ▼
┌──────────────────────────────────────────────────────────┐
│  public/index.php  (front controller)                    │
│  Router → Middleware (CORS, headers, rate limit) → Action │
└────────────────────┬─────────────────────────────────────┘
                     │ __invoke(Request)  (DI por constructor)
                     ▼
┌──────────────────────────────────────────────────────────┐
│  app/Features/*/Actions/  (ADR)                          │
│  Una clase por endpoint. SRP estricto.                   │
└────────────────────┬─────────────────────────────────────┘
                     │ Ports (interfaces) — nunca adapters directos
                     ▼
┌──────────────────────────────────────────────────────────┐
│  app/Features/Shared/                                    │
│  ├── Ports/     (PmsPortInterface, PaymentGatewayPortInterface) │
│  ├── Adapters/  (QloApp, MercadoPago)                    │
│  └── DiscountResolver, RoomTypeRegistry                  │
└────────────────────┬─────────────────────────────────────┘
                     │ HTTP(S)/XML + SQL directo (qlo_*)
                     ▼
┌──────────────────────────────────────────────────────────┐
│  SERVICIOS EXTERNOS                                      │
│  QloApps (PMS, cms.usgarhoteles.com) │ MercadoPago │ MySQL │
└──────────────────────────────────────────────────────────┘
```

## Regla de oro

> **El frontend NUNCA llama servicios externos directamente.**
> Todo pasa por `/api/` → el backend actúa como proxy seguro que protege credenciales.

## Flujo de una reserva (dinero real, no cambiar sin entender)

```
1. Disponibilidad (display)
   BookingWidget/book.astro → bookingService.getAvailableRooms()
   → GET /api/rooms → GetRoomsAction → QloAppAdapter.getAvailableRooms()
   → SQL directo a BD QloApps (inventario total - reservas activas - holds vivos)
   → por habitación: price (standard) + non_refundable_price (Feature Price Plan)

2. Hold (bloqueo temporal de 15 min)
   → POST /api/booking → CreateBookingAction
   → lectura de tasa PEN en QloApps y congelado del snapshot de precio
   → transacción: lock de serialización (room_locks, FOR UPDATE) + re-check
   → QloAppAdapter.createCart() [webservice XML → booking en QloApps] mientras
     el lock sigue tomado; QloApps no ofrece idempotency key ni cancelación
   → ProvisionalBookingRepository.create() [persiste hold + snapshots: precio USD,
     precio PEN, tipo de cambio — congelados al cotizar] + commit
   → Respuesta: cart_id + access_token (HMAC cart_id:email) + mp_public_key

3. Pago (Custom Checkout, Checkout API)
   → MercadoPago.js cardForm → bookingService.processPayment()
   → POST /api/process-payment → ProcessPaymentAction
   → valida access_token + gates (hold expirado, pago ya en curso)
   → DENTRO de la transacción del lock: MercadoPagoAdapter.processPayment()
     (X-Idempotency-Key fresco por intento; timeouts 15s)
   → approved → attach payment_id + status paid + BookingPaidEvent (outbox, misma txn)
   → pending/in_process → attach payment_id, polling/webhook reconcilian
   → rechazado → rollback, nada persistido

4. Confirmación en el PMS
   a) Síncrono: el listener ConfirmQloAppsOrderListener corre el dispatch del evento
      (outbox) — si el proceso muere, el cron process_outbox lo entrega (5 min)
   b) Webhook MP (canónico): POST /api/webhook → HandleMercadoPagoWebhookAction
      → verificación de firma HMAC (secret 300s de tolerancia) → getPaymentDetails
      → lock del hold + dedup por source/external_reference → confirma la orden en QloApps vía SQL atómico
      (qlo_customer/qlo_cart/qlo_orders/qlo_order_detail/qlo_htl_*) + outbox
   c) Reconciliación: cron reconcile_payments (15 min) consulta MP por holds
      pending con payment_id — cubre webhooks que nunca llegaron

5. Cron de limpieza
   → /api/cron/cleanup (CLI) → CleanExpiredCartsAction: holds expirados → expired
   → /api/cron/manual-review → RetryManualReviewAction
```

La confirmación PMS es reentrante: `QloAppAdapter::confirmOrder()` toma el lock
del hold local, reutiliza una orden QloApps ya confirmada para la misma referencia
y compone con una transacción abierta por el caller (panel u outbox).

## Zonas de riesgo

### ZONA SEGURA — editar libremente
| Directorio | Contenido |
|---|---|
| `src/components/` (visuales) | UI por dominio |
| `src/pages/` (excepto book.astro, book/success) | Páginas estáticas |
| `src/layouts/` | Layout.astro |
| `src/features/animations/`, `src/features/ui/` | Animaciones GSAP, toasts |
| `src/i18n/` | Traducciones (4 locales) |
| `src/content/` + `src/data/` | Contenido: JSON por dominio + cargadores tipados sobre él |

### ZONA DE INTERFAZ — entender el contrato antes de editar
| Archivo | Conecta con |
|---|---|
| `src/services/bookingService.ts` | Todos los endpoints de Booking, Rooms, Auth |
| `src/features/booking/` (wizardStore, calendar, roomAllocator, bookingStatus) | Lógica del wizard; contrato en docs/API.md |
| `src/pages/book.astro` / `book/success.astro` | Flujo completo de reserva + polling de pago |
| `src/pages/login.astro` / `my-bookings.astro` / `profile.astro` | Endpoints de Auth |
| `app/Features/Reviews/` | Scrapea fuentes externas (allowlist de URLs) y escribe el JSON de scores; lo consume la home |

### ZONA CRÍTICA — no tocar sin justificación
| Archivo/Directorio | Razón |
|---|---|
| `app/Core/` (todo) | Framework base: Router, Request, Response, Middleware, Config, Database |
| `app/Features/Shared/Ports/` | Interfaces que los Adapters implementan — romper = romper todas las integraciones |
| `app/Features/Shared/Adapters/` | Conectan con servicios externos reales (dinero real) |
| `app/Features/Webhooks/` | Reciben confirmaciones de pago — si fallan, se pierden ventas |
| `app/Features/Booking/` | El flujo hold→pago→confirmación (transacciones + outbox) |
| `app/Features/Panel/` | El panel del hotel **escribe en QloApps** (bloqueo de habitaciones, reservas, importación de disponibilidad) |
| `app/Features/Auth/` | Credenciales, emisión y revocación de JWT |
| `app/Features/Cron/Actions/ProcessOutboxAction.php` | Máquina de estados del outbox (semántica literal documentada en su cabecera) |
| `public/index.php` | Registro de rutas y entry point — toda la API depende de este archivo |
| `.env` (fuera de git) | Credenciales de producción |

## Multi-hotel (capacidad, no activa)

El schema de QloApps soporta multi-tienda (`id_hotel` + `id_shop`). Los endpoints reciben `id_hotel` (default `DEFAULT_HOTEL_ID=1`); el frontend no envía hotel hoy. Para añadir un hotel nuevo: crear la tienda en QloApps (Multitienda), definir `SITE_URL` + `PUBLIC_HOTEL_ID` en el despliegue del subdominio y verificar que las consultas del adapter filtren por `id_hotel` (todas lo hacen: `QloAppAdapter`, `ProvisionalBookingRepository`). No hay código multi-hotel en el frontend; el contrato API es compatible.

## Contenido: una fuente, dos capas

`src/content/<dominio>/<dominio>.json` es el **dato** (rooms, services, faq, reviews,
explore, about, settings). `src/data/<dominio>.ts` es el **cargador tipado** sobre esas
colecciones (`getCollection` / `getEntry`): los componentes leen de ahí, no del JSON
crudo. Editar el JSON y el componente ya ve el cambio.

Excepción: `src/data/legal.ts` es texto legal propio (no viene de una
colección), en los 4 idiomas del sitio (`legalMeta` + `privacySections` /
`termsSections`).

El catálogo de servicios es **una sola fuente**: `src/content/services/services.json`
tiene los 35 servicios del hotel con `group: "hotel" | "room"`, y
`src/data/services.ts` los expone ya tipados y de ahí se derivan las dos
listas (`hotelServices`, `roomServices`). Editar el JSON cambia las tarjetas
destacadas y las dos listas de la web a la vez.

### Fotos de la portada

Cada espacio de la portada usa un archivo propio en `src/assets/home/`, organizado por sección. Para cambiar una foto, reemplaza el JPG correspondiente conservando su nombre y formato. Las tarjetas de habitaciones usan el nombre de la carpeta de cada habitación; los destinos usan su identificador. Estas fotos son independientes de las de habitaciones, destinos y contacto, pero son las mismas en los cuatro idiomas. El primer slide del hero también sirve como póster de su video.
