# DECISIONS — Rediseño Awwwards

## 2026-08-02 (Fase 0 — cierre)
- **Laravel 12 + Filament v5.7.5 desplegado en subdominio admin (multi-tenant Property)**: decisión de arquitectura del panel admin confirmada en producción. Deploy variante B: zip (`usgar-admin-deploy.zip`) subido a `public_html/admin` en Hostinger compartido (sin Composer en prod → `vendor/` incluido, caches de prod). Contrato de no-regresión de Fase 0 verificado: sitio Astro intacto (check 0/0/0, api-harness OK, métricas visuales dentro de baseline) + suite Laravel verde (5 passed).
- **Panel Filament**: single admin login; tenant = Property (multi-tenant a nivel de recurso, guard/tenant en el panel). El siguiente ciclo de fase es el rate engine + recursos Filament (Fase 1 del plan).

## 2026-08-01 (Fase 3 + 4 polish — toasts, widget home, feedback dinámico)
- **Toasts estilo Sonner** (`src/features/ui/toast.ts`): top-center, glass dark/light, iconos por tipo, auto-dismiss 4s (error 6s, warning 5s), máx 3 visibles, aria-live/role, animación GSAP con reduced-motion off. Usados en validación de huésped, errores de pago, degradación de disponibilidad, rango sin opciones y recomputo de opciones.
- **Widget home = punto de decisión**: popover de calendario custom (mismo motor `calendar.ts`), rango por clic, clampRange con toast "Dates adjusted", redirect a /book con checkin/checkout/guests/roomType. Sección widget elevada a z-20 (el marquee z-10 del contexto raíz interceptaba clics del popover).
- **Feedback dinámico del algoritmo**: recomputo en vivo al cambiar fechas/huéspedes con indicador "checking" + overlay "recalculando" en AllocationStep + toast success "Options updated" + pulso del total en el resumen. `computeAllocation` con try/catch (el fetch nunca deja el indicador colgado).
- **Calendarios sin re-render**: build una vez + `refreshSelection()` in-place (clases/aria) — elimina la re-animación por clic (jank) y estabiliza E2E.
- **Artefactos**: separator `·` no-ASCII reemplazado (regla global).

## 2026-08-01 (Fase 3 + 4 — implementadas)
- **Wizard 3 pasos**: componentes por paso en `src/components/booking/` + store TS puro singleton (`wizardStore.ts`). Transiciones GSAP entre pasos, progreso 01/02/03.
- **Pago MP intacto** (no-go): `PaymentStep` conserva createHold → cardForm → processPayment tal cual; arranca solo cuando el formulario de huésped está válido.
- **Multi-habitación vs backend**: el contrato backend soporta UN roomSlug por createHold → las combinaciones multi-habitación se muestran para comparación (nota i18n `multiRoomNote`) y SOLO opciones de 1 habitación son seleccionables para pago online.
- **Disponibilidad por rango, no por día**: la API (`getAvailableRooms`) responde por RANGO; el calendario marca pasado/seleccionado/rango y el estado "agotado" se valida a nivel de rango con degradación elegante (banner amber + availability null → no excluye).
- **Inventario**: `roomInventory` en `settings.json` (claves = slugs reales: doble-superior, matrimonial, triple-standar, familiar-superior) con fallback `DEFAULT_ROOM_INVENTORY` en `src/data/settings.ts`.
- **Accesibilidad calendario** (WAI-APG): días = `<button role="gridcell">` con `aria-label` fecha completa, `aria-selected`, `aria-disabled` en pasados.
- **contact/login/my-bookings/profile**: ya cumplían el lenguaje editorial (Fase 1) → solo se corrigió texto no-ASCII (em dash/arrows/bullets corruptos) sin reescritura.

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
- **Migraciones NO ejecutar**: QloApps→otro CMS/PMS (solo ToDo).
