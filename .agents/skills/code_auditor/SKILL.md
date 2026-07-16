---
name: "code-auditor"
description: "Auditoría lógica completa del codebase USGAR Hotels. Revisa documento por documento: sincronización de datos entre frontend y backend, precios hardcodeados, flujos de reserva incompletos, habitaciones fantasma, i18n incompleto, imports rotos, lógica mock vs producción, Schema.org, y coherencia general del código."
---

# Auditoría Lógica — USGAR Hotels

## Propósito

Revisar CADA archivo del proyecto buscando incoherencias internas: datos duplicados
que no coinciden, flujos rotos, lógica que nunca se ejecuta, y desincronización
entre frontend (Astro/TypeScript) y backend (PHP).

## MCPs Requeridos

- **filesystem** — Para leer cada archivo del proyecto
- **sequential-thinking** — Para razonar paso a paso sobre cada hallazgo
- **context7** — Para verificar APIs correctas de Astro v5, Tailwind v4

## Procedimiento Paso a Paso

### Paso 1: Verificar sincronización de habitaciones

Leer estos 3 archivos y comparar que tengan LOS MISMOS datos:

1. `src/data/rooms.ts` — Frontend
2. `public/api/rooms.php` — Backend PHP
3. `.agents/BRAND.md` sección §6 — Fuente de verdad

**Verificar para cada habitación:**
- [ ] Nombre comercial idéntico (ES e EN)
- [ ] Slug idéntico
- [ ] Precio por noche idéntico ($90, $90, $120, $150)
- [ ] Número de camas idéntico
- [ ] Max huéspedes idéntico (2, 2, 3, 7)
- [ ] Solo existen 4 habitaciones (NO Quadruple Superior)

### Paso 2: Buscar precios hardcodeados

Buscar en TODO el proyecto la cadena `50 *` o `$50` o `45 *` o `65 *`:

**Archivos conocidos con este problema:**
- `public/api/create-preference.php` línea 26: `$totalPrice = 50 * $nights`
- `public/api/channex/booking.php` línea 29: `$totalPrice = 50 * $nights`

**Acción requerida:** Estos deben obtener el precio de `rooms.php` según el `roomId`.

### Paso 3: Verificar flujo de reserva completo

Trazar el flujo desde el frontend hasta el webhook:

1. `BookingWidget.astro` → ¿Hace fetch a `/api/channex/availability`?
2. `book.astro` → ¿Envía POST a `/api/channex/booking`?
3. `booking.php` → ¿Crea carrito en QloApps vía `QloAppWriter`?
4. `create-preference.php` → ¿Crea preferencia en Mercado Pago?
5. `webhook-mercado-pago.php` → ¿Confirma orden en QloApps + push a Channex?

**Para cada paso verificar:**
- [ ] El endpoint existe y responde al método correcto (GET/POST)
- [ ] Los parámetros enviados coinciden con los esperados
- [ ] Los datos de respuesta son consumidos correctamente por el siguiente paso

### Paso 4: Verificar i18n

Comparar `src/i18n/en.json` y `src/i18n/es.json`:
- [ ] Ambos tienen exactamente las mismas claves
- [ ] No hay claves vacías o placeholder
- [ ] Los componentes usan `t('clave')` y no texto hardcodeado

Buscar en componentes `.astro` la cadena `lang === 'es'` o condicionales inline:
- [ ] Si existen, reportar como incoherencia con las reglas i18n

### Paso 5: Verificar Schema.org

Leer `src/pages/index.astro` y buscar el bloque `<script type="application/ld+json">`:
- [ ] `checkinTime` = "12:00"
- [ ] `checkoutTime` = "10:30"
- [ ] `numberOfRooms` = 4 (NO 5)
- [ ] `priceRange` = "$$"
- [ ] URL del hotel = "https://usgarhoteles.com"

### Paso 6: Verificar variables de entorno

Leer `.env` y verificar que cada variable referenciada en el código PHP existe:
- Buscar todas las llamadas a `getEnvValue('...')` en los archivos PHP
- Verificar que cada clave exista en `.env`
- Reportar variables huérfanas (existen en .env pero nadie las usa)

### Paso 7: Buscar código muerto

- [ ] ¿Hay archivos en `src/services/` que no son importados por nadie?
- [ ] ¿Hay componentes en `src/components/` que no son usados en ninguna página?
- [ ] ¿Hay rutas en `router.php` que apuntan a archivos que no existen?

## Formato de Reporte

Crear un artifact `audit_code_results.md` con:

```markdown
# Auditoría Lógica — Resultados
Fecha: [fecha]

## ✅ Correcto
- [lista de cosas que están bien]

## ⚠️ Advertencias
- [inconsistencias menores]

## ❌ Errores Críticos
- [incoherencias que rompen funcionalidad]

## 📋 Acciones Requeridas
- [ ] [acción 1]
- [ ] [acción 2]
```
