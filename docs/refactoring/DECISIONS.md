# DECISIONS — Rediseño Awwwards

## 2026-08-01
- **Modo claro = referencia visual**; dark mode se corrige solo en el USO del morado (lavanda `#A78BBD`), nunca en fondos (#121214/#1A1A1E intocables — usuario probó tonalidades).
- **Capacidades**: todas +1 persona con $30/noche → doble 3, matri 3, triple 4, familiar 8. Modelo: `baseOccupancy` (incluidos) + `maxCapacity` (tope físico) + `extraGuestCharge`.
- **Algoritmo de habitaciones**: individual + combinaciones multi-habitación, orden por precio total, top 3-4, badge "Mejor precio". Motor TS puro `src/features/booking/roomAllocator.ts` (Fase 4).
- **Inventario**: desconocido → `settings.json` (doble 8, matri 6, triple 4, familiar 2). BD QloApps vacía verificada → degradación elegante si API no responde.
- **Calendario**: custom estético + bloqueo por disponibilidad (API existente).
- **Mapa**: SVG ilustrado hecho a mano (evitar look AI), reemplaza Leaflet (perf −145 KB).
- **Certificaciones**: logos reales TripAdvisor/Booking (guías de uso).
- **Newsletter**: endpoint PHP ADR → tabla `newsletter_subscribers`.
- **Cursor**: dot + ring, solo pointer:fine, kill-switch; si no queda impecable se retira.
- **SplitText ya usado** en el proyecto (licencia presente) → reutilizable para text-reveal.
- **Verificación**: astro check + build + Chrome DevTools. SIN Playwright (no instalado en este entorno).
- **Migraciones NO ejecutar**: Channex→Nobeds, QloApps→otro CMS/PMS (solo ToDo).
