# Arquitectura — USGAR Hotels

Fuente de verdad de capas, flujos y zonas de riesgo. Verificada contra el código (2026-08-11). El contrato de la API vive en `docs/API.md`; el deploy en `docs/DEPLOYMENT.md`.

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
   → QloAppAdapter.createCart() [webservice XML → booking en QloApps]
   → lock de serialización (room_locks, FOR UPDATE) + re-check de disponibilidad
   → ProvisionalBookingRepository.create() [persiste hold + snapshots: precio USD,
     precio PEN, tipo de cambio — congelados al cotizar]
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
      → dedup (isOrderConfirmed) → confirma la orden en QloApps vía SQL atómico
      (qlo_customer/qlo_cart/qlo_orders/qlo_order_detail/qlo_htl_*) + outbox
   c) Reconciliación: cron reconcile_payments (10 min) consulta MP por holds
      pending con payment_id — cubre webhooks que nunca llegaron

5. Cron de limpieza
   → /api/cron/cleanup (CLI) → CleanExpiredCartsAction: holds expirados → expired
   → /api/cron/manual-review → RetryManualReviewAction
```

## Zonas de riesgo

### ZONA SEGURA — editar libremente
| Directorio | Contenido |
|---|---|
| `src/components/` (visuales) | UI por dominio |
| `src/pages/` (excepto book.astro, book/success) | Páginas estáticas |
| `src/layouts/` | Layout.astro |
| `src/features/animations/`, `src/features/ui/` | Animaciones GSAP, toasts |
| `src/i18n/` | Traducciones (4 locales) |
| `src/content/` + `src/data/` | Contenido (ver nota de duplicación) |

### ZONA DE INTERFAZ — entender el contrato antes de editar
| Archivo | Conecta con |
|---|---|
| `src/services/bookingService.ts` | Todos los endpoints de Booking, Rooms, Auth |
| `src/features/booking/` (wizardStore, calendar, roomAllocator) | Lógica del wizard; contrato en docs/API.md |
| `src/pages/book.astro` / `book/success.astro` | Flujo completo de reserva + polling de pago |
| `src/pages/login.astro` / `my-bookings.astro` / `profile.astro` | Endpoints de Auth |

### ZONA CRÍTICA — no tocar sin justificación
| Archivo/Directorio | Razón |
|---|---|
| `app/Core/` (todo) | Framework base: Router, Request, Response, Middleware, Config, Database |
| `app/Features/Shared/Ports/` | Interfaces que los Adapters implementan — romper = romper todas las integraciones |
| `app/Features/Shared/Adapters/` | Conectan con servicios externos reales (dinero real) |
| `app/Features/Webhooks/` | Reciben confirmaciones de pago — si fallan, se pierden ventas |
| `app/Features/Booking/` | El flujo hold→pago→confirmación (transacciones + outbox) |
| `app/Features/Cron/Actions/ProcessOutboxAction.php` | Máquina de estados del outbox (semántica literal documentada en su cabecera) |
| `public/index.php` | Registro de rutas y entry point — toda la API depende de este archivo |
| `.env` (fuera de git) | Credenciales de producción |

## Multi-hotel (capacidad, no activa)

El schema de QloApps soporta multi-tienda (`id_hotel` + `id_shop`). Los endpoints reciben `id_hotel` (default `DEFAULT_HOTEL_ID=1`); el frontend no envía hotel hoy. Para añadir un hotel nuevo: crear la tienda en QloApps (Multitienda), definir `SITE_URL` + `PUBLIC_HOTEL_ID` en el despliegue del subdominio y verificar que las consultas del adapter filtren por `id_hotel` (todas lo hacen: `QloAppAdapter`, `ProvisionalBookingRepository`). No hay código multi-hotel en el frontend; el contrato API es compatible.

## Nota de duplicación de contenido (deuda conocida)

Existen dos fuentes paralelas de datos de negocio: `src/content/*.json` (rooms, services, faq, reviews, explore, settings, about) y `src/data/*.ts` (rooms, services, faq, reviews, hotelServices, attractions, settings, about). Antes de editar un dato, verifica con grep cuál consumen los componentes (ej. `rooms.ts` vs `rooms.json`). Consolidación pendiente: `docs/ROADMAP.md` F-6.
