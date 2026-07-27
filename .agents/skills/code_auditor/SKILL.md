---
name: "code-auditor"
description: "Auditoria logica y arquitectonica exhaustiva del codebase USGAR Hotels (Astro v7.x.x, PHP 8.x ADR, Tailwind CSS v4.3, Postman MCP). Revisa paso a paso cada archivo aplicando razonamiento profundo, verificacion de oportunidades de mejora con Context7, analisis de impacto con Graphify, auditoria exhaustiva y de estres de API con Postman MCP y busquedas en tiempo real con Tavily."
---

# Auditoria Logica y Arquitectonica — USGAR Hotels

## Proposito

Realizar una auditoria profunda y sin atajos de CADA archivo del proyecto buscando incoherencias internas, oportunidades de mejora con las versiones actuales del stack (**Astro v7.x.x**, **PHP 8.x ADR**, **Tailwind CSS v4.3**), auditoria exhaustiva y de estres de endpoints backend con **Postman MCP**, desincronizacion entre frontend y backend, precios hardcodeados y violaciones arquitectonicas.

---

## MANDATO IMPERATIVO DE USO DE MCPs (CERO OMISIONES NI USO SUPERFICIAL)

El uso de las herramientas MCP durante una auditoria o refactorizacion es OBLIGATORIO E INELUDIBLE en cada etapa:

- **`sequential-thinking` — MANDATORIO:** Invocar obligatoriamente via `call_mcp_tool` al inicio de cada analisis. Prohibido emitir hallazgos o realizar cambios sin este registro previo.
- **`postman-mcp-server` — MANDATORIO PARA AUDITORIA EXHAUSTIVA DE APIs:** Invocar herramientas de Postman (`getCollections`, `runCollection`, `createCollectionRequest`, `searchPostmanElements`) para probar **todas las opciones posibles** de endpoints (`/api/*`): caminos felices, payloads invalidos, parametros ausentes, firmas HMAC corruptas y pruebas de carga/estres. Prohibido limitar las pruebas a solo un par de peticiones simples.
- **`context7` — MANDATORIO:** Invocar `resolve-library-id` y `query-docs` para consultar la sintaxis oficial, especificacion de APIs y mejores practicas de Astro v7.x.x, Tailwind CSS v4.3 y PHP 8.x antes de proponer cambios.
- **`agent-skills` MCP & `tavily-mcp` — MANDATORIO:** Consultar e invocar las skills estandarizadas (`web-quality-audit`, `perf-astro`, `security-best-practices`, `best-practices`, `coding-guidelines`, `accessibility`) y ejecutar `tavily_search` para respaldar decisiones tecnicas en tiempo real.
- **`graphify` / Red de Nodos — MANDATORIO:** Consultar la red de dependencias antes de proponer cualquier refactorizacion.

---

## Ciclo de Auditoria & Codificacion (Flujo B)

Para cada archivo o componente auditado/modificado:

1. **Descomposicion Tecnica e Hipotesis (`sequential-thinking`):** Analisis del problema y formulacion de la hipotesis de solucion.
2. **Recoleccion de Oportunidades y Documentacion (Context7):** Inspeccionar APIs actualizadas y patrones optimizados del stack.
3. **Investigacion de Estandares (`agent-skills` & `tavily-mcp`):** Verificacion contra skills estandarizadas de calidad.
4. **Analisis de Impacto en Red de Nodos (`graphify` / Busqueda de Nodos):** Responder obligatoriamente:
   > *"¿Si hago este cambio o refactorizacion, en que afectara al resto del proyecto?"*
5. **Aplicacion Desacoplada (Zero-Hardcoding):** Implementar via `.env` o DB manteniendo SRP/DIP.
6. **Depuracion Logica Profunda en Runtime (**Postman MCP** & Script Unico PHP):** Validacion activa en ejecucion real (colecciones exhaustivas y pruebas de estres con **Postman MCP**, script unico `tests/api-harness.php`, pruebas de endpoints API en http://localhost:4321) superando simples comprobaciones estaticas (`npm build`/`check`).

---

## Reglas Anti-Hardcoding, Servidor Dev y Tipograficas
- **Entorno Dev:** Se ejecuta con `npm run dev:all` (servidor accesible en `http://localhost:4321`).
- **Script Unico PHP:** Se utiliza unicamente `tests/api-harness.php` para verificaciones locales en PHP.
- **Zero Hardcoding:** Toda configuracion, precio o token debe abstraerse via `.env` o base de datos.
- **Sin Tildes ni Acentos:** Prohibido el uso de tildes o caracteres acentuados en textos fuente, slugs y rutas web para evitar fallos tipograficos y problemas de codificacion.

---

## Procedimiento de Auditoria Completa

### Fase A: Sincronizacion de Datos y Contratos
- [ ] Comparar `src/content/rooms/rooms.json`, `app/Features/Shared/RoomTypeRegistry.php` y `.agents/BRAND.md` §6. Verificar los 4 tipos de habitacion exactos (Matrimonial $90, Doble Superior $90, Triple Estandar $120, Familiar Superior $150 USD). Confirmar eliminacion total de "Quadruple Superior".
- [ ] Auditar `src/services/bookingService.ts` y contratos en `src/services/contracts/` para asegurar desacoplamiento total de apis externas.
- [ ] Verificar `public/index.php` y Router ADR (`app/Core/Router.php`).

### Fase B: Auditoria Anti-Hardcoding y Configuracion
- [ ] Buscar cadenas hardcodeadas de precios (`50 *`, `$50`, `45 *`, etc.) en todo el codebase.
- [ ] Auditar `app/Features/Booking/Actions/CreateBookingAction.php` y `.env.example` vs `Config::get('...')`.

### Fase C: Integraciones Hexagonales y Seguridad API con Postman MCP (Exhaustivo + Estres)
- [ ] Ejecutar auditoria exhaustiva y de estres con Postman (`postman-mcp-server`) evaluando todas las combinaciones de payloads, cabeceras, firmas HMAC y limites contra endpoints de la API (`/api/rooms`, `/api/booking`, `/api/extend-hold`, `/api/booking-status`, `/api/webhook`).
- [ ] Auditar `MercadoPagoAdapter.php`, `HandleMercadoPagoWebhookAction.php`, `QloAppAdapter.php`, `ChannexAdapter.php` y `HandleChannexWebhookAction.php`.

### Fase D: Frontend Astro v7 & Tailwind v4.3
- [ ] Auditar `src/pages/index.astro` (Astro v7 View Transitions, `<Image />` assets, Schema.org `checkinTime: "12:00"`, `checkoutTime: "10:30"`).
- [ ] Auditar `src/styles/global.css` y directivas `@theme` de Tailwind CSS v4.3.
- [ ] Auditar i18n (`src/i18n/`).

### Fase E: Compilacion y Verificacion Logica Profunda en Runtime
- [ ] Ejecutar `npm run check` y `npm run build` como partida estatica.
- [ ] Ejecutar depuracion logica en runtime con colecciones exhaustivas de **Postman MCP** y `tests/api-harness.php`.
- [ ] Generar o actualizar el reporte en el artifact `audit_code_results.md`.
