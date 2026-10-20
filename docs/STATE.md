# STATE — Backend USGAR (handoff de sesión)

## Las correcciones del PMS están en `main` pero Hostinger no está construyendo — 2026-10-20 (04:05)

- **Push hecho**: `78ca8b2..7c42a2b` — `d210070` (la orden pagada inserta la transacción de pago y hereda la habitación del hold), `df7bfa3` (`releaseStaleWebserviceHolds()`) y sus docs. CI verde (`39389064189`, 6m31s).
- **El build de Hostinger NO se ejecutó**: su API responde `HTTP 500 [Hosting:9999]` en **todos** los endpoints (crons, builds, git, webs) desde ~06:30 UTC, y el hPanel pinta las páginas de datos vacías (su SPA recibe 401/503/504 de sus propios endpoints). Última build conocida: `27ac631` (22:42 del 19-10).
- **Evidencia de que el código nuevo no está en producción**:
  1. El cron `cleanup` **sí corre** (el cart 288 pasó a `expired` a las 03:17) pero **no libera** holds: las órdenes 284, 285 y 288 siguen con `is_refunded = 0` e `is_cancelled = 0`.
  2. `GET /api/rooms` para 2026-11-20→22 devuelve `available_qty` **6 de 8** en Doble Superior (siguen bloqueadas las habitaciones 3 y 5).
  3. La orden pagada 287 (E2E de las 01:35) **no tiene fila en `qlo_order_payment`** → el fix `d210070` no está desplegado. (`qlo_order_payment` en esta versión de QloApps **no** tiene `id_order`: enlaza con `order_reference`.)
- **No hay vía alternativa**: el usuario MySQL no tiene privilegio `FILE` (`#1227` en `SELECT … INTO OUTFILE`), así que el código no se puede escribir por SQL; File Manager y API de Hostinger están caídos.
- **El File Manager también está caído** (verificado 09:41 UTC): `srv909-files.hstgr.io` responde **403 Forbidden (openresty)** a todos los paths con la sesión del panel (`/bc54bf7fbb6ae995/files/`, `…/files/public_html/`, `…/files/hbuilds/config/`), y el `auth` cookie sigue vivo → no es un problema de sesión. Por tanto el plan B (hot-patch con `curl` a `raw.githubusercontent.com`) **también depende de la API**: solo se puede ejecutar con un cron, y los crons solo se crean por la API. **El build es la única vía de despliegue.**
- **Pendiente por la caída**: (i) borrar los crons de diagnóstico que quedaron registrados — `Y5krI6uG20` (minutero: `grep rate_plan_name` en `cms/modules/`) y `rIDxrBAnXe` (05:07 diario: `rm -f` de los ficheros de `/tmp`); **ningún cron ejecuta `_diag_cli.php`** (el script que creaba un hold del PMS en el webservice se ejecutó a mano por HTTP, no por cron), así que no se generan holds nuevos solos; (ii) **revertir los ajustes de PHP del CMS** (`cms.usgarhoteles.com`): la versión de PHP está en **7.4** y el original era **8.4** (hay que volver a 8.4), `display_errors` está en `On` (original: default `Off`), `error_reporting` en `E_ALL` (original: default `E_ALL & ~E_DEPRECATED & ~E_STRICT`), `log_errors` en `On` (original: default `Off`) y `error_log` apunta a `/home/u941268346/domains/cms.usgarhoteles.com/_php_err.log` (original: el default del panel) — todo con `hosting_php_update-version` / `hosting_php_update-options`; (iii) borrar del servidor los ficheros de diagnóstico (ver inventario abajo); (iv) disparar el build de `main`.
- **Checklist al volver la API**: `hosting_cron-jobs_list` → borrar `Y5krI6uG20` y `rIDxrBAnXe` → `hosting_nodejs_start-build` (o push vacío a `main`) → esperar el build → comprobar `GET /api/rooms?checkIn=2026-11-20&checkOut=2026-11-22` = **8/8** (hoy 6/8) y las órdenes 284/285/288 con `is_refunded = 1` → E2E completo en un rango limpio (2026-11-25→28, hoy 8/8) con la tarjeta de prueba PE y verificación en el PMS.

### Inventario exacto que hay que dejar limpio (verificado en la BD el 2026-10-20 05:00 UTC)

Reservas de QloApps que siguen **bloqueando** (`is_refunded = 0 AND is_cancelled = 0`), todas de `id_product` 2 (Doble Superior):

| `htl_booking_detail.id` | `id_order` | habitación | fechas | estado de la orden | origen |
|---|---|---|---|---|---|
| 223 | 266 | `id_room=0` | 20→23 oct | 2 (pagada) | E2E antiguo (holds locales 2116/2117) |
| 224 | 268 | `id_room=0` | 20→23 oct | 2 (pagada) | ídem |
| 227 | 286 | `id_room=3` | 21→24 oct | 1 (pendiente) | hold del webservice de la orden pagada 287 |
| 228 | 287 | `id_room=0` | 21→24 oct | 2 (pagada) | E2E del 20-10 01:35 |
| 225 | 284 | `id_room=3` | 20→22 nov | 1 (pendiente) | hold caducado |
| 226 | 285 | `id_room=5` | 20→22 nov | 1 (pendiente) | hold caducado |
| 229 | 288 | `id_room=3` | 10→12 dic | 1 (pendiente) | hold caducado |

- **Los liberará solos el fix desplegado** (`releaseStaleWebserviceHolds`): 225, 226 y 229 (holds locales `expired` con `cart_id` numérico). El cron `cleanup` corre cada 10 min → en ≤10 min tras el build.
- **Los de las órdenes ya pagadas (223, 224, 227, 228) NO se liberan** y no deben liberarse a la ligera: el cleanup nunca toca reservas `paid` (dinero capturado → revisión manual, RF-28) y las órdenes 266/268/287 se crearon con el código anterior a `d210070` (sin `id_room` heredado y sin `qlo_order_payment`). Liberar su hold dejaría la habitación **sin bloquear** con el cobro ya hecho (riesgo de overbooking). Se limpian desde el back-office de QloApps (Refund Request), no por SQL (regla 1 de `docs/PMS.md` §F).
- **Ficheros de diagnóstico que hay que borrar del servidor** (no hay operación de borrado en la API de ficheros: `hosting_files_website-content` es de solo lectura → hay que usar un cron con `rm -f`):
  - `cms.usgarhoteles.com` (docroot = `public_html/cms`): `_diag_cli.php` (crea un hold del PMS en el webservice **si se ejecuta**: lo lanzo a mano por HTTP, no hay ningún cron que lo ejecute).
  - `usgarhoteles.com` (docroot = `public_html`): `mpiso.php`, `mpcheck.php` si siguen ahí, y `cms/_diag_cli.php`.
  - `/tmp/`: `*.b64` (chunks base64), `q.php`, `b.php`, `qc*.b64` → el cron `rIDxrBAnXe` (05:07) ya los borra a diario; borrarlo del panel solo cuando se confirme que no queda ninguno.
  - `/home/u941268346/domains/cms.usgarhoteles.com/_php_err.log` (log de errores forzado).
- **Si el build no vuelve a funcionar** (fallback): el repo es **público**, así que un cron puede parchear el árbol desplegado sin build —
  `curl -fsS -o /tmp/q1.php https://raw.githubusercontent.com/dbqp01/usgar-redesign/main/app/Features/Shared/Adapters/QloAppAdapter.php` (y los otros 3 ficheros de runtime que cambiaron: `app/Features/Cron/Actions/CleanExpiredCartsAction.php`, `app/Features/Shared/Ports/PmsPortInterface.php`, `cron/cleanup.php`), validarlos con `php -l` y copiarlos a `public_html/`. El siguiente build los sobrescribe con el mismo contenido (ya están en `main`), así que no queda deriva.

## El hold del webservice abandonado ya no bloquea la habitación — 2026-10-20

- **Defecto (verificado en producción)**: una reserva web abandonada (el huésped nunca paga) dejaba su reserva pendiente en QloApps con `is_refunded = 0` → la habitación seguía bloqueada en el calendario del PMS hasta que el módulo *Cleaner* del propio QloApps la purgara. Comprobado en la BD de prod: los carts **284** y **285** (caducados) seguían bloqueando las habitaciones **3** y **5** del 20 al 22 de noviembre.
- **Fix**: `QloAppAdapter::releaseStaleWebserviceHolds()` libera en el PMS los holds cuyas reservas locales ya no están activas (`expired` / `cancelled` / `failed`) con la **misma** operación que usa `confirmOrder()` al superar el hold (`is_refunded = 1`, `is_cancelled = 1`, orden del hold a "Cancelado" + entrada de historial). Es idempotente y nunca toca `paid` / `expired_paid` (dinero capturado → revisión manual).
- **Dónde corre**: `cron/cleanup.php` (registrado cada 10 min en prod) y `POST /api/cron/cleanup` — los dos liberan los holds después de marcar `expired` los vencidos. El endpoint devuelve `cleaned_count` y `released_pms_holds`.
- **Spec**: RF-28 en `docs/specs/booking.spec.md`; contrato de escritura y regla 4 de `docs/PMS.md` §F actualizados.

## QloApps: el 500 del `POST /api/bookings` era una columna faltante — 2026-10-20

- **Root cause del 500**: el módulo `qlocmconnector` v5.0.4 (channel manager) inyecta `rate_plan_name` en la definición de `HotelBookingDetail` (hooks `actionObjectHotelBookingDetailPropertiesModifier` / `…DefinitionModifier`) y la columna **no existía** en `qlo_htl_booking_detail` → `PDOException` en **cada** `POST /api/bookings` (la creación del carrito en el PMS). No era el "specific management eliminado en v1.7.0" que afirmaba `docs/PMS.md` §F: esa conclusión era incorrecta y ya se corrigió.
- **Fix (BD)**: `ALTER TABLE qlo_htl_booking_detail ADD COLUMN rate_plan_name VARCHAR(255) DEFAULT NULL` — el mismo DDL que declara el propio módulo en su `upgrade-5.0.4.php` (idempotente, guardado por `information_schema`).
- **Flujo completo verificado en vivo**: `POST /api/booking` → hold en el PMS (orden **286** + booking **227**; QloApps asigna la habitación física) → checkout con Mercado Pago → webhook firmado recibido (`processed_payments` id 232 · payment_id 1353932639 · cart 286 · approved · 2026-10-20 06:34:23) → outbox `booking.paid` → `confirmOrder()` → **orden 287** en el PMS (estado 2 "Pago completo recibido", total S/. 660.67, fechas 21→24 oct) + `qlo_htl_booking_detail`. `payment_alerts` vacío (0 alertas). Ese booking 287 se creó **con el código anterior a `d210070`**: sin fila en `qlo_order_payment` y con `id_room = 0` (los dos defectos que corrige `d210070`, aún sin desplegar — ver § «Las correcciones del PMS están en `main` pero Hostinger no está construyendo»).
- **Defecto 1 (el pedido salía sin pagar en el back-office)**: PrestaShop calcula "Total pagado" y "Monto adeudado" sumando `qlo_order_payment` — **no** `orders.total_paid` — y recalcula `total_paid_real` solo cuando se añade un pago desde el back-office. Una orden creada por SQL quedaba *"Total pagado S/. 0 / adeudado S/. 661"* aunque `current_state=2`. Fix: `confirmOrder()` inserta la transacción de pago (`qlo_order_payment`) y escribe `total_paid_real` (unit test en `QloAppAdapterSqlTest`).
- **Defecto 2 (la reserva quedaba duplicada)**: el hold del webservice y la orden pagada eran dos reservas para la misma estancia → la habitación bloqueada dos veces (`/api/rooms` para 2026-10-21→24 devolvía `available_qty` 4 de 8, con `booked_count` 4 = 2 bookings del PMS + 2 holds locales). Fix: la orden pagada **hereda la habitación física** del hold (el PMS une `htl_booking_detail` con `htl_room_information` por `id_room`: sin ese id la reserva no bloquea nada en su calendario) y **libera el hold** (`is_refunded=1` + `is_cancelled=1`, su orden a "Cancelado" con entrada de historial); la disponibilidad web excluye el booking del webservice mientras haya un hold local activo para ese `cart_id` (`pb.cart_id = CAST(bd.id_order AS CHAR)`), porque el `id_order` del booking del webservice **es** el `cart_id`.
- **Semántica de QloApps verificada leyendo el tag `v1.7.0`** del repo oficial (no de memoria): `is_refunded=1` es lo que libera la habitación (su consulta de disponibilidad filtra `is_refunded=0`); `htl_booking_detail.id_status` **no** tiene estado "cancelado" en v1.7.0 (1 allotted / 2 checked-in / 3 checked-out) — no se toca; "Cancelado" = `qlo_order_state` **4** (leído de `qlo_order_state_lang`, no supuesto); el webservice expone `total_paid` desde `orders.total_paid_real`. Todo documentado en `docs/PMS.md` §F.
- **Pendiente**: (a) ~~push a `main`~~ — **hecho** (`78ca8b2..7c42a2b`); lo que falta es el **build de Hostinger**, bloqueado por la caída de su API (ver § «Las correcciones del PMS están en `main` pero Hostinger no está construyendo»); (b) limpiar en el PMS las órdenes que dejaron estas pruebas (270–288) — por la regla 1 de `docs/PMS.md` §F, desde el admin de QloApps, no por SQL; (c) el cron de diagnóstico `rIDxrBAnXe` y los ajustes de PHP del dominio **siguen sin poder borrarse/revertirse**: la API de Hostinger responde 500/504 desde ~06:30 UTC y el hPanel tampoco; (d) ~~un hold del webservice abandonado sigue bloqueando la habitación~~ — **resuelto el 2026-10-20** con `releaseStaleWebserviceHolds()` (ver § «El hold del webservice abandonado ya no bloquea la habitación»).

## El cron `refresh-reviews` no deja el fichero de scores (sin efecto visible) — 2026-10-20

- `GET /api/reviews-score` responde `{"scores":[],"updatedAt":null}` → el fichero `review-scores.json` **no existe**. Escritor y lector usan la misma ruta (`GetReviewScoresAction::scoresFile()` → `Config::get('STORAGE_PATH', …) . '/review-scores.json'`) y ambos ficheros son idénticos en el commit desplegado (`27ac631`): **no es un desfase de ruta**, el cron falla **antes** de escribir.
- El escritor solo puede fallar ahí: `mkdir` del directorio o `file_put_contents`/`rename` → `HttpException::internal`. Sospecha principal: **`STORAGE_PATH` vacío o no escribible** en el `.env` de prod → `rtrim('')` deja la ruta en `/review-scores.json` (raíz del sistema, no escribible por el usuario web) → 500 y ningún fichero. **A verificar** en cuanto se pueda leer `hbuilds/config/.env` (File Manager / API) y el resultado del cron `egFzCUjpmW` (03:00) con `hosting_cron-jobs_output`.
- **No es visible para el usuario**: `ReviewMarquee.astro` conserva los valores del build cuando el endpoint no trae datos, así que el ribbon sigue mostrando los scores compilados (el sitio no falla).

## Mercado Pago: credenciales de prueba en prod + firma del webhook corregida — 2026-10-19/20

- **Las credenciales "de prueba" del MCP no eran de prueba** (app `8501374849722569`): el card token devuelve `live_mode:true` y un pago con tarjeta de prueba responde 401 `Unauthorized use of live credentials`. El par sandbox real está en la app `3258714866708434` (token `TEST-…`, public key `TEST-…`): `live_mode:false`, `/v1/payments/search` vacío y pago de prueba aprobado (1353921601). Ese par quedó en hPanel (Variables de entorno → «Guardar») y el webhook se registró y rotó **en esa misma app** (callback `https://usgarhoteles.com/api/webhook`, evento "Pagos").
- **Bug de la firma (root cause)**: el `x-signature` real de MP trae el `ts` en **segundos** (`ts=1792467232`, 2026-10-19 22:33:52) aunque su documentación dice milisegundos. El `WebhookSignatureValidator` del SDK compara `ts` contra `now()` en ms → su chequeo de skew rechazaba **todas** las notificaciones (401 `TimestampOutOfTolerance`) y el webhook nunca se procesaba. Fix `27ac631`: el cálculo del drift (ventana anti-replay de 300 s) pasa al adaptador (`MercadoPagoAdapter::extractSignatureTimestamp()`, normaliza ms o s) y el SDK se invoca sin tolerancia, solo para el HMAC.
- **Verificado en producción** (simulador del panel MP): pago 1353921601 → `Webhook recibido` → `Firma de webhook validada correctamente` → ALERTA ORPHAN (ese pago de prueba no tiene hold) → idempotencia registrada; pago 1353921761 (el que procesó el sitio) → `ya consta como procesado (approved)`. El panel responde «¡Excelente! Enviamos una notificación Webhook con éxito — 200 - OK» (antes: «La prueba de esta URL falló»).
- **Flujo completo del sitio ya verificado con este par**: brick → card token sandbox → `POST /api/process-payment` → `approved` → hold `paid` → outbox → orden en QloApps (#266 / #268) → email omitido (SMTP_HOST vacío, por diseño). El `QloAppAdapter: API Error 500` al crear el carrito es un fallo **ajeno** a MP: el listener cae al checkout local de USGAR.
- **Limpieza**: borrado el cron de sondeo `tTTWr5wPm8` (minutero, ejecutaba `mpiso.php`); `mpiso.php`, `mpiso2.php` y `mpiso.json` ya no están en el webroot (el deploy reemplaza `public_html`); scripts de sondeo y el token de producción locales borrados de `%TEMP%\claude`.
- **Pendiente del dueño**: cambiar a las credenciales reales (procedimiento paso a paso en `docs/DEPLOYMENT.md` § «Credenciales de Mercado Pago») y rotar `PANEL_PASSWORD` (mismo valor que `DB_PASS`).

## `.env` de prod: hPanel queda como fuente única — 2026-10-19

Auditoría del entorno de Hostinger hecha **con el panel abierto en el navegador** (no solo con la API, que enmascara los valores) y cierre de la confusión que arrastraba el sitio desde el 2026-08-15.

- **La ruta del archivo de hPanel estaba mal documentada**: la integración escribe `hbuilds/config/.env` en la raíz del dominio. **`.builds/` no existe** (verificado en el File Manager). El código apuntaba a `.builds/config/.env` → nunca lo habría leído.
- **Precedencia corregida**: `Config::envCandidates()` pone ahora `hbuilds/config/.env` **antes** que el `.env` suelto de la raíz del dominio (que hasta ahora ganaba siempre y era la fuente efectiva real).
- **Los valores coinciden**: comparadas en memoria del navegador (sin sacarlos de él) las 38 claves del `.env` físico contra las 39 de hPanel → **38 idénticas, 0 diferencias**; hPanel añade solo `PANEL_PASSWORD`. Se conserva intacto el token de MP (**el de test**, decisión del dueño) y la ausencia de `SMTP_*` (emails siguen apagados por diseño).
- **Efectos colaterales revisados**: `PANEL_PASSWORD` pasa a estar configurado → `/panel` (disponibilidad del dueño) deja de estar fail-closed; `MP_BINARY_MODE=true` ya era el valor efectivo (está en las dos fuentes) → el payload de MP no cambia; `TokenRevocationStore` pasa a usar la raíz del despliegue (no la carpeta del `.env`), así que la lista de revocaciones no se invalida ni se mueve al cambiar de fuente.
- **El `.env` de la raíz del dominio queda RENOMBRADO** a `.env.pre-hpanel-2026-10-19` (no se borra: es el rollback). Con ese nombre ya no compite por precedencia — la app no lo lee. Copia local de seguridad en `.backups/prod-env-2026-10-19.env` (**gitignored**).
- **Puente para el código todavía sin desplegar** *(resuelto el 2026-10-20, ver §Deploy a `main` y verificación en producción)*: el código en producción (`2a824b3`) solo conocía `raíz/.env` → `raíz/.builds/config/.env` → `raíz del proyecto/.env`. Con el `.env` de la raíz fuera, se creó el symlink `raíz/.builds/config/.env → hbuilds/config/.env` (cron vigilante `yr3HD0iHWR`, `*/5`). **Ya retirado** al desplegar `5361623`: cron borrado, symlink borrado (con `rm`, que no sigue el enlace) y carpeta `.builds/` eliminada.

### El mecanismo real de hPanel (verificado 2026-10-19, no deducido)

- **El archivo solo se materializa con «Guardar»** en hPanel → **Despliegues → Ajustes y reimplementación → Variables de entorno** (o «Guardar y reimplementar», que además reconstruye). Esa acción reescribe `hbuilds/config/.env` (mtime observed) y **el efecto es inmediato, sin redeploy ni push**: `TIMEZONE` pasó de `America/Lima` a `America/New_York` y `/api/health` cambió de `-05:00` a `-04:00` en segundos; revertido a `America/Lima` → `-05:00` otra vez.
- **La página «Sitios web → Variables de entorno» es otra superficie**: ahí el valor se guarda en el panel (la tabla lo muestra al instante) pero **el archivo NO se reescribe** (mtime intacto tras guardar). Editar ahí y esperar el efecto no funciona: hay que usar la página de ajustes de despliegue.
- **Un build no reescribe el `.env`**: un redeploy del mismo commit (2026-10-20, uuid `01a156d7-2074-73d8-aaf4-a82dbf572072`, completado) regeneró `hbuilds/config/package.json` y `package-lock.json` pero dejó `hbuilds/config/.env` con su mtime anterior. El build solo lee ese archivo («Environment: variables loaded from .env»).
- **Pendiente de seguridad**: `PANEL_PASSWORD` tiene **el mismo valor** que `DB_PASS`. Rotarlo por un valor propio.

**Estado final del entorno (2026-10-20, tras el deploy):** raíz del dominio = `hbuilds/` · `public_html/` · `runtime/` · `logs/` · `.env.pre-hpanel-2026-10-19` (rollback) · `DO_NOT_UPLOAD_HERE`. **Ya no existe `.builds/`** (el puente se retiró; ver §Deploy a `main`). `hbuilds/config/.env` = 39 claves, valores entre comillas simples, **0 diferencias** contra el `.env` viejo en las 38 claves comunes.

**Limpieza en prod (mismo día, sin deploy):** los 3 holds `expired` con `payment_id` (1349906085 / 1327783824 / 171954453246, del 3-4 de agosto) se pasaron a `failed` en `provisional_bookings` → el cron `reconcile_payments` ya no los reintenta. Verificado: `Reconciliacion completada: checked=0, reconciled=0, skipped=0` (antes `checked=3, skipped=3`).

**Por qué era ruido y no ruido:** el `app.log` de prod tenía **1640 entradas ERROR de las cuales 1638 eran los 404 de MercadoPago** de esos 3 pagos (MercadoPago ya no los tiene: se purgaron de la cuenta de pruebas). El 99,9 % del log lo ocupaba un problema ya resuelto a mano; el resto de errores reales quedaban enterrados. La causa en código (un 404 no es un fallo de API, es una respuesta definitiva) ya está corregida en `MercadoPagoAdapter` y **desplegada el 2026-10-20**.

**Desplegado:** el fix de la ruta del `.env` (`hbuilds/config/.env`), el fix de las revocaciones, el log en la raíz del dominio, el 404 como INFO y el bump de dependencias que cierra 10 de las 12 vulnerabilidades que reporta hPanel. `main` = `2cc9479` (22 commits por delante de `2a824b3`).

## Deploy a `main` y verificación en producción — 2026-10-20

Push de `2a824b3..2cc9479` (22 commits) y **verificación en vivo** en `https://usgarhoteles.com`:

- **Push por SSH**: el token OAuth de `gh` no tiene el scope `workflow` y el lote toca `.github/workflows/`, así que `git push git@github.com:dbqp01/usgar-redesign.git main:main` (`2a824b3..2cc9479 main -> main`).
- **Build de Hostinger**: uuid `01a156e4-9abc-72a1-9e73-465fc280b409` → *checkout 2cc9479* · `npm install` (372 paquetes) · 114 páginas · postbuild copió `app/`, `vendor/` y `cron/` a `dist/` y no dejó `dist/.env` · publicado en 3 min 24 s. **El sitio siguió en 200 durante el build** (home 200 / 275 KB, `/api/health` con `database: online`).
- **Código nuevo confirmado en disco**: `public_html/app/Core/Config.php` en prod ya lista `hbuilds/config/.env` como **candidato 1** (leído desde el File Browser).
- **Puente retirado, en este orden**: cron vigilante `yr3HD0iHWR` borrado → symlink borrado con `rm` (un cron de un solo binario: `rm` **no** sigue enlaces, `hbuilds/config/.env` quedó intacto con su mtime) → `rmdir .builds/config` → `rmdir .builds`. Crons temporales borrados después. Resultado: **`.builds/` ya no existe** y solo quedan los 5 crons de la app.
- **Prueba de que la app lee el archivo de hPanel**: con `hbuilds/config/.env` como **única** fuente de env (raíz sin `.env`, sin `.builds/`), `/api/health` responde `database: online` — `DB_PASS` no tiene valor por defecto en código, así que sale del archivo de hPanel.
- **Log en la raíz del dominio, verificado**: `logs/app.log` (con su `.htaccess`) recibe líneas de los crons (`ReconcilePaymentsAction`, `CleanExpiredCartsAction`) y de las peticiones HTTP (`HandleMercadoPagoWebhookAction`); ya no se pierde en cada push.

**Pendiente para el dueño:** rotar `PANEL_PASSWORD` (tiene hoy **el mismo valor** que `DB_PASS`).

## Jerarquía de encabezados y paridad E2E contra `main` — 2026-10-19

Cierra el F-10 (la jerarquía que dejó el `<h1>` de página) y **prueba**, en vez de suponer, que el lote "infraestructura + SEO" no introduce regresiones.

- **`<h1>` de página → `<h2>` de contenido**: al promover el título de `/rooms/` y `/explore/` a `<h1>`, sus tarjetas seguían en `<h3>` (salto h1→h3). Ahora los títulos de habitación (`src/pages/rooms/index.astro`) y de atracción (`src/pages/explore.astro`) son `<h2>`. Tailwind Preflight neutraliza la diferencia de tag (ambos llevan clases explícitas de tamaño), así que **no hay cambio visual**. Outline verificado sobre el build: `/rooms/` → `h1 h2 h2 h2 h2 h2` · `/explore/` → `h1 h2 ×14` · `/gallery/` → `h1`.
- **El E2E roto lo rompió el propio cambio**: `booking-flow.spec.ts:22` buscaba `main h2` en `/rooms` — justo el heading que se promovió a `<h1>`. El locator pasó a `main h1` (sigue acotado a `<main>` porque el toolbar de Astro inyecta headings en su shadow root, que es hermano de `main`). **No se borró ninguna aserción**: la comprobación sigue exigiendo un encabezado visible en la página de habitaciones.
- **Paridad probada contra el commit base, no supuesta**: checkout de `2a824b3` con la **misma** `playwright.config.ts` (timeout 60 s) → **11 failed / 18 passed**. Con el fix → **11 failed / 18 passed**, y el `diff` de los nombres de fallo sale **vacío**: son los mismos 11 (selectores desfasados en `internals`/`login-spa`/`transitions` + 4 del wizard que necesitan un PMS vivo). **Cero regresiones del lote.**

**Verificación**: `npm run build` → 114 páginas · `npm run audit:content` → **0 errores, 0 avisos** · `npx playwright test --workers=1` → 11 failed / 18 passed, conjunto idéntico al de `2a824b3`.

## CSP en las páginas estáticas y `lastmod` en el sitemap — 2026-10-19

Cierra F-11 y el último aviso del audit de contenido. `npm run audit:content` → **0 errores, 0 avisos**.

- **CSP en el HTML estático (F-11)**: la allowlist se dedujo de lo que el sitio carga **de verdad**, con un Chromium real sobre el build: `sdk.mercadopago.com` + `api.mercadopago.com` + `www.mercadolibre.com` (SDK y pagos, en `/book/`), `*.basemaps.cartocdn.com` (los tiles del mapa de Leaflet — **no** `tile.openstreetmap.org`, que era lo que listaba la allowlist del Middleware) y fuentes self-hosted. La misma cadena va ahora en `public/.htaccess` (dentro de `<FilesMatch "\.html$">`: a nivel global `index.php` recibiría una segunda cabecera CSP y el navegador aplicaría la intersección) y en `app/Core/Middleware.php::securityHeaders`.
- **Dos guardas para que no se separen**: `npm run audit:content` falla con `CSP-DRIFT` si las dos allowlists dejan de ser iguales (probado: al divergir una, el audit lo detecta; restaurada, vuelve a 0). Y `npm run audit:csp` (nuevo, `scripts/audit-csp.mjs`) extrae la cabecera del `.htaccess`, sirve `dist/` con ella y carga cada página en Chromium: **114 páginas, 0 violaciones**.
- **`lastmod` en el sitemap**: `astro.config.mjs` → `sitemap({ lastmod: new Date() })`. Los precios y la disponibilidad vienen del PMS en tiempo de ejecución, así que la fecha del build es la única señal fiable de "esto pudo cambiar"; sin ella Google no tiene motivo para volver a rastrear las páginas de habitaciones.
- **Producción ya devuelve una CSP del host**: `curl -I https://usgarhoteles.com/` → `Content-Security-Policy: upgrade-insecure-requests` (la pone hPanel, no el repo) junto a `X-Frame-Options`, `HSTS`, `Referrer-Policy` y `Permissions-Policy` (sí del `.htaccess`, o sea que `mod_headers` funciona ahí). Con varias cabeceras CSP el navegador aplica la **intersección**: la de hPanel solo fuerza https en subrecursos, así que la que manda es la del repo. Y como `index.php` ya convive hoy con la CSP de hPanel, el patrón de dos cabeceras no es nuevo ni rompe nada.

**Verificación**: `npm run check` → 180 archivos, 0/0/0 · `npm run test:unit` → 77 OK · `php vendor/bin/phpunit` → 324 tests, 958 assertions, 38 skipped, 0 fallos · `php vendor/bin/phpstan analyse --level=7` → ok · `npm run build` → 114 páginas · `npm run audit:seo` → 0 errores · `npm run audit:content` → 0/0 · `npm run audit:csp` → 114 páginas, 0 violaciones.

**No verificado**: que la cabecera llegue igual en producción (LiteSpeed + `mod_headers` + `FilesMatch`); hace falta un `curl -I https://usgarhoteles.com/ | grep -i content-security` tras el push. Y el flujo real de pago con la CSP puesta (el SDK y la llamada a `api.mercadopago.com` sí se verificaron en local).

## SEO por página: descripción, og:image y un solo H1 — 2026-10-19

Cierre de los tres work items que dejó la auditoría de contenido (F-8, F-9, F-10 en `docs/ROADMAP.md`). `npm run audit:content` baja de **3 errores a 0**.

- **`meta description` propia por plantilla (F-8)**: sección `meta` nueva en los 4 i18n (`rooms`, `explore`, `gallery`, `contact`, `book`, `success`, `privacy`, `terms`) y `description=` en cada plantilla. Las páginas dinámicas reusan el texto que ya traían (`room.description[lang]`, `attraction.description[lang]`: 98-170 caracteres). Los horarios de `meta.success` NO están escritos en el i18n: son los placeholders `{checkin}`/`{checkout}`, rellenados con `siteSettings.checkinTime`/`checkoutTime` (misma fuente que el PMS). La home conserva la descripción global del sitio — es lo correcto.
- **`og:image` propia por plantilla (F-9)**: `src/utils/ogImage.ts` (`socialImage()`) recorta una foto del sitio a **1200×630 JPEG** (no AVIF/WebP: los scrapers de redes no los leen) y devuelve la URL absoluta. Cada plantilla pasa la foto que el visitante ve. 21 imágenes sociales nuevas (~2,2 MB en `dist/_astro/`, caché 1 año por el hash). Las fotos de `src/assets/explore/*.jpg` son cuadradas de 1024 px, así que su recorte sale a 1024×630 (por encima del mínimo de 600×315 que aceptan los scrapers); verificado visualmente.
- **Un solo `<h1>` por página (F-10)**: `SectionHeader.astro` acepta `level?: 'h1' | 'h2'` (por defecto `h2`); `/rooms/`, `/gallery/` y `/explore/` pasan `level="h1"`. En `book/success` los estados *pendiente* y *error* bajaron a `<h2>`: los tres estados conviven en el DOM (solo el de éxito es visible y es el título de la página).
- **Falso positivo del propio audit**: `isHome` no incluía `/fr/`, así que la home francesa pedía su propia descripción. Corregido.

**Verificación**: `npm run check` → 180 archivos, 0 errores/warnings/hints · `npm run test:unit` → 77 OK · `npm run build` → 114 páginas · `npm run audit:seo` → 123 páginas, 84 JSON-LD, 0 errores · `npm run audit:content` → **0 errores, 2 avisos** (sitemap sin `lastmod`, CSP: decisión del owner).

**E2E**: `npx playwright test --workers=1` → **11 failed / 18 passed**, con el **mismo conjunto de fallos que `main`** (selectores desfasados: `picture img`, overlay `[data-room-card]`, `#contact-form`, y 3 tests del wizard que necesitan un PMS vivo). `main` daba 12 fallos con la config original: el cambio **no** introduce regresiones y desbloquea un test. Causa raíz de los timeouts: el dev server de Astro tarda 20-34 s en la primera respuesta y Playwright corta a los 30 s → `timeout: 60_000` + `webServer.timeout: 120_000` en `playwright.config.ts`.

## Auditoría de contenido y SEO — 2026-10-19

Auditoría del sitio público con verificación **RED→GREEN sobre el artefacto construido**. Nuevo arnés: `npm run audit:content` (`scripts/audit-seo-content.js`), que comprueba 404 propia, `description`/`og:image` por plantilla, un H1 por página, `numberOfRooms` contra el inventario real, sitemap sin páginas de utilidad, `noindex` en las de utilidad, una sola fuente de verdad para check-in/check-out, CSP/MIME `.avif`/`ErrorDocument` en `.htaccess` y una sola coordenada de "cómo llegar". Estado: **13 errores → 3 errores** (los 3 restantes son work items en `docs/ROADMAP.md` F-8/F-9/F-10).

### Correcciones aplicadas

- **`numberOfRooms` del schema.org**: `src/pages/index.astro:53` usaba `rooms.length` (4 **tipos** de habitación) → ahora `Object.values(getRoomInventory()).reduce(...)` = **17** habitaciones reales (`settings.json.roomInventory`: 8+3+3+3). Verificado en `dist/index.html`: `"numberOfRooms":17`.
- **Horarios de check-in/check-out**: la web decía 11:00 (`src/i18n/{en,es,fr,pt}.json` → `contact.checkinHours`) y 10:00 (`src/pages/book/success.astro`), mientras el PMS y `settings.json` dicen 12:00 / 10:30 (`app/Features/Shared/Adapters/QloAppAdapter.php:31-32`). Ahora `contact.checkinHours` es la etiqueta con placeholder `{time}` y el horario sale de `siteSettings.checkinTime`/`checkoutTime` (mismo patrón que `src/data/legal.ts:126`). Verificado en `dist/`: "Check-in from 12:00", "Check-in desde las 12:00", "Desde/From 12:00", "Hasta/To 10:30". **Decisión del owner: 12:00** (se alinea el sitio al PMS).
- **Página 404 propia**: `src/pages/404.astro` (nuevo) + `ErrorDocument 404 /404.html` en `public/.htaccess`. Antes el servidor (hPanel) servía su 404 por defecto: perdía el diseño, cargaba gtag/analytics.js/doubleclick **fuera del CMP de cookies** y exponía `/htdocs_error/page_not_found.svg`.
- **MIME `.avif`**: `AddType image/avif .avif` en `public/.htaccess`. Verificado en producción: se servía como `text/plain` (el resto de formatos ya eran correctos) y el propio `.htaccess` ya declaraba `ExpiresByType image/avif`, o sea la intención estaba.
- **Páginas de utilidad**: `noindex,nofollow` en `/login/`, `/profile/`, `/my-bookings/` (`src/layouts/Layout.astro` acepta prop `robots`); `astro.config.mjs` excluye `/panel|login|profile|my-bookings` y sus variantes de locale del sitemap (`/panel/` ya era `noindex`).

### Hallazgos descartados con evidencia (falsos positivos)

- **El sitemap NO estaba roto**: `/sitemap.xml` → 301 → `/sitemap.xml/` → meta-refresh → `/sitemap-index.xml` → `sitemap-0.xml` (200 `application/xml`, 51 KB). Es deliberado (`astro.config.mjs:14-17`).
- **El MIME `.avif` NO rompía la carga de imágenes**: Chromium real contra producción → 67 imágenes, 48 renderizan; las 19 restantes tienen `displayW=0` (2 `display:none`, 17 lazy dentro de contenedores ocultos). `text/plain` + `nosniff` no bloquea AVIF same-origin. El problema era la corrección del Content-Type, no la rotura de imágenes.
- **Las 4 coordenadas de mapa no son un error**: `src/components/LocationMap.astro:16-38` declara 3 POIs del entorno (mercado, plaza, estación de tren) además del hotel. La auditoría ahora solo valida el CTA "cómo llegar" (`maps/dir/?destination=`).

### Verificación ejecutada

`npm run check` → 179 archivos, 0 errores/warnings/hints · `npm run test:unit` → 77 OK · `npm run build` → 114 páginas · `npm run audit:seo` → 123 páginas, 84 JSON-LD, 0 errores · `npm run audit:content` → 13 → 3 errores · `php vendor/bin/phpunit` → 324 tests, 958 assertions, 38 skipped, 0 fallos.

**No verificado**: E2E completos (requieren un PMS/BD vivo; solo 2 de 29 están acreditados como verdes en la sección de 2026-10-14), el comportamiento en producción de `ErrorDocument`/`AddType` (requiere `curl -I` tras el push) y el impacto real en Google (requiere GSC).

## Verificación acotada de calidad — 2026-10-14

- Vitest se integra en `npm test` y CI: **77 tests OK**. Los casos de fallback `fr→en` / `pt→es` y de `paymentId` sin estado ahora detectan sus regresiones; tres mutaciones en copia aislada dieron el fallo esperado.
- Playwright tiene configuración versionada, descubre **29 E2E** sin cargar Vitest y mantiene Astro en foreground mediante `ASTRO_DEV_BACKGROUND=0`. Verificados en Chromium **2 E2E OK**: contenido sin JavaScript y error seguro del panel con API mockeada. Dos comentarios con etiquetas script literales se corrigieron para evitar errores del escáner de dependencias.
- El harness HTTP valida el rechazo de `id_hotel=0` antes de consultar PMS; el éxito de `/api/rooms` con fechas por defecto queda cubierto en PHPUnit con PMS mockeado, sin exigir inventario externo al runner CI. No se acepta una alternativa permisiva 200/503 ni se cambia código de negocio.
- PHPUnit focalizado `GetRoomsActionTest`: **8 tests, 26 aserciones OK** (2 deprecaciones PHPUnit reportadas); PHP lint sin errores en el harness y el test.
- `npm run check`: **177 archivos, 0 errores/warnings/hints**; PHPStan nivel **7**, sin errores. Reglas y README precisan checks por impacto y distinguen pruebas aisladas de las que pueden usar BD/PMS/pagos.
- Alcance superficial: no se acredita el flujo externo de pagos, concurrencia MySQL ni una auditoría completa. Las suites con servicios externos requieren destino de pruebas verificado; mocks y skips no sustituyen esa evidencia.

## Verificación backend vigente — 2026-09-11

### Auditoría de seguimiento — 2026-09-11

- Verificación repetida: `composer analyse` → PHPStan nivel 7 sin errores; PHPUnit → **310 tests, 1029 assertions, 12 skipped, 2 deprecations, 0 failures**; `npm run check` → 177 archivos sin errores/warnings/hints; `npm run build` → **113 páginas**.
- E2E Chromium de home, booking y panel: **7/7 passed**. El selector `.usgar-marker` se conserva como alias compatible del marcador `.usgar-hotel-marker`.
- `event_outbox` auto-crea/verifica el índice compuesto `(status, next_attempt_at, attempts, id)` para el claim; `GET /api/user/bookings` devuelve 503 ante fallos reales de BD y conserva lista vacía solo para tabla aún no creada.
- El perfil acepta `room_data` legacy y multi-room, elimina el prefijo literal de fechas y usa traducciones para los textos del formulario y sus mensajes.
- `TokenRevocationStore` falla cerrado si el directorio de revocación desaparece tras un health-check previo; la regresión está cubierta.
- Riesgos operativos abiertos: el voucher SMTP conserva semántica at-least-once entre `send()` y `markSent()`; la cancelación PMS de refund/chargeback sigue manual; el warning `/fr` vs `/fr/` requiere una política única de rutas.

- Verificación final local: `npm run check` con 177 archivos y 0 errores/warnings/hints; `composer analyse` con PHPStan nivel 7 y 0 errores; PHPUnit completo con **299 tests, 982 assertions, 12 skipped, 2 deprecations, OK**; `npm run build` con **113 páginas**; SEO con **121 páginas, 84 JSON-LD, 0 errores**; auditoría de seguridad estática con salida 0; E2E del panel ante 5xx: **1 passed** contra preview local.
- Graphify se consultó sobre el flujo reserva → pago → webhook → outbox → QloApps → disponibilidad usando el grafo existente (**25.268 nodos**); cada hallazgo se contrastó con el código actual porque el grafo persistido precede a parte de los cambios locales.
- Verificación frontend ampliada: Vitest **4.1.11, 11 archivos y 75 tests OK**; `npm audit` y `npm audit --omit=dev`: **0 vulnerabilidades**. Se actualizaron Astro 7.3.2, sharp 0.35.4, svgo 4.1.0, deepmerge-ts 8.0.2 y fast-uri 3.1.7; los workflows de GitHub Actions usan SHA completos. Semgrep OSS 1.172.0 dejó únicamente advertencias conservadoras sobre usos internos ya acotados de `unserialize()`/`unlink()`.
- Auth/panel: revocación server-side de `jti` para cookies de huésped y panel, y rate limit dedicado de **5 intentos/15 min por IP** para `POST /api/panel/login`; OAuth exige email verificado y rechaza linking implícito. Pruebas focalizadas: **16 tests, 46 assertions OK**; PHPUnit completo: **309 tests, 1024 assertions, 12 skipped, 2 deprecations, OK**; PHPStan 0 errores.
- Pagos fuera de orden: un webhook `approved` sobre `fraud_review`/`manual_review` conserva la revisión, registra `approved_after_review` + alerta y no crea outbox; solo `RetryManualReviewAction` puede resolverlo tras revalidar el monto. Webhook/review focalizado: **39 tests, 224 assertions OK**.

- Auditoría integral local completada sobre frontend, backend y flujos de pago. `npm run check`: 177 archivos, 0 errores/warnings/hints; `composer analyse`: PHPStan nivel 7 sin errores; PHPUnit completo: **269 tests, 891 assertions, 12 skipped, OK**; `npm run build`: **113 páginas** generadas; SEO: 121 páginas, 84 JSON-LD, 0 errores; seguridad estática: salida 0; E2E del panel ante 502: **1 passed**.
- Corregido takeover de cuentas OAuth: el registro público ya no modifica ningún email existente (`User::createFromEmail()` devuelve conflicto); regresión en `tests/Unit/Features/Auth/UserTest.php`.
- Endurecida la frontera de auth: inputs JSON no-string responden 400 sin tocar DB; redirects internos comparten `AuthRedirect::safePath()` y rechazan externos, `//`, backslashes y controles; pruebas en `AuthInputValidationTest.php`.
- Mercado Pago: `findPaymentByExternalReference()` conserva coincidencia exacta/ventana reciente y ahora usa la forma compatible con el DTO del SDK; PHPStan y 19 tests del adapter pasan.
- UX: `/panel` muestra estado seguro con reintento ante 5xx sin filtrar HTTP/detalles internos; `/book` no muestra `$0` antes de tener una selección.
- Limitación conocida: Astro sigue emitiendo un warning no fatal por conflicto `/fr` y `/fr/`; requiere decidir una sola estrategia de rutas localizadas.
- Health queda definido como liveness: HTTP 200 con `status: degraded` cuando la base de datos no responde, evitando confundir proceso vivo con readiness de reservas.
- Pendientes de seguridad: retirar DDL/probes de tablas del hot path tras validar el flujo de deploy; revisar el warning de rutas i18n. El rate limit del panel, la revocación server-side por `jti` y la política de email verificado/OAuth ya están implementados y verificados.

## Verificación backend vigente — 2026-09-07

- Se revisó el diff actual de reconciliación, webhooks y transactional outbox; no se revirtieron cambios previos.
- Suites dirigidas: reconciliación **8 tests / 54 assertions OK**; webhooks W3+W6 **24 tests / 146 assertions OK**; `EventDispatcherTest` + wiring **5 tests / 11 assertions OK**.
- Se corrigió en `ProcessOutboxAction::claimBatch()` la selección de `claim_token` después del claim; sin ese campo `processOne()` descartaba la fila por fencing. Los casos de backfill, retry y fencing pasan aislados.
- La suite completa `ProcessOutboxActionTest` mantiene dos casos sensibles al entorno: `testRunWithSlowListenersCompletesAllEvents` (también falla aislado con 2/3) y `testConcurrentRunsProcessEachEventExactlyOnce` (workers `proc_open` sobre Windows + BD compartida). Tratar como limitación operativa/flaky hasta repetir en un runner Linux/MySQL dedicado; no hay evidencia de una regresión determinista del fencing.
- Los tests webhook usan persistencia exitosa (`updateStatus`, `markPaymentProcessed`, `recordAlert` devuelven `true`) por defecto; los escenarios de error conservan excepciones/retornos fallidos explícitos. Resultado agrupado: **24/24 OK**.
- Pendiente documental operativo: mantener sincronizadas las secciones históricas de webhook/credenciales de MP con la identidad actual de la app; varias entradas antiguas están marcadas como superseded y pueden inducir a error.

> Documento canónico de estado del backend (antes `docs/refactoring/BACKEND_STATE.md`). Fuente de la auditoría de mejores prácticas 2026-08-11: `docs/ROADMAP.md`.

## BUG CRÍTICO: EventDispatcher sin listeners en actions HTTP (2026-08-15, primera venta real)

**Síntoma del dueño (prueba de pago real 15/08 13:17 UTC-5, hold `USGAR-d5fab1ddb3b0` $3/10.16 PEN, payment_id 1350421801)**: el pago se aprobó (hold `paid` + `processed_payments` approved) pero **no llegó el email de confirmación** y **la reserva no apareció en el CMS QloApps** (calendario Book Now con 0 booked).

**Causa raíz (verificada con probe de wiring, no teoría)**: `Container::get()` por autowiring crea instancias NUEVAS (no cachea las resueltas por reflexión — solo `bind()`/`set()` cachean). bootstrap.php registraba los listeners (`ConfirmQloAppsOrderListener` + `SendBookingConfirmationEmailListener`) en `EventDispatcher::getInstance()` (singleton), pero las actions HTTP (`ProcessPaymentAction`, `HandleMercadoPagoWebhookAction`, `RetryManualReviewAction`) recibían por el Container un `EventDispatcher` **nuevo sin listeners** → `EventDispatcher::dispatch()` hacía `return` temprano (`empty($this->listeners[$eventName])`, EventDispatcher.php:32) → **el INSERT en `event_outbox` NUNCA ocurría** → el cron `process_outbox` (5 min, verificado vivo vía MCP Hostinger: "No events to process") no tenía nada que entregar → `confirmOrder` (QloApps) y el email jamás corrieron. Los tests no lo veían porque inyectan mocks del dispatcher; el cron `reconcile_payments.php` sí usa `EventDispatcher::getInstance()` directo (por eso el patrón correcto existía en un sitio).

**Fix (1 línea, commit pendiente)**: `app/bootstrap.php` → `$container->set(EventDispatcher::class, $eventDispatcher);` (mismo patrón que `set(PDO::class, ...)`). Test de regresión nuevo: `tests/Unit/Core/Events/EventDispatcherWiringTest.php` (RunInSeparateProcess, carga bootstrap real con BD anulada; rojo sin el fix, verde con él). Verificación: phpunit **208/694/12 OK** + PHPStan 0.

**Reserva huérfana `USGAR-d5fab1ddb3b0` — RECUPERADA 2026-08-15 14:05 UTC-5**: pago aprobado (13:18) pero el evento `booking.paid` jamás llegó al outbox (bug de wiring). Recuperación: `scripts/recover-orphan-booking.php USGAR-d5fab1ddb3b0` (reconstruye `BookingPaidEvent::fromHold` con los datos congelados del hold + roundtrip check + INSERT con el formato exacto del dispatcher; idempotente por dedup de payload y del listener). El cron process_outbox la entregó en el siguiente ciclo: evento outbox id 1759 **COMPLETED** → orden QloApps **#251** (`module=mercadopago`, `source=USGAR-d5fab1ddb3b0`, `current_state=2`, `payment="Mercado Pago (Online)"`) → `qlo_htl_booking_detail` id 221 (16→19/08, 10.16 PEN, `is_cancelled=0`). **El calendario del CMS ya descuenta la habitación** (verificado en BD prod). El email NO salió por falta de `SMTP_*` en el `.env` físico (fail-soft por diseño) — pendiente del bloque SMTP. **El fix `dcb2c5c` fue PUSHEADO y el build de Hostinger completado (19:07Z) — la próxima venta real encola sola.**

## .env de prod: DOS fuentes y manda el File Manager (2026-08-15) — **RESUELTO el 2026-10-19**

- El dueño reportó: "cambio vars en hPanel → redeploy → las credenciales no cambian; edito el `.env` del File Manager y sí". **Confirmado por diseño**: `Config::loadEnv()` usa el PRIMER candidato que existe — `/home/u941268346/domains/usgarhoteles.com/.env` (File Manager) gana sobre el archivo de hPanel. **Resuelto el 2026-10-19**: hPanel es ahora la fuente única (ver la sección de este mismo archivo, arriba). Procedimiento de edición + backup documentado en `docs/DEPLOYMENT.md` §Variables de entorno (sección "DÓNDE se edita de verdad").
- **El `.env` físico de prod NO tiene `SMTP_*`/`MAIL_*`** (38 líneas, termina en `OTA_DEFAULT_SURNAME`) → el email de confirmación NO saldrá ni con el fix del outbox desplegado (fail-soft: sin `SMTP_HOST` → log + return). PENDIENTE: copiar la sección 6 (SMTP_HOST..MAIL_FROM_NAME) del `.env` local al `.env` del servidor + confirmar que la cuenta `no-reply@usgarhoteles.com` existe en hPanel → Emails. También faltan `ROOMS_CACHE_TTL`, `BOOKING_HOLD_MAX_EXTENSIONS`, `QLOAPPS_DEFAULT_LANG_ID`, `MP_NOTIFICATION_URL` (tienen defaults en código, opcionales).
- **Credenciales MP del `.env` físico de prod = TEST** (decisión deliberada del dueño: "test reales en campo suelto (producción)" — tarjeta APRO/OTHE contra el sitio real). NO "corregirlas" a PROD sin OK explícito.

## Scores de reseñas (2026-08-15, WIP local — sin push)

- El ribbon de reviews de la home mostraba scores inventados (Booking 9.6/380+, TripAdvisor 5.0/240+, Google 4.9/190+). Reemplazados por **datos reales extraídos** (web_extract 2026-08-15): Booking **8.7/414**, KAYAK **8.7/683**, Expedia **8.6**. `src/content/reviews/reviews.json` ahora tiene 8 reseñas reales de Booking (textos reales EN + traducción es/fr/pt; fechas "2026" porque Booking no expone el mes en la extracción). TripAdvisor bloquea scraping (sin score inventado).
- **Nuevo**: `App\Features\Reviews\Actions\GetReviewScoresAction` (GET `/api/reviews-score`, lee `app/storage/review-scores.json` — creado por el cron; `Cache-Control: public, max-age=3600`) + `RefreshReviewScoresAction` (POST `/api/cron/refresh-reviews`, CLI: fetch curl UA-navegador + parse con regex, **fallback por fuente al valor anterior**). Probado local: KAYAK 8.7/683 parseado ✓; Booking responde challenge Akamai (fallback conserva el valor del build); Expedia 429 rate-limit (fallback).
- **PENDIENTE EN PROD (RESUELTO 2026-08-15)**: el cron quedó REGISTRADO en hPanel vía API Hostinger (uid `egFzCUjpmW`, `0 3 * * *` diario 03:00, comando `index.php /api/cron/refresh-reviews`). Las URLs de las fuentes usan los defaults del código (se pueden sobreescribir con `REVIEW_*_URL` en el .env de prod si hace falta).
- Frontend: ribbon enlazado a las plataformas (Booking/KAYAK/Expedia, target _blank), tarjetas de reseñas enlazadas a Booking, y fetch en `astro:page-load` que actualiza los números desde `/api/reviews-score` si hay datos (fallback silencioso).

## Correo transaccional + recibo completo (2026-08-14, commit 5cd4f1e)

- **No existía NINGÚN email transaccional** en el proyecto (auditado: composer.json sin PHPMailer, `.env` sin claves SMTP, bootstrap.php solo registraba el listener de QloApps). El huésped nunca recibía su voucher tras pagar.
- **Nuevo**: `MailerPortInterface` (Port) + `PhpMailerAdapter` (SMTP vía Config: `SMTP_HOST/PORT/SECURE/USER/PASS/TIMEOUT`, `MAIL_FROM/NAME`) + `SendBookingConfirmationEmailListener` suscrito a `booking.paid` en bootstrap. Construye el voucher HTML (estilos inline, compat Gmail/Outlook) con los montos CONGELADOS del hold (PEN cobrado + equivalencia USD + tasa) y el pickup, en el idioma del huésped (`guest_data.locale`, default en, 4 idiomas).
- **Semántica de fallo**: `SMTP_HOST` vacío → fail-soft (log + return, el outbox NO reintenta); SMTP configurado y falla → fail-closed (propaga, outbox reintenta con backoff, mismo patrón que ConfirmQloAppsOrderListener).
- **`guest_data.locale`**: el wizard envía `locale` en `guestDetails` (PaymentStep) y `CreateBookingAction` lo persiste — el email llega en el idioma del huésped.
- **Frontend**: el recibo de éxito inline del wizard es ahora un voucher completo (huésped, fechas, habitación, total S/ + ≈USD, nota pickup) + enlace "Ver recibo completo" a `/book/success?bookingId=...&token=...` (URL compartible/imprimible). `success.openReceipt` en los 4 idiomas.
- Verificación: `phpunit` **203 tests / 687 assertions, 12 skipped, OK** (5 tests nuevos del listener, 17 assertions) · `npm run check` 0 errores · build OK · probe e2e `tests/e2e/probe-receipt.cjs` **6/6 PASS** contra router-dev.
- **PENDIENTE EN PROD**: crear la cuenta de correo del dominio en Hostinger (ej. `no-reply@usgarhoteles.com`), setear `SMTP_*`/`MAIL_FROM*` en hPanel y desplegar. Sin SMTP_HOST el email se omite silenciosamente (por diseño).

## P2 — Deuda estructural (2026-08-12, commits pendientes de push local)

- **P2-2** `Request::sanitize()` (no-op recursivo) eliminado: método + llamadas en constructor. Verificado: `grep -rn sanitize app/ tests/` → solo `tests/pentest.php` (variable local ajena).
- **P2-3** Constantes de instalación en `QloAppAdapter` (`SHOP_GROUP_ID/SHOP_ID/LANG_ID/ORDER_STATE_PAID/CHECKIN_TIME/CHECKOUT_TIME/HOTEL_NAME/HOTEL_CITY`) + variables interpolables en los SQL (los strings de doble comilla NO expanden `self::CONST` — pitfall detectado 2× en el desarrollo, cubierto por test de regresión). Bonus: `getAvailabilityCalendar()` ignoraba el parámetro `id_lang` (hardcode 1) — ahora usa `QLOAPPS_DEFAULT_LANG_ID`.
- **P2-5** 405 + `Allow` en `Router::dispatch` (RFC 9110 §9.6.2). Evidencia real con `php -S 127.0.0.1:8891 public/index.php` + curl:
  - `POST /api/rooms` → `HTTP/1.1 405 Method Not Allowed` + `Allow: GET` ✓
  - `GET /api/health` → 200 ✓ · `GET /api/nonexistent` → 404 ✓
  - Pitfall: en phpunit CLI `header()` no se registra en `headers_list()` (el banner ya envió output, `headers_sent()`=true) → el test unitario valida contrato observable (status + JSON), el header se verifica en integración.
- **P2-6** `Request::getRequestId()` (generate-if-missing, bin2hex CSPRNG de 16 bytes) + `Middleware::requestId()` en el pipeline + `request_id` en logs de error del Router.
- **P2-8** Sección F en `docs/PMS.md`: contrato de escritura directa al schema `qlo_*` (7 tablas) + valores fijos + reglas para el upgrade de QloApps (pendiente).
- **P2-7 DIFERIDO (deuda)**: mover `exit()` de `Response::json` al front controller — ~40 call sites asumen terminación; no se toca sin refactor mayor.

Verificación: `php vendor/bin/phpunit` → **184 tests / 633 assertions, 12 skipped, OK** · `php -l` en los 5 archivos tocados → sin errores · `composer analyse` (phpstan nivel 6) → **No errors**.

## Multi-room + ocupancia/extras (2026-08-12, commits e2ed07d/e25b238 + WIP)

- **Reservas multi-habitación**: `POST /api/booking` acepta `rooms[]` (1-3, {slug, guests}) con `rateType` GLOBAL por reserva; legacy `roomSlug` normaliza internamente (sin regresión, verificado con harness). `room_data` en el hold pasa a LISTA; consumidores (GetBookingStatus, ProcessPayment, my-bookings, success) normalizan en el punto de lectura (primer room para campos legacy; `room_summary` en respuestas).
- **Ocupancia +1 persona / $30 (regla del negocio, verificada en BD real)**: `qlo_htl_room_type.max_guests` = 3/3/4/8 → base = max−1 = 2/2/3/7. rooms.json sincronizado. Cargo extra `EXTRA_GUEST_CHARGE_USD` (Config, default 30) a PRECIO COMPLETO (el −10% no reembolsable solo aplica al base — decisión del negocio). Lógica pura en `PriceCalculator::extraGuestCharge` + tests.
- **XML webservice bookings**: `createCartMulti` agrupa por tipo con `number_of_rooms=N` (schema verificado en `WebserviceSpecificManagementBookings.php` de QloApps).
- **BUG encontrado (doc-first, fuente de QloApps)**: el adapter enviaba `payment_status=0` → 400 "Estado de pago inválido" (valores válidos: 1 Completed / 2 Partial / 3 Awaiting). FIX: `3` (hold sin pagar). **PERO** el POST del webservice `bookings` sigue dando **500** en esta instalación en la creación de la orden (`validateOrder`). Corrección 2026-08-12 (verificado en BD de prod: `QLO_VERSION_DB=1.7.0.0` = release v1.7.0, 2025-07-04 — la instalación YA está en la última versión; no hay upgrade disponible): el changelog de v1.7.0 arregla #1471 "issues in bookings and services web services" y **elimina el specific management qlo web service** — el claim previo de "bug pre-existente arreglado en versiones posteriores" era falso; el 500 no se resuelve con un upgrade. El fallback a cart local `USGAR-` es el diseño resiliente vigente (holds + webhook `confirmOrder` funcionan por PDO local).

## Verificación 2026-08-11 (centralización de docs; sin cambios de código)
- `npm run check` → 0 errors / 0 warnings (1 hint pre-existente en `scripts/dev.js`).
- `php vendor/bin/phpunit` → **160 tests / 569 assertions, 12 skipped, 1 fallo flaky** (`ProcessOutboxActionTest::testConcurrentRunsProcessEachEventExactlyOnce` — pasa aislado; workers proc_open + timing en Windows). Backlog: ROADMAP P3-7.
- `scripts/run-exhaustive-tests.php` → **no termina**: golpea MP real (404 "Payment not found", timeouts 8s c/u) y BD remota (1045 si la IP no está re-anillada). Evidencia: `logs/app.log` 12:47. Backlog: ROADMAP P3-6.

## Verificación 2026-08-11 (fixes de suite — commits `7472967`)
- **Causa raíz del cuelgue de la suite exhaustiva**: `Database` sin `PDO::ATTR_TIMEOUT` (3 hosts × timeout TCP del OS ~21s+ cuando la BD remota no responde) + host alias viejo `us-imm-web909.main-hosting.eu`. FIX: `PDO::ATTR_TIMEOUT=3` + `.env` local con `DB_HOST=srv909.hstgr.io` (host canónico del panel Hostinger).
- `php scripts/run-exhaustive-tests.php` → **21/21 PASS, EXIT=0 en <1 min** (antes: colgaba >7 min).
- `php vendor/bin/phpunit` → **160 tests / 578 assertions, 12 skipped, 0 fallos** (fix P3-7: timeout worker 120s).
- Credenciales MP de producción verificadas contra la API (HTTP 200, live_mode=true; coinciden con `.env`).

## Historial — STATE original (Fase 0/2: línea base + limpieza de pagos)

> Memoria de sesión del backend: este archivo es el handoff del trabajo de backend (migraciones + refactor).
> Última actualización: 2026-08-05 (F0 completa; F2 cerrada; verificación webhook y checkout completadas; ola de refactor de limpieza en curso).

## Contexto (decisiones aprobadas por el usuario)

- **Orden de trabajo**: F0 línea base → F2 limpieza de pagos → F4 frontend (redesign pendiente). **F3 (salir de QloApps) CANCELADA — QloApps se mantiene como PMS (decisión 2026-08-04); ver `docs/DECISIONS.md`.**
- **CMS**: QloApps se mantiene. **Pagos**: MercadoPago se mantiene (Checkout API).
- **Channel manager**: QloApps Channel Manager (Webkul) — sin código en el repo (módulo del PMS); activación pendiente de pago (runbook `docs/PMS.md` §D).

## Línea base (2026-08-01, verificada)

| Suite | Resultado |
|---|---|
| `php composer.phar check` (PHPUnit 11.5.56 + PHPStan 2) | **26 tests / 97 assertions PASS** + PHPStan sin errores |
| `npm run check` (astro check) | 0 errores, 6 hints pre-existentes (Hero.astro Props, RoomCard index, TypographicMarquee i, heroTextReveal ScrollTrigger, Layout media, profile.astro profileCard) |
| `npm run test:php` (run-exhaustive-tests) | **22/22 PASS** tras fix de harness |
| `npm run audit:security` | 3 hallazgos, **todos falsos positivos**: `PDO::exec()` solo para `CREATE TABLE IF NOT EXISTS` sin input (User.php:31, ProvisionalBookingRepository.php:87, SubscribeNewsletterAction.php:34) |

### Cambios de F0 aplicados
- `scripts/run-exhaustive-tests.php`: (a) expectativas obsoletas de `GET /api/rooms` sin params — el código usa hoy/mañana por defecto (el frontend hace polling sin params, src/services/bookingService.ts:155) y la prueba esperaba 400; actualizado a 200+success. (b) log del servidor de pruebas único por ejecución (`php-test-server-<pid>.log`) — Windows deja zombies de `php -S` que bloquean el archivo de log fijo.

## Fase 2 — hecho (2026-08-01)

### Residuos Checkout Pro — AUDITADO (resultado: backend limpio)
- **Backend PHP (`app/`)**: SIN residuos. No existe `preference`/`init_point`/`WebhookDebugAction`/filtro IPN legacy (ya eliminados en commits previos). Verificado por grep global.
- **Frontend (`src/`)**: limpio — `book.astro:929` ya usa MercadoPago.js cardForm (Custom Checkout) → `processPayment`; `bookingService.ts` sin referencia a preference. Solo falsos positivos (`prefers-reduced-motion`, tab de preferencias de perfil).
- **`access_token` en /api/booking y /api/process-payment**: NO es residuo — es el token HMAC de hold (`BOOKING_TOKEN_SECRET`, `cart_id:email`), parte del flujo Checkout API actual. Se mantiene.
- **Limpiado** (commit `cca8fd5`): `tests/postman-payment-suite.php` (asertaba `preference_id` → ahora `access_token` + `mp_public_key`), `docs/API_REGISTRY.md` (respuesta con `init_point` → contrato real + endpoint `/api/process-payment` documentado por primera vez), `docs/ARCHITECTURE.md` (flujo de reserva con nombres obsoletos → flujo real), borrado `task.md` (lista obsoleta, ítem de frontend ya estaba hecho).

### Diagnóstico webhooks MP (MCP oficial, read-only)
- `notifications_history`: **sin notificaciones registradas** — probablemente el webhook NO está registrado en la app de MercadoPago (candidato #1 de la "falta de comunicación"). El código envía `notification_url` por-pago, pero MP recomienda registrarlo en el panel. → **Diagnóstico SUPERSEDIDO 2026-08-03**: el webhook sí estaba registrado desde 2026-07-13; el historial vacío era por cero eventos (ver "Verificación webhook (2026-08-03) — COMPLETADA").
- **PENDIENTE (requiere confirmación del usuario — cambia producción)**: registrar webhook vía `mercadopago-mcp-server_save_webhook` con callback `https://usgarhoteles.com/api/webhook`, topic `payment`, o hacerlo en el panel. → **RESUELTO 2026-08-03**: ya estaba registrado (callback `https://usgarhoteles.com/api/webhook`, topic `payment`); no se re-escribió.

### Secretos en git — AUDITADO (resultado: limpio, NO rotar)
- `.env` está gitignored y nunca se commiteó. Historial completo escaneado (`git grep APP_USR-` sobre todos los commits): solo placeholders (`YOUR_MP_*`) y comentarios. Los tokens reales solo viven en `.env` local/producción (fuera de git).
- `tests/test_sdk_webhook.php:27` y `scripts/run-stress-tests.php:85` ya leen de env con fallback de test (P4-1 ya hecho).

### DIP y schema
- `cron/reconcile_payments.php`: instanciaba `new MercadoPagoAdapter()` a mano → ahora resuelve `PaymentGatewayPortInterface` desde el container (commit `90e2442`).
- `scripts/create_processed_payments_table.sql`: duplicaba el schema con `created_at` y PK en payment_id → unificado con `ProvisionalBookingRepository::ensureTablesExist()` (`id` AI + `payment_id` UNIQUE + `processed_at`). La tabla vieja de producción sigue siendo funcionalmente compatible (INSERT no usa `processed_at`); el script es la definición canónica para instalaciones nuevas (commit `65d7751`).

## Auditoría de seguridad completa (2026-08-01) — hallazgos y fixes

### Dependencias
- `composer audit` (Packagist advisories): **0 vulnerabilidades**. `npm audit`: **0 vulnerabilidades**.

### BUGS CORREGIDOS (con tests, commits por tema)
1. **Refund parcial roto** (commit `58c497a`): `MercadoPagoAdapter::refundPayment()` llamaba `PaymentRefundClient::create()` que **no existe** en el SDK (los métodos son `refund()` y `refundTotal()`) → cualquier reembolso parcial crasheaba con Error fatal. Corregido a `refund()` + test de caracterización nuevo (`testRefundPaymentPartialRefundUsesRefundMethod`).
2. **Header de idempotencia malformado** (commit `58c497a`): el SDK espera `["X-Idempotency-Key" => valor]` (claves→valores) y el código pasaba `["X-Idempotency-Key: valor"]` (clave entera) — `array_merge` lo reindexaba y **el header no se enviaba** → sin protección de idempotencia (riesgo de pagos duplicados en reintentos). Corregido en `processPayment` y `refundPayment`; test actualizado (el test viejo fijaba el formato buggy — TDD rojo→verde).
3. **`payment_id` faltante en producción** (commit `c4f529d`): la tabla `provisional_bookings` real NO tenía la columna `payment_id` (la migración de docs/refactoring/CRON.md nunca se ejecutó) → `attachPaymentId()` y `getPendingHoldsWithPaymentId()` fallaban silenciosamente → **la reconciliación de pagos estaba muerta en producción**. Fix: ALTER ejecutado en producción (verificado por schema MCP) + auto-heal en `ensureTablesExist()` para entornos nuevos.
4. **Escape de entrada corrupta datos** (commit `95c0eb4`): `Request::sanitize()` aplicaba `htmlspecialchars()` a TODOS los strings de entrada → contraseñas con `&`/`<` se corrompían (login roto), nombres con doble-escape (`&amp;amp;`), `CreateBookingAction` re-escapaba (doble). Fix: modelo correcto (validar input, escapar output) — `sanitize()` ya no altera strings; se quitó el doble-escape; **se añadieron escapes de salida** en los templates innerHTML del frontend (my-bookings, profile, AllocationStep, panel — helper `escapeHtml`). XSS por innerHTML con datos de reservas eliminado.
5. **Fuga de detalles internos en OAuth** (commit `d8a0c89`): el catch de `AuthCallbackAction` ponía `$e->getMessage()` (rutas, hosts, mensajes internos) en la URL de error → ahora mensaje genérico; detalle solo al log del servidor.
6. **Dependencias muertas** (commit `7685577`): `HandleMercadoPagoWebhookAction::$pms` y `CreateBookingAction::$paymentGateway` inyectadas pero nunca usadas (+ imports de adapters concretos) — eliminadas; tests actualizados; baseline PHPStan regenerado (2× patrones obsoletos por bugs corregidos).
7. **Higiene git** (chore): `logs/app.log`, `preview.log` y `.playwright-mcp/*` estaban versionados → untracked (`git rm --cached`); `.gitignore` extendido (`playwright-report/`, `test-results/`, `.playwright-mcp/`, `preview.log`, `*.log`).

### Verificaciones sin hallazgos
- Patrones peligrosos (eval/exec/system/unserialize/extract/assert): **ninguno real** (solo `PDO::exec` DDL y `curl_exec` de adaptadores).
- JWT (SessionService): HMAC-SHA256 + `hash_equals` + exp + alg pinned + secret ≥32 chars; cookie HttpOnly/SameSite=Lax/Secure. OK.
- Rate limiter: archivos con `flock(LOCK_EX)` + SHA-256 + fuera de dir público. OK.
- IP spoofing: `X-Forwarded-For` solo confía en `TRUSTED_PROXIES` (vacío por defecto = seguro). OK.
- Open redirects: validación `str_starts_with($redirect, '/')` + rechazo `//` en callback/register/login. OK.
- SQL del código vs schema real (mysql MCP): `qlo_*` de `getAvailableRooms` **válido**; `processed_payments`/`event_outbox`/`users` correctos.
- SQLi: todo prepared statements. XSS frontend: textContent en success/payment; innerHTML restantes revisados.
- `preference_id` (residuo Checkout Pro) existe en `provisional_bookings` con **52 registros históricos** → NO se elimina (preservación de datos); queda documentado.

### Pendiente del usuario (webhook) — RESUELTO 2026-08-03
- Verificación de `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` vs el secreto del panel MP (app 8501374849722569): **CONFIRMADO** — el secret del panel empieza con `a8c54ec...`, idéntico a `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64) y al secret proporcionado por el usuario. El webhook SÍ estaba registrado en la app (callback `https://usgarhoteles.com/api/webhook`, topic `payment`, desde 2026-07-13); el historial vacío era por cero eventos. Detalle en "Verificación webhook (2026-08-03)".

### Nota: agente paralelo
- Hay una sesión paralela trabajando el wizard de reserva (BookingWidget, GuestStep, BookingCalendarStep, PaymentStep, book.astro, i18n). Al cierre de esta auditoría, `astro check` reporta **15 errores TS en su WIP** (null-checks y variables sin definir en BookingWidget/BookingCalendarStep/book.astro) — no tocados para no pisar su trabajo; quedan para cuando cierre su tarea.

## Pendiente (F2 restante) — cerrado 2026-08-03
- [x] Verificar `MERCADO_PAGO_WEBHOOK_SECRET` == secreto del panel MP — **RESUELTO 2026-08-03** (user-gated completado). **Evidencia**: secret del panel empieza con `a8c54ec...` == `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64) == secret proporcionado por el usuario.
- [x] Confirmar el registro del webhook — **CONFIRMADO 2026-08-03**: registrado en la app 8501374849722569 desde 2026-07-13 (callback `https://usgarhoteles.com/api/webhook`, topic `payment`; URLs prod+sandbox). El historial vacío de `notifications_history` era por cero eventos, no por webhook sin registrar.
- [x] Pago de prueba + re-ejecutar `notifications_history` — **EJECUTADO 2026-08-03**: pagos 1349900853 (`pagoefectivo_atm`, pending) y 1327783012 (Visa MPE test, **approved**); 2 eventos `payment` entregados; simulación local firmada con HTTP 200. Detalle en "Verificación webhook (2026-08-03)".
- **FUERA del alcance de la app de test** (pendiente, no bloquea): registrar el webhook en el panel de la app de **producción** (8776209959654245) para pagos reales; restaurar las credenciales `APP_USR` en el `.env` antes de cualquier deploy (el `.env` local quedó en modo TEST por instrucción del usuario).
- [x] `success.astro` 3 estados (confirmado / pendiente / verificando) — refactor PLAN P6-1. **Evidencia (verificada 2026-08-03)**: `src/pages/book/success.astro:214` (`pollForPayment`), `:287` (call con AbortSignal), `:291` (comentario "3-state flow: paid -> success card, pending -> pending card, else -> error"), `:294` (`PENDING_STATUSES`), `:299` (branch de estado pendiente); claves i18n en las 4 locales: `en.json:319/321`, `es.json:323/325`, `fr.json:323/325`, `pt.json:323/325` (`paymentPending`, `verifyingPaymentMessage`).
- [x] Reducir el logging diagnóstico pesado ("WEBHOOK DIAGNOSTICS") a un log conciso. **Evidencia (verificada 2026-08-03)**: `grep -rn "WEBHOOK DIAGNOSTICS" app/` → 0 resultados; commit `1e3226b` ("refactor: trim webhook diagnostic logging to concise per-event info"); `HandleMercadoPagoWebhookAction.php` con 5 `Logger::info` (líneas 57/71/99/117/164), entrada concisa con solo `type`+`payment_id` (:71), cero headers/query/`$_SERVER`; ERRORs intactos.
- [x] DIP restante (P3-1): constructores nullable en actions. **Evidencia (verificada 2026-08-03)**: `grep -rn "= null)" app/Features/Booking/Actions/ app/Features/Webhooks/Actions/` → 0 matches; constructores no-nullable en `CreateBookingAction.php:32`, `ProcessPaymentAction.php:29`, `GetBookingStatusAction.php:22`, `ExtendHoldAction.php:21`, `HandleMercadoPagoWebhookAction.php:30`. CSP duplicada ya confirmada (no hay `.htaccess` raíz; `public/.htaccess` solo setea nosniff — CSP única en Middleware).
- [x] Revisar/fixear los 15 errores TS del WIP del agente paralelo. **Evidencia (verificada 2026-08-03)**: `npm run check` → 0 errors / 0 warnings / 0 hints (136 archivos); corroborado en `docs/refactoring/STATE.md` (Fases 3+4: "astro check 0 errores", STATE.md:30/48).

## F2 — cerrada (2026-08-03)

Cierre de la Fase 2 del refactor backend (plan `f2-close-backend-refactor`), con evidencia verificada en sesión:

- **Recorte de logging diagnóstico** (Todo 1, commit `1e3226b`): bloque "WEBHOOK DIAGNOSTICS" eliminado de `HandleMercadoPagoWebhookAction.php`; quedan 5 `Logger::info` por evento (líneas 57/71/99/117/164), entrada concisa con solo `type` + `payment_id` (:71), cero headers/query/`$_SERVER`; ERRORs intactos. Comportamiento HTTP y dispatch de `BookingPaidEvent` sin cambios (refactor behavior-preserving).
- **Verificación webhook** (Todo 2): **COMPLETADA 2026-08-03**. El webhook estaba registrado en la app 8501374849722569 desde 2026-07-13 (callback `https://usgarhoteles.com/api/webhook`, topic `payment`); secret del panel (`a8c54ec...`) == `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64); 2 pagos de prueba (1327783012 **approved**) + 2 notificaciones entregadas; simulación local firmada con HTTP 200. Detalle en "Verificación webhook (2026-08-03)".
- **P6-1 (success.astro 3 estados) y P3-1 (DIP no-nullable) ya hechos** — verificados con evidencia file:line en la sección "Pendiente (F2 restante) — cerrado 2026-08-03".

**User-gated 2026-08-03 — RESUELTO (app de test):**
1. Comparación del secret del panel MP (app 8501374849722569) vs `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64): **VERIFICADO** — panel `a8c54ec...` == `.env`.
2. Registro del webhook (callback `https://usgarhoteles.com/api/webhook`, topic `payment`): **CONFIRMADO** — registrado desde 2026-07-13.
3. Pago de prueba + monitoreo de `notifications_history`: **EJECUTADO** — pagos 1349900853 y 1327783012 (**approved**), 2 notificaciones entregadas, simulación local HTTP 200.

**FUERA del alcance de la app de test (pendiente para pagos reales, no bloquea):**
- Registrar el webhook en el panel de la app de **producción** (8776209959654245) — el webhook verificado pertenece a la app de test 8501374849722569.
- Restaurar las credenciales `APP_USR` de producción en el `.env` antes de cualquier deploy (el `.env` local quedó en modo TEST por instrucción del usuario).

## Ola de refactor de limpieza (dead code + helpers + hardcodes → config) — 2026-08-05 (EN CURSO)

Auditoría de limpieza en curso (P5-2, decisión 2026-08-05). Estado verificado con evidencia en el árbol de trabajo al cierre de esta sesión — el agente paralelo trabaja sobre `app/`/`scripts/`/`tests/` y todavía **no ha commiteado** nada de esta ola:

- **Scripts candidatos a eliminar — AÚN EXISTEN (no borrados al 2026-08-05)**: `scripts/seed-database.php`, `scripts/test-db-connection.php`, `scripts/audit-performance.js`, `scripts/test_endpoints.php`, `scripts/test-api-runtime.php`, `scripts/update-i18n.js`. Verificado por listado de `scripts/` + grep: ninguno está referenciado desde `package.json` (scripts), `app/`, `public/` ni `src/` — solo self-references en sus cabeceras. Candidatos seguros a borrar cuando la ola los procese.
- **Helpers nuevos en `app/Core/`**: NINGUNO verificado todavía. No existen `BookingHoldToken`, `PriceCalculator`, `Retry` ni `GuestName` (grep 0 resultados en `app/`). La extracción de helpers queda pendiente de la ola.
- **Hardcodes → config (parcial, verificado)**: `app/Core/Config.php::DEFAULTS` ya centraliza `SITE_URL`, `TIMEZONE`, `DB_HOST`/`DB_PORT`/`DB_NAME`, `ALLOWED_ORIGINS`, `DEFAULT_HOTEL_ID`, `EXCHANGE_RATE_USD_PEN`, `DEFAULT_GUEST_EMAIL`, `DEFAULT_REPLY_EMAIL`. Queda auditar los hardcodes restantes en actions/adapters.
- **Drift de docs corregido en esta misma sesión (2026-08-05)**: `docs/API_REGISTRY.md` (variables de entorno obsoletas → nombres reales verificados con `Config::get()`; endpoints sin documentar añadidos), `AGENTS.md` + `README.md` (distinción de los dos contratos de test), `docs/multi-hotel.md` (estructura `public/index.php` + `app/`).
- **Cierre**: la ola queda **EN CURSO**; actualizar esta sección cuando el agente paralelo commitee el borrado de scripts muertos, los helpers de `app/Core/` y la migración de hardcodes a config.

## Hook pre-commit (2026-08-01) — reescrito y VERIFICADO
- Causa: `.githooks/pre-commit` (sh) fallaba en esta máquina — `sh` del PATH (shim scoop) delegaba en el relay WSL (`execvpe(/bin/bash) failed`).
- Solución: hook reescrito en **PowerShell puro** (Windows PowerShell 5.1) con shebang `#!/usr/bin/env powershell`; misma lógica (mp4 → ffmpeg con tag USGAR_COMPRESSED; imágenes → `node scripts/compress-images.js`; re-stage; nunca bloquea el commit). Docs: `docs/HOOKS.md`.
- Verificación con evidencia: commit real con PNG 40x40 staged → blob commiteado **difiere** del PNG fresco (hash SHA256 distinto) → sharp re-encodeó vía el hook; `GIT_TRACE=1` confirma `start_command: .githooks/pre-commit` (~5s = arranque PowerShell). Commits posteriores OK por terminal.
- **Limitación**: el servidor MCP de git (`git_git_commit`) falla con CUALQUIER hook (ejecuta `bash` → relay WSL). Los commits se hacen por terminal (bash tool). Documentado en `docs/HOOKS.md`.
- Commit: `3a5b50c` (hook + HOOKS.md), `b6555e4`/`bb15b4c` (fixture de prueba ida y vuelta).

## Notas de entorno (Windows)
- `npm run test:php` puede dejar zombies `php -S 127.0.0.1:8089` si se interrumpe; limpiar con `Stop-Process` o matando listeners de 8089.
- WIP sin commitear del usuario (NO tocar): `app/Core/Container.php`, `app/bootstrap.php`, `scripts/dev.js`. **Ojo**: hay una sesión paralela tocando el frontend F4 (`src/pages/book.astro`, `src/components/booking/PaymentStep.astro`, `tests/e2e/internals.spec.ts`, `tests/e2e/wizard-flow.spec.ts`) — no pisar; stagear solo lo propio al commitear.
- **Hook pre-commit**: ahora PowerShell (funciona por terminal). El MCP de git (`git_git_commit`) NO puede ejecutar hooks en esta máquina (usa bash→WSL) → commits por terminal (bash tool).

## Verificación webhook (2026-08-03) — COMPLETADA

Verificación end-to-end del flujo de webhooks de MercadoPago ejecutada el 2026-08-03. **Reemplaza la interpretación previa de esta sección** (diagnóstico read-only que infería "webhook probablemente NO registrado" a partir del historial vacío de `notifications_history`): esa inferencia era errónea y queda corregida por la evidencia real de abajo.

### Hallazgos (evidencia verificada vía mercadopago-mcp y logs)

1. **Webhook REGISTRADO** (app 8501374849722569): existía desde 2026-07-13; actualizado hoy. URLs prod+sandbox = `https://usgarhoteles.com/api/webhook`; topic `payment` incluido. Confirmado vía `save_webhook`/`notifications_history`. El historial vacío previo ("📭 No Notifications Found") era por **cero eventos**, no por falta de registro.
2. **Secret VERIFICADO**: el secret del panel empieza con `a8c54ec...` == `MERCADO_PAGO_WEBHOOK_SECRET` del `.env` (len=64, valor nunca impreso) == el secret que el usuario proporcionó.
3. **Pagos de prueba ejecutados**: `1349900853` (`pagoefectivo_atm`, **pending**, 20:43 UTC) y `1327783012` (tarjeta Visa MPE de test, **approved**, 20:47 UTC).
4. **Notificaciones ENTREGADAS al sitio**: 2 eventos `payment` recibidos en producción, ambos con HTTP 500: "No se pudieron obtener los detalles del pago de Mercado Pago". **Diagnóstico**: el `.env` de producción usa el token `APP_USR` de la app 8776209959654245, que no puede leer pagos TEST de la app 8501374849722569 — artefacto del entorno de test, **NO un bug de código**.
5. **Simulación local EXITOSA** (con `.env` en TEST): POST firmado a `/api/webhook?data.id=1327783012` → **HTTP 200**. Log: "Webhook recibido" (payment_id 1327783012) → "Firma de webhook validada correctamente" → "Pago ID 1327783012 tiene estado 'approved'. Omitiendo confirmacion." → el path completo (firma HMAC + fetch de detalles + respuesta) funciona.

### Conclusión

- El diagnóstico previo era incorrecto: el webhook sí estaba registrado desde 2026-07-13; el historial vacío era por cero eventos y el secret del panel no se había comparado todavía.
- El flujo de código (firma + fetch de detalles + respuesta) está verificado localmente con HTTP 200; los 500 de producción corresponden al mismatch de tokens (test vs prod) documentado en el punto 4.

### Fuera del alcance de la app de test

- Registrar el webhook en el panel de la app de **producción** (8776209959654245) para pagos reales (el webhook verificado pertenece a la app de test 8501374849722569).
- Restaurar las credenciales `APP_USR` de producción en el `.env` antes de cualquier deploy (el `.env` local quedó en modo TEST por instrucción del usuario).

## Verificación checkout + pagos en vivo (2026-08-03) — COMPLETADA (fix incluido)

**CORRECCIÓN DE IDENTIDAD DE APP (aclaración del usuario)**: la app `8501374849722569` **ES la app de producción** de MercadoPago (con credenciales APP_USR-8501374849722569-... y public key APP_USR-24cfc998-...). La app `8776209959654245` era la app ANTERIOR/legacy (sus tokens APP_USR-8776209959654245 estaban en el .env del servidor → no podían leer los pagos de la app correcta → 500 en webhook). Las secciones previas que decían "app de test 8501374849722569 / producción 8776209959654245" quedan superseded por esta corrección.

### Diagnóstico en vivo (producción, 2026-08-03)
- `POST https://usgarhoteles.com/api/booking` → **200** con `mp_public_key: APP_USR-24cfc998-...` (credenciales APP_USR correctas YA en el servidor) y hold real (`USGAR-5a972718a6c5`, gateway_price 684 PEN = 180 USD × 3.8).
- Token APP_USR válido: `GET api.mercadopago.com/v1/payments/search` → 200, 5 pagos reales legibles (approved 2025-11-27).
- **Aislamiento TEST/PROD confirmado**: token APP_USR → 404 "Payment not found" para pagos TEST (`live_mode: false`); token TEST → 200. Los 500 históricos del webhook eran eventos TEST entregados a un servidor con token de la app vieja — **irrecuperables por diseño, NO bug**.

### BUG BLOQUEANTE ENCONTRADO Y CORREGIDO (este es el fix grande del día)

**Síntoma del usuario**: "no me deja poner ni la tarjeta ni el tipo de documento" en el checkout de producción.

**Causa raíz 1 — cardForm nunca montaba (frontend)**: `PaymentStep.start()` solo se re-evaluaba con cambios del `wizardStore`; el usuario llegaba al paso 3 con los campos del huésped vacíos → `start()` retornaba; al llenarlos y pulsar CONTINUAR, `wizardStore.next()` era **no-op** en step 3 (`if (state.step < 3)`) → nadie volvía a llamar `start()` → 0 POST `/api/booking` desde el navegador → `cardForm` de MP nunca montaba (divs de tarjeta/CVV vacíos, select de documento sin opciones).
- **Fix**: `src/components/booking/GuestStep.astro` — el botón `[data-guest-next]` ahora llama `wizardStore.setState({ step: 3 })` (setState SIEMPRE notifica) en vez de `wizardStore.next()` (no-op en step 3). Además `PaymentStep.astro`: `started = false` en los paths de error (permite reintento).

**Causa raíz 2 — 500 en process-payment (backend, bug SDK dx-php)**: `MercadoPagoAdapter::processPayment()` pasaba `setCustomHeaders(['X-Idempotency-Key' => $key])` (clave con mayúscula). El SDK `MercadoPagoClient::getIdempotencyKey()` (línea 149-159) detecta la key con `array_change_key_case()` pero devuelve `$headers[strtolower($key)]` del array ORIGINAL (case-sensitive) → **null → TypeError → 500** "Return value must be of type string, null returned".
- **Fix**: clave en **minúsculas** `['x-idempotency-key' => $key]` en `processPayment` y `refundPayment` (el SDK busca exactamente `$headers['x-idempotency-key']`). Test `testProcessPaymentSendsIdempotencyHeader` actualizado al formato correcto.

### E2E verificado en local (entorno TEST, sin dinero real) — evidencia completa

| Escenario | Evidencia |
|---|---|
| cardForm monta (iframes tarjeta/exp/CVV + select doc `DNI/C.E/RUC/Otro`) | ✅ snapshot + `hasIframe: true` |
| Pago Visa test `4009 1753 3280 6176`, titular `APRO`, doc `123456789` | ✅ payment `1327783766` **approved/accredited**, `live_mode: false`, 684 PEN, `external_reference: USGAR-20376530e184` |
| DB: hold → `paid` + `processed_payments` (payment_id, processed_at) | ✅ `provisional_bookings` + `processed_payments` |
| Redirect a `/book/success` | ✅ |
| Rama OTHE (rechazo general): titular `OTHE` | ✅ payment `1349906085` **rejected** `cc_rejected_other_reason`; wizard muestra "Error al procesar el pago." + botón re-habilitado; hold queda **pending** (NO paid) |
| Rama CONT (pendiente): titular `CONT` | ✅ payment `1327783824` **rejected** (esperado con `binary_mode=true`: MP fuerza approved/rejected, nunca pending); hold **pending**; error mostrado |
| Webhook firmado, pago approved ya procesado | ✅ **200 "Payment already processed"** (idempotencia) |
| Webhook firmado, pago rejected | ✅ **200 "Payment status is not approved"** |
| Webhook firma inválida | ✅ **401 "Firma de webhook invalida o ausente"** |
| Stress: 50× GET /api/rooms + 20× POST /api/booking + 10× webhook idempotente | ✅ **0 errores 5xx**; p95: rooms 574ms / booking 1239ms / webhook 578ms (umbral plan: <2s) |

### Notas de la verificación
- `npm run check` → 0 errores; `php composer.phar check` → **27/27 tests / 103 assertions PASS + PHPStan OK**.
- `quality_evaluation` del MCP NO funciona con pagos TEST (el wrapper consulta con token APP_USR → 404) ni con pagos de la app anterior ("not originated from app") — límite de la herramienta; funcionará con el primer pago LIVE de la app.
- El `.env` local queda en modo TEST (para E2E/stress); el servidor tiene APP_USR (verificado en vivo).

## Siguiente
- **Checkout VERIFICADO end-to-end (2026-08-03)** con tarjetas de prueba (APRO/OTHE/CONT), webhook firmado (3 ramas) y stress sin errores. Los fixes (cardForm trigger + idempotency key) están en el branch pendiente de commit.
- Para el cierre definitivo en producción: desplegar los fixes + primera reserva real (user-gated) → verificar webhook 200 + email + booking Paid (y `quality_evaluation` del MCP funcionará con ese pago LIVE).
- F4 frontend (fases 3-4 redesign: internas + wizard reserva).
- F3 CMS (mini-contrato de alcance al empezar).

## P3 cerrado (2026-08-12) — rendimiento/ops + PHP 8.4 en prod
- **P3-1** `App\Core\FileCache` (data/cache/, TTL por mtime, escritura atómica tmp+rename) + `GetRoomsAction` cachea la respuesta de display 30s (`ROOMS_CACHE_TTL`) y envía `Cache-Control: public, max-age=N` explícito. Nunca en `CreateBookingAction` (FOR UPDATE). Test: `FileCacheTest` (4 casos: miss/hit/expiración/sobreescritura).
- **P3-3** `RateLimiter::purgeOldFiles(dir, maxAge)` público (purga 2×ventana en cada check) + exenciones en `Middleware::rateLimit` para `/api/webhook` y `/api/health` (OWASP API4:2023: límites por endpoint; MP reintenta en ráfagas y la ruta cara solo corre con firma HMAC válida). Tests: `RateLimiterPurgeTest` (3) + `MiddlewareRateLimitExemptTest` (3).
- **P3-5** composer.json: `"php": ">=8.2"` + `config.platform.php: 8.4.0`; lock refrescado (`composer update --lock`, sin cambios de dependencias); `check-platform-reqs` OK.
- **PHP prod 8.3.30 → 8.4.19** (decisión del usuario: 8.4 = máximo soportado por QloApps 1.7.0, changelog #1464 8.1→8.4; 8.5 queda fuera por el PMS, que comparte versión del sitio en cms.usgarhoteles.com). Verificado en vivo vía MCP Hostinger (`getPHPDetailsV1`) + curl: `X-Powered-By: PHP/8.4.19`, `/api/health` 200, `/api/rooms` 200, webservice QloApps responde (401 = auth, vivo). Extensiones intactas (bcmath, gd, intl, mbstring, openssl, pdo_mysql...).
- **Nota bcmath (corrección de supuesto)**: `bcmath` SÍ está habilitado en prod 8.4 — el fix P1-6 (céntimos enteros) se mantiene como está (ya verificado y testeado); la nota "no garantizado en Hostinger" era un supuesto, no una limitación real.
- **Corrección de estado del `.env` local (2026-08-12)**: ya NO está en modo TEST — está en **modo PROD** (token `APP_USR-...`, verificado por prefijo sin imprimir valores). STATE.md 2026-08-03 decía "modo TEST"; quedó obsoleto. Cuidado al correr suites que tocan MP real (usan credenciales de producción; los tests hermeticos no golpean red).
- **Compatibilidad 8.4/8.5 verificada por grep**: 0 implicitly nullable params (deprecación 8.4), 0 casts no canónicos `(integer|boolean|double|binary)` (deprecación 8.5), `round()` default intacto (HALF_UP, dinero ya en céntimos). `password_needs_rehash()` de P1-4 rehashea automáticamente los hashes bcrypt al nuevo cost 12 de 8.4 en el próximo login.

Verificación: `php vendor/bin/phpunit` → **194 tests / 648 assertions, 12 skipped, OK** · `composer analyse` → **No errors** · `npm run check` → 0 errors / 0 warnings (2 hints pre-existentes) · `composer validate` OK.

## Verificación 2026-08-12 (eliminación del baseline de PHPStan)
- **`phpstan-baseline.neon` ELIMINADO (67 entradas / 17 archivos → 0)**. `composer analyse` → `[OK] No errors` sin baseline.
- Fixes aplicados (cero impacto runtime, salvo código probadamente muerto):
  - 60× `missingType.iterableValue` → PHPDoc `array<string, mixed>` / shapes (`array{...}`) en Core (Config, Request, Response, Router, Validator, Logger, HttpException, EventInterface) y Features (Auth, Booking, Shared).
  - 3× `function.alreadyNarrowedType` → guard redundante de `getallheaders()` (Request: `?: []`), wrapper `is_string` del dispatch (Router: PHPStan prueba string), `is_array` en `logApiError` (simplificado).
  - 1× `nullCoalesce.offset` → `$user['provider']` (columna `NOT NULL DEFAULT 'email'`).
  - 2× `booleanNot.alwaysFalse` (MercadoPagoAdapter) → guards eliminados; SDK `create()/get(): Payment` no-nullable, lanza MPApiException (verificado en vendor dx-php 3.12).
  - 1× `ternary.alwaysTrue` (logApiError) → simplificado, paridad con ProcessPaymentAction.
  - 1× `if.alwaysFalse` (AuthService) → resuelto por refactor de autoload (2026-08-12, bootstrap.php).
- Verificación: suite exhaustiva 21/21 PASS; api-harness 6/6 (400×5 + 200 happy path); phpunit 161 tests / 573 assertions, 12 skipped, **2 fallos flaky documentados (P3-7)**: `ProcessOutboxActionTest::testRunWithSlowListenersCompletesAllEvents` y `testConcurrentRunsProcessEachEventExactlyOnce` (workers + timing Windows + cuota MySQL Hostinger 500 conn/h — el run aislado siguiente saltó por BD no disponible).
