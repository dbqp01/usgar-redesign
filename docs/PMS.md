# QloApps (PMS) — operación y configuración

> Fuente de verdad consolidada (2026-08-11) de: `docs/QLOAPPS_TUTORIAL.md`, `docs/QLOAPPS_INVENTORY_SETUP.md`, `docs/RATE_PLANS.md`, `docs/CHANNEL_MANAGER_SETUP.md` y `docs/postman/README.md` (archivos originales eliminados; contenido preservado).

## Índice

- **A. Guía rápida del dueño del hotel** — las 3 tareas imprescindibles en el CMS
- **B. Inventario de habitaciones** — BLOQUEANTE para reservas web
- **C. Tarifa No Reembolsable** — Feature Price Plan (cómo funciona y cómo configurarla)
- **D. Channel Manager (Webkul)** — runbook de activación, pendiente de pago
- **E. Contratos API Postman** — webservice + channel manager

---

## Sección A — Guía rápida: configurar el CMS (sin tecnicismos)

# Guía rápida — Configurar el CMS de USGAR (sin tecnicismos)

> **Para:** el dueño del hotel. **Tiempo estimado:** 1–2 horas la primera vez.
> **Qué es el CMS (QloApps):** es la "caja fuerte" del hotel: ahí viven tus 4 tipos de habitación, los precios y cada reserva. Tu página web le pregunta "¿hay habitación disponible?" y, cuando alguien paga, **guarda la reserva aquí**. Si esto no está bien configurado, la web no puede aceptar reservas.
> **Detalle técnico (solo si lo necesitas):** los runbooks de este mismo documento (Sección B — inventario, Sección D — channel manager) y la revisión técnica (sección 0 del tutorial anterior, recuperable en git) tienen el porqué de cada paso.

---

## Solo necesitas hacer 3 cosas (en este orden)

### TAREA 1 — Registrar tus habitaciones (la más importante) 🔴

Sin esto, la web **rechaza todas las reservas** ("Las habitaciones solicitadas no están disponibles"). Actualmente el sistema tiene 4 tipos de habitación creados, pero con **cero habitaciones dentro**.

**Dónde:** panel de administración → menú **Catalog** (Catálogo) → **Manage Room Types** (Gestionar tipos de habitación).

1. Verás una tabla con tus 4 tipos de habitación:
   - **Matrimonial Superior** — precio $90
   - **Doble Superior** — precio $90
   - **Triple Estándar** — precio $120
   - **Familiar Superior** — precio $150
2. Clic en **editar** (lápiz) en el primero.
3. Busca la sección **Rooms** (Habitaciones) → clic en **Add** (Añadir) y crea **cada habitación física** del hotel:
   - **Room number**: el número de la habitación (ej. `A-101`, `B-202`, `301`…).
   - **Floor**: el piso.
   - **Status**: **Active** (Activo) = se puede vender.
   - Añade tantas como tengas en la realidad (si tienes 3 matrimoniales, añade 3).
4. Clic en **Save and stay** (Guardar y continuar) y repite en los 4 tipos.

**Cómo saber que quedó bien:** al volver a la tabla, cada tipo de habitación muestra el número de habitaciones que le pusiste.

---

### TAREA 2 — Crear la "llave" que conecta tu web con el CMS

Tu web necesita una **llave de acceso** para hablar con el CMS y guardar reservas. La creas en el CMS y se la pasas al desarrollador para que la ponga en el sitio (una sola vez).

**Dónde:** menú **Advanced Parameters** (Parámetros avanzados) → **Webservice**.

1. En la pestaña *Configuration* (Configuración): pon **YES** (Sí) en **"Enable QloApps's Webservice"** (Activar el webservice) → **Save**.
2. Ve a la pestaña *Webservice Accounts* (Cuentas del webservice) → clic en **Add new Webservice key** (Añadir nueva llave).
3. **Key**: clic en el botón **Generate** (Generar) — el sistema crea la llave solo. No la escribas a mano.
4. **Key description**: escribe algo como `web usgar` (solo para que la reconozcas).
5. **Status**: **YES** (Sí).
6. En **Permissions** (Permisos): busca en la lista el recurso **`bookings`** (reservas) y marca **SOLO esas casillas**: **GET, POST, PUT**. ⚠️ No marques los demás recursos (mientras menos permisos, más seguro).
7. Clic en **Save** (Guardar). ⚠️ La llave se muestra **una sola vez**: cópiala y guárdala en tu gestor de contraseñas (ej. Google Password Manager).
8. **Pásale la llave al desarrollador** — él la pondrá en el archivo de configuración del sitio. (La llave NO se pega en ninguna URL ni se manda por chat de soporte.)

---

### TAREA 3 — Conectar Booking.com, Expedia y Airbnb (solo si quieres vender ahí) 🔴

Esto usa el **Channel Manager** (un servicio aparte que sincroniza tus habitaciones y precios con las OTAs). Requiere **pagar una suscripción**: **$30 USD/mes por hotel** (o $300/año). ⚠️ El periodo de prueba gratis (15 días) **NO sincroniza** nada — solo funciona pagado.

**Paso A — Pagar la suscripción:**
1. Entra a **https://channels.qloapps.com/** y crea tu cuenta (nombre, correo, teléfono, nombre del hotel, contraseña).
2. Clic en tu **foto de perfil** (esquina) → **Subscriptions** (Suscripciones).
3. Clic en **Upgrade Plan** → elige **Monthly** ($30) o **Yearly** ($300) → **Select Plan**.
4. En **Properties Count** pon `1` → **CALCULATE PRICE** → **Proceed** → completa la dirección de facturación → **Proceed To Payment** (pago con tarjeta/PayPal).
5. Verifica en **Subscriptions → View details** que el plan quedó activo.

**Paso B — Crear la segunda llave (para el Channel Manager):**
1. En el CMS: **Advanced Parameters → Webservice** → **Add new Webservice key** (igual que Tarea 2).
2. **Generate** → descripción `channel manager` → **Status YES**.
3. En **Permissions**: marca el recurso **`cm_api`** con **TODAS** las casillas (GET, POST, PUT, DELETE, HEAD). ⚠️ Solo ese recurso.
4. **Save** y guarda la llave en tu gestor de contraseñas.

**Paso C — Conectar el CMS con el Channel Manager:**
1. En channels.qloapps.com → pestaña **PMS Setting → General Settings** y pega:
   - **QloApps Webservice Key**: la llave del Paso B.
   - **QloApps Webservice URL**: `https://cms.usgarhoteles.com/api/cm_api`
   - **QloApps TimeZone**: `America/Lima`
2. Clic en **Test Connection** → debe decir OK (si falla, revisa que la llave esté activa y con el recurso `cm_api`).
3. Clic en **Synchronize Properties** (Sincronizar propiedades) → traerá tus 4 tipos de habitación.
4. **Mapear (paso obligatorio):** en **Property Mapping** elige tu hotel; en **Room Type Mapping** empareja cada tipo del Channel Manager con su igual del CMS:
   - Matrimonial ↔ Matrimonial Superior
   - Doble ↔ Doble Superior
   - Triple ↔ Triple Estándar
   - Familiar ↔ Familiar Superior
   → **Save**.
5. Clic en **Synchronize Inventory** (Sincronizar inventario).

**Paso D — Conectar el primer canal (Booking.com):**
1. Entra a **https://account.booking.com/** (el extranet de Booking) → **Account** → **Connectivity Provider** → busca **"Channex"** → selecciónalo → **Next** → acepta los términos → quedará "en espera" hasta que Booking lo apruebe (puede tardar días; avísales por el extranet si se demora).
2. Cuando esté aprobado, en el Channel Manager: **Channels → Add Channel → Booking.com** → pega el **Property ID** (código de tu hotel en Booking) → **Currency** (USD) → **Test Connection** → guarda.
3. Repite el mismo patrón para **Expedia** (en el extranet de Expedia: **Rooms and Rates → Connectivity Settings** → elegir **Channex**) y **Airbnb** (en el Channel Manager: Add Channel → Airbnb → **Authorize with Airbnb Account** → inicia sesión y acepta; quedará desactivado hasta completar el mapeo).

**Paso E — Sincronización automática (cron de 1 minuto):**
Para que no haya overbookings, el Channel Manager necesita un "despertador" que corra **cada minuto**. ⚠️ Este paso lo hace el desarrollador/hosting (Hostinger → Trabajos programados), porque la dirección exacta se copia de la pantalla de configuración del módulo conector dentro del CMS.

> **Importante:** los canales solo traen reservas creadas **después** de conectarse. Antes de activar Booking, haz un **Full Sync** de inventario y compáralo con el calendario del CMS.

---

## Cómo saber que TODO quedó bien (prueba final)

1. Abre tu web, busca habitaciones y haz una **reserva de prueba** con fechas cercanas.
2. Paga con tarjeta de prueba (Modo prueba de MercadoPago).
3. Revisa el resumen/correo: el número de reserva debe ser **numérico** (ej. `45`).
   - ✅ Si sale un número → la reserva quedó guardada en el CMS. ¡Listo!
   - ❌ Si sale algo como `USGAR-...` → la reserva NO llegó al CMS: revisa la Tarea 1 (habitaciones) y la Tarea 2 (llave).

## Checklist exprés

- [ ] Los 4 tipos de habitación tienen sus habitaciones físicas (Tarea 1)
- [ ] Llave creada y pasada al desarrollador (Tarea 2)
- [ ] Suscripción del Channel Manager pagada (Paso A)
- [ ] Llave `cm_api` creada y conexión OK (Pasos B–C)
- [ ] Los 4 tipos mapeados y Booking conectado (Paso D)
- [ ] Cron de 1 minuto activado (Paso E)
- [ ] Reserva de prueba con número numérico ✅

## ¿Dudas o se atasca algo?

- **Soporte oficial del CMS/Channel Manager** (pagos, conexiones, bugs): ticket en https://webkul.uvdesk.com/en/customer/create-ticket/ (tipo "Qloapps")
- **Foro comunitario**: https://forums.qloapps.com/
- **Documentación oficial (con imágenes)**: https://docs.qloapps.com/

---

## Sección B — Runbook: inventario de habitaciones (BLOQUEANTE para reservas web)

# Runbook — Configurar inventario de habitaciones en QloApps (BLOQUEANTE para reservas web)

> **Para:** propietario/admin del PMS QloApps (`https://cms.usgarhoteles.com/admin`). **Idioma:** español.
> **Objetivo:** mapear las habitaciones físicas a los 4 room types para que el webservice acepte reservas. Hoy el POST de reservas de la web falla con **"Las habitaciones solicitadas no están disponibles"** (404) porque no hay inventario físico configurado.
> **Leyenda:** ✅ paso verificado en doc oficial citada. ⚠️ requiere confirmar en pantalla.

## Estado actual (verificado en vivo, 2026-08-06)

- `qlo_htl_room_information` = **0 filas** (no hay habitaciones físicas mapeadas a ningún room type).
- Existen 4 room types activos (con precio en `qlo_product`):

| id_room_type | id_product | Precio (USD) | Nombre |
|---|---|---|---|
| 1 | 1 | 90 | Matrimonial Superior |
| 2 | 2 | 90 | Doble Superior |
| 3 | 3 | 120 | Triple Estándar |
| 4 | 4 | 150 | Familiar Superior |

- El sitio web muestra disponibilidad con un fallback de código (`COALESCE(...,5)`), pero **el webservice de QloApps valida contra habitaciones reales** → rechaza toda reserva. Por eso las reservas web quedan solo en `provisional_bookings` (tabla local) y **nunca se crean en el PMS**.
- Fix de código aplicado (2026-08-06, `QloAppAdapter.php`): el XML de `createCart`/`confirmOrder` ahora incluye `total_tax` (campo requerido por el endpoint `bookings` del webservice — eliminaba el error *"Undefined array key total_tax"*). Verificado: el POST ya no devuelve ese warning; **solo falta el inventario**.

## Pasos (doc oficial: https://docs.qloapps.com/catalog/manage_room_types/)

1. Back office QloApps → **Catalog → Manage Room Types** ✅
2. Clic en **editar** cada room type de la tabla (4 existentes) → sección **Rooms** ✅
3. Clic en **Add** / añadir habitación con:
   - **Room number**: número de habitación física (ej. `A-101`, `B-202`). ✅
   - **Floor**: piso. ✅
   - **Status**: `Active` (disponible para reserva), `Inactive` (fuera de venta permanente) o `Temporarily Inactive` (mantenimiento — permite definir *disable dates*). ✅
   - **Extra Information**: comentarios opcionales. ✅
4. Repetir para los 4 room types; guardar con **Save and stay**. ✅
5. ⚠️ **Verificar en pantalla** que el inventario total coincida con la realidad del hotel (es el mismo inventario que usarán el booking engine y el Channel Manager).

## Criterio de salida

- `qlo_htl_room_information` tiene filas por cada room type, **y**
- una reserva de prueba desde la web (`POST /api/booking` Happy Path) devuelve un `cart_id` **numérico** (id de booking de QloApps), no `USGAR-*` (fallback local), **y**
- `GET /api/bookings` del webservice devuelve `totalItems > 0`.

Verificación técnica opcional: `GET https://cms.usgarhoteles.com/api/room_types/1` debe listar las habitaciones en el XML.

---

## Sección C — Tarifa No Reembolsable (centralizada en QloApps)

# RATE_PLANS.md — Tarifa No Reembolsable (centralizada en QloApps)

Cómo funciona la tarifa No Reembolsable de usgarhoteles.com y cómo configurarla.

## Cómo funciona (arquitectura)

El cliente pidió 2 opciones al elegir habitación: **Tarifa Estándar** y **Tarifa No
Reembolsable** (WhatsApp 10/8/2026, con IBE de referencia). El precio de ambas sale de
QloApps — nunca del frontend:

1. **Precio base** (`standard`): `qlo_product.price` (ya era así).
2. **Precio no reembolsable** (`non_refundable`): precio base tras aplicar el
   **Feature Price Plan** configurado en el admin de QloApps. El backend lo resuelve:
   - `QloAppAdapter::getAvailableRooms()` carga los planes maestros
     (`qlo_htl_room_type_feature_pricing` con `id_cart=0 AND id_guest=0 AND id_room=0
     AND active=1`) y sus restricciones (`qlo_htl_room_type_feature_pricing_restriction`)
     y calcula `non_refundable_price` por habitación y por rango de fechas.
   - `App\Features\Shared\DiscountResolver` aplica la fórmula de QloApps (verificada
     contra el código fuente del módulo `hotelreservationsystem`): `impact_way`
     (1=Decrease, 2=Increase, 3=Fixed) x `impact_type` (1=Percentage, 2=Fixed).
3. **Contrato API**:
   - `GET /api/rooms` → cada habitación trae `rate_plans: { standard, non_refundable }`.
   - `POST /api/booking` → el frontend envía `rateType: standard|non_refundable`;
     el backend congela el precio de la tarifa (hold + MercadoPago). El cliente jamás
     envía precios.
4. **Frontend**: el wizard muestra ambas tarifas con los precios servidos por la API y
   el resumen refleja el total de la tarifa elegida. Sin plan configurado en QloApps,
   ambas tarifas muestran el mismo precio (honesto).

## Cómo configurar el descuento (admin de QloApps)

1. Back office → **Hotel Reservation → Settings → Feature Price** → *Add new*.
2. **Room Type**: la habitación (o "create multiple" para todas).
3. **Impact**: *Decrease Price* + *Percentage* (ej. `10`) → la web muestra la tarjeta
   "No Reembolsable" con el badge `-10%`.
4. **Restricciones de fecha**: dejar el plan **sin restricciones** = permanente
   (aplica a cualquier estadía).
5. Guardar. La web lo refleja sin deploy (el adapter lee los planes en cada consulta).

## Por qué NO se usaron otros mecanismos (evidencia, 2026-08-10)

- **Catalog Price Rules** (`qlo_catalog_price_rule`): es schema de PrestaShop clásico y
  **no existe en esta instalación** de QloApps (verificado contra la BD real).
- **Specific Price**: el foro oficial de QloApps (topic 292) confirma que se aplica
  según la **fecha actual**, no las fechas de la reserva; 1 regla por habitación.
- **Cart Rules**: cupones de checkout; nuestro flujo no pasa por el carrito de QloApps
  (el adapter crea el carrito con el total ya calculado).
- **Rate plans del Channel Manager**: solo aplican a OTAs, no a reservas directas.
- **Feature Price Plan** es el mecanismo nativo que el propio soporte de QloApps
  recomienda para precios por fechas de estadía (módulo `hotelreservationsystem`,
  tabla `qlo_htl_room_type_feature_pricing`, enums verificados en el código fuente).

## Reembolsos (la política NO reembolsable)

QloApps **no tiene** tarifas "no reembolsables" nativas. Un reembolso es una operación
manual de back-office (Refund Request → marca `is_refunded` en
`qlo_htl_booking_detail`). Nuestra parte:

- La política se muestra al huésped en el wizard (texto del rate plan).
- El `rate_type` elegido queda registrado en el hold (`provisional_bookings.room_data`)
  para que el hotel lo vea al procesar un refund manual.
- No hay flujo de auto-reembolso (ni debe haberlo): el criterio lo aplica el personal.

## Límites documentados (ponytail)

- Se resuelven planes con restricción de **rango** (`date_selection_type=1`) sin
  `special_days`; planes con días especiales se ignoran (no resoluble de forma fiable
  sin el calendario de QloApps). El caso del negocio es UN plan permanente sin
  restricciones.
- Si hay varios planes aplicables se usa el de **menor id** (la prioridad global
  `HTL_FEATURE_PRICING_PRIORITY` de QloApps no se replica).
- Si las tablas no existen o la consulta falla → precio no reembolsable = precio base
  (fail-safe, nunca rompe la reserva).
- El descuento se aplica al precio base sin resolución de impuestos (coherente con
  cómo el adapter ya lee `qlo_product.price`).

## Acceso a la BD (dev local)

La BD de QloApps es remota (`us-imm-web909.main-hosting.eu:3306`). El acceso remoto de
MySQL se habilita por IP en hPanel → Remote MySQL (o vía API: el MCP `hostinger`
incluye `hosting_createDatabaseRemoteConnectionV1`). El 2026-08-10 se añadió la IP
`181.176.97.216` (la IP del ISP de Akim es dinámica; si vuelve el error 1045
"Access denied", añadir la IP actual).

---

## Sección D — Runbook: activación del QloApps Channel Manager (Webkul)

# Runbook — Activación del QloApps Channel Manager (Webkul) para USGAR Hotels

> **Para:** propietario del hotel + agente ejecutor. **Idioma:** español.
> **Objetivo:** activar la suscripción SaaS **QloApps Channel Manager** y conectar el PMS QloApps (`https://cms.usgarhoteles.com`) con Booking.com, Expedia y Airbnb, con sincronización de precios/inventario/reservas vía webservice + cron (~1 min) para evitar overbookings.
> **Contexto:** el QloApps Channel Manager (Webkul) no requiere código en el repo — es un módulo del lado PMS que sincroniza vía webservice `cm_api` (ver `docs/ROADMAP.md` §Migraciones). Este documento NO modifica código: solo activación.
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
4. **Regla del repo:** el backend ya corre crons en Hostinger (`php public/index.php /api/cron/cleanup` — ver `docs/DEPLOYMENT.md` §Cron); el cron del CM se suma al mismo panel, sin tocar el existente.
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
4. **Room types de USGAR a mapear (4)** — slugs canónicos del repo (`app/Features/Shared/RoomTypeRegistry.php`, SLUG_MAP), a emparejar con los room types del PMS:

| Slug (referencia del repo) | Room type en QloApps (PMS) a seleccionar |
|---|---|
| `doble-superior` | Doble Superior |
| `matrimonial` | Matrimonial |
| `familiar-superior` | Familiar Superior |
| `triple-standar` | Triple Standard |

> **Estado actual hasta que se active el CM (post-pago):** los canales OTA **NO están conectados** — la única vía de reserva activa es la venta directa del sitio (webservice QloApps). Antes de conectar el primer canal en la Sección 5, hacer un **Full Sync de inventario** (Synchronize Inventory de la Sección 4) y verificar contra el calendario del PMS (Sección 6) para cerrar la ventana de overbooking entre el alta del canal y el primer sync del cron.

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

**Rollback de la integración:** el repo no contiene código de channel manager; este runbook solo cubre la activación del QloApps CM. Si se cancela la suscripción y se quiere detener el sync, además de cancelar: (a) desactivar el cron de la Sección 3, (b) quitar la clave `cm_api` del webservice QloApps (Sección 7.5) — sin tocar código del repo.

---

## Apéndice A — Datos de referencia (placeholders)

| Placeholder | Significado |
|---|---|
| `<WEBSERVICE_KEY_CM>` | Clave webservice de 32 caracteres generada en QloApps (Sección 1) |
| `<API_CLIENT_ID>` / `<API_CLIENT_SECRET>` | Credenciales API generadas en el CM → Account Settings → Generate API Credentials (Sección 2, 🔴 plan pagado) |

**Verificaciones pendientes marcadas ⚠️/VERIFY-ON-SITE** (la doc oficial no las publica): nombre exacto del módulo instalado (Sección 2.1), URL exacta del cron del conector (Sección 3.2/3.3), duración real del trial 15 vs 7 días (Sección 0), y comportamiento del recurso `cm_api` si el CM requiere recursos adicionales.

---

## Sección E — Contratos API (Postman)

# Contratos API — Postman (esqueleto USGAR Hotels)

> Fecha: 2026-08-07 · Entrega final. Los contratos viven en el workspace Postman del proyecto; este README los referencia para el repo.

## Colección: "USGAR - QloApps Webservice & Channel Manager (skeleton)"

Workspace: **My Workspace** (Postman, cuenta del proyecto). Entorno: **USGAR - Produccion**.

| Variable | Valor | Tipo |
|---|---|---|
| `base_url` | `https://cms.usgarhoteles.com/api` | default |
| `ws_key` | *(webservice key QloApps)* | **secret** |
| `cm_base_url` | `https://channels.qloapps.com` | default (confirmar post-pago) |
| `cm_api_key` | *(API Client Secret del CM — solo plan pagado)* | **secret** |
| `booking_id` | ID de booking para A6 | default |

## Folder A — QloApps Webservice (PMS) · **OPERATIVO sin suscripción**

Autenticación: HTTP Basic (username = `ws_key`, sin password) — convención oficial del webservice QloApps/PrestaShop.
Fuente: https://devdocs.qloapps.com/webservice/basic-topics.html · https://devdocs.qloapps.com/webservice/advanced-api-uses.html

| # | Método | Endpoint | Contrato que refleja |
|---|---|---|---|
| A1 | GET | `/api` | Lista de recursos + permisos de la key |
| A2 | GET | `/api/bookings?schema=blank` | Esquema vacío para crear booking |
| A3 | GET | `/api/bookings?schema=synopsis` | Esquema con tipos/campos |
| A4 | GET | `/api/bookings` | Listar reservas del PMS |
| A5 | POST | `/api/bookings` | `QloAppAdapter::createCart()` (XML `<qloapps><booking>`) |
| A6 | PUT | `/api/bookings/{{booking_id}}` | `QloAppAdapter::confirmOrder()` (payment_status=1) |

## Folder B — QloApps Channel Manager API (Webkul) · **REQUIERE plan pagado**

Autenticación: header `api-key: {{cm_api_key}}` (el secret del CM; el campo *Generate API Credentials* es exclusivo de plan pagado — fuente: https://qloapps.com/qloapps-pms-channel-manager-connector/).
Contrato verificado contra la colección pública de Webkul "Qlo Channel Manager Collection" (Postman API Network) — el CM de QloApps es white-label de la plataforma Channex.

| # | Método | Endpoint | Uso |
|---|---|---|---|
| B1 | GET | `/api/test_connection` | Probar credenciales |
| B2 | GET | `/api/properties` | Propiedades del hotel |
| B3 | GET | `/api/room_types?filter[id_property]=1` | Tipos de habitación |
| B4 | GET | `/api/bookings?filter[check_in][gte]="..."` | Reservas del CM |
| B5 | POST | `/api/booking_notification` | Push booking OTA→PMS (status `new`/`modified`/`cancelled`, guest_detail, price_details, room_bookings, occupancy, taxes) |

## Estado (entrega 2026-08-07)

- ✅ Folder A probablemente **funcional ya** (el webservice está en uso por `QloAppAdapter` — `api-harness` crea carts reales). Falta solo pegar `ws_key` en el entorno.
- ⏸️ Folder B **bloqueado por pago** (suscripción CM rechazada): las credenciales `cm_api_key` se generan al activar el plan. El runbook de la Sección D de este documento tiene la activación paso a paso.

## Verificación local (alternativa a Postman)

El repo ya prueba los mismos contratos sin Postman:
- `php tests/api-harness.php` — contrato de booking del sitio (requiere servidor dev en :8000).
- `php scripts/run-exhaustive-tests.php` — suite exhaustiva (incluye asserts del port del channel manager).
