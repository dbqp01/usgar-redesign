# Runbook — Configurar inventario de habitaciones en QloApps (BLOQUEANTE para reservas web)

> **Para:** propietario/admin del PMS QloApps (`https://cms.usgarhoteles.com/admin`). **Idioma:** español.
> **Objetivo:** mapear las habitaciones físicas a los 4 room types para que el webservice acepte reservas. Hoy el POST de reservas de la web falla con **"Las habitaciones solicitadas no están disponibles"** (404) porque no hay inventario físico configurado.
> **Leyenda:** ✅ paso verificado en doc oficial citada. ⚠️ requiere confirmar en pantalla.

## Estado actual (verificado en vivo, 2026-08-06)

- `qlo_htl_room_information` = **0 filas** (no hay habitaciones físicas mapeadas a ningún room type).
- Existen 4 room types activos (con precio en `qlo_product`):

| id_room_type | id_product | Precio (USD) | Nombre |
|---|---|---|---|
| 1 | 1 | 90 | Matrimonial Superior |
| 2 | 2 | 90 | Doble Superior |
| 3 | 3 | 120 | Triple Estándar |
| 4 | 4 | 150 | Familiar Superior |

- El sitio web muestra disponibilidad con un fallback de código (`COALESCE(...,5)`), pero **el webservice de QloApps valida contra habitaciones reales** → rechaza toda reserva. Por eso las reservas web quedan solo en `provisional_bookings` (tabla local) y **nunca se crean en el PMS**.
- Fix de código aplicado (2026-08-06, `QloAppAdapter.php`): el XML de `createCart`/`confirmOrder` ahora incluye `total_tax` (campo requerido por el endpoint `bookings` del webservice — eliminaba el error *"Undefined array key total_tax"*). Verificado: el POST ya no devuelve ese warning; **solo falta el inventario**.

## Pasos (doc oficial: https://docs.qloapps.com/catalog/manage_room_types/)

1. Back office QloApps → **Catalog → Manage Room Types** ✅
2. Clic en **editar** cada room type de la tabla (4 existentes) → sección **Rooms** ✅
3. Clic en **Add** / añadir habitación con:
   - **Room number**: número de habitación física (ej. `A-101`, `B-202`). ✅
   - **Floor**: piso. ✅
   - **Status**: `Active` (disponible para reserva), `Inactive` (fuera de venta permanente) o `Temporarily Inactive` (mantenimiento — permite definir *disable dates*). ✅
   - **Extra Information**: comentarios opcionales. ✅
4. Repetir para los 4 room types; guardar con **Save and stay**. ✅
5. ⚠️ **Verificar en pantalla** que el inventario total coincida con la realidad del hotel (es el mismo inventario que usarán el booking engine y el Channel Manager).

## Criterio de salida

- `qlo_htl_room_information` tiene filas por cada room type, **y**
- una reserva de prueba desde la web (`POST /api/booking` Happy Path) devuelve un `cart_id` **numérico** (id de booking de QloApps), no `USGAR-*` (fallback local), **y**
- `GET /api/bookings` del webservice devuelve `totalItems > 0`.

Verificación técnica opcional: `GET https://cms.usgarhoteles.com/api/room_types/1` debe listar las habitaciones en el XML.
