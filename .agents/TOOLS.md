# Herramientas de calidad disponibles

Inventario de las herramientas de apoyo que este repo tiene a mano. **No son
requisito para contribuir**: úsalas si aportan al cambio, no por rutina. Si una
no está instalada en tu máquina, sigue con el flujo normal (`docs/` → código →
tests) y reporta qué no pudiste verificar.

| Herramienta | Para qué | Cómo se usa |
|---|---|---|
| **Context7** | Documentación actual de librerías (Astro, Tailwind, SDK de Mercado Pago) | MCP `context7` — `resolve-library-id` + `query-docs` antes de asumir una API |
| **Semgrep** | SAST local y gratuito: auditoría de seguridad estándar | MCP `semgrep` — escanea el diff o el código antes de un cambio que toca inputs |
| **open-code-review (Alibaba)** | Revisión de código independiente, en modo delegación | `ocr delegate preview` + `ocr delegate rule <file>` (sin API key extra) |
| **Ponytail** | Escalera YAGNI antes de escribir código: ¿existe ya? ¿stdlib? ¿dependencia instalada? → solo entonces el mínimo que funciona | Modo `/ponytail lite|full|ultra` |
| **Skills de diseño** | `design-taste-frontend` (anti-slop, landing/redesign — **no** en el booking wizard) y `modlens` (visión para imágenes; requiere `GEMINI_API_KEY`) | Skills en `.agents/skills/` |
| **Skills de animación y navegador** | `gsap-*` (core, scrolltrigger, timeline, plugins, performance) y `chrome-devtools` (inspección del navegador) | Skills en `.agents/skills/` |
| **graphify** | Índice de código para orientación | Configurado en el repo. **No instalar `codebase-memory-mcp`**: duplica graphify; si hace falta comparar, usar su CLI standalone |

Los hooks de git (`.githooks/`) y el CI (`.github/workflows/build.yml`) no son
herramientas opcionales: el hook comprime imágenes y vídeos al commitear, y el
CI corre la suite completa antes de llegar a `main`.
