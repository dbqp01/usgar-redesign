# Deploy y operación — USGAR Hotels

Fuente de verdad de deploy, cron jobs, hooks de git y secretos. Verificada contra las docs oficiales de Hostinger (2026-08-11: `docs.hostinger.com/node.js/github`).

## Deploy (Hostinger — automático desde GitHub)

Hostinger está conectado a GitHub (integración **Node.js web app**) y **solo mira la rama `main`**. No se sube nada manualmente; no existe rama `build` útil (el workflow `deploy-build-branch.yml` está desactivado y es historial; `origin/build` tiene contenido viejo que Hostinger no usa).

1. `git push origin main` → GitHub envía webhook → Hostinger hace pull, `npm install` (auto-detecta npm por lockfile), `npm run build`, y sirve el output. Un solo deploy a la vez; pushes extra se encolan.
2. El `postbuild` (package.json) copia `app/`, `vendor/` y `cron/` a `dist/` (Composer no existe en prod) y **elimina cualquier `dist/.env`**. Astro vuelca `public/*` en la raíz de `dist/`, así que el entry PHP queda en `dist/index.php` + `dist/.htaccess`.
   El `.htaccess` bloquea por HTTP el acceso directo a `app/`, `vendor/`, `cron/`, `logs/`, `storage/` y `runtime/`; esos directorios solo se conservan en el webroot para que los trabajos CLI puedan ejecutarse. Las revocaciones JWT se escriben en `runtime/revoked-tokens` junto al `.env` efectivo, fuera de `public_html` cuando esa raíz existe, y sobreviven al reemplazo de `dist`.
3. Config vigente en hPanel: branch `main` · build script `build` · output directory `dist` · Node **22** (fijado también en `engines` de package.json) · package manager npm.
4. Historial/logs de cada build: hPanel → Deployments (branch, commit, autor, estado, log completo).
5. Si el build falla durante `npm install`: primera causa = versión de Node vs `engines` (npm reintenta con `--legacy-peer-deps` antes de fallar).

**Rollback:** hPanel → Deployments → Redeploy de un build anterior, o `git revert`/push de un commit viejo a `main`.

## Variables de entorno en producción

- Fuente efectiva: hPanel → **Environment variables** (la integración las escribe en `/home/<usuario>/domains/usgarhoteles.com/.builds/config/.env`; se inyectan en build y runtime y persisten entre deploys).
- Fallback soportado por `app/Core/Config.php::loadEnv`: un `.env` **un nivel arriba de `public_html`**; si existe, tiene prioridad sobre las vars de hPanel.
- **Nunca** dentro de `public_html` ni de `dist/`. El build borra `dist/.env` y falla si quedara (guard del workflow desactivado, replicado en el postbuild). El directorio `runtime/` junto al `.env` efectivo debe ser escribible por PHP/cron; si no puede crearse o leerse, la autenticación falla cerrada hasta corregir permisos.
- Lista completa de variables: `.env.example` (canónico) + `docs/API.md` §Variables.

### ⚠️ DÓNDE se edita de verdad (verificado 2026-08-15 — hay DOS fuentes y una gana)

`loadEnv()` (app/Core/Config.php:130) evalúa candidatos **en orden y usa el primero que existe**:

1. `/home/u941268346/domains/usgarhoteles.com/.env` (un nivel arriba de `public_html`) — **el `.env` del File Manager**
2. `/home/u941268346/domains/usgarhoteles.com/.builds/config/.env` — **las "Variables de entorno" de hPanel**
3. `dist/.env` (raíz del deploy) — se borra en postbuild

**Si el `.env` del File Manager existe (caso real en prod), TODO cambio en hPanel → Variables de entorno se ignora silenciosamente** — el código nunca llega a leer el candidato 2. Esto confundió al dueño (2026-08-15): "cambio en hPanel, hago redeploy, y las credenciales no cambian; cambio el `.env` del File Manager y sí".
Los procesos CLI de cron aplican la misma precedencia externa aunque no reciban `DOCUMENT_ROOT`: si el código corre dentro del árbol `public_html`, buscan primero el `.env` junto a `public_html` y luego `.builds/config/.env`; el `.env` del checkout solo queda como fallback local.

**Cómo editar la configuración en producción (procedimiento para no-técnicos):**

1. hPanel → Archivos → **File Manager** (sitio `usgarhoteles.com`).
2. Subir a la raíz del dominio: la barra de navegación debe mostrar `domains/usgarhoteles.com/` (el `.env` está **junto a** `public_html`, no dentro).
3. Click derecho sobre `.env` → **Editar** (o descargar → editar → subir de vuelta).
4. Guardar. **No requiere redeploy ni push** — el código lo lee en cada request/cron. (Un redeploy no daña nada pero tampoco hace falta.)
5. `Config::get()` da prioridad al cache del boot, no a `getenv()`: tras editar el archivo, **no hay que reiniciar nada** (PHP-FPM lee el archivo en cada request; el cache se puebla por request).

**Backup del `.env` (imprescindible, no está en git ni en hPanel):** File Manager → click derecho sobre `.env` → **Descargar** → guardar una copia local (`backup-env-prod-<fecha>.env`). Es la ÚNICA copia: si el archivo se borra o se corrompe, el sitio pierde MP/QloApps/OAuth de golpe (hPanel tiene 39 claves y **enmascara los valores**: no se pueden recuperar por API).

**Regla de oro:** elegir UNA fuente y mantenerla. Hoy la fuente efectiva es el `.env` del File Manager; hPanel → Variables de entorno queda como espejo decorativo (no borrarlo, pero no confiar en él para cambios). Si algún día se quiere que hPanel mande: copiar TODAS las claves a hPanel **y borrar el `.env` del File Manager**.

### Migración a hPanel como fuente única (decidida 2026-10-19, pendiente de ejecutar)

Auditoría del entorno de Hostinger (2026-10-19) — estado real de las tres fuentes:

| Fuente | Claves | Estado |
|---|---|---|
| hPanel → Environment variables | 39 | Vigente pero **inerte**: el `.env` del File Manager gana siempre |
| `.env` del File Manager (un nivel arriba de `public_html`) | 38 | **Fuente efectiva real** |
| `.env.example` (canónico del repo) | 60 + 1 comentada | Documenta el contrato; le faltan 4 claves que el código lee |

Las 38 claves del `.env` del servidor son exactamente las canónicas que hPanel tiene; hPanel añade `MP_BINARY_MODE`, que el `.env` del servidor **no** tiene. Las dos fuentes ya difieren en una clave: al unificar, el valor efectivo de `MP_BINARY_MODE` puede pasar de `false` (default del código) al que tenga hPanel — hay que revisarlo antes de borrar nada.

**Procedimiento (lo ejecuta el dueño desde hPanel; sin push ni redeploy):**

1. **Descargar** el `.env` del File Manager → `backup-env-prod-2026-10-19.env` (única copia).
2. **Comparar clave por clave y valor por valor** el `.env` con hPanel → Environment variables. Secretos a cotejar: `DB_PASS`, `MERCADO_PAGO_ACCESS_TOKEN`, `PUBLIC_MERCADO_PAGO_PUBLIC_KEY`, `MERCADO_PAGO_WEBHOOK_SECRET`, `QLOAPP_API_KEY`, `CRON_SECRET`, `BOOKING_TOKEN_SECRET`, `AUTH_JWT_SECRET`, `PANEL_PASSWORD`, `GOOGLE_CLIENT_SECRET`.
3. **Completar** en hPanel las claves que falten hasta igualar el conjunto. `.env.example` es el checklist canónico.
4. **Preparar `runtime/`**: `TokenRevocationStore` lo crea **junto al `.env` efectivo**, así que al cambiar de fuente se mueve a `.builds/config/runtime/`. Crear esa carpeta escribible por PHP y **mover** el contenido actual si existe (si no puede crearse, la autenticación falla cerrada — ver §Deploy punto 2).
5. **Borrar** `/home/u941268346/domains/usgarhoteles.com/.env`. No requiere push ni redeploy: `loadEnv()` cae al candidato 2 en la siguiente request.
6. **Verificar**: `https://usgarhoteles.com/api/health` → healthy, y el cron `process_outbox` (cada 5 min) sigue entregando sin error. Si el cron falla con `No .env file found`, revertir restaurando el backup.
7. **Opcional (código, solo después de verificar)**: quitar el candidato 1 de `loadEnv()` para que el `.env` del File Manager no vuelva a ganar por sorpresa.

**Lo que NO cambia con la migración (verificado 2026-10-19):**

- El enlace de afiliado de Booking.com (`aid=311839`) está hardcodeado en `src/content/settings/settings.json` y `src/components/contact/ContactChannels.astro`, no en el `.env`.
- `REVIEW_BOOKING_URL` solo alimenta el scraping de puntajes (cron `refresh-reviews`); su default en código apunta al mismo hotel.
- Los emails transaccionales **ya están desactivados** en prod: no hay `SMTP_*` en ninguna de las dos fuentes (fail-soft documentado en `docs/STATE.md`).

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

## Cron jobs (Hostinger: Avanzado → Trabajos programados)

Verificado en Hostinger vía API (2026-08-14) — esta es la configuración REAL, no la teórica:

| Cada | Comando | Qué hace |
|---|---|---|
| 5 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/process_outbox.php` | Procesa `event_outbox`: eventos de dominio (booking.paid) que no se entregaron en la petición HTTP. Máquina de estados con reclaim/backoff/lease (ver cabecera de `ProcessOutboxAction`). **Desde 2026-08-14 también envía el email de confirmación (voucher) al huésped** vía `SendBookingConfirmationEmailListener` (PHPMailer + SMTP; si `SMTP_HOST` está vacío se omite sin reintentar) |
| **15 min** | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/reconcile_payments.php` | Consulta MP por holds pendientes con payment_id cuyo webhook nunca llegó; si está `approved`, completa la reserva y dispara `booking.paid` |
| 10 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/cleanup.php` | Marca `expired` los holds `pending` vencidos (auditoría 2026-08-11: el endpoint HTTP no tenía operador y la tabla acumulaba basura) |
| 20 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/index.php /api/cron/manual-review` | Lista holds en `manual_review`/`fraud_review` para revisión operativa (la acción CLI por defecto es `list`; no redispatcha pagos automáticamente) |
| **1 día** | `/usr/bin/php /home/<USER>/domains/usgarhoteles.com/public_html/index.php /api/cron/refresh-reviews` | Refresca los scores de reseñas (Booking/KAYAK/Expedia) → `app/storage/review-scores.json`; `GET /api/reviews-score` los sirve al ribbon de la home. Fallback por fuente (fetch/parse fallido → valor anterior). **REGISTRADO en hPanel 2026-08-15** (uid `egFzCUjpmW`, `0 3 * * *` = diario 03:00). Verificación manual: `php public/index.php /api/cron/refresh-reviews` → JSON con `scores` |

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
