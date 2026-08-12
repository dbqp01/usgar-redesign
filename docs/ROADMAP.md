# Roadmap — USGAR Hotels

Estado de migraciones y backlog de mejoras priorizado. Cada ítem se marca `[x]` solo cuando está hecho y verificado (tests pasando). Actualizado: 2026-08-11.

## Migraciones en curso (decisiones cerradas)

| Tema | Decisión | Estado |
|---|---|---|
| Pagos | **MercadoPago se mantiene** como pasarela única. Stripe/LLC descartados definitivamente (2026-08-05) | Cerrado |
| CMS/PMS | **QloApps se mantiene**; migración a Filament cancelada (2026-08-04) y panel eliminado del repo (2026-08-05; `admin.usgarhoteles.com` ya no existe) | Cerrado |
| Channel manager | **QloApps Channel Manager (Webkul)**: sin código en el repo (módulo del lado PMS, sync vía webservice `cm_api`); **activación pendiente de pago** ($30/propiedad/mes). Runbook: `docs/PMS.md` §D | En curso (gated por pago) |
| Refactor backend | Cerrado (F2, 2026-08-03); auditoría de limpieza en curso | En curso |

### Channel manager — pasos pendientes (detalle en `docs/PMS.md` §D)
- [ ] Confirmar con Webkul antes de pagar: conectividad Booking/Expedia incluida, compatibilidad de versión, límite de canales.
- [ ] Activar suscripción en channels.qloapps.com (trial 15 días NO sincroniza precios/inventario).
- [ ] Habilitar webservice QloApps con permisos `cm_api` + módulo conector.
- [ ] Configurar canales (Booking/Expedia con "Channex" como proveedor, Airbnb authorize) + mapeo room types.
- [ ] Monitoreo: reconciliación periódica inventario/tarifas OTA vs QloApps (el CM no tiene status page pública).
- [ ] Pruebas con OTAs reales y verificación de overbooking.

## Backlog priorizado de mejoras del backend

Auditoría de mejores prácticas PHP realizada el 2026-08-11 (evidencia en `docs/STATE.md`). Orden: P1 seguridad/dinero → P2 deuda estructural → P3 rendimiento/ops.

### P1 — Seguridad y dinero (diffs pequeños, alto valor)
- [x] **P1-1** `ProcessOutboxAction.php:280`: `unserialize` sin `allowed_classes` → restringir a `[BookingPaidEvent::class]` (object injection si la BD se compromete). **FIX 2026-08-12**: `allowed_classes` con `BookingPaidEvent` + `DateTimeImmutable` ya presente en `ProcessOutboxAction.php:288-294` (comentario de auditoría incluido); el roadmap estaba desactualizado.
- [x] **P1-2** `Config.php:164`: el parseo de `.env` corta valores que contienen `" #"` (bug real con contraseñas) → no soportar comentarios inline o respetar comillas. **FIX 2026-08-12**: `Config::parseLine()` público; comentario inline solo se recorta si el valor NO está entre comillas (`DB_PASS="ab #cd"` ya no se trunca). Tests: `tests/Unit/Core/ConfigParseTest.php`.
- [x] **P1-3** `HealthCheckAction.php:37-44`: `/api/health` expone document_root, ruta del `.env` y prefijos de tokens → diagnosticar solo fuera de `APP_ENV=production`. **FIX 2026-08-12**: `env_diag` solo fuera de producción (default seguro: `APP_ENV` ausente = producción).
- [x] **P1-4** `User.php:165,184`: `password_hash(PASSWORD_BCRYPT)` sin `password_needs_rehash()` → usar `PASSWORD_DEFAULT` + rehash en login exitoso. **FIX 2026-08-12**: ambos hash a `PASSWORD_DEFAULT`; rehash automático en `verifyPassword` tras login válido.
- [x] **P1-5** Login sin límite por cuenta (`POST /api/auth/login-email`): el global 300/600s no frena fuerza bruta dirigida → límite estricto por email+IP (ej. 5/15 min). **FIX 2026-08-12**: `RateLimiter::check(email|ip, 5, 900)` en `AuthLoginEmailAction` → 429.
- [x] **P1-6** Dinero en `float` (`PriceCalculator.php:15-18`, `CreateBookingAction.php:112`) → `bcmath` o céntimos enteros para la conversión USD→PEN. **FIX 2026-08-12**: acumulación en **céntimos enteros** (`PriceCalculator::roomTotalCents()`, `$totalPriceCents` en `CreateBookingAction`); float solo en la frontera JSON. Sin bcmath (no garantizado en Hostinger).
- [x] **P1-7** `SessionService.php:36-60`: JWT sin `iss`/`aud`/`jti` → añadir claims; `jti`+tabla si se necesita revocación server-side. **FIX 2026-08-12**: claims `iss` (SITE_URL), `aud` (usgar-web), `jti` (CSPRNG) + validación explícita en `validateToken`. Tokens viejos quedan invalidados (re-login único). Tests: `tests/Unit/Features/Auth/SessionServiceTest.php`.
- [x] **P1-8** Endpoints POST autenticados (logout, profile) sin token CSRF (solo SameSite=Lax) → token de doble cookie para mutaciones con auth por cookie. **FIX 2026-08-12**: cookie `usgar_csrf` (legible por JS, regenerada en cada login) + header `X-CSRF-Token` exigido por `SessionService::assertCsrf()` en `AuthLogoutAction` y `UpdateUserProfileAction`; frontend: `auth-client.ts::csrfToken()` + `profile.astro`. Formularios HTML exentos (SameSite=Lax). Pendiente menor: panel (`usgar_panel`) usa su propia cookie sin CSRF — mismo patrón si se expone fuera del dueño.

### P2 — Deuda estructural (refactor acotado; borrar antes que construir)
- [x] **P2-1** Autoloader único de Composer: declarar `"autoload": {"psr-4": {"App\\": "app/"}}` en composer.json; eliminar `app/Core/Autoloader.php`, la búsqueda manual de vendor en `bootstrap.php:21-29` y el escáner de `AuthService::ensureHybridauthLoaded()` (vendor ya viaja a dist/). **HECHO 2026-08-10** (autoload PSR-4 + `Autoloader.php` eliminado, verificado: el archivo no existe y `tests/bootstrap.php` carga `vendor/autoload.php`). Cierre 2026-08-12: el guard en `bootstrap.php:24-29` y `ensureHybridauthLoaded()` se **mantienen deliberadamente** como red de seguridad para entradas sin bootstrap (cron/tests) — dead code inofensivo, prioridad máxima a no romper el boot.
- [x] **P2-2** `Request::sanitize()` (`Request.php:209-218`) es no-op recursivo → eliminar (modelo: validar input, escapar output). **FIX 2026-08-12**: método y sus 2 llamadas eliminados (`Request.php`); sin usos en `app/` ni `tests/` (verificado con grep).
- [x] **P2-3** Hardcodes QloApps en `QloAppAdapter::confirmOrder()` (`:395-485`): `id_shop_group=1`, `id_shop=1`, `id_lang=1`, `id_currency=2`, `'USGAR Hotels'`, `'San Pedro'`, horarios 12:00/10:30 duplicados en 3 consultas → constantes del adapter o Config. **FIX 2026-08-12**: constantes `SHOP_GROUP_ID/SHOP_ID/LANG_ID/ORDER_STATE_PAID/CHECKIN_TIME/CHECKOUT_TIME/HOTEL_NAME/HOTEL_CITY` + variables interpolables en los SQL (los strings de doble comilla no expanden `self::CONST`). Bonus: `getAvailabilityCalendar()` respetaba `id_lang` hardcodeado 1 ignorando el parámetro/`QLOAPPS_DEFAULT_LANG_ID` — corregido. La moneda ya era dinámica (PEN, auditoría 2026-08-11). Test de regresión de interpolación: `tests/Unit/Features/Shared/QloAppAdapterSqlTest.php`. Queda anotado: XML del webservice (`createCartMulti:380`) conserva horarios literales — camino degradado (webservice da 500), se migra si se restaura.
- [x] **P2-4** `md5(rand())` como passwd de `qlo_customer` (`QloAppAdapter.php:396`): documentar por qué es inofensivo (cuenta guest) o generar hash válido de PrestaShop. **HECHO 2026-08-10** (`bin2hex(random_bytes(16))` para passwd/secure_key, comentario CWE-338 en `QloAppAdapter.php:486-491`). Cierre 2026-08-12 con docs en línea: PrestaShop 1.7 usa bcrypt para logins reales; los guests (`is_guest=1`) nunca se autentican → valor aleatorio CSPRNG es seguro y el formato 32-hex cabe en el schema.
- [x] **P2-5** `Router.php:125-127`: método no permitido responde 404 → `405 Method Not Allowed` + header `Allow`. **FIX 2026-08-12** (RFC 9110 §9.6.2, verificado con MDN/http.dev): `allowedMethods()` + respuesta 405 con `Allow` en `Router::dispatch`. Evidencia real: `php -S` + curl → `HTTP/1.1 405 Method Not Allowed` + `Allow: GET` (POST a ruta GET); GET health 200; ruta inexistente 404. Tests: `tests/Unit/Core/RouterMethodNotAllowedTest.php` (contrato observable; header no es testeable en CLI porque el banner de phpunit ya envió output — `headers_sent()`).
- [x] **P2-6** `request_id`: `x-request-id` ya está en CORS Allow-Headers pero nadie lo genera → middleware que lo genere + header de respuesta + contexto en cada log. **FIX 2026-08-12**: patrón generate-if-missing (http.dev/x-request-id): `Request::getRequestId()` (respeta header entrante o `bin2hex(random_bytes(16))`), `Middleware::requestId()` registrado en el pipeline, y `request_id` en el contexto de los logs de error del Router. Tests: `tests/Unit/Core/RequestIdTest.php`.
- [ ] **P2-7** `Response::json` hace `exit()` dentro de la clase (`Response.php:37-39`) → mover el exit al front controller. **DIFERIDO 2026-08-12 (deuda documentada, riesgo alto)**: ~40 call sites de `Response::json` asumen que la respuesta termina el script; mover el exit sin tocar todas las acciones es regresión garantizada. El guard `PHP_TESTING`/`APP_ENV=testing` ya hace la clase testeable. Revisar junto a un refactor mayor de acciones.
- [x] **P2-8** Documentar el contrato de escritura directa al schema `qlo_*` (riesgo de upgrades del PMS) en `docs/PMS.md`; evaluar webservice como alternativa (estratégico, no urgente). **HECHO 2026-08-12**: Sección F de `docs/PMS.md` (7 tablas, columnas, valores fijos, reglas antes de actualizar QloApps).
- [x] **P2-9** `use` sueltos a mitad de `public/index.php:85` → mover imports al inicio (estilo). **FIX 2026-08-12**: `UpdateUserProfileAction` movido al bloque de imports.

### P3 — Rendimiento y operación en Hostinger compartido
- [ ] **P3-1** Cache de display 30-60s (archivo en `data/`) para `GET /api/rooms` SOLO en el endpoint de display — nunca en `CreateBookingAction` (ya re-verifica con FOR UPDATE). + `Cache-Control`.
- [x] **P3-2** `Database.php:39-43`: sin `PDO::ATTR_TIMEOUT` → con la BD caída, 3 hosts × timeout TCP cuelgan requests → **FIX 2026-08-11 (commit `7472967`)**: `PDO::ATTR_TIMEOUT=3`. Efecto: la suite exhaustiva pasó de colgar >7 min a completar en <1 min.
- [ ] **P3-3** Rate limiter: archivos `limit_*.json` nunca se limpian → purgar >2 ventanas en `init()`; límites por endpoint (auth estricto, webhook/health exentos o altos).
- [ ] **P3-4** PHPStan nivel 6 → 7 (`phpstan.neon:5`) consumiendo el baseline progresivamente.
- [ ] **P3-5** composer.json sin `"php": ">=8.2"` ni `config.platform` → declararlos para reproducibilidad del lock.
- [ ] **P3-6** Suite `run-exhaustive-tests.php`: ya no cuelga (fix P3-2); sigue golpeando servicios reales si los tests de integración se extienden — si se añaden tests que toquen MP/QloApps, mockear los adapters (los unit ya son hermeticos: phpunit 160 tests/578 assertions sin red).
- [x] **P3-7** `ProcessOutboxActionTest::testConcurrentRunsProcessEachEventExactlyOnce` flaky en Windows (workers proc_open + timing 40s) → **FIX 2026-08-11 (commit `7472967`)**: timeout del worker 120s. Verificado: suite completa verde (160/578).

### F — Frontend y contenido
- [ ] **F-6** Duplicación `src/content/*.json` vs `src/data/*.ts` (rooms, services, faq, reviews, settings, about) → elegir una fuente por dominio y migrar consumidores.
- [ ] **F-7** Pendientes TS de `astro check` (hints pre-existentes en Hero.astro, RoomCard, TypographicMarquee, Layout, profile) — ver `docs/STATE.md`.

## Reglas del roadmap

- Marcar `[x]` solo cuando esté hecho y verificado (tests pasando).
- Una mejora = un commit `fix:`/`refactor:`/`perf:` por tema, con su test.
- Al cerrar un ítem: actualizar `docs/STATE.md` con la evidencia (file:line + comando de verificación).
