# Archivo — Diagnóstico PMS, octubre 2026

> **Archivo — bitácora de dos incidencias del webservice de QloApps.**
> Esto **no** describe el estado actual del sistema: son dos 500 del
> `POST /api/bookings` (webservice) ya **resueltos** el 2026-10-20.
> Para el contrato vigente de escritura en `qlo_*` y el flujo actual, lee
> [`docs/PMS.md`](../PMS.md) §F.
> Si algo de este archivo contradice al código o a `docs/PMS.md`, **manda el
> código** y luego `docs/PMS.md`.

## Incidencia 1 — `PDOException` por `rate_plan_name` inexistente

Orden del hallazgo (todo verificado en producción):

1. `POST /api/bookings` respondía 500 en cada intento. La traza del módulo
   `qlocmconnector` v5.0.4 muestra que inyecta `rate_plan_name` en la
   definición de `HotelBookingDetail` (hooks `actionObjectHotelBookingDetailPropertiesModifier`
   y `…DefinitionModifier`); la columna no existía → `PDOException`.
2. Fix: `ALTER TABLE qlo_htl_booking_detail ADD COLUMN rate_plan_name VARCHAR(255) DEFAULT NULL`
   (idempotente, el mismo DDL que declara el propio módulo en su `upgrade-5.0.4.php`).
3. Con la columna creada, el webservice volvió a crear reservas (orden 286 +
   booking 227) y el flujo web completo se verificó en vivo: webhook de MP
   recibido, `processed_payments` con la fila del pago, orden 287 creada y
   pagada en el PMS.

> Si tras un upgrade de QloApps reaparece el 500, revisar primero si el módulo
> de channel manager (`qlocmconnector`) volvió a exigir una columna nueva:
> `SHOW COLUMNS FROM qlo_htl_booking_detail` y comparar con lo que inyectan sus
> hooks.

## Incidencia 2 — `PrestaShopException: Property Customer->lastname is not valid`

Con `rate_plan_name` ya creada, el webservice volvió a dar 500, pero por otra
razón y con otra firma: **cuerpo vacío**. El `dispatcher` solo captura
`WebserviceException` (`webservice/WebserviceRequest.php:563-565`), así que una
`PrestaShopException` escapa de `WebserviceRequest::fetch()` y, con
`display_errors=Off`, el PHP responde **500 sin cuerpo y sin traza**.

Traza real del log del CMS (`~/.logs/error_log_cms_usgarhoteles_com`):

```
PrestaShopException: Property Customer->lastname is not valid
#3 classes/Customer.php(288): ObjectModelCore->update()
#4 classes/ObjectModel.php(451): CustomerCore->update()
#5 classes/webservice/WebserviceSpecificManagementBookings.php(1403): ObjectModelCore->save()
#6 classes/webservice/WebserviceSpecificManagementBookings.php(843): …->processCustomer()
#7 classes/webservice/WebserviceSpecificManagementBookings.php(324): …->handlePostRequest()
#8 classes/webservice/WebserviceSpecificManagementBookings.php(564): …->manage()
#9 classes/WebserviceRequest.php(564)
#10 webservice/dispatcher.php(87): WebserviceRequestCore->fetch()
```

**Por qué salta.** PrestaShop valida el nombre del cliente con
`Validate::isName()` (`classes/Validate.php:157-160`):

```php
preg_match(Tools::cleanNonUnicodeSupport('/^[^0-9!<>;,?=+()@#"°{}_$%:]*$/u'), stripslashes($name))
```

Es decir: **sin dígitos**. En el flujo del webservice, `processCustomer()`
reasinga `firstname`/`lastname` **solo si** el valor entrante pasa
`Validate::isName()`, y luego `save()` valida lo almacenado → un cliente ya
guardado con un nombre inválido (p. ej. `lastname = "E2E"`) hace que **todo**
POST posterior lance la excepción: el 500 es permanente hasta corregir el
nombre en el PMS. En el primer alta el fallo es silencioso (`ObjectModel::add()`
valida con `validateFields(false)` y devuelve `false` sin lanzar) → el cliente no
se crea y el error aparece recién en la segunda visita del mismo email.

**Fix (web).** `QloAppAdapter::pmsGuestNames()` normaliza el nombre del huésped
con la misma regla del CMS (quita dígitos y símbolos prohibidos, recorta a 32
caracteres, `DEFAULT_GUEST_NAME` si queda vacío) **antes** de mandarlo al PMS:
tanto en el XML del webservice (`createCartMulti()`) como en el INSERT de
`qlo_customer` (`confirmOrder()`). El mismo POST **repara** al cliente ya
guardado, porque el webservice reasigna el `lastname` cuando el valor entrante
es válido. Test de regresión:
`tests/Unit/Features/Shared/Adapters/QloAppAdapterPmsNameTest.php` (la
expectativa del test es la propia regla del CMS).
