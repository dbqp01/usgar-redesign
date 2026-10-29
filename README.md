<div align="center">

<img src="public/og-image.jpg" alt="Patio interior del hotel Usgar, San Pedro, Cusco" width="720" />

# USGAR Hotels

Sitio de reservas del hotel boutique Usgar, en San Pedro (Cusco, Perú): [usgarhoteles.com](https://usgarhoteles.com).
Cuatro idiomas, precios en dólares cobrados en soles vía MercadoPago y disponibilidad sincronizada con QloApps.

[![CI](https://github.com/dbqp01/usgar-redesign/actions/workflows/build.yml/badge.svg)](https://github.com/dbqp01/usgar-redesign/actions/workflows/build.yml)
[![Astro 7](https://img.shields.io/badge/Astro-7-FF5D01?logo=astro&logoColor=white)](https://astro.build)
[![Tailwind 4](https://img.shields.io/badge/Tailwind-4-06B6D4?logo=tailwindcss&logoColor=white)](https://tailwindcss.com)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](https://www.php.net)

</div>

El frontend es Astro estático. La API es PHP sin framework, dividida por features. Además del hotel, el repo sirve como una colección de piezas que se pueden llevar a otro proyecto: el asignador de habitaciones, el wizard de reserva, el motor de animaciones y los adaptadores de PMS y pagos funcionan sin el resto del sitio. No son paquetes publicados; son módulos del repo que puedes copiar tal cual.

## Piezas que puedes llevarte

### Frontend

| Pieza | Archivo | Qué hace |
|---|---|---|
| Asignación de habitaciones | `src/features/booking/roomAllocator.ts` | Reparte huéspedes entre una o dos habitaciones, respeta capacidad e inventario y ordena las opciones por precio total. TS puro, sin dependencias. |
| Estado del wizard | `src/features/booking/wizardStore.ts` | Store mínimo sobre `EventTarget` con `getState`, `setState`, `subscribe`, `next` y `back`. Sin librerías de estado. |
| Calendario | `src/features/booking/calendar.ts` | Grid de 42 celdas, noches entre fechas, suma de días y límite de 30 noches. Funciones puras, fáciles de testear. |
| Polling de estado | `src/features/booking/bookingStatus.ts` | Consulta el estado de la reserva con backoff exponencial y jitter, pausa cuando la pestaña está oculta y respeta `Retry-After`. |
| Cliente de API | `src/services/bookingService.ts` | Única capa de `fetch` del frontend: timeout de 10 s, cancelación y errores normalizados para toda la app. |
| i18n con fallback | `src/i18n/utils.ts` | en/es/fr/pt con cadena de fallback por clave (fr cae a en, pt a es). Si una clave falta en todas, devuelve la clave en vez de romper. |
| Motor de animaciones | `src/features/animations/` | GSAP + ScrollTrigger con carga diferida por página, `prefers-reduced-motion` y un lifecycle por generaciones para no dejar triggers vivos al navegar. |
| Detalles de UI | `src/components/common/` | Cursor propio, preloader, scroll suave con Lenis y overlay de ruido. Se montan como componentes sueltos. |
| Contenido tipado | `src/content/` + `src/data/` | Un JSON por dominio y un cargador tipado encima. El catálogo de servicios separa hotel y habitación con un campo `group`. |

### Backend

| Pieza | Archivo | Qué hace |
|---|---|---|
| Núcleo HTTP | `app/Core/` | Router, `Request`/`Response`, contenedor PSR-11 con autowiring por reflexión, validador, rate limiter, `PriceCalculator`, logger y eventos. |
| Módulos ADR | `app/Features/<Feature>/` | Cada feature tiene sus `Actions`, su `Domain` y sus listeners. Añadir una feature no toca las demás. |
| Puertos y adaptadores | `app/Features/Shared/` | `PmsPortInterface`, `PaymentGatewayPortInterface` y `MailerPortInterface` con implementaciones para QloApps, MercadoPago y PHPMailer. Cambiar de proveedor es escribir un adapter. |
| Hold y bloqueo de inventario | `app/Core/BookingHoldToken.php` + repositorios | Reserva provisional con token y liberación automática del stock si el pago no llega. |
| Outbox de eventos | `app/Core/Events/` + `cron/process_outbox.php` | Los eventos de pago se guardan antes de salir a la red y un worker los reintenta. |
| Suite PHP aislada | `npm run test:php` | Arranca su propio servidor con un `.env` de pruebas: sin BD, sin MercadoPago y sin SMTP. |

## Cómo se arma una reserva

```
Página Astro → bookingService.ts → /api/* → public/index.php
                                                ↓
                                    Features/<Feature>/Actions
                                                ↓
                          Ports → Adapters (QloApps, MercadoPago, SMTP)
```

El recorrido completo pasa por fechas y asignación, un hold que bloquea inventario, el formulario de tarjeta de MercadoPago, el webhook de confirmación, la cola de eventos y la creación de la orden en QloApps. El backend calcula y congela el precio; el cliente no lo envía.

## Empezar

Necesitas Node 24.22.1 o superior de la rama 24, PHP 8.2 o superior (con `pdo_mysql`, `xml` y `mbstring`), Composer y MySQL.

```bash
npm ci
composer install          # vendor/ ya trae producción; esto añade phpunit y phpstan
cp .env.example .env      # credenciales de BD, MercadoPago y QloApps
npm run dev:all           # Astro en :4321 + API PHP en :8000 con proxy /api
```

## Comandos

| Comando | Qué hace |
|---|---|
| `npm run dev:all` | Entorno completo: Astro, API PHP y proxy `/api` |
| `npm run dev` / `npm run dev:php` | Solo frontend / solo API |
| `npm run check` | `astro check` + TypeScript |
| `npm run test:unit` | Vitest sobre lógica TypeScript, sin servicios externos |
| `npm run test:php` | Suite PHP aislada con su propio `.env` de pruebas |
| `npm run test:e2e` | Playwright; pasa un spec con `npm run test:e2e -- <spec>` |
| `php tests/api-harness.php` | Contrato del flujo de reserva vía curl |
| `npm run build` | Build estático y copia de `app/`, `vendor/` y `cron/` a `dist/` |
| `npm run audit:security`, `audit:seo`, `audit:content`, `audit:csp` | Auditorías. Las de contenido y CSP necesitan `npm run build` antes |

`npm test` encadena `check`, `test:unit` y `test:php`. Para E2E, instala Chromium una vez con `npx playwright install chromium`. Los casos con API real necesitan un backend local de pruebas; no apuntes el proxy a producción.

## Pagos

Las credenciales salen del [panel de desarrolladores de MercadoPago](https://www.mercadopago.com/developers/panel/app). El prefijo del token no indica el entorno: `APP_USR` aparece tanto en pruebas como en producción, así que el deploy a producción pasa por `php scripts/check-prod-env.php` y un allowlist de hashes SHA-256 en `.env.production`, fuera de git.

## Deploy

Hostinger mira únicamente la rama `main`. Al hacer push, toma el repo, ejecuta `npm install` y `npm run build`, y sirve `dist/`. El `postbuild` copia `app/`, `vendor/` y `cron/`, y elimina cualquier `.env` que haya quedado ahí.

Las variables de entorno van en hPanel → Despliegues → Ajustes → Variables de entorno, nunca dentro de `public_html` ni de `dist/`. El detalle de crons, secretos y hooks está en [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Documentación

| Documento | Contenido |
|---|---|
| [`docs/README.md`](docs/README.md) | Índice y por dónde empezar |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Capas, flujo de reserva y zonas de riesgo |
| [`docs/API.md`](docs/API.md) | Contrato de los endpoints y variables de entorno |
| [`docs/PMS.md`](docs/PMS.md) | Operación de QloApps, inventario y channel manager |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Modelo de seguridad y pendientes |
| [`docs/STATE.md`](docs/STATE.md) | Qué está desplegado y verificado hoy |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Decisiones de arquitectura vigentes |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | Lo que sigue abierto y por qué |
| [`docs/specs/`](docs/specs/README.md) | Requisitos por feature en formato EARS |

## Contribuir

Un cambio por commit, con prefijo `feat:`, `fix:`, `chore:` o `docs:`. Antes de tocar código, revisa si el comportamiento ya está documentado en `docs/`; si cambia, actualiza el doc en el mismo commit. No se hace push a `main` sin el ok del dueño del proyecto.

Si vienes por primera vez, o eres un agente, [`AGENTS.md`](AGENTS.md) tiene el mapa completo, las reglas y qué verificar según lo que toques.
