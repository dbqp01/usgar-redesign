# Contrato del backend actual (linea base)

Captura realizada el 2026-08-01 19:42 (local) contra php -S localhost:4399 -t public public/index.php

## health

- HTTP: 200
- Headers relevantes: Content-Type=application/json; charset=utf-8
- Body:
```json
{"success":true,"status":"healthy","database":"online","timestamp":"2026-08-01T19:42:04-05:00"}
```

## rooms

- HTTP: 200
- Headers relevantes: Content-Type=application/json; charset=utf-8
- Body:
```json
{"success":true,"rooms":[{"id_room_type":1,"id_product":1,"room_name":"Matrimonial Superior","price":90,"max_guests":2,"available_qty":1,"slug":"matrimonial","currency":"USD","price_formatted":"$90.00","nights":1,"total_stay_price":90},{"id_room_type":2,"id_product":2,"room_name":"Doble Superior","price":90,"max_guests":2,"available_qty":1,"slug":"doble-superior","currency":"USD","price_formatted":"$90.00","nights":1,"total_stay_price":90},{"id_room_type":3,"id_product":3,"room_name":"Triple Estándar","price":120,"max_guests":2,"available_qty":1,"slug":"triple-standar","currency":"USD","price_formatted":"$120.00","nights":1,"total_stay_price":120},{"id_room_type":4,"id_product":4,"room_name":"Familiar Superior","price":150,"max_guests":2,"available_qty":1,"slug":"familiar-superior","currency":"USD","price_formatted":"$150.00","nights":1,"total_stay_price":150}]}
```

## providers

- HTTP: 200
- Headers relevantes: Content-Type=application/json; charset=utf-8
- Body:
```json
{"success":true,"providers":["Google"]}
```

## booking-invalid

- HTTP: 400
- Headers relevantes: Content-Type=application/json; charset=utf-8
- Body:
```json
{"success":false,"error":{"code":"BAD_REQUEST","message":"Faltan parámetros requeridos: id_room_type, checkIn, checkOut, guestName, guestEmail."}}
```

## Hallazgos

Fecha de captura: 2026-08-01 (local, America/Lima UTC-5).

### Envelope general
- Toda respuesta es JSON con `Content-Type: application/json; charset=utf-8`.
- Éxito: `{"success":true, ...}`. Error: `{"success":false, "error": {"code": "<CODIGO>", "message": "<texto>"}}` — el formato `success/error/code/message` confirmado con el 400 de booking.
- `health` usa `"status":"healthy"` (NO `"ok"` como asumía el brief) y añade `database` + `timestamp` ISO-8601 con offset.

### Payload de rooms (GET /api/rooms)
- Envelope: `{"success":true,"rooms":[...]}` — sin paginación ni meta; lista plana.
- Campos por habitación (todos presentes, sin nulls): `id_room_type`, `id_product`, `room_name`, `price`, `max_guests`, `available_qty`, `slug`, `currency`, `price_formatted`, `nights`, `total_stay_price`.
- Derivados calculados por el backend: `price_formatted` (USD, 2 decimales), `nights` (1 para 2026-08-15→16), `total_stay_price` (price × nights).
- `available_qty` es 1 para las 4 habitaciones. Mecanismo real (QloAppAdapter.php:39-75 + verificación MySQL 2026-08-01): la tabla `qlo_htl_room_information` está **vacía (0 filas)**, por lo que el subquery `COUNT(*)` devuelve 0 y el `COALESCE(...,5)` (línea 46-49) nunca dispara (0 ≠ NULL) → `total_rooms=0` → el fallback `max((int)$row['total_rooms'], 1)` (línea 74) lo convierte en 1 → con `booked_count=0`, `available_qty = 1 - 0 = 1`. Es un fallback del adaptador, no stock real ni cap de consulta.
- Anomalía: **Triple Estándar (id_room_type 3) reporta `max_guests: 2`** — es **dato real de QloApps** (verificado en `qlo_htl_room_type` vía MySQL, columna max_guests=2 para los 4 tipos); no es artefacto del adaptador. Corregir en QloApps y revisar el porteo en Fase 2.
- Inconsistencia menor de slugs: `"triple-standar"` (sin ñ, divergente de `"matrimonial"`/`"doble-superior"`).

### providers (GET /api/auth/providers)
- `{"success":true,"providers":["Google"]}` — solo Google configurado (hybridauth).

### booking-invalid (POST /api/booking con body `{}`)
- HTTP 400 ANTES de tocar BD (validación primero, no crea hold). Confirmado.
- Mensaje en español con acentos, lista explícita de campos requeridos: `id_room_type, checkIn, checkOut, guestName, guestEmail`.
- No hay campo `error.field`/detalización por campo en este caso.

### Notas para la Fase 2
- Portar endpoints 1:1: envelope `success`, error `error.code`/`error.message`, Content-Type charset=utf-8.
- `/api/rooms` **NO valida** la presencia de `checkIn`/`checkOut`: si faltan, defaultiza a hoy/+1 (`GetRoomsAction.php:32-35`) y responde **200 con datos** (verificado en vivo 2026-08-01: `GET /api/rooms` sin params → 200, success:true, 4 rooms, nights:1). El porteo debe reproducir este default, no un error de validación.
- `nights` se calcula con `max(1, round((checkOut - checkIn) / 86400))` — un checkout de un día después da nights=1 (GetRoomsAction.php:41).


