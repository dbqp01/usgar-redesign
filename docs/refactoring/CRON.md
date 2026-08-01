# Configuracion de Cron Jobs (Hostinger)

Los siguientes crons deben configurarse en el panel de Hostinger
(`Avanzado -> Trabajos programados` / Cron Jobs).

## 1. Procesador del Outbox (eventos de dominio pendientes)

**Cada 5 minutos:**

```
php /home/USER/domains/usgarhoteles.com/public_html/cron/process_outbox.php
```

> Reemplazar `/home/USER/domains/usgarhoteles.com/public_html/` por la ruta real
> de despliegue del proyecto. Verificar con `pwd` tras subir los archivos.

Procesa la tabla `event_outbox`: eventos `booking.paid` serializados que no
pudieron ejecutarse en la peticion HTTP (integraciones externas: QloApps,
Channex). Los listeners se registran via `app/bootstrap.php` (compartido).

## 2. Reconciliacion de pagos (webhooks que nunca llegaron)

**Cada 10 minutos:**

```
php /home/USER/domains/usgarhoteles.com/public_html/cron/reconcile_payments.php
```

Consulta MercadoPago por holds pendientes cuyo webhook no fue entregado.
Si el pago esta `approved`, completa la reserva y dispara `booking.paid`.

## Requisitos en la BD

La columna `payment_id` debe existir en `provisional_bookings`:

```sql
ALTER TABLE provisional_bookings ADD COLUMN payment_id VARCHAR(64) NULL AFTER status;
```

Ejecutar una sola vez en produccion. El codigo ignora la columna si no existe
(`attachPaymentId` falla silenciosamente con log de error).

## Verificacion

```bash
php cron/process_outbox.php        # Esperado: "No events to process." o lista de procesados
php cron/reconcile_payments.php    # Esperado: "Reconciliacion completada: checked=0, reconciled=0, skipped=0"
```
