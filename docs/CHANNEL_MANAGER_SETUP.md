# Runbook — Activación del QloApps Channel Manager (Webkul) para USGAR Hotels

> **Para:** propietario del hotel + agente ejecutor. **Idioma:** español.
> **Objetivo:** activar la suscripción SaaS **QloApps Channel Manager** y conectar el PMS QloApps (`https://cms.usgarhoteles.com`) con Booking.com, Expedia y Airbnb, con sincronización de precios/inventario/reservas vía webservice + cron (~1 min) para evitar overbookings.
> **Contexto:** sustituye a la integración Channex anterior (ya retirada del repo, ver `docs/MIGRATION_PLAN.md` §2). Este documento NO modifica código: solo activación.
> **Leyenda:** 🔴 **BLOQUEADO** = no se puede completar hasta pagar la suscripción. ✅ = paso verificado en la doc oficial citada. ⚠️ = paso que requiere confirmar en pantalla (la doc no publica el detalle).

**Estado actual (verificado en doc oficial, no inventar):** el **pago de la suscripción fue RECHAZADO** y la sincronización solo funciona con plan activo: *"Users can synchronize price and inventory after upgrading the plan"* — https://qloapps.com/qloapps-channel-manager/ . Ejecutar la **Sección 0 primero**; las secciones 2 (generar credenciales API) y 4-6 quedan 🔴 hasta que el pago se confirme.

---

## 0. Pasarela de pago — activar la suscripción 🔴 (BLOQUEANTE)

La suscripción del QloApps Channel Manager **se paga en su plataforma** (`channels.qloapps.com`), no en el sitio web. El trial de 15 días **NO sincroniza** precio/inventario.

| Dato | Valor oficial | Fuente |
|---|---|---|
| Plan mensual | **$30 USD / propiedad / mes** | https://qloapps.com/channel-manager/ |
| Plan anual | **$300 USD / propiedad / año** (desc. 16.6 % sobre $360) | https://qloapps.com/channel-manager/ |
| Trial | 15 días gratis — **sin sync de precio/inventario** | https://qloapps.com/qloapps-channel-manager/ |
| Reembolsos | **No hay reembolsos** una vez comprado | https://qloapps.com/refund-policy/ |
| Soporte si falla el pago | Ticket Webkul (formulario UVdesk) | https://webkul.uvdesk.com/en/customer/create-ticket/ |

> ⚠️ **Discrepancia documentada:** la guía del CM dice *"15-day free trial"* (https://qloapps.com/qloapps-channel-manager/), pero la política de reembolsos dice *"trial period of 7 days"* (https://qloapps.com/refund-policy/). Verificar en pantalla cuál aplica; no depender del trial para nada.

**Pasos para pagar (flujo oficial del CM — https://qloapps.com/qloapps-channel-manager/ → "How To Upgrade the Plan"):**

1. Entrar a https://channels.qloapps.com/ e iniciar sesión (cuenta creada en el registro: nombre, email, teléfono, nombre del hotel, contraseña — https://qloapps.com/qloapps-channel-manager/).
2. Clic en el **logo de perfil** (esquina) → desplegable → **Subscriptions**.
3. Clic en **Upgrade Plan**.
4. Seleccionar plan **Monthly** o **Yearly** → **Select Plan**.
5. Ingresar el **Properties Count** = `1` (propiedad USGAR).
6. Clic en **CALCULATE PRICE** (muestra el precio final por mes/año).
7. Clic en **Proceed** → completar la dirección de facturación → **Proceed To Payment**.
8. Métodos de pago oficiales: **PayPal, Stripe o Razorpay** (usuarios no indios); **Cashfree** (usuarios indios). Confirmar el método que ofrece la pasarela para Perú. — https://qloapps.com/qloapps-channel-manager/
9. Tras el pago, en **Subscriptions → View details** verificar: **Subscription Details** y **Subscription Payment History** (ahí se ve si el cargo se aplicó). — https://qloapps.com/qloapps-channel-manager/
10. **Si el pago vuelve a fallar:** crear ticket en https://webkul.uvdesk.com/en/customer/create-ticket/ (formulario "Create Sales / Support Request", tipo **Qloapps**), adjuntando el error de la pasarela. No reintentar decenas de veces con la misma tarjeta sin consultar.

**Criterio de salida de la Sección 0:** el panel de Subscriptions muestra el plan activo (Monthly/Yearly) y `Subscription Payment History` refleja el cobro. 🔴 Sin esto, detenerse: el resto del runbook no sincronizará (trial = sin sync).

---

## 1. Webservice de QloApps — crear la clave `cm_api`

Crea la clave que el CM usará para leer/escribir en el PMS.

1. **Habilitar el webservice:** administrador de QloApps → **Advanced Parameters → Webservice** → activar **"Enable QloApps's webservice"** (panel Configuration). — https://devdocs.qloapps.com/webservice/enable-webservice.html
2. **Crear la clave:** en el panel *Webservice Accounts* → **Add new webservice key** → clic en **Generate** (clave generada de 32 caracteres; la doc recomienda la generada por sistema por encima de una escrita a mano). — https://devdocs.prestashop-project.org/9/webservice/tutorials/creating-access/
3. **Permisos del recurso `cm_api`:** en la sección *Permissions*, habilitar el recurso **`cm_api`** con TODOS los permisos (la doc del conector los lista explícitamente):
   - `ALL` (los activa todos de una vez), `GET` (ver), `POST` (añadir), `PUT` (modificar), `DELETE` (eliminar), `HEAD` (ver metadatos). — https://store.webkul.com/qloapps-pms-channel-manager-connector.html
   - ⚠️ **Solo `cm_api`:** la guía del CM muestra marcar *todas* las casillas, pero la doc oficial de PrestaShop advierte: *"Do not give all the rights for all resources to any key"* (privilegio mínimo). Marcar únicamente `cm_api` con todos los permisos. — https://devdocs.prestashop-project.org/9/webservice/tutorials/creating-access/
4. **Guardar y copiar la clave.** ⚠️ Se muestra una sola vez; guardarla en el gestor de contraseñas. Nunca en el repo ni en URLs.
5. **Datos que necesitará el CM (Sección 4):**

| Campo CM | Valor para USGAR | Cómo obtenerlo | Fuente |
|---|---|---|---|
| QloApps Webservice Key | `<WEBSERVICE_KEY_CM>` (32 chars) | Paso 2-3 | https://qloapps.com/qloapps-channel-manager/ |
| QloApps Webservice URL | `https://cms.usgarhoteles.com/api/cm_api` | QloApps instalado en la raíz → patrón `/api/cm_api` | https://qloapps.com/qloapps-channel-manager/ (sección "Credentials for API URL") |
| QloApps TimeZone | `America/Lima` | QloApps → **Localization → Localization** → panel Configuration | https://qloapps.com/qloapps-channel-manager/ (sección "Credentials for QloApps TimeZone") |

**Prueba rápida de la clave (opcional, requiere el header de autorización — ver Sección 7):**
`GET https://cms.usgarhoteles.com/api/cm_api` con header `Authorization: Basic base64(<clave>:)` debe devolver XML sin error 401. — https://devdocs.prestashop-project.org/9/webservice/tutorials/testing-access/

---

## 2. Módulo conector "QloApps PMS & Channel Manager Connector"

Puente entre el PMS y el CM; gratuito e incluido en el core de QloApps.

1. **¿Ya está instalado?** QloApps admin → **Modules & Services → Manage Module** → buscar **"Channel Manager Connector"** (en el repo oficial el módulo es `modules/qlochannelmanagerconnector/`, instalado por defecto; changelog: *"#516: Added 'Channel Manager Connector' module to QloApps"* — https://github.com/Qloapps/QloApps/blob/development/CHANGELOG.txt ). ⚠️ Confirmar el nombre exacto en pantalla (la tienda lo llama "QloApps PMS & Channel Manager Connector"; el módulo del core se llama "Channel Manager Connector").
2. **Si falta, instalarlo:** descarga gratuita (**$0.00 / Free Download**) desde https://store.webkul.com/qloapps-pms-channel-manager-connector.html → subir en **Modules & Services → Upload a module**. — https://store.webkul.com/qloapps-pms-channel-manager-connector.html
3. **Configurar (🔴 requiere plan pagado):** en la página de configuración del módulo (botón **Configuration**) ingresar:
   - **API Client ID** → `<API_CLIENT_ID>`
   - **API Client Secret** → `<API_CLIENT_SECRET>`
   - Guardar. — https://qloapps.com/qloapps-pms-channel-manager-connector/
4. **Dónde se generan esas credenciales:** en el CM → **Account Settings → Generate API Credentials**. 🔴 **La doc es explícita:** *"The 'Generate API Credential' field is exclusive to users having a paid plan on Channel Manager. If you are on a trial plan, this field will remain hidden."* — https://qloapps.com/qloapps-pms-channel-manager-connector/
5. **Verificación:** la sincronización de inventario del CM solo está disponible *"for users who have installed and configured the PMS & Channel Manager Connector Module"*. — https://qloapps.com/qloapps-channel-manager/ (sección "Inventory Synchronization")

---

## 3. Cron — sincronización cada minuto (anti-overbooking)

El conector necesita un cron que corra **cada minuto** contra la URL de sincronización del módulo.

1. **Por qué:** la doc oficial del conector: *"it is essential to configure a cron job that runs every minute. This cron process automatically updates room availability across all connected booking channels, minimizing overbooking risks and ensuring inventory accuracy."* — https://qloapps.com/qloapps-pms-channel-manager-connector/ (sección "Cron Setting for Seamless Availability Syncing"). Igual en la tienda: *"Set up a cron job to run every minute… prevents overbookings"* — https://store.webkul.com/qloapps-pms-channel-manager-connector.html
2. **Obtener la URL de sincronización:** ⚠️ **La doc NO publica la URL exacta del cron** (solo capturas de pantalla). La URL exacta se copia de la **página de configuración del módulo en QloApps** (botón Configuration, sección Cron). **VERIFY-ON-SITE:** copiar esa URL exacta antes de crear el cron; es la única fuente fiable.
3. **Crear el cron en Hostinger:** hPanel → **Avanzado → Trabajos programados (Cron Jobs)**:
   - Frecuencia: `* * * * *` (cada minuto).
   - Comando (patrón típico de URL; sustituir por la URL copiada en el paso 2):
     `wget -q -O /dev/null "https://cms.usgarhoteles.com/<ruta-del-modulo>"` o bien `php /home/<usuario>/domains/usgarhoteles.com/public_html/modules/qlochannelmanagerconnector/<archivo>.php`
   - ⚠️ **VERIFY-ON-SITE:** confirmar si el módulo expone URL HTTP o script PHP local; usar el que indique la página de configuración.
4. **Regla del repo:** el backend ya corre crons en Hostinger (`php public/index.php /api/cron/cleanup` — ver `docs/API_REGISTRY.md`); el cron del CM se suma al mismo panel, sin tocar el existente.
5. **Criterio de salida:** el cron queda visible en el panel con la frecuencia por minuto y se puede ejecutar manualmente una vez para comprobar que no da error.

---

## 4. Conexión PMS + mapeo de propiedades y habitaciones en el CM 🔴 (requiere plan activo)

En `channels.qloapps.com` (pestaña PMS del panel del CM).

1. **Credenciales del PMS (de la Sección 1):** pegar en *General Settings* del CM:
   - **QloApps Webservice Key** → `<WEBSERVICE_KEY_CM>`
   - **QloApps Webservice URL** → `https://cms.usgarhoteles.com/api/cm_api`
   - **QloApps TimeZone** → `America/Lima`
   - Clic en **Test Connection** (debe responder OK; si falla, revisar que el webservice esté habilitado y que el recurso `cm_api` tenga permisos). — https://qloapps.com/qloapps-channel-manager/ (secciones "PMS Connection" y "Credentials…")
2. **Sincronizar propiedades:** botón **Synchronize Properties** → trae las propiedades/habitaciones del PMS al CM para el mapeo. — https://qloapps.com/qloapps-channel-manager/ (sección "Properties and Bookings Synchronization with PMS")
3. **Mapear (OBLIGATORIO antes de sincronizar reservas):** la doc es explícita: *"**Note:** Map properties and room types before syncing."* — https://qloapps.com/qloapps-channel-manager/
   - **Property Mapping:** seleccionar la propiedad PMS del desplegable correspondiente a la propiedad del CM → estado **Mapped**.
   - **Room Type Mapping:** por cada room type del CM, seleccionar el room type correspondiente del PMS → **Save**. Un mapeo correcto *"ensures that inventory and booking data can be synchronized accurately"* y *"reduce the risk of overbookings"*. — https://qloapps.com/qloapps-channel-manager/ (sección "Properties / Room Mapping")
4. **Room types de USGAR a mapear (4)** — slugs del `.env.example` (`CHANNEX_ROOM_*`), a emparejar con los room types del PMS:

| Slug (referencia del repo) | Room type en QloApps (PMS) a seleccionar |
|---|---|
| `doble-superior` | Doble Superior |
| `matrimonial` | Matrimonial |
| `familiar-superior` | Familiar Superior |
| `triple-standar` | Triple Standard |

5. **Sincronizar inventario:** botón **Synchronize Inventory** a nivel de propiedad (todas las room types mapeadas) o junto a cada room type (solo esa). Disponible únicamente con el módulo conector instalado y configurado (Sección 2). — https://qloapps.com/qloapps-channel-manager/
6. **Sincronizar reservas:** botón **Synchronize Bookings** → *"PMS bookings will be synced to the channel manager and channel manager bookings will be synced to PMS."* — https://qloapps.com/qloapps-channel-manager/
7. **Verificación rápida:** si una habitación no aparece en el mapeo, la doc avisa con el alert *"This room type is not found at the PMS while Syncing"* (room type borrado del PMS). — https://qloapps.com/qloapps-channel-manager/

---

## 5. Propiedad de prueba + canales (Booking.com primero) 🔴 (requiere plan activo)

**Regla de la doc:** *"Before adding channels at QloApps Channel Manager for your property, you have to complete the setup process of the channel at the OTA (channel) account."* — https://qloapps.com/qloapps-channel-manager/

### 5.1 Crear una propiedad NUEVA de prueba en el CM (no tocar producción)
1. Pestaña **Hotels → Add Hotel**. Datos (pestaña Information): **Hotel Name** (ej. "USGAR PRUEBA"), **Time Zone** (`America/Lima`), **Currency** (`USD`). — https://qloapps.com/qloapps-channel-manager/
2. Pestaña Additional Details: teléfono, dirección, país (Perú), ciudad, código postal + ubicación en Google Maps + website link. → **Update**. — https://qloapps.com/qloapps-channel-manager/
3. **Room Types → Add Room Type** (name, total de rooms, ocupación por defecto, máx. adultos/niños/bebés) y **Add Rate Plan** (name, base price, modelo de precio; mín/máx si aplica). — https://qloapps.com/qloapps-channel-manager/
4. Límites del plan Hotel (doc oficial): room types máx. 20, rate plans máx. 200/propiedad. — https://qloapps.com/qloapps-channel-manager/ (sección "Property Size Limits")
5. Esta propiedad de prueba se mapea con el PMS igual que en la Sección 4 (Synchronize Properties → mapeo → Sync).

### 5.2 Canal 1 — Booking.com (conectividad "Channex")
1. **En el extranet de Booking.com** (`https://account.booking.com/`): **Account → Connectivity Provider → Search → "Channex"** → seleccionar **Channex.io** → **Next** → aceptar términos y condiciones ("Yes, I accept") → quedará en estado de espera hasta que Booking acepte la conexión. *"Channex is our connectivity partner for Booking.com Channel."* — https://qloapps.com/how-to-set-up-booking-com-for-qloapps-channel-manager/
2. **En el CM:** Channels → Add Channel → Booking.com → credenciales: **Property ID** (código de la propiedad en Booking), **Currency**, **Conversion Factor** (tipo de cambio OTA↔CM), **Live Currency Converter** (opcional) → **Test Connection**. — https://qloapps.com/how-to-set-up-booking-com-for-qloapps-channel-manager/
3. ⚠️ Solo se traen reservas creadas **después** de establecida la conexión (*"Bookings made prior to the establishment of this connection will not be retrieved"*). — https://qloapps.com/how-to-set-up-booking-com-for-qloapps-channel-manager/

### 5.3 Canal 2 — Expedia
1. **Extranet Expedia:** **Rooms and Rates → Connectivity Settings** → seleccionar **Channex para connectivity y para booking options**. *"Channex is our connectivity provider for Expedia Channel Manager."* — https://qloapps.com/how-to-set-up-expedia-for-qloapps-channel-manager/
2. **En el CM:** Channels → Add Channel → Expedia + credenciales de la propiedad (mismo patrón que 5.2).

### 5.4 Canal 3 — Airbnb (OAuth)
1. **En el CM:** Channels → Add Channel → Airbnb → clic en **Authorize with Airbnb Account** → redirige al login de Airbnb → aceptar **Airbnb Additional Terms of Service** → **Allow** → *"Authorization is successful"*. — https://qloapps.com/airbnb-setup-for-qloapps-channel-manager/
2. Credenciales: **Property ID**, **Min Stay Type**, **Currency**. — https://qloapps.com/airbnb-setup-for-qloapps-channel-manager/
3. ⚠️ Airbnb queda **deshabilitado por defecto**; se activa desde el listado de canales **solo después de completar el mapeo de room types/rate plans** de esa propiedad Airbnb. — https://qloapps.com/airbnb-setup-for-qloapps-channel-manager/

### 5.5 Sincronización de reservas por canal
- Para Booking.com, Expedia y Airbnb existe la acción **Pull Future Bookings** (*"This will pull all bookings that were created before connection"*) — disponible **solo en esos 3 canales**. — https://docs.channex.io/application-documentation/channels-management.md
- Uso de restricciones (Stop Sell, CTA/CTD, Min/Max LOS…) vía **Price and Inventory** del CM; nota oficial: en Booking.com y Expedia **no aplican** las restricciones. — https://qloapps.com/qloapps-channel-manager/ (sección "Price And Inventory Management")

---

## 6. Checklist de validación (incl. anti-overbooking)

Ejecutar con la propiedad de prueba (5.1) y registrar evidencia (capturas + hora).

| # | Prueba | Pasos | Esperado | Fuente del procedimiento |
|---|---|---|---|---|
| 1 | Disponibilidad (ARI) | Abrir rango de fechas de prueba en el calendario QloApps y en la OTA | Inventario/availability idénticos (rooms libres por día) | https://qloapps.com/qloapps-channel-manager/ (Price And Inventory / Full Sync) |
| 2 | Tarifa | Comparar tarifa OTA vs precio en QloApps (`qlo_product.price` del PMS) | Mismo precio (mismo modelo de precio del rate plan) | https://qloapps.com/qloapps-channel-manager/ (rate plans) |
| 3 | Full Sync | Price and Inventory → **Full Sync** | Estados en verde (actualizado) | https://qloapps.com/qloapps-channel-manager/ |
| 4 | Reserva OTA → PMS | Hacer una reserva de prueba en la OTA | Llega al PMS (QloApps) vía webservice en **≤ ~1 min** (cron); visible en Bookings del CM | https://qloapps.com/qloapps-channel-manager/ (Bookings); cron: https://qloapps.com/qloapps-pms-channel-manager-connector/ |
| 5 | **Anti-overbooking** | Cerrar disponibilidad en QloApps (inventario = 0) para el rango de prueba | La OTA muestra el rango **cerrado en ≤ ~1 min** (cron) | https://qloapps.com/qloapps-pms-channel-manager-connector/ ("minimizing overbooking risks") |
| 6 | Restricción Stop Sell | Aplicar Stop Sell a un rate plan en el CM | Desaparece la venta en la OTA | https://qloapps.com/qloapps-channel-manager/ (Price And Inventory) |
| 7 | Logs | CM → **Logs** | Entradas en *Price/Availability feeds*, *Property details feed*, *Booking feeds* | https://qloapps.com/qloapps-channel-manager/ (Logs) |
| 8 | Cancelación OTA | Cancelar la reserva de prueba en la OTA | El inventario se libera en el PMS | https://qloapps.com/qloapps-channel-manager/ |

> **Criterio de salida:** pruebas 4 y 5 pasan (booking entra ≤1 min; cierre de disponibilidad reflejado ≤1 min). Si fallan, revisar el cron (Sección 3) y el mapeo (Sección 4) antes de tocar canales en producción.

---

## 7. Seguridad

1. **La clave del webservice NUNCA en URLs.** Autenticar con header `Authorization` (método oficial): `base64_encode(<clave> . ':')` — el usuario es la clave y la contraseña va vacía. — https://devdocs.prestashop-project.org/9/webservice/tutorials/testing-access/ y https://devdocs.qloapps.com/webservice/basic-topics.html
   ```php
   $apiKey = '<WEBSERVICE_KEY_CM>';            // 32 caracteres
   $authorizationKey = base64_encode($apiKey . ':'); // Basic <base64>
   ```
2. **HTTPS siempre** (`https://cms.usgarhoteles.com/api/cm_api`); no probar por HTTP.
3. **Si el header no llega** (entornos Apache/CGI): añadir en `.htaccess`: `CGIPassAuth On` (o `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`). — https://devdocs.prestashop-project.org/9/webservice/tutorials/testing-access/
4. **Privilegio mínimo:** la clave solo tiene el recurso `cm_api` (ver Sección 1). La doc de QloApps lo subraya: *"you need to be very careful with your access key rights"* y *"Do not give all the rights for all resources to any key"*. — https://devdocs.qloapps.com/webservice/basic-topics.html y https://devdocs.prestashop-project.org/9/webservice/tutorials/creating-access/
5. **Rotación:** la clave del webservice se puede desactivar/eliminar en cualquier momento desde **Advanced Parameters → Webservice** (toggle de estado de la cuenta). Si se sospecha filtración, desactivar y generar otra. — https://devdocs.prestashop-project.org/9/webservice/tutorials/creating-access/
6. **Dónde viven las credenciales (solo 2 lugares):** configuración del módulo conector en QloApps (Sección 2) y la cuenta del CM (Sección 4). **Nunca** en el repo, `.env`, URLs, tickets ni el frontend.
7. **No poner la clave en la URL del cron** (la URL del cron sale de la config del módulo; si incluye credenciales, la doc oficial no las publica — revisar la pantalla; preferir script PHP local si el módulo lo permite — VERIFY-ON-SITE, Sección 3).

---

## 8. Soporte y rollback

| Necesidad | Acción | Fuente |
|---|---|---|
| Ticket de soporte (pago, conexión, bugs) | https://webkul.uvdesk.com/en/customer/create-ticket/ (tipo "Qloapps") | verificado: formulario "Create Sales / Support Request" |
| Foro comunitario | https://forums.qloapps.com/ (Technical Help / Bug Report / Knowledge Base) | verificado |
| Guías de canales (Booking/Expedia/Airbnb/Agoda…) | Lista oficial en https://qloapps.com/qloapps-channel-manager/ → "Set up … for QloApps Channel Manager" | — |
| Cancelar suscripción | CM → **Subscriptions → cancel subscription → Cancel Membership**; la cancelación es **efectiva al final del ciclo de facturación** (se sigue usando hasta esa fecha) | https://qloapps.com/qloapps-channel-manager/ (sección "How to Cancel the QloApps Channel Manager Subscription?") |
| Reembolso | No aplica: *"no refunds will be issued"* una vez comprado | https://qloapps.com/refund-policy/ |

**Rollback de la integración:** la retirada del lado repo (eliminación de la integración Channex antigua) **ya está hecha** — ver `docs/MIGRATION_PLAN.md` §2. Este runbook solo cubre la activación del QloApps CM. Si se cancela la suscripción y se quiere detener el sync, además de cancelar: (a) desactivar el cron de la Sección 3, (b) quitar la clave `cm_api` del webservice QloApps (Sección 7.5) — sin tocar código del repo.

---

## Apéndice A — Datos de referencia (placeholders)

| Placeholder | Significado |
|---|---|
| `<WEBSERVICE_KEY_CM>` | Clave webservice de 32 caracteres generada en QloApps (Sección 1) |
| `<API_CLIENT_ID>` / `<API_CLIENT_SECRET>` | Credenciales API generadas en el CM → Account Settings → Generate API Credentials (Sección 2, 🔴 plan pagado) |

**Verificaciones pendientes marcadas ⚠️/VERIFY-ON-SITE** (la doc oficial no las publica): nombre exacto del módulo instalado (Sección 2.1), URL exacta del cron del conector (Sección 3.2/3.3), duración real del trial 15 vs 7 días (Sección 0), y comportamiento del recurso `cm_api` si el CM requiere recursos adicionales.
