# Deploy y operación — USGAR Hotels

Fuente de verdad de deploy, cron jobs, hooks de git y secretos. Verificada contra las docs oficiales de Hostinger (2026-08-11: `docs.hostinger.com/node.js/github`).

## Deploy (Hostinger — automático desde GitHub)

Hostinger está conectado a GitHub (integración **Node.js web app**) y **solo mira la rama `main`**. No se sube nada manualmente; no existe rama `build` útil (el workflow `deploy-build-branch.yml` está desactivado y es historial; `origin/build` tiene contenido viejo que Hostinger no usa).

1. `git push origin main` → GitHub envía webhook → Hostinger hace pull, `npm install` (auto-detecta npm por lockfile), `npm run build`, y sirve el output. Un solo deploy a la vez; pushes extra se encolan.
2. El `postbuild` (package.json) copia `app/`, `vendor/` y `cron/` a `dist/` (Composer no existe en prod) y **elimina cualquier `dist/.env`**. Astro vuelca `public/*` en la raíz de `dist/`, así que el entry PHP queda en `dist/index.php` + `dist/.htaccess`.
   El `.htaccess` bloquea por HTTP el acceso directo a `app/`, `vendor/`, `cron/`, `logs/`, `storage/` y `runtime/`; esos directorios solo se conservan en el webroot para que los trabajos CLI puedan ejecutarse. Las revocaciones JWT se escriben en `runtime/revoked-tokens` en la **raíz del dominio** (junto a `public_html`), fuera del árbol que el deploy reemplaza, y no se mueven al cambiar de fuente de `.env`.
   El log de la app (`app.log`) se escribe en **`/home/<usuario>/domains/usgarhoteles.com/logs/`** (raíz del dominio, fuera de `public_html`): el build reemplaza `public_html` entero, así que un log dentro se reiniciaba en cada push (verificado: el `app.log` de prod empezaba en la fecha del último build). Si esa carpeta no fuese escribible, `Logger` cae al `logs/` del árbol de deploy y, en último caso, a `error_log()`. **Verificado en prod el 2026-10-20** tras el deploy: `logs/app.log` aparece en la raíz del dominio con su `.htaccess` de protección y recibe tanto las líneas de los crons (`ReconcilePaymentsAction`, `CleanExpiredCartsAction`) como las de las peticiones HTTP (`HandleMercadoPagoWebhookAction`).
3. Config vigente en hPanel: branch `main` · build script `build` · output directory `dist` · Node **22** (fijado también en `engines` de package.json) · package manager npm.
4. Historial/logs de cada build: hPanel → Deployments (branch, commit, autor, estado, log completo).
5. Si el build falla durante `npm install`: primera causa = versión de Node vs `engines` (npm reintenta con `--legacy-peer-deps` antes de fallar).

**Rollback:** hPanel → Deployments → Redeploy de un build anterior, o `git revert`/push de un commit viejo a `main`.

## Variables de entorno en producción

- Archivo efectivo: **`/home/u941268346/domains/usgarhoteles.com/hbuilds/config/.env`** (39 claves, todas con valores entre comillas simples). Lo escribe hPanel; lo lee `app/Core/Config.php::loadEnv` en cada request/cron.
- Fallback soportado por `loadEnv`: un `.env` **un nivel arriba de `public_html`**, que solo se usa si hPanel no tiene archivo.
- **Nunca** dentro de `public_html` ni de `dist/`. El build borra `dist/.env` y falla si quedara (guard del workflow desactivado, replicado en el postbuild). El directorio `runtime/` junto al `.env` efectivo debe ser escribible por PHP/cron; si no puede crearse o leerse, la autenticación falla cerrada hasta corregir permisos.
- Lista completa de variables: `.env.example` (canónico) + `docs/API.md` §Variables.

### ⚠️ DÓNDE se edita de verdad (verificado 2026-10-19 — hPanel es la fuente única)

`loadEnv()` (app/Core/Config.php) evalúa candidatos **en orden y usa el primero que existe**:

1. `/home/u941268346/domains/usgarhoteles.com/hbuilds/config/.env` — **las "Variables de entorno" de hPanel** (ya en el código desplegado, commit `5361623`)
2. `/home/u941268346/domains/usgarhoteles.com/.env` — el `.env` del File Manager (fallback)
3. `dist/.env` (raíz del deploy) — se borra en postbuild

> **Corrección 2026-10-19:** hasta ese día el candidato 1 era el `.env` de la raíz del dominio, que gana siempre, y la ruta del archivo de hPanel estaba mal documentada (`.builds/config/.env` — **esa carpeta no existe**). Verificado en el File Manager: la ruta real es `hbuilds/config/.env`. Por eso "cambio en hPanel, hago redeploy, y las credenciales no cambian" (queja del dueño del 2026-08-15).

Los procesos CLI de cron aplican la misma precedencia aunque no reciban `DOCUMENT_ROOT`: si el código corre dentro del árbol `public_html`, la raíz del despliegue se detecta subiendo desde el propio archivo y el resultado es idéntico.

**Cómo editar la configuración en producción (procedimiento para no-técnicos):**

1. hPanel → **Despliegues** → **Ajustes y reimplementación** → sección **Variables de entorno** (la tabla con 40 filas: 39 claves + una vacía para añadir).
2. Editar el valor en la columna **Valor** y pulsar **«Guardar»** (o «Guardar y reimplementar», que además reconstruye).
3. **No requiere redeploy ni push**: «Guardar» reescribe `hbuilds/config/.env` y la app lo lee en el siguiente request. Verificado 2026-10-19 con `TIMEZONE`: `America/Lima → America/New_York` cambió el offset de `/api/health` de `-05:00` a `-04:00` en segundos, sin desplegar nada; se revirtió a `America/Lima` y volvió a `-05:00`.

> ⚠️ **Ojo, son dos superficies distintas** (comprobado el 2026-10-19): la página **Sitios web → Variables de entorno** (la tabla con «Mostrar»/«Actualizar»/«Eliminar») guarda el valor en el panel pero **NO reescribe el archivo**; el archivo solo se materializa con «Guardar» en la página de ajustes de despliegue. Editar en la primera y esperar el efecto no funciona: hay que usar la segunda.

**Los valores sí se pueden ver en la UI:** la tabla del sitio los enmascara, pero cada fila tiene un botón **«Mostrar»** que los revela en claro (la API de hPanel sí los devuelve enmascarados, `********`). El editor de la página de ajustes los muestra en claro directamente.

**Backup del `.env`:** con hPanel como fuente única, el respaldo de los valores es la propia tabla de hPanel (con «Mostrar») y el archivo del File Manager. Aun así, antes de tocar la configuración conviene descargar el `.env` del File Manager → guardar una copia local en `.backups/prod-env-<fecha>.env` (carpeta **gitignored**; nunca versionar secretos).

**Regla de oro:** hPanel → Variables de entorno es la fuente única. No volver a subir un `.env` a la raíz del dominio: volvería a ganar por precedencia y los cambios del panel dejarían de aplicarse.

### Puente entre el código desplegado y el archivo de hPanel (2026-10-19 · RESUELTO 2026-10-20)

**Estado: ya no existe.** Se resolvió al desplegar `5361623` (y `2cc9479`) en `main`.

*Lo que fue*: el código desplegado (`2a824b3`) solo conocía `raíz/.env` → `raíz/.builds/config/.env` → `raíz del proyecto/.env`. Como la raíz ya no tiene `.env` (renombrado), se creó un **symlink** `raíz/.builds/config/.env → hbuilds/config/.env` con un cron vigilante (`yr3HD0iHWR`, `*/5`, `ln -sfn …`) que lo recreaba cada 5 minutos.

*Cómo se retiró (2026-10-20, en este orden para no dejar el puente a medias)*:

1. **Borrar primero el cron vigilante** (`hosting_cron-jobs_delete`, uid `yr3HD0iHWR`): si siguiera vivo, recrearía el symlink.
2. **Borrar el symlink** con un cron de un solo binario: `/usr/bin/rm -f <raíz>/.builds/config/.env` — `rm` **no** sigue symlinks, borra el enlace y deja intacto `hbuilds/config/.env` (verificado: el archivo siguió en disco con su mtime).
3. **Borrar las carpetas vacías**: `/usr/bin/rmdir <raíz>/.builds/config` y `/usr/bin/rmdir <raíz>/.builds`.
4. Borrar los crons temporales y verificar: `hbuilds/config/.env` sigue ahí, `.builds/` ya no existe, y el sitio sigue sano.

> No borrar `hbuilds/config/.env` desde el File Manager esperando que borre el symlink: se depende del enlace. Para symlinks usar `rm` en un cron de un solo binario (o el File Manager, que también borra el enlace y no el destino).

*Rollback del puente* (si hubiera que volver atrás): recrear el symlink `raíz/.builds/config/.env → hbuilds/config/.env` y volver a desplegar el código antiguo; no hace falta tocar hPanel.

**Pendiente de seguridad:** `PANEL_PASSWORD` tiene hoy **el mismo valor** que `DB_PASS` (verificado en la tabla de hPanel). Rotar `PANEL_PASSWORD` por un valor propio y distinto.

### Migración a hPanel como fuente única (ejecutada 2026-10-19)

Auditoría del entorno de Hostinger (2026-10-19) — estado real de las tres fuentes:

| Fuente | Claves | Estado |
|---|---|---|
| hPanel → Variables de entorno (`hbuilds/config/.env`) | 39 | **Fuente única** |
| `.env` del File Manager (raíz del dominio) | 38 | Renombrado a `.env.pre-hpanel-2026-10-19` (rollback); ya no compite por precedencia |
| `.env.example` (canónico del repo) | 60 + 1 comentada | Documenta el contrato; le faltan 4 claves que el código lee |

**Comparación (2026-10-19, con el navegador: File Manager + tabla de variables de hPanel, valores comparados en memoria del navegador sin sacarlos de ahí):** las **38 claves del `.env` tienen valor idéntico** en hPanel — **0 diferencias**. hPanel añade una clave: `PANEL_PASSWORD`, que el `.env` no tenía.

**Qué cambió al unificar:**

- `PANEL_PASSWORD` pasa a estar configurado → `/panel` (disponibilidad del dueño) deja de estar bloqueado por el fail-closed.
- `MP_BINARY_MODE=true` **ya era** el valor efectivo (está en las dos fuentes) → el payload de MercadoPago no cambia.
- Las revocaciones JWT se quedan en `<raíz del dominio>/runtime/revoked-tokens`: `TokenRevocationStore` usa la raíz del despliegue, no la carpeta del `.env`, así que la lista existente no se invalida al cambiar de fuente.
- El código (`Config::envCandidates`) pone `hbuilds/config/.env` **antes** que el `.env` de la raíz: si alguien vuelve a subir un `.env` suelto, hPanel sigue mandando.

**Lo que NO cambia con la migración (verificado 2026-10-19):**

- El enlace de afiliado de Booking.com (`aid=311839`) está hardcodeado en `src/content/settings/settings.json` y `src/components/contact/ContactChannels.astro`, no en el `.env`.
- `REVIEW_BOOKING_URL` solo alimenta el scraping de puntajes (cron `refresh-reviews`); su default en código apunta al mismo hotel.
- Los emails transaccionales **ya están desactivados** en prod: no hay `SMTP_*` en ninguna de las dos fuentes (fail-soft documentado en `docs/STATE.md`).

**Si hay que repetirla (hacia atrás):** renombrar `.env.pre-hpanel-2026-10-19` de vuelta a `.env` en la raíz del dominio (eso vuelve a ganar por precedencia) y borrar el symlink.

## Credenciales de Mercado Pago

Panel: https://www.mercadopago.com/developers/panel/app

1. **Pruebas** (sandbox): sección *Pruebas* → *Credenciales de prueba* (Public Key + Access Token).
2. **Producción**: sección *Producción* → *Credenciales de producción* (activar completando los datos del negocio).
3. Configurar en `.env` (nunca en el repo): `MERCADO_PAGO_ACCESS_TOKEN`, `PUBLIC_MERCADO_PAGO_PUBLIC_KEY`, `MERCADO_PAGO_WEBHOOK_SECRET`, `MERCADO_PAGO_CURRENCY=PEN`.

> Importante (doc MP vigente): el **prefijo del token NO define el entorno** — los Access Tokens de prueba y producción pueden empezar ambos con `APP_USR`. El entorno lo define la app/panel. Por eso el deploy exige un **guard por allowlist de hashes** (no por prefijo).

**Guard de deploy:** antes de desplegar, `php scripts/check-prod-env.php` verifica que el token esté en la allowlist. El guard aplica SIEMPRE (fail-closed; desde 2026-08-15 ya no depende de `APP_ENV`, eliminada de raíz). Configúrala en `.env.production` (archivo FUERA de git, solo hashes):

```
MERCADO_PAGO_PROD_TOKEN_SHA256=<sha256 del Access Token de producción>
```

Generar el hash: `php -r "echo hash('sha256', getenv('MERCADO_PAGO_ACCESS_TOKEN'));"`. Si el token se rota o se expone: renovar credenciales desde el panel (las antiguas quedan activas 12 horas) y actualizar el hash.

**Webhook MP:** registrar en el panel el callback `https://usgarhoteles.com/api/webhook`, topic `payment`. `MERCADO_PAGO_WEBHOOK_SECRET` debe ser EXACTO al secreto del panel, o la firma HMAC rechaza todos los webhooks (401).

### Credenciales de Mercado Pago: app, token, public key y secreto van en pareja (verificado 2026-10-19/20)

En la cuenta hay **dos aplicaciones** y cada una tiene su propio par token/public key **y su propio secreto de webhook**:

| App | Credenciales | Comportamiento verificado | Uso |
|---|---|---|---|
| `8501374849722569` (la que expone el MCP de Mercado Pago) | "Test (Sandbox)" | **Son live para esta cuenta**: el card token devuelve `live_mode:true` y un pago con tarjeta de prueba responde 401 `Unauthorized use of live credentials`. `/v1/payments/search` ve los pagos reales. | Solo producción/credenciales reales |
| `3258714866708434` | token `TEST-…` + public key `TEST-…` | **Sandbox real**: `live_mode:false`, `/v1/payments/search` vacío, pago de prueba aprobado (1353921601) | Pruebas |

Reglas que se sacan de esa verificación:

1. **El webhook es por aplicación.** Registra el callback y rota el secreto **en la misma app** cuyas credenciales van en el `.env`; si el `MERCADO_PAGO_WEBHOOK_SECRET` es de otra app, la firma nunca valida (401).
2. **El secreto solo se rota desde el panel**: Webhooks → *Configurar notificación* → «Restablecer firma secreta». No hay API: `POST/GET /v1/webhooks` responde 404 y `save_webhook` del MCP solo acepta apps del usuario propietario ("The application is not from the user").
3. **`get_credentials` del MCP devuelve los secretos enmascarados** (`********`). No se puede copiar ese valor a una petición de reemplazo: hay que revelarlo en el panel o en el editor de hPanel («Mostrar»).
4. **Simulador del panel**: «Simular notificación» → «Nueva prueba» → **Tipo de evento = "Pagos"** + Data ID → «Enviar prueba». Si el tipo de evento no es "Pagos", no se envía nada. Resultado correcto: *«¡Excelente! Enviamos una notificación Webhook con éxito — 200 - OK»*.
5. El `ts` del header `x-signature` llega en **segundos** aunque la doc diga milisegundos → el adaptador normaliza las dos unidades (ver `docs/SECURITY.md`).

**Cambio a las credenciales reales (cuenta principal del dueño):**

1. En la app definitiva: copiar Access Token y Public Key.
2. Registrar/verificar el webhook `https://usgarhoteles.com/api/webhook` (topic "Pagos") **en esa app** y rotar su secreto.
3. hPanel → Despliegues → Ajustes y reimplementación → Variables de entorno → poner `MERCADO_PAGO_ACCESS_TOKEN`, `PUBLIC_MERCADO_PAGO_PUBLIC_KEY` y `MERCADO_PAGO_WEBHOOK_SECRET` → **«Guardar»** (sin redeploy; la app lo lee en el siguiente request).
4. Regenerar el hash del guard: `php -r "echo hash('sha256', getenv('MERCADO_PAGO_ACCESS_TOKEN'));"` → `MERCADO_PAGO_PROD_TOKEN_SHA256` en `.env.production` (fuera de git).
5. Ojo al entorno: con credenciales **live**, una tarjeta de prueba de MP da 401 (`Unauthorized use of live credentials`). Para la comprobación final hace falta una tarjeta real y un cobro real de importe mínimo; para las regresiones se sigue usando el par sandbox de la app `3258714866708434` (con su propio webhook y su propio secreto).

## Cron jobs (Hostinger: Avanzado → Trabajos programados)

Verificado en Hostinger vía API (2026-08-14) — esta es la configuración REAL, no la teórica:

| Cada | Comando | Qué hace |
|---|---|---|
| 5 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/process_outbox.php` | Procesa `event_outbox`: eventos de dominio (booking.paid) que no se entregaron en la petición HTTP. Máquina de estados con reclaim/backoff/lease (ver cabecera de `ProcessOutboxAction`). **Desde 2026-08-14 también envía el email de confirmación (voucher) al huésped** vía `SendBookingConfirmationEmailListener` (PHPMailer + SMTP; si `SMTP_HOST` está vacío se omite sin reintentar) |
| **15 min** | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/reconcile_payments.php` | Consulta MP por holds pendientes con payment_id cuyo webhook nunca llegó; si está `approved`, completa la reserva y dispara `booking.paid` |
| 10 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/cleanup.php` | Marca `expired` los holds `pending` vencidos (auditoría 2026-08-11: el endpoint HTTP no tenía operador y la tabla acumulaba basura) y **libera en el PMS** los holds de esas reservas caducadas (`releaseStaleWebserviceHolds`): sin esto la habitación queda bloqueada en el calendario de QloApps |
| 20 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/index.php /api/cron/manual-review` | Lista holds en `manual_review`/`fraud_review` para revisión operativa (la acción CLI por defecto es `list`; no redispatcha pagos automáticamente) |
| **1 día** | `/usr/bin/php /home/<USER>/domains/usgarhoteles.com/public_html/index.php /api/cron/refresh-reviews` | Refresca los scores de reseñas (Booking/KAYAK/Expedia) → `storage/review-scores.json` (raíz del dominio, fuera del árbol del deploy: si viviera dentro, cada build lo borraría); `GET /api/reviews-score` los sirve al ribbon de la home. Fallback por fuente (fetch/parse fallido → valor anterior). **REGISTRADO en hPanel 2026-08-15** (uid `egFzCUjpmW`, `0 3 * * *` = diario 03:00). Verificación manual: `php public/index.php /api/cron/refresh-reviews` → JSON con `scores` |


> Nota: el cron real de reconcile es **15 min** (no 10 como decía esta tabla antes — la doc estaba desincronizada de hPanel). El polling del frontend aguanta 10 min; si el webhook se pierde, el hold se resuelve en ≤15 min por el cron y el usuario puede pulsar "Verificar de nuevo".

Requisito de BD (ya aplicado en prod): columnas `payment_id` y `payment_idempotency_key` en `provisional_bookings`. El código auto-crea/migra ambas vía `ensureTablesExist` o en el primer intento de pago de instalaciones antiguas; la DDL se ejecuta fuera de la transacción del cobro.

Verificación manual:
```bash
php cron/process_outbox.php        # Esperado: "No events to process." o lista de procesados
php cron/reconcile_payments.php    # Esperado: "Reconciliacion completada: checked=0, ..."
```

El cron de `manual-review` solo genera el listado anterior. Para re-chequear y redispatchar un pago se requiere una invocación explícita con `action=redispatch` y `cart_id`, protegida por `CRON_SECRET` cuando se usa HTTP; no se automatiza esa transición porque puede confirmar un pago y disparar la sincronización con el PMS.

## Hooks de git

Configuración: `core.hooksPath = .githooks` (definido por `npm run prepare`).

| Hook | Qué hace |
|---|---|
| `pre-commit` | Comprime media staged: `.mp4` (ffmpeg, tag `USGAR_COMPRESSED` en metadatos para no re-comprimir) e imágenes (sharp vía `scripts/compress-images.js`). Nunca bloquea el commit |
| `post-commit` / `post-checkout` | Reconstruyen el knowledge graph de graphify |

Requisitos: `ffmpeg` + `ffprobe` en PATH (`winget install Gyan.FFmpeg`), `node`, `sharp` (npm install). El hook es PowerShell puro (shebang `#!/usr/bin/env powershell`) porque el `sh` de Windows resuelve al relay de WSL (falla con `execvpe(/bin/bash)`).

**Limitación conocida:** los commits vía MCP de git invocan `bash` explícitamente y fallan con el relay de WSL, independientemente del hook. Usar terminal/PowerShell para commits, o `git commit --no-verify` si se usa el MCP (el hook ya se validó por terminal).

## Sitios y subdominios (Hostinger, verificado 2026-08-07)

- `usgarhoteles.com` — sitio principal (integración Node.js GitHub, rama `main`).
- `cms.usgarhoteles.com` — QloApps (PMS).
- `app.usgarhoteles.com` — addon (app web Flutter `hotel_usgar`, fuera del repo).
- `admin.usgarhoteles.com` — **ya no existe** (panel Filament eliminado 2026-08-05). Si `public_html/admin` sigue en disco, retirarlo por File Manager (no lo cubre el deploy de main).
