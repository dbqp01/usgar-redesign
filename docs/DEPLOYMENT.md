# Deploy y operación — USGAR Hotels

Fuente de verdad de deploy, cron jobs, hooks de git y secretos. Verificada contra las docs oficiales de Hostinger (2026-08-11: `docs.hostinger.com/node.js/github`).

## Deploy (Hostinger — automático desde GitHub)

Hostinger está conectado a GitHub (integración **Node.js web app**) y **solo mira la rama `main`**. No se sube nada manualmente; no existe rama `build` útil (el workflow `deploy-build-branch.yml` está desactivado y es historial; `origin/build` tiene contenido viejo que Hostinger no usa).

1. `git push origin main` → GitHub envía webhook → Hostinger hace pull, `npm install` (auto-detecta npm por lockfile), `npm run build`, y sirve el output. Un solo deploy a la vez; pushes extra se encolan.
2. El `postbuild` (package.json) copia `app/`, `vendor/` y `cron/` a `dist/` (Composer no existe en prod) y **elimina cualquier `dist/.env`**. Astro vuelca `public/*` en la raíz de `dist/`, así que el entry PHP queda en `dist/index.php` + `dist/.htaccess`.
3. Config vigente en hPanel: branch `main` · build script `build` · output directory `dist` · Node **22** (fijado también en `engines` de package.json) · package manager npm.
4. Historial/logs de cada build: hPanel → Deployments (branch, commit, autor, estado, log completo).
5. Si el build falla durante `npm install`: primera causa = versión de Node vs `engines` (npm reintenta con `--legacy-peer-deps` antes de fallar).

**Rollback:** hPanel → Deployments → Redeploy de un build anterior, o `git revert`/push de un commit viejo a `main`.

## Variables de entorno en producción

- Fuente efectiva: hPanel → **Environment variables** (la integración las escribe en `/home/<usuario>/domains/usgarhoteles.com/.builds/config/.env`; se inyectan en build y runtime y persisten entre deploys).
- Fallback soportado por `app/Core/Config.php::loadEnv`: un `.env` **un nivel arriba de `public_html`**; si existe, tiene prioridad sobre las vars de hPanel.
- **Nunca** dentro de `public_html` ni de `dist/`. El build borra `dist/.env` y falla si quedara (guard del workflow desactivado, replicado en el postbuild).
- Lista completa de variables: `.env.example` (canónico) + `docs/API.md` §Variables.

## Credenciales de Mercado Pago

Panel: https://www.mercadopago.com/developers/panel/app

1. **Pruebas** (sandbox): sección *Pruebas* → *Credenciales de prueba* (Public Key + Access Token).
2. **Producción**: sección *Producción* → *Credenciales de producción* (activar completando los datos del negocio).
3. Configurar en `.env` (nunca en el repo): `MERCADO_PAGO_ACCESS_TOKEN`, `PUBLIC_MERCADO_PAGO_PUBLIC_KEY`, `MERCADO_PAGO_WEBHOOK_SECRET`, `MERCADO_PAGO_CURRENCY=PEN`.

> Importante (doc MP vigente): el **prefijo del token NO define el entorno** — los Access Tokens de prueba y producción pueden empezar ambos con `APP_USR`. El entorno lo define la app/panel. Por eso el deploy exige un **guard por allowlist de hashes** (no por prefijo).

**Guard de deploy:** antes de desplegar con `APP_ENV=production`, `php scripts/check-prod-env.php` verifica que el token esté en la allowlist. Configúrala en `.env.production` (archivo FUERA de git, solo hashes):

```
MERCADO_PAGO_PROD_TOKEN_SHA256=<sha256 del Access Token de producción>
```

Generar el hash: `php -r "echo hash('sha256', getenv('MERCADO_PAGO_ACCESS_TOKEN'));"`. Si el token se rota o se expone: renovar credenciales desde el panel (las antiguas quedan activas 12 horas) y actualizar el hash.

**Webhook MP:** registrar en el panel el callback `https://usgarhoteles.com/api/webhook`, topic `payment`. `MERCADO_PAGO_WEBHOOK_SECRET` debe ser EXACTO al secreto del panel, o la firma HMAC rechaza todos los webhooks (401).

## Cron jobs (Hostinger: Avanzado → Trabajos programados)

| Cada | Comando | Qué hace |
|---|---|---|
| 5 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/process_outbox.php` | Procesa `event_outbox`: eventos de dominio (booking.paid) que no se entregaron en la petición HTTP. Máquina de estados con reclaim/backoff/lease (ver cabecera de `ProcessOutboxAction`) |
| 10 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/reconcile_payments.php` | Consulta MP por holds pendientes con payment_id cuyo webhook nunca llegó; si está `approved`, completa la reserva y dispara `booking.paid` |
| 10 min | `php /home/<USER>/domains/usgarhoteles.com/public_html/cron/cleanup.php` | Marca `expired` los holds `pending` vencidos (auditoría 2026-08-11: el endpoint HTTP no tenía operador y la tabla acumulaba basura) |

Requisito de BD (ya aplicado en prod): columna `payment_id` en `provisional_bookings` (el código la auto-crea vía `ensureTablesExist` en entornos nuevos).

Verificación manual:
```bash
php cron/process_outbox.php        # Esperado: "No events to process." o lista de procesados
php cron/reconcile_payments.php    # Esperado: "Reconciliacion completada: checked=0, ..."
```

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
