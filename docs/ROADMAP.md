# Roadmap — USGAR Hotels

Backlog **abierto**, priorizado. Solo viven aquí los ítems que siguen sin
hecer: lo cerrado está en [`docs/STATE.md`](STATE.md) (estado actual) o en
[`docs/archive/`](archive/) (bitácora). Actualizado: 2026-10-20.

## P1 — Pendientes del dueño (bloquean la operación real)

| # | Qué | Por qué |
|---|---|---|
| P1-D1 | **Reembolsar en el back-office de QloApps** las órdenes de prueba 266, 268, 287, 289 y 293 | 289 y 293 tienen S/. 660.67 capturados. Solo desde el back-office: la regla 1 de `docs/PMS.md` §F prohíbe tocar `qlo_*` por SQL |
| P1-D2 | **Rotar `PANEL_PASSWORD`** | Hoy tiene el mismo valor que `DB_PASS` |
| P1-D3 | **Pasar Mercado Pago a credenciales reales** | Producción usa hoy el par sandbox. Procedimiento en `docs/DEPLOYMENT.md` |
| P1-D4 | **Configurar SMTP (`SMTP_*`)** | Sin SMTP no sale el email de confirmación (fail-soft por diseño) |
| P1-D5 | **Activar QloApps Channel Manager (Webkul)** | Activación de pago; runbook en `docs/PMS.md` §D |

## P2 — Deuda estructural

- [ ] **P2-7** `Response::json()` hace `exit()` dentro de la clase → mover el
  exit al front controller. **Diferido**: ~40 call sites asumen que la
  respuesta termina el script; tocarlo sin refactorizar las acciones a la vez
  es una regresión garantizada. Revisar junto a un refactor mayor de acciones.
- [ ] **Compensación del carrito de QloApps** si el INSERT local falla después
  de crear el carrito remoto (auditoría 2026-09-07): el contrato de cancelación
  del webservice aún no está verificado.
- [ ] **Política de rutas i18n `/fr` vs `/fr/`**: Astro emite un warning no
  fatal; falta decidir una única estrategia.
- [ ] **Cancelaciones por refund/chargeback**: hoy son manuales.

## Channel manager — pasos de activación

- [ ] Confirmar con Webkul antes de pagar: conectividad Booking/Expedia
  incluida, compatibilidad de versión, límite de canales.
- [ ] Activar suscripción en channels.qloapps.com (el trial de 15 días **no**
  sincroniza precios ni inventario).
- [ ] Habilitar el webservice QloApps con permisos `cm_api` + módulo conector.
- [ ] Configurar canales (Booking/Expedia con «Channex» como proveedor, Airbnb
  con authorize) + mapeo de room types.
- [ ] Monitoreo: reconciliación periódica de inventario/tarifas OTA vs QloApps
  (el CM no tiene status page pública).
- [ ] Pruebas con OTAs reales y verificación de overbooking.

## Reglas del roadmap

- Un ítem entra aquí solo si **no** está hecho y verificado; al cerrarlo se
  borra de esta lista y su evidencia va a `docs/STATE.md` (file:line + comando
  de verificación).
- Marcar `[x]` solo cuando está hecho **y** verificado (tests pasando).
- Una mejora = un commit `fix:`/`refactor:`/`perf:` por tema, con su test.
- Lo que es Bitácora (qué se intentó, qué falló y por qué) **no** va aquí: va a
  `docs/archive/`.
