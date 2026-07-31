# USGAR Hotels — Cusco, Peru

Sitio web transaccional para turistas internacionales. Reservas directas con Mercado Pago y sincronizacion de inventario con OTAs via Channex.

## Stack
- **Frontend:** Astro v7.x.x (estatico en http://localhost:4321), Tailwind CSS v4.3, Leaflet
- **Backend:** PHP 8.x nativo (Monolito Modular, patron ADR con DI Container PSR-11)
- **Server:** Hostinger compartido (PHP + MySQL, sin Composer en prod)
- **Payments:** Mercado Pago (USD)
- **PMS:** QloApps (API XML)
- **Channel Manager:** Channex

## Commands
- Dev Entorno Completo (Astro + PHP API): `npm run dev:all` (Abre en http://localhost:4321)
- Dev Solo Frontend: `npm run dev` (http://localhost:4321)
- PHP API server solo: `npm run dev:php` (http://localhost:8000)
- Build: `npm run build`
- TypeCheck: `npm run check`
- Lint PHP: `vendor/bin/phpstan analyse`
- Tests PHP (Script Unico): `php tests/api-harness.php`

## REGLA IMPERATIVA DE MENCION DE MCPs EN CHAT
Si el usuario menciona cualquier servidor o herramienta MCP por su nombre en el chat (por ejemplo: `postman`, `context7`, `tavily`, `sequential-thinking`, `graphify`, `agent-skills`, `filesystem`, etc.), el agente TIENE PROHIBIDO omitir o usar de forma superficial dicho MCP. El agente DEBE invocar de forma automatica, constante, profunda y exhaustiva las herramientas correspondientes a ese MCP durante toda la conversacion.

---

## MANDATO IMPERATIVO DE USO DE MCPs (CERO OMISIONES)

1. **`sequential-thinking` (MANDATORIO):** Invocacion obligatoria via `call_mcp_tool` al inicio de cada turno. Prohibido emitir propuestas o codigo sin este registro previo.
2. **`context7` (MANDATORIO EN CODIFICACION Y CONSULTAS):** Consulta obligatoria de `resolve-library-id` y `query-docs` para resolver dudas de documentacion, verificar sintaxis y mejores practicas de Astro v7.x.x, Tailwind CSS v4.3 y PHP 8.x antes de escribir codigo.
3. **`tavily-mcp` (MANDATORIO EN INVESTIGACION):** Consultar en tiempo real con `tavily_search` estandares de la industria y coding agentico.
4. **`postman-mcp-server` (MANDATORIO EN AUDITORIA EXHAUSTIVA DE API):** Utilizar colecciones y solicitudes Postman (`getCollections`, `runCollection`, `createCollectionRequest`, `searchPostmanElements`) para probar **todas las opciones posibles** de endpoints (`/api/*`): caminos felices, errores, cargas, escenarios limite y pruebas de estres.
5. **`agent-skills` MCP (MANDATORIO EN CALIDAD Y SEGURIDAD):** Invocacion obligatoria de skills estandarizadas (`coding-guidelines`, `security-best-practices`, `perf-astro`, `web-quality-audit`, `accessibility`).
6. **`graphify` / Red de Nodos (MANDATORIO EN IMPACTO):** Consulta obligatoria de dependencias respondiendo a: *"¿Si hago este cambio, en que afectara al proyecto?"*.

---

## Pruebas y Scripts PHP
- **Script Unico PHP:** Se mantiene exclusivamente `tests/api-harness.php` para verificaciones locales basicas en PHP. Las auditorias complejas de endpoints y pruebas de estres/carga se realizan obligatoriamente con **Postman MCP**.

---

## Flujos de Razonamiento Diferenciados

### Flujo A — Lenguaje Natural e Ideacion (Clarificacion y Validacion)
1. **Divergencia e Intencion:** Descomponer supuestos, necesidades de usuario y objetivos.
2. **Estres-Test Adversarial:** Evaluar puntos de fallo, complejidad innecesaria y alternativas.
3. **Recoleccion de Referencias:** Consultar Context7, Tavily y agent-skills MCP para enriquecer el concepto.
4. **Sintesis Convergente:** Proponer una solucion clara antes de modificar codigo.

### Flujo B — Codificacion y Refactorizacion (Desarrollo Agentico Seguro)
1. **Descomposicion Tecnica:** Hipotesis de solucion guiada con `sequential-thinking`.
2. **Oportunidades de Mejora (Context7):** APIs y patrones actualizados de Astro v7.x.x, Tailwind CSS v4.3 y PHP 8.x ADR.
3. **Investigacion de Estandares (Tavily & agent-skills MCP):** Consultar `web-quality-audit`, `perf-astro`, `security-best-practices`, `best-practices`, `coding-guidelines`, `accessibility`.
4. **Reglas Estrictas de Animacion GSAP (MANDATORIO):**
   - **Cleanup:** TODO script de GSAP en Astro DEBE usar `gsap.context()` y limpiarse en el evento `astro:before-preparation`. (ej. `ctx.revert()`). NUNCA dejar callbacks huerfanos.
   - **Visibilidad/Accesibilidad:** Usar `autoAlpha` en lugar de `opacity` (NUNCA animar opacidad sola). Elementos invisibles no deben ser focuseables por teclado.
   - **Responsividad y Movimiento:** Usar `gsap.matchMedia()` SIEMPRE. Desactivar animaciones complejas (como scroll horizontal o parallax pesados) en pantallas menores a `768px` y respetar `(prefers-reduced-motion: reduce)`.
   - **Rendimiento:** Evitar animar `top`, `left`, `width`, `height`, `borderRadius`. Usar SIEMPRE `x`, `y`, `scale`, `rotation` (transformaciones de hardware). Utilizar `xPercent`/`yPercent` en lugar de porcentajes en translaciones manuales.
5. **Analisis de Impacto en Red de Nodos (Graphify / Busqueda de Nodos):** Responder obligatoriamente:
   > *"¿Si hago este cambio o refactorizacion, en que afectara al resto del proyecto?"*
6. **Aplicacion Desacoplada (Zero-Hardcoding):** Implementar mediante `.env` o DB.
7. **Depuracion Logica Profunda en Runtime:** Validar en ejecucion real (colecciones exhaustivas de **Postman MCP**, script unico `tests/api-harness.php`, inspeccion de payloads) superando simples comprobaciones estaticas (`npm build`/`check`).

## Reglas de Verificacion y Calidad
- **Depuracion Logica Profunda en Runtime:** `npm run check` y `npm run build` son unicamente controles sintacticos/estaticos iniciales. TODO cambio debe validarse en tiempo de ejecucion via Postman MCP (todas las opciones y estres) y `tests/api-harness.php`.
- **Sin Tildes ni Acentos en Contenido o Rutas:** Prohibido el uso de tildes o caracteres acentuados en textos fuente, slugs y rutas web para evitar fallos tipograficos y problemas de codificacion.

## Project Map
- `app/` — Backend PHP completo
- `app/Core/` — Router, Request, Response, Config, Middleware, Events (NO TOCAR)
- `app/Features/` — Vertical slices: Auth, Booking, Rooms, Webhooks, Cron, Health
- `app/Features/Shared/` — Ports (interfaces) + Adapters (QloApps, MercadoPago, Channex)
- `src/` — Frontend Astro exclusivamente
- `src/services/` — Capa de conexion frontend → backend API (httpClient, bookingService)
- `src/services/contracts/` — Interfaces TypeScript (IBookingService, IHttpClient)
- `public/` — Document Root: index.php (entry point PHP) + .htaccess
- `docs/` — API_REGISTRY, ARCHITECTURE, HARNESS

## Non-Obvious Patterns
- Entry point: `public/index.php` → `Router` → `Action` class (una por endpoint)
- Cada endpoint es una clase PHP invocable `__invoke(Request): void` (ADR, no MVC)
- El frontend NUNCA llama servicios externos directo; siempre pasa por `/api/`
- QloApps usa API XML (no JSON). El adapter en Shared/ traduce
- Bloqueo temporal de 15 min al iniciar reserva (`ProvisionalBookingRepository`). Webhook de MP confirma
- Autenticacion via JWT en cookie HttpOnly (`usgar_session`)
- Room slugs canonicos: `matrimonial`, `doble-superior`, `triple-standar`, `familiar-superior`
- Fuente unica de slugs: `app/Features/Shared/RoomTypeRegistry.php`
- Autoloader PSR-4 propio (sin Composer en prod): `app/Core/Autoloader.php`
- Adaptadores implementan Ports (interfaces) en `Shared/Ports/` para ser intercambiables

## Key Files
- `public/index.php` — API entry point y registro de rutas
- `app/Features/Shared/RoomTypeRegistry.php` — Mapeo centralizado de habitaciones
- `src/services/bookingService.ts` — Cliente de reservas frontend
- `src/services/httpClient.ts` — Cliente HTTP base
- `.env` / `.env.example` — Variables de entorno requeridas
- `docs/API_REGISTRY.md` — Catalogo completo de endpoints
- `.agents/BRAND.md` — Identidad visual y de marca