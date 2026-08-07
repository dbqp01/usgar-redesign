# Contratos API — Postman (esqueleto USGAR Hotels)

> Fecha: 2026-08-07 · Entrega final. Los contratos viven en el workspace Postman del proyecto; este README los referencia para el repo.

## Colección: "USGAR - QloApps Webservice & Channel Manager (skeleton)"

Workspace: **My Workspace** (Postman, cuenta del proyecto). Entorno: **USGAR - Produccion**.

| Variable | Valor | Tipo |
|---|---|---|
| `base_url` | `https://cms.usgarhoteles.com/api` | default |
| `ws_key` | *(webservice key QloApps)* | **secret** |
| `cm_base_url` | `https://channels.qloapps.com` | default (confirmar post-pago) |
| `cm_api_key` | *(API Client Secret del CM — solo plan pagado)* | **secret** |
| `booking_id` | ID de booking para A6 | default |

## Folder A — QloApps Webservice (PMS) · **OPERATIVO sin suscripción**

Autenticación: HTTP Basic (username = `ws_key`, sin password) — convención oficial del webservice QloApps/PrestaShop.
Fuente: https://devdocs.qloapps.com/webservice/basic-topics.html · https://devdocs.qloapps.com/webservice/advanced-api-uses.html

| # | Método | Endpoint | Contrato que refleja |
|---|---|---|---|
| A1 | GET | `/api` | Lista de recursos + permisos de la key |
| A2 | GET | `/api/bookings?schema=blank` | Esquema vacío para crear booking |
| A3 | GET | `/api/bookings?schema=synopsis` | Esquema con tipos/campos |
| A4 | GET | `/api/bookings` | Listar reservas del PMS |
| A5 | POST | `/api/bookings` | `QloAppAdapter::createCart()` (XML `<qloapps><booking>`) |
| A6 | PUT | `/api/bookings/{{booking_id}}` | `QloAppAdapter::confirmOrder()` (payment_status=1) |

## Folder B — QloApps Channel Manager API (Webkul) · **REQUIERE plan pagado**

Autenticación: header `api-key: {{cm_api_key}}` (el secret del CM; el campo *Generate API Credentials* es exclusivo de plan pagado — fuente: https://qloapps.com/qloapps-pms-channel-manager-connector/).
Contrato verificado contra la colección pública de Webkul "Qlo Channel Manager Collection" (Postman API Network) — el CM de QloApps es white-label de la plataforma Channex.

| # | Método | Endpoint | Uso |
|---|---|---|---|
| B1 | GET | `/api/test_connection` | Probar credenciales |
| B2 | GET | `/api/properties` | Propiedades del hotel |
| B3 | GET | `/api/room_types?filter[id_property]=1` | Tipos de habitación |
| B4 | GET | `/api/bookings?filter[check_in][gte]="..."` | Reservas del CM |
| B5 | POST | `/api/booking_notification` | Push booking OTA→PMS (status `new`/`modified`/`cancelled`, guest_detail, price_details, room_bookings, occupancy, taxes) |

## Estado (entrega 2026-08-07)

- ✅ Folder A probablemente **funcional ya** (el webservice está en uso por `QloAppAdapter` — `api-harness` crea carts reales). Falta solo pegar `ws_key` en el entorno.
- ⏸️ Folder B **bloqueado por pago** (suscripción CM rechazada): las credenciales `cm_api_key` se generan al activar el plan. El runbook `docs/CHANNEL_MANAGER_SETUP.md` tiene la activación paso a paso.

## Verificación local (alternativa a Postman)

El repo ya prueba los mismos contratos sin Postman:
- `php tests/api-harness.php` — contrato de booking del sitio (requiere servidor dev en :8000).
- `php scripts/run-exhaustive-tests.php` — suite exhaustiva (incluye asserts del port del channel manager).
