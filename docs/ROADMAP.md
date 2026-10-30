# Roadmap — USGAR Hotels

Backlog **abierto**, priorizado. Solo viven aquí los ítems que siguen sin
hecer: lo cerrado está en [`docs/STATE.md`](STATE.md) (estado actual) o en
[`docs/archive/`](archive/) (bitácora). Actualizado: 2026-10-29.

## P1 — Pendientes del dueño (bloquean la operación real)

| # | Qué | Por qué |
|---|---|---|
| P1-D1 | **Reembolsar en el back-office de QloApps** las órdenes de prueba 266, 268, 287, 289 y 293 | 289 y 293 tienen S/. 660.67 capturados. Solo desde el back-office: la regla 1 de `docs/PMS.md` §F prohíbe tocar `qlo_*` por SQL |
| P1-D2 | **Rotar `PANEL_PASSWORD`** | Hoy tiene el mismo valor que `DB_PASS` |
| P1-D3 | **Pasar Mercado Pago a credenciales reales** | Producción usa hoy el par sandbox. Procedimiento en `docs/DEPLOYMENT.md` |
| P1-D4 | **Configurar SMTP (`SMTP_*`)** | Sin SMTP no sale el email de confirmación (fail-soft por diseño) |
| P1-D5 | **Activar QloApps Channel Manager (Webkul)** | Activación de pago; runbook en `docs/PMS.md` §D |

## P1 — Legal (requieren dueño, no se cierran desde el código)

La copia de `src/data/legal.ts` ya coincide con el código (cookies, encargados,
cifrado, tipo de cambio) y con la norma vigente (ver `tests/Unit/legal.test.ts`).
Quedan puntos que necesitan decisión o dato del dueño:

| # | Qué | Por qué |
|---|---|---|
| P1-L1 | Razón social y RUC del titular, y estado de inscripción de la base de datos en el Registro Nacional de Protección de Datos Personales | La política identifica "USGAR Hotels" y la dirección, pero no la persona jurídica; el DS 016-2024-JUS (art. 43.1.2) inscribe las bases de datos privadas |
| P1-L2 | Libro de Reclamaciones físico o virtual | La Ley 29571 (art. 150) lo exige al establecimiento; el sitio no lo enlaza ni consta uno físico |
| P1-L3 | Plazo de conservación por tipo de dato y purga | No hay ningún `DELETE` en `app/`: contactos y cuentas se guardan indefinidamente; la newsletter solo pasa a `unsubscribed` como lista de supresión. La política describe el criterio, no un plazo cerrado |
| P1-L4 | Representante en la UE (art. 27 GDPR) y mecanismo de transferencia documentado | La política dice que se usa "el mecanismo del proveedor", sin identificar SCC ni representante |

## P2 — Deuda estructural

- [ ] **P2-7** `Response::json()` hace `exit()` dentro de la clase → mover el
  exit al front controller. **Diferido**: ~40 call sites asumen que la
  respuesta termina el script; tocarlo sin refactorizar las acciones a la vez
  es una regresión garantizada. Revisar junto a un refactor mayor de acciones.
- [ ] **Compensación del carrito de QloApps** si el INSERT local falla después
  de crear el carrito remoto (auditoría 2026-09-07): el contrato de cancelación
  del webservice aún no está verificado.
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
