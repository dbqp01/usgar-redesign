# USGAR Hotels — San Pedro, Cusco

Repositorio oficial del sitio web transaccional de **USGAR Hotels** en Cusco, Perú. Arquitectura híbrida: **Astro v7.x.x** (frontend estático en `http://localhost:4321`) + **Tailwind CSS v4.3** + **PHP 8.x** (Front Controller API ADR con DI Container PSR-11) + **QloApps** (PMS) + **Channex** (channel manager) + **Postman MCP** (auditoría exhaustiva y de estrés de endpoints).

**Sitio en producción:** [https://usgarhoteles.com](https://usgarhoteles.com)  
**PMS / Back-Office:** [https://cms.usgarhoteles.com](https://cms.usgarhoteles.com)

---

## 1. Arquitectura General

```text
┌──────────────────────────────────────────────────────────────────┐
│                      USUARIO (Browser / App)                     │
│                                                                  │
│  Astro v7.x.x HTML (http://localhost:4321 via npm run dev:all)   │
│         │                                                        │
│         ▼                                                        │
│  fetch('/api/...')  →  PHP Front Controller (public/index.php)   │
│         │                │            │            │             │
│         │                ▼            ▼            ▼             │
│         │           QloApps API    Channex API   Mercado Pago    │
│         │          (cms.usgar      (Channel      (Pagos)         │
│         │           hoteles.com)   Manager)                      │
│         │                │            │                          │
│         │                ▼            ▼                          │
│         │           MySQL DB     Booking.com                     │
│         │                        TripAdvisor / Airbnb            │
└──────────────────────────────────────────────────────────────────┘
```

### Roles de cada sistema

| Sistema | Rol | URL/Endpoint |
|---|---|---|
| **Astro v7.x.x** | Frontend estático (HTML, Tailwind CSS v4.3, TS) | `http://localhost:4321` (Dev) / `https://usgarhoteles.com` |
| **PHP 8.x API** | Front Controller proxy seguro (ADR + DI Container PSR-11) | `public/index.php` → `app/Features/` |
| **Postman MCP** | Auditoría exhaustiva, límites y pruebas de estrés de API | `postman-mcp-server` (`runCollection`, `createCollectionRequest`) |
| **PHP Test Harness** | Script único local de pruebas PHP | `tests/api-harness.php` |
| **QloApps** | PMS — gestión de habitaciones, reservas, inventario local | `https://cms.usgarhoteles.com` |
| **Channex** | Channel Manager — Sincronización en tiempo real con OTAs | API REST `api.channex.io` |
| **Mercado Pago** | Pasarela de pagos | API REST & Webhooks IPN |
| **Hostinger** | Hosting compartido (PHP 8 nativo + MySQL `srv909.hstgr.io`) | Panel hPanel |

---

## 2. Metodología de Desarrollo Agentico y Auditoría (Postman MCP & 5 Etapas)

El desarrollo y auditoría del proyecto siguen un procedimiento estricto para prevenir errores en cascada y asegurar cero regresiones:

1. **Razonamiento Inicial (`sequential-thinking`):** Planteamiento estructurado de hipótesis y división en pasos secuenciales.
2. **Recolección de Oportunidades y Documentación (`context7`):** Consulta activa de documentación oficial, mejores prácticas, APIs modernas y patrones optimizados según Astro v7.x.x, Tailwind CSS v4.3 y PHP 8.x.
3. **Auditoría Exhaustiva y de Estrés de API (**Postman MCP**):** Ejecución de solicitudes y colecciones Postman evaluando **todas las opciones posibles** (caminos felices, payloads inválidos, firmas corruptas, tokens expirados y cargas sintéticas).
4. **Investigación en Tiempo Real (`tavily` & `agent-skills` MCP):** Búsquedas sobre librerías y consulta de habilidades estandarizadas (`coding-guidelines`, `security-best-practices`, `perf-astro`, `web-quality-audit`).
5. **Análisis de Impacto / Red de Nodos (`graphify` / búsqueda incorporada):** Evaluación obligatoria antes de modificar código:
   > *"¿Si hago este cambio o refactorización, en qué afectará al proyecto?"*
6. **Aplicación y Abstracción:** Implementación desacoplada, sin hardcoding (Zero-Hardcoding), mediante `.env` o base de datos.

---

## 3. Entorno de Desarrollo y Comandos

```bash
# 1. Instalar dependencias
npm install

# 2. Servidor de desarrollo completo (Astro frontend en http://localhost:4321 + PHP API backend en 8000)
npm run dev:all

# O ejecutar servidores individualmente:
npm run dev      # Servidor Astro dev (http://localhost:4321)
npm run dev:php  # Servidor PHP API (http://localhost:8000)

# 3. Verificación de Tipos, Pruebas y Build
npm run check             # Verificación de tipos TypeScript y componentes Astro v7
php tests/api-harness.php  # Script único local de pruebas PHP
npm run build             # Compilación estática para producción
```

---

## 4. Despliegue en Hostinger Shared Hosting

1. Ejecutar el script SQL `scripts/create_processed_payments_table.sql` en phpMyAdmin.
2. Compilar el sitio estático Astro: `npm run build`.
3. Subir el contenido generado en `dist/` a la raíz del hosting (`public_html`).
4. El archivo `public/index.php` actuará como Front Controller en PHP 8 nativo para la API REST en `/api/*`.
