# DECISIONS — Refactor USGAR

Registro de decisiones (ADR ligeros). Fecha: 2026-08-01.

## D-001: Mantener MercadoPago como pasarela
- **Estado:** Aceptada (usuario).
- **Contexto:** Se analizaron Payoneer Checkout, Stripe y TAB en MIGRATION_PLAN.md.
- **Decisión:** No migrar. MercadoPago se mantiene; el refactor se limita a limpiar residuos Checkout Pro y reparar la comunicación (webhooks/outbox/reconciliación).

## D-002: QA con PHPUnit real + PHPStan
- **Estado:** Aceptada (usuario).
- **Decisión:** Sustituir el polyfill casero de PHPUnit (`tests/Unit/TestCase.php`) por `phpunit/phpunit ^11` como dev-dependency; añadir `phpstan/phpstan ^2` con baseline congelado. Runner unificado: `php composer.phar check`.

## D-003: Reparar outbox (no rediseñarlo)
- **Estado:** Aceptada (usuario).
- **Decisión:** Crear `app/bootstrap.php` compartido (container + listeners) usado por `public/index.php` y `cron/process_outbox.php`; cablear cron en Hostinger; añadir job de reconciliación de pagos (consulta a MP de payments pendientes cuyo webhook no llegó).

## D-004: Rotación de secretos — manual por el usuario
- **Estado:** Aceptada (usuario).
- **Decisión:** La rotación de credenciales (token MP, BD, webhook secret, session secret) es manual por el usuario en los paneles. El repo solo se limpia (P4-1: quitar secretos del working tree, `.env.example` con placeholders). El git history conservará secretos hasta la rotación.

## D-005: Alcance — solo refactor + MCP MP
- **Estado:** Aceptada (usuario).
- **Decisión:** Nobeds (reemplazo de Channex) y Filament (reemplazo de QloApps) quedan como fases futuras fuera de este plan. El MCP de MercadoPago se usa para diagnóstico real (notifications_history, save_webhook, search_documentation).

## D-006: MCP MercadoPago — formato de config opencode
- **Estado:** Aceptada (evidencia).
- **Decisión:** El bloque MCP debe usar `command` como array de strings (schema oficial https://opencode.ai/config.json, `McpLocalConfig`), token inline en `--header "Authorization:Bearer ..."`. El formato Claude Desktop (`command: "npx"` string + `${VAR}`) es inválido en opencode. Verificado: handshake MCP real OK con 11 tools (`FINAL_OK`).
