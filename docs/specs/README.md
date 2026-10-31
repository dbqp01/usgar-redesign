# Specs — Spec-Driven Development (SDD)

Las specs son la **fuente de verdad de comportamientos**: qué debe hacer cada feature y cómo se verifica. La documentación operativa vive en el resto de `docs/` (ver tabla en `AGENTS.md`); una spec **no duplica** esa documentación, la referencia.

## Reglas

1. Toda spec describe **comportamiento observable**, no implementación interna. La implementación se referencia por archivo (`app/Features/X/...`), nunca se transcribe.
2. Los requisitos usan el formato **EARS**: `CUANDO <evento>, EL SISTEMA DEBE <respuesta>` (y `SIEMPRE` para invariantes).
3. Toda afirmación sobre el código debe verificarse contra el código real. Si el código no coincide con la spec, la spec marca la desviación en la sección *Desviaciones conocidas*.
4. Nueva feature = nueva spec ANTES de codear (SDD). Cambio de comportamiento = actualizar la spec en el mismo commit.
5. Idioma: español. Formato: la plantilla de abajo.

## Plantilla

```markdown
# Spec: <Nombre de la feature>

**Estado:** estable | en desarrollo | deprecada
**Código:** `app/Features/<X>/`, `src/...`
**Docs relacionadas:** [links a docs/API.md, ARCHITECTURE.md, etc.]

## Propósito
1-3 frases. Para qué existe.

## Requisitos funcionales (EARS)
- CUANDO ..., EL SISTEMA DEBE ...
- SIEMPRE ...
- RF-XX: [id numérico para trazabilidad]

## Requisitos no funcionales
- Seguridad, performance, i18n, etc. (solo los que apliquen)

## Contratos
- Endpoints (método + ruta + in/out resumido → link a docs/API.md)
- Eventos / tablas propias / integraciones externas

## Verificación
- Tests que cubren esta spec (ruta al test) + comando.

## Desviaciones conocidas / TODO
- Solo si aplica. Si no, omitir la sección.
```

## Índice

| Spec | Feature | Estado |
|---|---|---|
| `booking.spec.md` | Flujo de reserva (wizard, holds, precio, confirmación QloApps) | en desarrollo |
| `auth.spec.md` | Autenticación (JWT propio + OAuth social) y Panel admin | en desarrollo |
| `payments.spec.md` | MercadoPago, webhooks, outbox, conciliación y crons | en desarrollo |
| `seo-location.spec.md` | Páginas de alojamiento y ubicación en San Pedro | en desarrollo |
