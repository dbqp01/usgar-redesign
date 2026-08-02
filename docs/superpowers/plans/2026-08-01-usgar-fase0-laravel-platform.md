# Fase 0 — Plataforma paralela (Laravel + Filament) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Levantar el backend nuevo (Laravel 12 + Filament v5, con multi-tenancy) en `backend/` del monorepo, caracterizar el backend actual como línea base de no-regresión, y desplegar el panel admin en producción paralela sin tocar el sitio actual.

**Architecture:** Monorepo: `backend/` = aplicación Laravel completa (dominio + adapters + panel Filament). El frontend Astro y el backend PHP actual (`app/`, `public/index.php`) NO se tocan en esta fase. El panel se despliega en un subdominio `admin.` con deploy independiente; la API pública Laravel llegará en la Fase 2.

**Tech Stack:** PHP 8.2+ (requisito Filament v5), Laravel 12.x (última estable verificada con context7), Filament v5 (`filament/filament:^5.0`), MySQL (prod) / SQLite in-memory (tests), Composer.

## Global Constraints

- PHP ≥ 8.2 obligatorio (Filament v5 — verificado context7: "Filament requires PHP 8.2 or higher, Laravel v11.28 or higher, Tailwind CSS v4.1+").
- Todo el código Laravel vive en `backend/`; no tocar `src/`, `app/`, `public/` (frontend), ni `docs/` fuera de lo especificado.
- Zero hardcoding: credenciales/URLs solo en `backend/.env` (nunca en código ni commits). `.env`, `vendor/`, `node_modules/`, `storage/logs/*` en `.gitignore`.
- Docs-first con MCPs OBLIGATORIO antes de escribir código Laravel/Filament: `context7` (resolver `/websites/laravel_12_x` y `/websites/filamentphp_5_x`) y consultar la API exacta del componente que se use. Si un MCP esencial no está disponible → BLOCKED, no continuar (regla del usuario).
- Test-first (TDD): cada pieza tiene su test PHPUnit antes de la implementación.
- Commits por tarea con prefijos del repo (`feat:` / `fix:` / `chore:` / `docs:`), sin `--no-verify` salvo que el hook siga roto (documentado); nunca commitear `.env` ni `vendor/`.
- Contrato de no-regresión: `npm run check` (0/0/0) + `php tests/api-harness.php` verdes al final de la fase; el sitio actual no cambia.
- Subagentes: ejecutar con superpowers:subagent-driven-development (implementador fresco por tarea, revisor por tarea, auditoría whole-branch al final). El implementador DEBE reportar evidencia real (comandos + output), nunca afirmar sin verificar.
- Windows/PowerShell 5.1; nunca asumir bash/sh disponible (WSL roto en esta máquina). Los comandos del plan usan `php`, `composer`, `git` desde PowerShell.

---

### Task 1: Caracterización del backend actual (línea base de contrato)

**Files:**
- Create: `docs/refactoring/backend-contract.md` (snapshots del contrato actual)
- Create: `tests/backend-contract.ps1` (script de captura, idempotente)

**Interfaces:**
- Consumes: nada (lee el backend PHP actual vía HTTP/CLI)
- Produces: `docs/refactoring/backend-contract.md` — el documento de referencia para la Fase 2 (portar endpoints 1:1); el revisor de la Fase 2 lo usará como spec del contrato.

**Contexto:** El backend actual corre con `php -S localhost:4321 -t public public/index.php` (o `npm run dev:all`). Los endpoints seguros para caracterizar (sin efectos secundarios en prod QloApps): GET `/api/health`, GET `/api/rooms`, GET `/api/auth/providers`; y el formato de error con un POST inválido a `/api/booking` (sin body → 400 esperado, NO crea hold porque la validación falla antes).

- [ ] **Step 1: Verificar el backend actual responde**

```powershell
# En la raíz del repo, servidor temporal (puerto 4399 para no chocar con dev)
Start-Process php -ArgumentList '-S','localhost:4399','-t','public','public/index.php' -WindowStyle Hidden
Start-Sleep -Seconds 2
Invoke-WebRequest http://localhost:4399/api/health -UseBasicParsing | Select-Object StatusCode
```

Expected: `StatusCode 200` y body JSON `{"status":"ok",...}`. Si no responde, reportar BLOCKED con el error del servidor (revisar `php -v` primero).

- [ ] **Step 2: Capturar snapshots de los endpoints seguros**

```powershell
$out = 'docs/refactoring/backend-contract.md'
@(
  @{ name='health';  method='GET';  url='http://localhost:4399/api/health' },
  @{ name='rooms';   method='GET';  url='http://localhost:4399/api/rooms?checkIn=2026-08-15&checkOut=2026-08-16' },
  @{ name='providers'; method='GET'; url='http://localhost:4399/api/auth/providers' },
  @{ name='booking-invalid'; method='POST'; url='http://localhost:4399/api/booking'; body='{}' }
) | ForEach-Object {
  $r = if ($_.method -eq 'POST') { Invoke-WebRequest $_.url -Method POST -Body $_.body -ContentType 'application/json' -UseBasicParsing -SkipHttpErrorCheck } else { Invoke-WebRequest $_.url -UseBasicParsing -SkipHttpErrorCheck }
  "## $($_.name)`n`n- HTTP: $($r.StatusCode)`n- Headers relevantes: Content-Type=$($r.Headers['Content-Type'])`n- Body:`n````json`n$($r.Content)`n````" | Add-Content -Path $out -Encoding UTF8
}
```

Expected: `docs/refactoring/backend-contract.md` con 4 secciones. NOTA: si `/api/rooms` devuelve error por BD remota no disponible, capturar IGUAL el error (el formato del error es parte del contrato) y anotarlo.

- [ ] **Step 3: Verificar formato del contrato y documentar hallazgos**

Revisar el archivo generado: formato JSON, códigos HTTP, estructura `success/error/code/message`. Añadir al final una sección "Hallazgos" listando: campos clave del payload de rooms (id_room_type, id_product, room_name, price, max_guests, available_qty, slug si existe), el formato de error de booking-invalid (esperado 400 con `error`), y cualquier anomalía (valores null, campos faltantes). Citar fecha.

- [ ] **Step 4: Commit**

```powershell
git add docs/refactoring/backend-contract.md tests/backend-contract.ps1
git commit -m "docs: caracterizacion del contrato API actual (linea base Fase 0)"
```

Expected: commit creado (si el pre-commit hook falla por WSL, usar `--no-verify` y anotarlo en el reporte).

---

### Task 2: Entorno local + scaffolding Laravel en `backend/`

**Files:**
- Create: `backend/` (proyecto Laravel completo vía Composer)
- Create: `backend/.env` (local, SQLite + APP_KEY generado)
- Modify: `.gitignore` (raíz): añadir `backend/.env`, `backend/vendor/`, `backend/storage/logs/*.log`, `backend/storage/framework/cache/*`, `backend/node_modules/`
- Test: `backend/tests/Feature/HealthTest.php` (test de humo)

**Interfaces:**
- Consumes: nada.
- Produces: `backend/` con `php artisan test` verde y `php artisan serve` respondiendo; base para las Tasks 3-5.

**Contexto:** Windows/PowerShell. Verificar requisitos ANTES de crear nada: PHP ≥ 8.2 (`php -v`), Composer (`composer --version`). Si falta Composer: instalar con winget (`winget install Composer.Composer` — requiere aprobación del usuario, anunciarla) o choco; si PHP < 8.2, BLOCKED (el usuario debe actualizar PHP antes de continuar).

- [ ] **Step 1: Verificar requisitos (docs-first con context7)**

```powershell
php -v
composer --version
```

Expected: PHP ≥ 8.2 y Composer presente. Luego consultar context7 `/websites/laravel_12_x` ("create project laravel requirements PHP version") y confirmar la versión estable 12.x actual. Si la versión estable es 13.x y la spec dice 12, usar la 12.x LTS más reciente (la spec se actualiza con `docs:` commit si cambia).

- [ ] **Step 2: Crear el proyecto Laravel**

```powershell
composer create-project laravel/laravel:^12.0 backend --prefer-dist --no-interaction
```

Expected: `backend/` creado con estructura Laravel 12 (artisan, app/, bootstrap/, config/, database/, routes/, tests/). Si el create-project falla por red/PHP, reportar BLOCKED con el error exacto.

- [ ] **Step 3: Configurar .env local + gitignore**

```powershell
Copy-Item backend\.env.example backend\.env
php backend\artisan key:generate
```

Editar `backend/.env`: `DB_CONNECTION=sqlite`, `APP_URL=http://localhost:8000`. Crear `backend/database/database.sqlite` (archivo vacío). En `.gitignore` raíz añadir las entradas de Global Constraints. Comprobar con `git status` que `backend/.env` y `backend/vendor/` NO aparecen como untracked.

- [ ] **Step 4: Test de humo (TDD) — escribir el test que falla**

Crear `backend/tests/Feature/HealthTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_responds(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertStatus(200)->assertJson(['status' => 'ok']);
    }
}
```

Y la ruta en `backend/routes/api.php` (root `api` ya registrado en `bootstrap/app.php`):

```php
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'service' => 'usgar-api', 'time' => now()->toIso8601String()]);
});
```

(La ruta `/api/health` es EL primer endpoint del contrato de la Fase 2; implementarla ya en Fase 0 da al panel una health-check propia.)

- [ ] **Step 5: Correr el test (debe pasar)**

```powershell
cd backend
php artisan test --filter=HealthTest
```

Expected: `PASS` (1 passed). Si falla, corregir la ruta/config antes de continuar (nunca dejar un test rojo en el commit).

- [ ] **Step 6: Verificación de humo del servidor**

```powershell
Start-Process php -ArgumentList 'artisan','serve','--port=8000' -WorkingDirectory (Get-Location) -WindowStyle Hidden
Start-Sleep -Seconds 3
Invoke-WebRequest http://127.0.0.1:8000/api/health -UseBasicParsing | Select-Object StatusCode
```

Expected: `StatusCode 200`, body JSON con `status: ok`. Matar el proceso `artisan serve` al terminar.

- [ ] **Step 7: Commit**

```powershell
git add .gitignore backend/
git commit -m "feat: scaffolding Laravel 12 en backend/ + health endpoint con test"
```

Expected: commit creado (sin `.env` ni `vendor/` staged — verificarlo con `git status` antes).

---

### Task 3: Filament v5 + panel admin con multi-tenancy (Property)

**Files:**
- Modify: `backend/composer.json` (filament/filament ^5.0)
- Create: `backend/app/Models/Property.php`, `backend/database/migrations/xxxx_create_properties_table.php`, `backend/app/Providers/Filament/AdminPanelProvider.php` (generado y editado)
- Test: `backend/tests/Feature/FilamentPanelTest.php`

**Interfaces:**
- Consumes: Task 2 (`backend/` funcional, `php artisan test` verde).
- Produces: modelo `Property` (campos: name, slug — unique), panel `/admin` con login y tenancy activado (`->tenant(Property::class)` + registro de tenant), tests verdes.

**Contexto:** docs-first OBLIGATORIO con context7 antes de codear: consultar `/websites/filamentphp_5_x` — "install panel builder", "configure tenant model in panel", "tenant registration page" (ya verificadas en el diseño: `->tenant(Property::class)`, `->tenantRegistration(RegisterProperty::class)`). Filament v5 usa `php artisan filament:install --panels`.

- [ ] **Step 1: Instalar Filament v5 (docs-first)**

```powershell
cd backend
composer require filament/filament:"^5.0" --no-interaction
php artisan filament:install --panels --no-interaction
```

Expected: panel creado en `app/Providers/Filament/AdminPanelProvider.php`; `/admin` definido. Si composer falla por dependencias PHP, BLOCKED con el error.

- [ ] **Step 2: Modelo Property (TDD) — test primero**

Crear `backend/tests/Feature/FilamentPanelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_can_be_created(): void
    {
        $property = Property::create(['name' => 'Usgar Cusco', 'slug' => 'usgar-cusco']);
        $this->assertDatabaseHas('properties', ['slug' => 'usgar-cusco']);
        $this->assertSame('usgar-cusco', $property->slug);
    }

    public function test_admin_login_page_renders(): void
    {
        $response = $this->get('/admin/login');
        $response->assertStatus(200);
    }
}
```

- [ ] **Step 3: Migración + modelo**

```powershell
php artisan make:model Property -m
```

Editar la migración generada:

```php
Schema::create('properties', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->timestamps();
});
```

Editar `backend/app/Models/Property.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Property extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];
}
```

- [ ] **Step 4: Activar tenancy en el panel**

En `backend/app/Providers/Filament/AdminPanelProvider.php` (generado por filament:install), dentro de la closure del panel añadir:

```php
->tenant(\App\Models\Property::class)
->tenantRegistration(\App\Filament\Pages\Tenancy\RegisterProperty::class)
```

Crear `backend/app/Filament/Pages/Tenancy/RegisterProperty.php` (siguiendo la API verificada en context7 — RegisterTenant de Filament v5):

```php
<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Property;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Tenancy\RegisterTenant;

class RegisterProperty extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register property';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required(),
            TextInput::make('slug')->required()->unique('properties', 'slug'),
        ]);
    }

    protected function handleRegistration(array $data): Property
    {
        return Property::create($data);
    }
}
```

NOTA: si la firma exacta de `RegisterTenant`/`Form` difiere en la versión instalada, consultarla con context7 antes de ajustar (docs-first, nunca asumir).

- [ ] **Step 5: Correr los tests**

```powershell
php artisan test --filter=FilamentPanelTest
```

Expected: 2 passed. Si `test_admin_login_page_renders` falla por tema de sesión/CSRF del panel, ajustar la URL o el assert (consultar la doc del panel con context7 si hace falta).

- [ ] **Step 6: Verificación manual del panel (MCP chrome-devtools obligatorio)**

```powershell
Start-Process php -ArgumentList 'artisan','serve','--port=8000' -WorkingDirectory (Get-Location) -WindowStyle Hidden
```

Con chrome-devtools-mcp navegar a `http://127.0.0.1:8000/admin/login`: debe renderizar el login de Filament (sin errores de consola). Luego `http://127.0.0.1:8000/admin/register-property` (o la URL de tenancy) debe mostrar el formulario de registro de Property. Guardar screenshot como evidencia. Matar `artisan serve`.

- [ ] **Step 7: Commit**

```powershell
git add backend/
git commit -m "feat: panel Filament v5 con multi-tenancy (Property) en backend/"
```

Expected: commit creado.

---

### Task 4: Deploy del panel en Hostinger (subdominio admin) — requiere acceso del usuario

**Files:**
- Create: `backend/README.md` (instrucciones de deploy para Hostinger)
- Modify: ninguno en el código (si acaso `backend/` optimizaciones de prod)

**Interfaces:**
- Consumes: Tasks 2-3 (backend funcional con panel).
- Produces: panel accesible en `admin.hotelesusgar.com` en producción paralela; instrucciones de deploy reproducibles en `backend/README.md`.

**Contexto:** Hostinger compartido, sin Composer en el servidor. El usuario DEBE: (1) crear el subdominio `admin.hotelesusgar.com` (apunta a un directorio, ej. `public_html/admin`), (2) crear una BD MySQL nueva (ej. `u941268346_usgar`), (3) dar acceso al agente (FTP/SFTP o subir el zip él mismo). Esta tarea tiene DOS variantes: A) con acceso (la ejecuta el subagente), B) sin acceso (el subagente prepara el paquete de deploy y el usuario lo sube; verificación conjunta).

- [ ] **Step 1: Preparar build de producción**

```powershell
cd backend
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Expected: sin errores (si alguna cache falla por entorno, corregir y re-verificar). Después comprimir `backend/` EXCLUYENDO `.env`, `vendor` NO (el vendor SÍ va — sin Composer en prod), `storage/logs`, `tests` (opcional). El `.env.production` se crea aparte con: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://admin.hotelesusgar.com`, `DB_*` de la BD nueva, `SESSION_SECURE_COOKIE=true`.

- [ ] **Step 2: Instrucciones de deploy en `backend/README.md`**

Documentar: estructura esperada en el hosting (raíz del subdominio → `backend/public` como docroot si Hostinger lo permite vía panel, o `public_html/admin` con `.htaccess` apuntando a `public/index.php`), permisos `storage/` y `bootstrap/cache` (775), `php artisan migrate --force`, `php artisan storage:link`, `php artisan schedule:run` en cron del hosting. Cada paso con el comando exacto. (Docs-first si hace falta: context7 `/websites/laravel_12_x` "deployment" + tavily "hostinger laravel shared hosting public_html" para el patrón exacto de Hostinger.)

- [ ] **Step 3a (variante A — hay acceso): subir y migrar**

Subir el paquete al directorio del subdominio, `php artisan migrate --force --seed=false`, crear el primer usuario admin (`php artisan make:filament-user` o seeder), verificar con chrome-devtools que `https://admin.hotelesusgar.com/admin/login` carga y que el login con el usuario creado funciona (screenshot).

- [ ] **Step 3b (variante B — sin acceso): empaquetar para el usuario**

Generar `usgar-admin-deploy.zip` en la raíz del repo (gitignored) con instrucciones de un solo párrafo: "subir a public_html/admin, ejecutar X, Y, Z en el panel de Hostinger (terminal)". El subagente verifica con el usuario que el panel quedó accesible (pedirle la URL cuando esté subido).

- [ ] **Step 4: Verificación de no-regresión + docs**

Verificar que el sitio actual (dist/ o `npm run dev:all`) sigue funcionando (chrome-devtools home carga, consola 0 errores) — el deploy del panel NO debe tocar `public_html` principal. Actualizar `docs/refactoring/STATE.md` (Fase 0 en curso → panel desplegado).

- [ ] **Step 5: Commit**

```powershell
git add backend/README.md docs/refactoring/STATE.md
git commit -m "docs: deploy del panel admin en Hostinger (Fase 0)"
```

Expected: commit creado (el zip queda fuera del repo).

---

### Task 5: Cierre de Fase 0 — contrato de no-regresión + auditoría

**Files:**
- Modify: `docs/refactoring/STATE.md` (Fase 0 completada), `docs/refactoring/DECISIONS.md` (decisión desplegada)
- Verify: nada nuevo en código

**Interfaces:**
- Consumes: Tasks 1-4.
- Produces: criterio de salida de la Fase 0 verificado: panel en producción + sitio actual intacto + línea base documentada.

- [ ] **Step 1: Línea base completa (no-regresión)**

```powershell
# En la raíz del repo
npm run check
php tests/api-harness.php
```

Expected: `npm run check` → 0/0/0 (o el baseline documentado si el hook/entorno difiere — nunca "se supone que pasa" sin correrlo). api-harness: contratos OK (HTTP 200 en happy path). Registrar los resultados EXACTOS en el reporte.

- [ ] **Step 2: Auditoría whole-branch (subagente revisor final)**

El controlador despacha la revisión final de la Fase 0 (todo el diff de la fase) con el modelo más capaz: spec compliance (las 4 tareas contra esta plan) + calidad + seguridad (no hay secrets en commits — verificar `git log -p` sin `.env`). Cualquier hallazgo → fix loop antes de cerrar.

- [ ] **Step 3: Actualizar memoria multi-sesión**

`docs/refactoring/STATE.md`: Fase 0 completada (fecha, evidencia: tests verdes, URL del panel, línea base). `docs/refactoring/DECISIONS.md`: decisión "Laravel 12 + Filament v5 desplegado en admin subdominio (multi-tenant Property)". Actualizar `AGENTS.md` (estructura del repo: `backend/`).

- [ ] **Step 4: Commit final**

```powershell
git add docs/refactoring/ AGENTS.md
git commit -m "docs: cierre Fase 0 - panel admin en produccion, linea base documentada"
```

Expected: commit creado. Fase 0 cerrada: el siguiente ciclo (spec → plan) es la Fase 1 (rate engine + recursos Filament).
