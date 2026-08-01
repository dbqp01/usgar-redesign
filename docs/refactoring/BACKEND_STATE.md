# STATE — Backend USGAR (Fase 0/2: línea base + limpieza de pagos)

> Memoria de sesión del backend. El `STATE.md` anterior documenta el redesign del frontend;
> este archivo es el handoff del trabajo de backend (migraciones + refactor).
> Sesión: 2026-08-01.

## Contexto (decisiones aprobadas por el usuario)

- **Orden de trabajo**: F0 línea base → F2 limpieza de pagos → F4 frontend (redesign pendiente) → F3 salir de QloApps (tablas propias + Filament) → F1 Nobeds (bloqueada: requiere suscripción pagada, se hace al final).
- **CMS**: salir de QloApps por completo (estable y simple). **Pagos**: MercadoPago se mantiene (Checkout API).
- **Nobeds**: sin free trial — requiere sub mensual; se integra cuando el proyecto esté presentado. Channex sigue en producción hasta entonces.

## Línea base (2026-08-01, verificado)

| Suite | Resultado |
|---|---|
| `php composer.phar check` (PHPUnit 11.5.56 + PHPStan 2) | **26 tests / 97 assertions PASS** + PHPStan sin errores |
| `npm run check` (astro check) | 0 errores, 6 hints pre-existentes (Hero.astro Props, RoomCard index, TypographicMarquee i, heroTextReveal ScrollTrigger, Layout media, profile.astro profileCard) |
| `npm run test:php` (run-exhaustive-tests) | **22/22 PASS** tras fix de harness (ver abajo) |
| `npm run audit:security` | 3 hallazgos, **todos falsos positivos**: `PDO::exec()` usado solo para `CREATE TABLE IF NOT EXISTS` sin input de usuario (User.php:31, ProvisionalBookingRepository.php:87, SubscribeNewsletterAction.php:34) |

### Cambios de F0 aplicados
- `scripts/run-exhaustive-tests.php`: (a) expectativas obsoletas de `GET /api/rooms` sin params — el código usa hoy/mañana por defecto (el frontend hace polling sin params en `subscribeToRoomAvailability`, src/services/bookingService.ts:155) y la prueba esperaba 400; actualizado a 200+success. (b) log del servidor de pruebas único por ejecución (`php-test-server-<pid>.log`) — Windows deja zombies de `php -S` que bloquean el archivo de log fijo.

### Notas de entorno (Windows)
- `npm run test:php` / ejecución directa del harness puede dejar procesos huérfanos `php -S 127.0.0.1:8089` si se interrumpe; limpiar con `Stop-Process` por PID o matando listeners de 8089.
- WIP sin commitear del usuario (NO tocar): `app/Core/Container.php` (fallback deps no resueltas), `app/bootstrap.php` (binding PMS con null), `scripts/dev.js` (fix Windows).

## En curso (Fase 2 — limpieza de pagos)

- Auditar residuos Checkout Pro (preference/init_point/checkout_pro/access_token) — ver hallazgos abajo al completar.
- Pendiente por sesión: outbox/cron, DIP, secretos en git (rotación manual por el usuario), dead code, success.astro 3 estados.

## Siguiente
- F2 completar → F4 frontend (fases 3-4 redesign: internas + wizard reserva).
- F3 CMS (necesita mini-contrato de alcance con el usuario al empezar).
- F1 Nobeds (requiere sub pagada; instrucciones de cuenta/API key al llegar).
