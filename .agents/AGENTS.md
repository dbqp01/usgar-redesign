# Proyecto: USGAR Hotels (Cusco, Perú)

## Propósito
Desarrollo de un sitio web transaccional enfocado en turistas internacionales. El sistema procesa reservas directas y sincroniza disponibilidad con plataformas externas.

## Tecnología e Infraestructura
**Frontend:** Astro v7 (estático), Tailwind CSS v4, Leaflet (OpenStreetMap).
**Backend (El Híbrido 2026):** PHP 8 estructurado como un Monolito Modular Híbrido (Vertical Slicing). Actúa como Front Controller proxy seguro para enrutar llamadas a APIs externas y proteger credenciales del cliente.
**Servidor:** Hosting compartido en Hostinger (PHP nativo y MySQL).
**Arquitectura:** Multitienda. Frontend en subdominios por hotel; QloApps centralizado como sistema de gestión (PMS) y base de datos única.

## Diseño y Experiencia de Usuario
**Visual:** Tema claro y oscuro. Paleta basada en morados, amarillos y verdes. Tipografías AkhirTahun para títulos y Montserrat para cuerpo.
**Interfaz:** Navbar adaptable, widget de reservas, galerías limitadas a 4 imágenes por habitación y transiciones fluidas.
**Rendimiento y SEO:** Compresión de imágenes, carga diferida, datos estructurados (Schema.org), soporte multilenguaje y URLs descriptivas.

## Integraciones, API y Lógica de Reserva
**Endpoints REST:** La API PHP expone rutas para inventario, creación de reservas, extensión de bloqueos, webhooks de pagos y limpieza de carritos expirados mediante tareas programadas (cron).
**Disponibilidad:** Channex sincroniza inventario en tiempo real con agencias de viaje en línea (OTAs).
**Reservas:** QloApps aplica un bloqueo temporal de inventario de 15 minutos al iniciar la compra. Si el pago falla, el sistema permite extender el bloqueo por 15 minutos adicionales.
**Pagos:** Mercado Pago. Los webhooks de confirmación son gestionados por el backend.
**Seguridad:** Uso estricto de variables de entorno y tokens secretos. Las peticiones no autenticadas a la API son rechazadas para proteger los datos.

---

# LA BIBLIA DE DESARROLLO (Manifiesto Holístico para Agentes de IA)

Las siguientes reglas son **INQUEBRANTABLES** y gobiernan a cualquier Agente IA que opere en este ecosistema:

## I. Herramientas Cognitivas y de Ejecución Obligatorias (Uso de MCPs)
- **Razonamiento Estructurado:** Uso innegociable de `sequential-thinking` para desglosar problemas antes de tocar el código base.
- **Conocimiento Contextual y Estándares:** Consulta obligatoria a `context7` para absorber sintaxis modernas y a `tavily` para investigar estándares de arquitectura actualizados al año en curso.
- **Auditoría y Validación Real:** Prohibido asumir que el código funciona. Todo flujo visual debe verificarse mediante `chrome-devtools-mcp` (screenshot/DOM) y todo flujo transaccional E2E mediante scripts de `playwright` o endpoints HTTP (`curl`/`fetch`).

## II. Arquitectura Backend Híbrida 2026
- **SOLID Pragmático:** Abstracción equilibrada. Principio de Responsabilidad Única (SRP) estricto.
- **Monolito Modular (Vertical Slicing):** El código se organiza por Dominios/Features de negocio, no por capas técnicas de framework (Ej: `src/Features/Booking`).
- **Action-Domain-Responder (ADR):** Las APIs no usan Controladores MVC masivos. Se exige una Clase-Acción por cada Endpoint HTTP.
- **Arquitectura Hexagonal (Puertos y Adaptadores):** Integraciones con QloApps, MercadoPago y Channex deben ser conectables e intercambiables mediante Interfaces, aislando estrictamente el núcleo de dominio.
- **Event-Driven Interno:** Procesos asíncronos y post-procesamiento (emails, sync) deben manejarse mediante disparadores de eventos internos.

## III. Regla Anti-Hardcoding Absoluta (Cero Tolerancia)
- Queda totalmente prohibido dejar "quemados" en el código base (hardcodeados) valores susceptibles a cambios (precios, stock, tarifas, ocupaciones máximas, correos, tokens). 
- Todo dato dinámico debe provenir del Back-Office (BD / QloApps) vía API, o en su defecto, estar inyectado de forma segura mediante el archivo `.env`.

## IV. Ciclo de Auditoría y Mejora Continua (Regla del Boy Scout)
- Todo agente está obligado a aplicar **Doubt-Driven Development**. No asumas nada; duda del código legacy.
- Al modificar un archivo heredado (legacy), el agente debe auditarlo holísticamente y, de ser seguro, refactorizarlo hacia la Arquitectura Híbrida sin quebrar las integraciones existentes. Todo cambio arquitectónico debe registrarse para facilitar auditorías futuras.