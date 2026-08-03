# USGAR — Panel de Administración (backend)

Panel de administración del hotel boutique Usgar (San Pedro, Cusco, Perú): Laravel 12 + Filament v5 con
multi-tenancy por propiedad. Se despliega como **producción paralela** en el subdominio
`admin.hotelesusgar.com` (Hostinger compartido). No interfiere con el sitio público (`public_html` principal).

## Stack

- PHP 8.2+ (recomendado 8.3)
- Laravel 12 (PHP nativo, PDO/MySQL)
- Filament v5 (panel admin, multi-tenancy `Property`)
- Sin Composer en el servidor → `vendor/` se sube incluido en el paquete

## Estructura

```
backend/
├── public/          → DOCROOT real (index.php, .htaccess propio de Laravel)
├── .htaccess        → redirige todo el tráfico a public/ (para docroot = raíz del subdominio)
├── .env.production.example → TEMPLATE del .env de producción (placeholders)
├── vendor/          → incluido en el paquete (NO hay Composer en prod)
├── app/ bootstrap/ config/ database/ resources/ routes/ storage/
└── artisan          → CLI (vía SSH/Terminal o cron del hosting)
```

---

## Deploy paso a paso (Hostinger compartido)

### 0. Requisitos previos en hPanel

1. **Subdominio**: Websites → Dashboard → dominio `hotelesusgar.com` → **Subdomains** →
   crear `admin`, directorio `public_html/admin` (o el que prefieras). SSL para el subdominio (Let's Encrypt).
2. **Base de datos**: **Databases** → crear BD MySQL nueva (ej. `u941268346_usgar`) y un usuario con
   todos los privilegios sobre esa BD. Anota host/puerto/nombre/usuario/password.
3. **PHP**: en el subdominio, PHP Configuration → versión **8.2 o superior** y extensiones habituales
   (`pdo_mysql`, `mbstring`, `openssl`, `xml`, `fileinfo`, `bcmath`, `curl`, `intl`).
4. **Acceso a comandos**: activa **SSH** (Advanced → SSH Access) o usa el **Terminal** de hPanel;
   si no tienes ninguno, los comandos artisan se pueden ejecutar como **cron de un solo uso**
   (Advanced → Cron Jobs, tipo Custom, y borrarlo después — ver nota al final).

### 1. Subir y extraer el paquete

- Sube `usgar-admin-deploy.zip` (raíz del repo, gitignored) a `public_html/admin` con el
  **File Manager** o FTP.
- Extrae el zip **dentro** de `public_html/admin` (que el contenido quede directamente ahí:
  `public_html/admin/artisan`, `public_html/admin/public/`, etc.).

### 2. Estructura del docroot (una de dos)

- **Opción A (recomendada, sin .htaccess)**: si hPanel te permite elegir el directorio del subdominio,
  apunta `admin.hotelesusgar.com` directamente a `public_html/admin/public` (el docroot real de Laravel).
- **Opción B (por defecto)**: deja el subdominio en `public_html/admin`. El `.htaccess` incluido en la
  raíz ya redirige todo a `public/` (`RewriteRule ^(.*)$ public/$1 [L]`).

### 3. Permisos

En el File Manager (o SSH), asegúrate de que el servidor web pueda escribir en:

```
chmod -R 775 storage bootstrap/cache
```

Si usas File Manager: permisos **775** para `storage/` y `bootstrap/cache/`.

### 4. Crear el `.env` de producción

1. Copia `.env.production.example` → `.env` (mismo directorio, la raíz del backend).
2. Completa los valores `REEMPLAZAR_*`:
   - `APP_KEY` → se genera automáticamente en el paso 5.
   - `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` → los de la BD creada en el paso 0.2.
   - `MAIL_FROM_ADDRESS` → email remitente real.
3. No toques `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://admin.hotelesusgar.com`,
   `SESSION_SECURE_COOKIE=true`.

> **NUNCA** subas el `.env` real a git ni lo incluyas en paquetes de deploy. Solo existe en el servidor.

### 5. Generar APP_KEY, migrar, enlazar storage y regenerar caches

Desde SSH/Terminal, en `public_html/admin` (o `domains/hotelesusgar.com/<subdominio-dir>`):

```bash
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

> **IMPORTANTE**: el paquete ya trae caches de producción generadas localmente (`bootstrap/cache/*` y
> vistas compiladas). `php artisan optimize` las **regenera con tu `.env` real** — es obligatorio
> ejecutarlo después de crear `.env`, o la app usará valores locales.

### 6. Crear el primer administrador

```bash
php artisan make:filament-user
```

Te pedirá nombre, email y contraseña. Guarda esas credenciales: son el login del panel.
(En el formulario de registro de tenancy, la primera vez crea/registra la propiedad del hotel.)

### 7. Cron para el scheduler

En **hPanel → Advanced → Cron Jobs** (tipo **Custom**), cada minuto:

```
* * * * * /usr/bin/php /home/<TU_USUARIO>/domains/hotelesusgar.com/public_html/admin/artisan schedule:run
```

Reemplaza `<TU_USUARIO>` y el path con los reales (los verás en hPanel → Advanced → Cron Jobs → path sugerido).

### 8. Verificación final

1. Abre `https://admin.hotelesusgar.com/admin/login` → debe cargar el login de Filament.
2. Entra con el usuario creado en el paso 6.
3. Si algo falla: revisa `storage/logs/laravel.log` (permisos del paso 3) y que el `.env` tenga
   `APP_DEBUG=false` (no exponer errores en producción).

---

## Notas

- **Sin SSH**: ejecuta los comandos del paso 5 y 6 como **cron de un solo uso** (tipo Custom,
  comando = ruta absoluta a `artisan <comando>`, schedule a un minuto futuro o "run now",
  y bórralo después). El cron de `schedule:run` del paso 7 sí debe quedarse.
- **Actualizaciones**: repetir pasos 1-5 (sustituyendo archivos, sin borrar `storage/` ni `.env`).
- **Local dev**: `composer install` (con dev deps) y `php artisan optimize:clear` para volver a
  entorno de desarrollo tras construir el paquete de deploy.
- `tests/` no se incluye en el paquete de deploy (solo desarrollo).
