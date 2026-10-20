# Docs — USGAR Hotels

Índice de la documentación. **Qué leer para qué.**

> **Regla de lectura:** si un documento contradice al código, **manda el
> código**; y si el código cambia de comportamiento, el documento se corrige en
> el **mismo commit**. Los docs con fecha dentro del texto son snapshots: para
> el histórico hay `docs/archive/`.

## Documentos operativos

| Documento | Para qué | Verificado |
|---|---|---|
| [`API.md`](API.md) | Contrato de la API HTTP (33 endpoints, envelope de errores, env vars). Fuente: `public/index.php` | 2026-10-20 |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Capas, flujo de reserva, zonas de riesgo | 2026-08-11 |
| [`SECURITY.md`](SECURITY.md) | Modelo de seguridad (auth, webhooks, headers, CSRF) | 2026-09-11 |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Deploy en Hostinger, crons, hooks git, secretos, env vars | 2026-08-11 |
| [`PMS.md`](PMS.md) | QloApps: tutorial del dueño, inventario, tarifa, webservice, channel manager, contrato de escritura en `qlo_*` (§F) | 2026-08-11 · §F 2026-10-20 |
| [`DECISIONS.md`](DECISIONS.md) | Decisiones de arquitectura vivas | 2026-08-11 |

## Estado y backlog

| Documento | Para qué | Verificado |
|---|---|---|
| [`STATE.md`](STATE.md) | **Estado actual del backend**: qué está desplegado y verificado, decisiones vigentes, pendiente real | 2026-10-20 |
| [`ROADMAP.md`](ROADMAP.md) | **Solo lo que está abierto**: pendientes del dueño, deuda técnica, activación del channel manager | 2026-10-20 |

## Specs (SDD)

Fuente de verdad del **comportamiento** de cada feature (requisitos EARS +
cómo se verifican). Ver [`specs/README.md`](specs/README.md) para las reglas.

| Spec | Estado |
|---|---|
| [`specs/booking.spec.md`](specs/booking.spec.md) | estable |
| [`specs/auth.spec.md`](specs/auth.spec.md) | estable |
| [`specs/payments.spec.md`](specs/payments.spec.md) | estable |

## Archivo (histórico, no vigente)

Bitácora de sesiones y diagnósticos ya resueltos. **No describe el sistema
actual**; sirve para saber qué se intentó y por qué.

- [`archive/state-log-2026-08-10.md`](archive/state-log-2026-08-10.md) — bitácora
  del backend (2026-08 → 2026-10-20).
- [`archive/pms-diagnostico-2026-10.md`](archive/pms-diagnostico-2026-10.md) — los
  dos 500 del webservice de QloApps y sus fixes.

## Reglas

1. **Una fuente por dominio.** Antes de escribir en un doc, lee el que ya
   cubre ese tema (`docs/` está centralizado a propósito: `AGENTS.md` §Fuentes
   de verdad).
2. **Sin bitácora en los docs vigentes.** Lo que se intentó, lo que falló y los
   diagnósticos con fecha van a `docs/archive/`, no a `STATE.md`/`PMS.md`.
3. **Fecha de verificación explícita.** Cada doc dice cuándo se contrastó con
   el código; sin esa fecha no se sabe si sigue siendo cierto.
4. **Los docs se actualizan en el mismo commit** que el comportamiento que
   describen.
