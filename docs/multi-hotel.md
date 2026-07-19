# Arquitectura Multi-hotel y Mapeo en QloApps

Esta documentación detalla el funcionamiento del sistema multitienda para **USGAR Hotels** y los pasos técnicos requeridos para incorporar nuevos subdominios y hoteles en el futuro.

---

## 1. Funcionamiento del Mapeo Dinámico

El backend en PHP (`public/api/`) interactúa con una base de datos centralizada de QloApps. Cada hotel en la base de datos se identifica mediante un valor único de `id_hotel` y `id_shop`.

En los endpoints críticos (como `/api/rooms` para listar habitaciones), la consulta SQL filtra dinámicamente utilizando el prefijo de tablas oficial de USGAR (`qlo_`):
```sql
SELECT rt.id_room_type, rt.room_name, i.price
FROM qlo_htl_room_type rt
LEFT JOIN qlo_htl_room_information i ON i.id_room_type = rt.id_room_type
WHERE rt.active = 1 AND rt.id_hotel = :id_hotel
```

---

## 2. Cómo agregar un Nuevo Hotel (Subdominio)

Cuando USGAR Hotels expanda su presencia y añada una nueva sucursal (ejemplo: `arequipa.hotelesusgar.com`):

### Paso A: Configuración en QloApps Backoffice
1. Accede al panel de administración de QloApps (`cms.hotelesusgar.com/admin/`).
2. Ve a **Multitienda** o **Hotel General Settings** y da de alta una nueva tienda/sucursal.
3. Toma nota del `id_hotel` asignado en el sistema (por ejemplo, Arequipa = `2`).

### Paso B: Despliegue del Frontend
1. Clona el frontend en Astro en el nuevo subdominio (ej: `arequipa.hotelesusgar.com`).
2. En el archivo `.env` del nuevo despliegue del frontend, define el identificador del hotel correspondiente:
   ```env
   # En el frontend de Arequipa
   SITE_URL=https://arequipa.hotelesusgar.com
   PUBLIC_HOTEL_ID=2
   ```
3. La página web de Arequipa hará consultas automáticas al endpoint del backend enviando su identificador:
   `/api/rooms?hotelId=2`
   De esta forma, cargará de manera automática únicamente las habitaciones y tarifas de Arequipa sin interferir con las de Cusco.
