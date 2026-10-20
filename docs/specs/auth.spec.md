# Spec: Autenticación (Acceso) — Auth pública y Panel admin

**Estado:** estable
**Código:** `app/Features/Auth/`, `app/Features/Panel/`
**Docs relacionadas:** [API.md](../API.md), [SECURITY.md](../SECURITY.md), [ARCHITECTURE.md](../ARCHITECTURE.md), [PMS.md](../PMS.md)

## Propósito

Gestionar el acceso al sitio de huéspedes (registro/login email + OAuth social, sesión JWT en cookie) y al panel de disponibilidad del dueño (login por password, gestión de bloqueos y reservas manuales). Son dos zonas de autenticación independientes que comparten el mismo secret (`AUTH_JWT_SECRET`) pero usan cookies y claims distintos.

## Requisitos funcionales (EARS)

### Auth pública (huéspedes)

- RF-01: CUANDO un usuario envía `POST /api/auth/register` con email válido, contraseña ≥ 8 caracteres y nombre, EL SISTEMA DEBE crear la cuenta (password hasheado, ver `User::createFromEmail`) y emitir sesión; SI el email ya existe DEBE responder 409.
- RF-02: CUANDO un usuario envía `POST /api/auth/login-email`, EL SISTEMA DEBE verificar las credenciales (`User::verifyPassword`), responder 401 si son inválidas y emitir sesión si son correctas.
- RF-03: CUANDO la cuenta existe pero fue creada vía OAuth, EL SISTEMA DEBE rechazar el login email con un mensaje que indique usar "Continue with <provider>" (400 con `isOAuth: true`).
- RF-04: CUANDO un usuario supera 5 intentos de login fallidos por email+IP en 15 minutos, EL SISTEMA DEBE responder 429 (rate limit dirigido anti fuerza bruta; `RateLimiter`).
- RF-05: CUANDO un usuario inicia `GET /api/auth/login?provider=X&redirect=Y`, EL SISTEMA DEBE guardar provider/redirect en cookies temporales (5 min) y redirigir al proveedor OAuth configurado en `.env` (vía `AuthService`); SI el proveedor no está configurado DEBE responder 400.
- RF-06: CUANDO el proveedor retorna a `GET /api/auth/callback`, EL SISTEMA DEBE ejecutar el handshake hybridauth, obtener el email del perfil, crear/actualizar la cuenta (`User::createFromOAuth`), emitir sesión y redirigir a la cookie `usgar_auth_redirect` (default `/profile`); SI falla DEBE loggear el error internamente y redirigir a `/login` con mensaje genérico (sin filtrar detalles internos).
- RF-07: CUANDO se solicita `GET /api/auth/providers`, EL SISTEMA DEBE devolver solo los proveedores con credenciales presentes en `.env` (Google/Microsoft/Facebook si aplica).
- RF-08: CUANDO se solicita `GET /api/auth/me` con sesión válida, EL SISTEMA DEBE devolver los datos públicos del usuario; SIN sesión DEBE responder 200 con `user: null` (comportamiento verificado, ver Desviaciones).
- RF-09: CUANDO se solicita `POST /api/auth/logout`, EL SISTEMA DEBE expirar la cookie de sesión y responder JSON (si el cliente lo pide) o redirigir a `/login`; `GET /api/auth/logout` no está registrado porque logout es una mutación.
- RF-10: CUANDO un usuario autenticado envía `POST /api/user/profile`, EL SISTEMA DEBE actualizar nombre/apellido/teléfono (`User::updateProfile`) y re-emitir el JWT con los datos frescos; SI no hay sesión DEBE responder 401.
- RF-11: CUANDO un usuario autenticado solicita `GET /api/user/bookings`, EL SISTEMA DEBE devolver sus reservas (`User::getBookings`); SIN sesión DEBE responder 401.

### Panel admin (dueño)

- RF-12: CUANDO se envía `POST /api/panel/login` con la password que coincide con `PANEL_PASSWORD`, EL SISTEMA DEBE emitir la cookie del panel (`usgar_panel`, JWT con `role=panel`, TTL 12 h); Fail-closed: SI `PANEL_PASSWORD` no está configurada, NADIE puede entrar (comparación con `hash_equals`).
- RF-13: SIEMPRE que cualquier endpoint `/api/panel/*` (salvo login/logout) es invocado, EL SISTEMA DEBE exigir cookie de panel válida (`PanelAuth::requireAuth` → 401 si falta o es inválida).
- RF-14: CUANDO se solicita `POST /api/panel/logout`, EL SISTEMA DEBE invalidar la cookie del panel.
- RF-15: CUANDO se solicita `GET /api/panel/availability?month=YYYY-MM[&hotel=N]`, EL SISTEMA DEBE devolver el grid del mes (habitaciones, reservas, holds, bloqueos manuales, mantenimiento) vía `AvailabilityRepository::getMonth`; mes mal formateado → 400.
- RF-16: CUANDO se solicita `GET /api/panel/rooms-availability?from=...&to=...`, EL SISTEMA DEBE devolver disponibilidad por habitación física en el rango (el frontend calcula continuidad); rango inválido → 400.
- RF-17: CUANDO se envía `POST /api/panel/block` con `room_id`, `date_from` ≤ `date_to`, EL SISTEMA DEBE insertar un disable_date en `qlo_htl_room_disable_dates` (tabla NATIVA de QloApps: la habitación deja de venderse en web y CMS); habitación inexistente → 400 `ROOM_NOT_FOUND`.
- RF-18: CUANDO se solicita `DELETE /api/panel/block?id=N`, EL SISTEMA DEBE liberar ese bloqueo (no es cancelación de reserva: sin cargo ni penalización); id inexistente → 404 `BLOCK_NOT_FOUND`.
- RF-19: CUANDO se envía `POST /api/panel/booking` con datos de huésped válidos, EL SISTEMA DEBE (1) crear un hold local transitorio `pending` con el `channel` congelado en `guest_data`, (2) confirmar la orden en QloApps vía `PmsPortInterface::confirmOrder` (module `panel-{channel}`, etiqueta `Panel ({channel})`, habitación física elegida), y (3) marcar el hold `paid` solo después de crear la orden PMS. SI falla antes de crearla, debe conservar el hold como `failed`; SI falla después de crearla, debe conservarlo como `manual_review`; ambos casos responden 500 `PANEL_BOOKING_FAILED`. Precio: el enviado (`price_usd`) o precio del tipo × noches.
- RF-20: CUANDO se solicita `GET /api/panel/export?format=csv|xlsx&month=...`, EL SISTEMA DEBE descargar las reservas del mes (CSV con BOM UTF-8 o XLSX real con PhpSpreadsheet).
- RF-21: CUANDO se envía `POST /api/panel/import` con CSV o XLSX (base64 o texto), EL SISTEMA DEBE registrar cada fila como bloqueo manual (`manual_blocks`) y reportar importadas/omitidas con errores por fila (máx 20).

## Requisitos no funcionales

- **Sesión huésped**: JWT HS256 (librería `firebase/php-jwt`, RFC 7519) firmado con `AUTH_JWT_SECRET` (≥ 32 chars, si no → 500/401). Cookie `usgar_session` HttpOnly, SameSite=Lax, Secure en HTTPS, TTL 30 días. Validación estándar (alg fijo, exp, firma) + `iss`/`aud` explícitos.
- **CSRF**: doble cookie (`usgar_csrf`, legible por JS) + header `X-CSRF-Token` obligatorio en **todas las mutaciones autenticadas**, incluidas las enviadas desde formularios HTML (`SessionService::assertCsrf`, con `hash_equals`). `SameSite=Lax` es una capa adicional de defensa y no sustituye la validación del token.
- **Sesión panel**: cookie `usgar_panel` HttpOnly, TTL 12 h, claim `role=panel`, `iss`/`aud`/`jti` validados. Independiente de la sesión de huésped; logout revoca el `jti` server-side.
- **Rate limiting**: login email 5 intentos/15 min por email+IP y login del panel 5 intentos/15 min por IP; global vía middleware (ver `docs/SECURITY.md`).
- **i18n/API**: mensajes de error de panel en español; auth pública en inglés (convención actual del código).

## Contratos

Endpoints completos (in/out) → [API.md](../API.md). Resumen:

| Ruta | Acción |
|---|---|
| `GET /api/auth/providers` · `GET /api/auth/login` · `GET /api/auth/callback` | OAuth hybridauth (`AuthProvidersAction`, `AuthLoginAction`, `AuthCallbackAction`) |
| `POST /api/auth/register` · `POST /api/auth/login-email` · `GET /api/auth/me` · `POST /api/auth/logout` | Email/sesión (`AuthRegisterAction`, `AuthLoginEmailAction`, `AuthMeAction`, `AuthLogoutAction`) |
| `GET /api/user/bookings` · `POST /api/user/profile` | Cuenta (`GetUserBookingsAction`, `UpdateUserProfileAction`) |
| `POST /api/panel/login` · `POST /api/panel/logout` | `PanelLoginAction`, `PanelLogoutAction` |
| `GET /api/panel/availability` · `GET /api/panel/rooms-availability` · `GET /api/panel/export` · `POST /api/panel/import` | Lectura/exportación (`GetAvailabilityAction`, `PanelRoomsAvailabilityAction`, `ExportAvailabilityAction`, `ImportAvailabilityAction`) |
| `POST /api/panel/block` · `DELETE /api/panel/block` · `POST /api/panel/booking` | Mutaciones (`PanelBlockAction`, `PanelUnblockAction`, `PanelBookingAction`) |

**Integraciones y datos:** hybridauth 3 (Google/Microsoft/Facebook, solo con credenciales en `.env`); tabla propia `users` (auto-creada en `User::ensureTableExists`); panel escribe en QloApps vía `QloAppAdapter` SQL directo (`qlo_htl_room_disable_dates`) y `confirmOrder` webservice; reserva manual pasa por `ProvisionalBookingRepository` (hold `pending` → `paid`, con `failed`/`manual_review` de compensación) y `PriceCalculator` (USD→PEN). El canal se conserva en `guest_data.channel` y viaja sin cambiar la firma del puerto mediante `module=panel-{channel}` y `paymentLabel=Panel ({channel})`. Dependencias verificadas con graphify: `SessionService` (cookies/CSRF) es el punto de entrada de auth compartido por Booking/User; el panel usa `PmsPortInterface` (nunca el adapter directo).

## Verificación

- Unit: `tests/Unit/Features/Auth/SessionServiceTest.php` (tokens, cookies, CSRF) y `tests/Unit/Features/Panel/ExportAvailabilityActionTest.php` (export CSV/XLSX) → `composer test:unit` o `npm run test:php`.
- Suite completa backend: `npm run test:php` (`php scripts/run-exhaustive-tests.php`).
- Contrato API del flujo reserva/login: `php tests/api-harness.php`.
- E2E (login/book): `npx playwright test` (tests/e2e/).

## Desviaciones conocidas / TODO

- `AuthMeAction` responde **200 con `user: null`** (no 401) cuando no hay sesión — decisión actual para que el frontend distinga "anónimo" de "error"; documentar si se cambia.
- `PanelAuth` emite y valida `iss`, `aud` y `jti` igual que `SessionService`; `TokenRevocationStore` rechaza tokens revocados después del logout.
- Dos mecanismos de bloqueo conviven: `POST /api/panel/block` escribe en `qlo_htl_room_disable_dates` (nativo QloApps), mientras `POST /api/panel/import` escribe en `manual_blocks` (propio). No unificar sin revisar `docs/PMS.md`.

Nota de consistencia: la reserva manual usa un hold transitorio pending, toma el lock de `room_locks` y solo lo marca paid después de confirmar QloApps; ante fallo se marca failed o manual_review para evitar inventario fantasma.
