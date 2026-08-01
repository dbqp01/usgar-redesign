# Refactor USGAR Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactorizar el backend PHP ADR + frontend Astro de USGAR Hotels: QA real (PHPUnit + PHPStan), eliminar residuos Checkout Pro de MercadoPago, reparar el outbox (webhooks/cron), DIP/DI efectivo, limpiar secretos expuestos en git.

**Architecture:** Framework casero en `app/Core/` (Container con autowiring por Reflection, Config singleton que parsea `.env`, Router tabla plana, EventDispatcher con outbox `event_outbox`). Features en `app/Features/` (Auth, Booking, Webhooks, Shared, Cron). Front controller `public/index.php`. Frontend Astro estático en `src/`. La pasarela de pagos SE MANTIENE en MercadoPago (Checkout API).

**Tech Stack:** PHP 8.x, `mercadopago/dx-php ^3.12`, `hybridauth/hybridauth ^3.13`, Composer (composer.phar), PHPUnit ^11 (dev), PHPStan ^2 (dev), Astro, vitest.

## Global Constraints

- Zero hardcoding: precios, tokens, emails, URLs → `.env` (inyectadas) o BD. Nunca literales en código.
- Secretos NUNCA en texto plano en archivos versionados. `.env` no se commitea; `.env.example` solo con placeholders.
- Prepared statements para SQL (jamás concatenar).
- SRP estricto; DIP: inyectar dependencias por interfaz, no instanciar servicios dentro de otro.
- PHP 8 sin extensiones raras; debe correr en Hostinger (PHP 8.x + MySQL).
- Los tests deben pasar con: `php composer.phar test` (runner unificado).
- No romper el flujo de pago existente: Checkout API (payment_id + status), provisional_bookings, processed_payments.
- Windows y Linux: rutas con DIRECTORY_SEPARATOR donde aplique; no depender de `NUL`.
- El refactor de pagos NO cambia el comportamiento observado por el cliente (tests de caracterización lo garantizan).

---

### Task P0-1: Instalar PHPUnit ^11 y PHPStan ^2 como dev-deps

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml.dist`
- Modify: `phpstan.neon` (añadir tests)

**Interfaces:**
- Consumes: `composer.phar` (ya presente en la raíz)
- Produces: `vendor/bin/phpunit` y `vendor/bin/phpstan` ejecutables

- [ ] **Step 1: Añadir dev-deps y scripts**

```json
{
    "require": {
        "hybridauth/hybridauth": "^3.13",
        "mercadopago/dx-php": "^3.12"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^2.0"
    },
    "scripts": {
        "test": "phpunit",
        "analyse": "phpstan analyse",
        "check": [
            "@test",
            "@analyse"
        ]
    }
}
```

- [ ] **Step 2: Instalar**

Run: `php composer.phar require --dev phpunit/phpunit:^11 phpstan/phpstan:^2`
Expected: vendor/bin/phpunit y vendor/bin/phpstan existen.

- [ ] **Step 3: Crear phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 4: Ejecutar suite existente**

Run: `php composer.phar test`
Expected: los 17 archivos de tests corren bajo PHPUnit real. Si el polyfill (tests/Unit/TestCase.php) colisiona con PHPUnit real, ver Task P0-2.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock phpunit.xml.dist
git commit -m "test: add PHPUnit 11 and PHPStan 2 dev dependencies"
```

---

### Task P0-2: Sustituir polyfill de PHPUnit por el real

**Files:**
- Modify: `tests/Unit/TestCase.php` (borrar polyfill si colisiona)
- Modify: cada test que dependa del polyfill (extender `PHPUnit\Framework\TestCase` real)

**Interfaces:**
- Consumes: PHPUnit ^11 instalado (P0-1)
- Produces: tests corriendo contra PHPUnit real con asserts reales (mocks con `willReturn`, `expects`)

- [ ] **Step 1: Detectar colisión**

Run: `php composer.phar test`
Expected (si falla): `Cannot declare class PHPUnit\Framework\TestCase, because the name is already in use` → el polyfill estorba.

- [ ] **Step 2: Borrar polyfill**

Eliminar `tests/Unit/TestCase.php` (o el `if (!class_exists(...))` queda inofensivo porque PHPUnit real ya declara la clase — en ese caso NO tocar nada).

- [ ] **Step 3: Ejecutar suite**

Run: `php composer.phar test`
Expected: PASS en todos los tests, o fallos únicamente por asserts del polyfill que el real no soporta (p.ej. `assertNotEquals` con tipos estrictos). Arreglar uno a uno, solo lo que falle.

- [ ] **Step 4: Commit**

```bash
git add tests/ phpunit.xml.dist
git commit -m "test: replace hand-rolled TestCase polyfill with real PHPUnit"
```

---

### Task P0-3: PHPStan baseline real

**Files:**
- Modify: `phpstan.neon` (paths tests + baseline)

**Interfaces:**
- Consumes: PHPStan ^2 instalado (P0-1)
- Produces: `phpstan-baseline.neon` con errores existentes congelados; nuevos errores rompen CI

- [ ] **Step 1: Ejecutar PHPStan sin baseline**

Run: `php vendor/bin/phpstan analyse --no-progress 2>&1`
Expected: lista de errores de nivel 6. Registrar el conteo.

- [ ] **Step 2: Generar baseline**

Run: `php vendor/bin/phpstan analyse --generate-baseline --no-progress`
Expected: `phpstan-baseline.neon` creado.

- [ ] **Step 3: Verificar cero errores nuevos**

Run: `php vendor/bin/phpstan analyse --no-progress`
Expected: exit 0.

- [ ] **Step 4: Commit**

```bash
git add phpstan.neon phpstan-baseline.neon
git commit -m "chore: freeze PHPStan baseline at current error level"
```

---

### Task P0-4: Runner unificado + documentar estado en STATE.md

**Files:**
- Create: `docs/refactoring/STATE.md` (estado inicial documentado)
- Create: `docs/refactoring/DECISIONS.md` (decisiones tomadas)

**Interfaces:**
- Consumes: P0-1..3
- Produces: línea base verificable para el resto del plan

- [ ] **Step 1: Escribir STATE.md inicial**

Documentar: versión PHP, deps, nº tests, nivel PHPStan + baseline, secretos conocidos en git, rutas del front controller.

- [ ] **Step 2: Escribir DECISIONS.md**

Registrar: mantener MercadoPago (no Payoneer/Stripe), PHPUnit real + PHPStan como QA, reparar outbox (bootstrap compartido + cron + reconciliación), rotación de secretos manual por el usuario + limpieza automática, alcance sin Nobeds/Filament (fases futuras).

- [ ] **Step 3: Commit**

```bash
git add docs/refactoring/
git commit -m "docs: materialize refactoring plan state and decisions"
```

---

### Task P1-1: Test de caracterización de MercadoPagoAdapter

**Files:**
- Create: `tests/Unit/Features/Shared/MercadoPagoAdapterTest.php`

**Interfaces:**
- Consumes: `app/Features/Shared/Adapters/MercadoPagoAdapter.php` (API actual sin cambios)
- Produces: red de seguridad que prueba comportamiento observable: crearPayment con payload correcto, manejo de error, cast de paymentId.

- [ ] **Step 1: Escribir test que refleja el contrato actual**

Leer `MercadoPagoAdapter.php` completo primero. Test con mock de `MercadoPago\Client\Payment\PaymentClient`:
- `createHold(amount, currency, token, ...)` llama a `PaymentClient->create` con payload con `payment_method_id: 'card'`, `token`, `description`, `transaction_amount`, `currency_id` y `notification_url` del Config.
- El adapter NO convierte `(int)` el paymentId (string).
- Error del SDK → excepción propia del adapter con mensaje legible.

- [ ] **Step 2: Ejecutar y ajustar**

Run: `php composer.phar test -- --filter MercadoPagoAdapter`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/
git commit -m "test: characterize MercadoPagoAdapter contract"
```

---

### Task P1-2: Test de caracterización de HandleMercadoPagoWebhookAction

**Files:**
- Create: `tests/Unit/Features/Webhooks/HandleMercadoPagoWebhookActionTest.php`

**Interfaces:**
- Consumes: `app/Features/Webhooks/Actions/HandleMercadoPagoWebhookAction.php` (API actual)
- Produces: verificación de: firma HMAC validada, `type=payment` procesado, `type` distinto ignorado, idempotencia (payment ya procesado → no re-procesa).

- [ ] **Step 1: Escribir test de caracterización**

Leer el action completo. Mock de repositorios (ProvisionalBookingRepository, etc.) y del EventDispatcher. Casos:
1. Firma inválida → 401 y no toca repos.
2. Tipo no `payment` → responde 200 sin procesar.
3. Payment ya procesado → no re-procesa (verificar processed_payments).
4. Payment aprobado con booking provisional → dispara BookingPaidEvent.

- [ ] **Step 2: Ejecutar y ajustar**

Run: `php composer.phar test -- --filter HandleMercadoPagoWebhook`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/
git commit -m "test: characterize webhook action contract"
```

---

### Task P1-3: Eliminar residuos Checkout Pro del backend

**Files:**
- Modify: `app/Features/Booking/...` (quitar `updatePreferenceId()` y usos de `preference_id`)
- Modify: `app/Features/Shared/Adapters/MercadoPagoAdapter.php` (quitar `createHoldAndPreference`, dejar `createHold`; quitar `(int)` cast si procede tras P1-1)
- Modify: `app/Features/Webhooks/Actions/HandleMercadoPagoWebhookAction.php:111-116` (quitar filtro IPN legacy)
- Modify: `public/index.php` si registra rutas de Checkout Pro legacy
- Delete: `app/Features/Webhooks/Actions/WebhookDebugAction.php` si es dead code

**Interfaces:**
- Consumes: tests P1-1/P1-2 como red de seguridad
- Produces: código sin rastro de preference/init_point en PHP

- [ ] **Step 1: Buscar todos los usos**

Run: `rg -n "preference|init_point|createHoldAndPreference|WebhookDebug" app/ public/`
Expected: lista completa de sitios a tocar.

- [ ] **Step 2: Eliminar uno a uno, corriendo tests tras cada eliminación**

Para cada sitio: borrar; Run: `php composer.phar test` → PASS.

- [ ] **Step 3: Commit**

```bash
git add app/ public/
git commit -m "refactor: remove legacy Checkout Pro preference code"
```

---

### Task P1-4: Eliminar residuos Checkout Pro del frontend

**Files:**
- Modify: `src/lib/IBookingService.ts:41-42` (quitar `init_point`/`preference_url` del tipo)
- Modify: `src/...` donde se consuma `preferenceUrl`/`initPoint`
- Modify: `src/pages/...` (flujo de pago si renderiza botón de preference)

**Interfaces:**
- Consumes: contrato de la API tras P1-3 (sin preference_id)
- Produces: frontend sin rastro Checkout Pro

- [ ] **Step 1: Buscar usos**

Run: `rg -n "preference|init_point|initPoint" src/`
Expected: lista de sitios.

- [ ] **Step 2: Eliminar y verificar tipos**

Run: `npx astro check` y `npx tsc --noEmit` si está configurado → sin errores de tipos.

- [ ] **Step 3: Commit**

```bash
git add src/
git commit -m "refactor: remove legacy Checkout Pro references from frontend"
```

---

### Task P1-5: Diagnóstico de webhooks en producción (MCP MercadoPago)

**Files:**
- No code. Herramientas: `mercadopago-mcp-server_notifications_history`, `mercadopago-mcp-server_save_webhook`.

**Interfaces:**
- Consumes: MCP MP activo (token APP_USR en config)
- Produces: webhook registrado en la app MP + historial monitoreable

- [ ] **Step 1: Consultar historial**

Run: `mercadopago-mcp-server_notifications_history` (application_id 8501374849722569)
Expected: estado actual de entregas. Documentar hallazgo en STATE.md.

- [ ] **Step 2: Registrar webhook (con confirmación del usuario)**

Run: `mercadopago-mcp-server_save_webhook` con callback `https://usgarhoteles.com/api/webhook`, topic `payment`.
Expected: 200 OK.

- [ ] **Step 3: Documentar en STATE.md**

Resultado + fecha para monitoreo durante el refactor.

- [ ] **Step 4: Commit**

```bash
git add docs/refactoring/STATE.md
git commit -m "docs: webhook registration diagnostics"
```

---

### Task P2-1: Crear app/bootstrap.php compartido

**Files:**
- Create: `app/bootstrap.php`
- Modify: `public/index.php` (usar bootstrap en vez de registrar listeners inline)

**Interfaces:**
- Consumes: `app/Core/Container.php`, `app/Core/Config.php` (`Config::boot()`), `app/Core/Autoloader.php`
- Produces: `function app(): Container` (o equivalente) + listeners de dominio registrados UNA vez (BookingPaidEvent → ConfirmQloAppsOrderListener + SyncChannexBookingListener)

- [ ] **Step 1: Escribir app/bootstrap.php**

Contenido: autoload (`require vendor/autoload.php` + Autoloader propio si falta PSR-4), `Config::boot()` desde `.env`, construir Container con `$container->set(...)` para Database/Logger/EventDispatcher, registrar listeners. Exportar acceso global `app()` (retorna Container).

- [ ] **Step 2: Refactorizar public/index.php**

Reemplazar el bloque que construye el container y registra listeners (líneas ~58-74 y donde registre listeners) por `require app/bootstrap.php`.

- [ ] **Step 3: Verificar**

Run: test e2e manual: `php -S localhost:8080 -t public` y curl a `/api/health` (o ruta existente) → responde.
Expected: misma respuesta que antes.

- [ ] **Step 4: Commit**

```bash
git add app/bootstrap.php public/index.php
git commit -m "refactor: extract shared bootstrap with domain listeners"
```

---

### Task P2-2: Reparar cron/process_outbox.php

**Files:**
- Modify: `cron/process_outbox.php` (usar `app/bootstrap.php`)

**Interfaces:**
- Consumes: `app/bootstrap.php` (P2-1), `app/Core/Events/EventDispatcher.php`
- Produces: script que procesa `event_outbox` sin depender de `index.php`

- [ ] **Step 1: Reescribir el script**

Sustituir el `require app/bootstrap.php` inexistente por el nuevo bootstrap; procesar outbox: SELECT pendientes, despachar, marcar procesados, con límite de lotes y sleep entre lotes. Logging a `logs/outbox.log`.

- [ ] **Step 2: Verificar ejecución**

Run: `php cron/process_outbox.php --dry-run` (si soporta) o ejecución real con outbox vacío → sale 0 sin errores.

- [ ] **Step 3: Commit**

```bash
git add cron/process_outbox.php
git commit -m "fix: wire outbox cron to shared bootstrap"
```

---

### Task P2-3: Cablear cron en Hostinger

**Files:**
- Create: `docs/refactoring/CRON.md` (instrucciones de despliegue)

**Interfaces:**
- Consumes: P2-1, P2-2
- Produces: documentación exacta para el panel de Hostinger (comando cron)

- [ ] **Step 1: Documentar**

Comando: `php /home/uXXXXX/usgar.redesign/cron/process_outbox.php` cada 5 minutos. Verificar que el path del `.env` sea absoluto o relativo correcto.

- [ ] **Step 2: Preguntar al usuario si configura el cron en el panel (o dar pasos)**

No ejecutar nada remoto sin confirmación.

- [ ] **Step 3: Commit**

```bash
git add docs/refactoring/CRON.md
git commit -m "docs: cron setup instructions for outbox processor"
```

---

### Task P2-4: ReconcilePaymentsJob

**Files:**
- Create: `app/Features/Cron/Actions/ReconcilePaymentsAction.php` (o nombre acorde a convención)
- Modify: `cron/reconcile_payments.php` (o añadir al mismo cron)

**Interfaces:**
- Consumes: `ProvisionalBookingRepository`, `MercadoPagoAdapter` (o client de búsqueda de pagos)
- Produces: acción que busca bookings provisionales pendientes y consulta a MP el estado real del payment, actualizando/despachando eventos si MP dice "approved" y el webhook no llegó.

- [ ] **Step 1: Escribir el action**

Búsqueda: provisional_bookings con estado pendiente y `payment_id` no nulo, creados hace > 5 min. Para cada uno: `GET /v1/payments/{id}` (vía adapter). Si `status=approved` y no hay processed_payment → marcar procesado + despachar BookingPaidEvent.

- [ ] **Step 2: Escribir test**

Mock repos: booking pendiente + MP devuelve approved → evento disparado; MP devuelve pending → no toca; sin payment_id → skip.

- [ ] **Step 3: Ejecutar**

Run: `php composer.phar test -- --filter Reconcile`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/ tests/
git commit -m "feat: add payment reconciliation job"
```

---

### Task P3-1: DIP — dependencias por interfaz no-nullable

**Files:**
- Modify: constructor de `HandleMercadoPagoWebhookAction`, `ProcessPaymentAction`, `CreateBookingAction`, `GetBookingStatusAction` (params nullable → interfaz concreta)
- Modify: `app/Core/Container.php` si hace falta registrar bindings interfaz→implementación

**Interfaces:**
- Consumes: container con autowiring (Reflection)
- Produces: constructores tipados con interfaces (p.ej. `ProvisionalBookingRepositoryInterface` o la clase concreta), sin `?Tipo $dep = null`

- [ ] **Step 1: Auditar constructores**

Run: `rg -n "= null\)" app/Features/*/Actions/ app/Features/Shared/` → lista de params nullable.

- [ ] **Step 2: Reemplazar nullable por tipo concreto (o interfaz + binding en container)**

Para cada uno: quitar `?` y default; si el container no puede resolver, añadir binding `$container->set(Interface::class, fn($c) => new Concreto(...))`.

- [ ] **Step 3: Verificar**

Run: `php composer.phar test` + `php composer.phar analyse` → PASS.

- [ ] **Step 4: Commit**

```bash
git add app/
git commit -m "refactor: make constructor dependencies non-nullable interfaces"
```

---

### Task P3-2: Quitar clone en index.php

**Files:**
- Modify: `public/index.php:58-74`

**Interfaces:**
- Consumes: P3-1
- Produces: container compartido sin `clone`

- [ ] **Step 1: Leer el bloque**

Ver qué clona y por qué. Eliminar el clone; si era para aislar estado por request, verificar que los singletons no guarden estado por-request (si lo guardan, documentar y pasar la instancia sin clonar).

- [ ] **Step 2: Verificar**

Run: `php composer.phar test` + smoke test HTTP.

- [ ] **Step 3: Commit**

```bash
git add public/index.php
git commit -m "refactor: remove container clone in front controller"
```

---

### Task P4-1: Limpiar secretos del repo

**Files:**
- Modify: `.env.example` (reemplazar credenciales reales por placeholders)
- Modify: `tests/test_sdk_webhook.php:27` (webhook secret → env `MP_WEBHOOK_SECRET`)
- Modify: `scripts/run-stress-tests.php:85` (idem)
- Verify: `git log -p` de archivos con secretos (los commits viejos conservan secretos — rotar en P4-2)

**Interfaces:**
- Consumes: —
- Produces: working tree sin secretos; archivos leen de env con fallback para tests

- [ ] **Step 1: Audit**

Run: `rg -rn "APP_USR|TEST-|password|passwd|api_key|secret" --glob '!vendor/**' --glob '!.git/**' .`
Expected: lista de sitios.

- [ ] **Step 2: Sustituir por env**

`.env.example`: `DB_HOST=localhost`, `DB_NAME=your_db`, etc. Tests: `getenv('MP_WEBHOOK_SECRET')` con fallback `'test-secret'` (para que CI sin env no falle).

- [ ] **Step 3: Verificar**

Run: `rg -n "APP_USR|TEST-" --glob '!vendor/**' --glob '!.git/**' .` → sin resultados en archivos versionados (excepto opensecrets legítimos de test).

- [ ] **Step 4: Commit**

```bash
git add .env.example tests/ scripts/
git commit -m "security: remove production secrets from repository"
```

---

### Task P4-2: Rotación de credenciales (usuario)

**Files:**
- No code (acción manual del usuario)

**Interfaces:**
- Consumes: —
- Produces: tokens/contraseñas nuevos; los viejos (presentes en git history) quedan inválidos

- [ ] **Step 1: Pedir al usuario rotar**

Listar: token MP APP_USR, credenciales BD, webhook secret, secret de sesión. El usuario rota en los paneles. Marcar como DONE solo tras confirmación explícita.

---

### Task P4-3: Unificar CSP

**Files:**
- Modify: `.htaccess:26` (quitar CSP duplicada) o `app/Core/Middleware.php:94` (quitar la de ahí) — dejar UNA fuente de verdad

**Interfaces:**
- Consumes: —
- Produces: CSP única definida en código (Middleware) o en .htaccess

- [ ] **Step 1: Comparar ambas**

Leer las dos CSP. Decidir: mantener la de Middleware (se aplica siempre, incluye dev server) y eliminar la del .htaccess (o viceversa si .htaccess cubre estáticos).

- [ ] **Step 2: Eliminar la duplicada y verificar**

Run: `php -S localhost:8080 -t public` + curl -I → header CSP presente, una sola.

- [ ] **Step 3: Commit**

```bash
git add .htaccess app/Core/Middleware.php
git commit -m "security: unify CSP to single source of truth"
```

---

### Task P5-1: Eliminar dead code

**Files:**
- Delete: `app/Features/Webhooks/Actions/WebhookDebugAction.php` (si no tiene rutas registradas)
- Modify: `tests/scripts/postman-payment-suite.php:112-114` (aserciones obsoletas de preference)
- Otros hallazgos de `rg` en P1-3

**Interfaces:**
- Consumes: tests pasando
- Produces: repo sin archivos/lineas muertas

- [ ] **Step 1: Confirmar que es dead code**

Run: `rg -n "WebhookDebugAction" app/ public/` → sin usos.
Run: `rg -n "postman-payment-suite" docs/ README.md` → referencias.

- [ ] **Step 2: Eliminar**

Borrar archivo + líneas obsoletas.

- [ ] **Step 3: Verificar**

Run: `php composer.phar test` → PASS.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: remove dead code and obsolete assertions"
```

---

### Task P5-2: Hardcodes a configuración

**Files:**
- Modify: `.env.example` + `app/Core/Config.php` (o donde se lean) — añadir claves
- Modify: sitios con hardcodes:
  - `EXCHANGE_RATE_USD_PEN=3.80` → env
  - `https://cms.hotelesusgar.com`, `https://api.channex.io`, `localhost:4321` → env
  - `id_hotel=1` → env o BD
  - emails → env

**Interfaces:**
- Consumes: Config singleton
- Produces: valores configurables vía .env con defaults en Config

- [ ] **Step 1: Auditar**

Run: `rg -n "3\.80|cms\.hotelesusgar\.com|api\.channex\.io|localhost:4321|id_hotel.?=.?1|@" app/ src/` → lista.

- [ ] **Step 2: Mover a Config**

Para cada valor: añadir clave en `.env.example` + método getter en Config (o ya existente genérico `Config::get('key')`), reemplazar literal.

- [ ] **Step 3: Verificar**

Run: `php composer.phar test` + `php composer.phar analyse` → PASS.

- [ ] **Step 4: Commit**

```bash
git add app/ src/ .env.example
git commit -m "refactor: externalize hardcoded values to configuration"
```

---

### Task P5-3: Unificar glob de tests de vitest

**Files:**
- Modify: `vitest.config.ts` (o package.json) — `tests/unit` → `tests/Unit` (case)

**Interfaces:**
- Consumes: —
- Produces: vitest descubre los tests frontend

- [ ] **Step 1: Verificar glob actual**

Run: `npx vitest run --reporter=verbose` → ¿descubre tests?

- [ ] **Step 2: Corregir y verificar**

Corregir ruta del glob; Run: `npx vitest run` → PASS.

- [ ] **Step 3: Commit**

```bash
git add vitest.config.ts package.json
git commit -m "fix: vitest glob discovers tests/Unit"
```

---

### Task P6-1: success.astro distingue pendiente vs webhook no llegó

**Files:**
- Modify: `src/pages/success.astro` (polling)
- Modify: `src/lib/IBookingService.ts` si hace falta endpoint de estado

**Interfaces:**
- Consumes: `GET /api/booking-status` (GetBookingStatusAction)
- Produces: UX que muestra "pago pendiente de confirmación" vs "el webhook no llegó aún, reintentando..." vs "confirmado"

- [ ] **Step 1: Leer success.astro actual**

Ver el polling existente y qué estados distingue.

- [ ] **Step 2: Implementar tres estados**

1. `status=approved` → confirmado.
2. `status=pending` (MP aún procesando) → "tu pago está pendiente, se confirmará automáticamente".
3. `status=provisional`/sin respuesta tras N intentos → "estamos verificando tu pago, te llegará un email; no cierres esta página".

- [ ] **Step 3: Verificar**

Run: `npx astro check` + `npx vitest run` (si hay test del componente).

- [ ] **Step 4: Commit**

```bash
git add src/
git commit -m "feat: distinguish pending payment from missing webhook in success page"
```

---

### Task F-1: Verificación final integral

**Files:**
- Modify: `docs/refactoring/STATE.md` (estado final)

**Interfaces:**
- Consumes: todas las tareas P0-P6
- Produces: evidencia de que todo pasa junto

- [ ] **Step 1: Suite completa**

Run: `php composer.phar check`
Expected: PHPUnit PASS + PHPStan exit 0.

- [ ] **Step 2: Frontend**

Run: `npm run build` (o `npx astro build`)
Expected: build exit 0.

- [ ] **Step 3: Smoke e2e**

Run: `php -S localhost:8080 -t public` + curl health + flujo de booking con datos de prueba.
Expected: respuestas correctas.

- [ ] **Step 4: Actualizar STATE.md**

Documentar qué se hizo, qué se verificó, qué quedó pendiente (rotación manual de credenciales).

- [ ] **Step 5: Commit final**

```bash
git add -A
git commit -m "docs: final refactor state"
```
