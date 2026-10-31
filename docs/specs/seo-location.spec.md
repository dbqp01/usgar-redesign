# Spec: Páginas de ubicación y alojamiento en San Pedro

**Estado:** en desarrollo

**Código:** `src/pages/index.astro`, `src/pages/stay/`, `src/pages/{es,fr,pt}/stay/`, `src/i18n/location*`, `src/components/LocationMap.astro`

**Docs relacionadas:** `AGENTS.md`, `docs/DEPLOYMENT.md`

## Propósito
Ayudar a viajeros que buscan alojamiento junto al Mercado o la estación San Pedro a evaluar la ubicación real de USGAR y consultar su estancia. Cada página responde a una intención distinta; no son una lista de palabras clave ni una promesa de disponibilidad o tarifa.

## Requisitos funcionales (EARS)
- RF-01: CUANDO alguien visita la página del mercado o la de la estación, EL SISTEMA DEBE mostrar información específica del lugar, la dirección del hotel y enlaces útiles a habitaciones, contacto y consulta de estancia.
- RF-02: CUANDO alguien visita una de las páginas en inglés, español, francés o portugués, EL SISTEMA DEBE servir contenido propio del idioma, título, descripción, canónica y alternates coherentes.
- RF-03: CUANDO se genera el sitio, EL SISTEMA DEBE incluir las ocho URL indexables en el sitemap e incluir enlaces HTML rastreables a ambas desde el mapa de ubicación.
- RF-04: SIEMPRE se debe indicar que el hotel confirma precio y disponibilidad; no se deben prometer trenes, horarios, tarifas, recorridos o servicios no verificados.
- RF-05: CUANDO se solicita la portada en cualquiera de los cuatro idiomas, EL SISTEMA DEBE incluir el nombre del establecimiento «Usgar San Pedro Boutique» en el título de la página.

## Requisitos no funcionales
- HTML visible sin JavaScript, estructura semántica accesible y diseño coherente con el sitio.
- Conservar las ediciones concurrentes y no modificar pagos ni datos privados.

## Contratos
Sin cambios a endpoints ni integraciones. Las rutas son `/stay/near-san-pedro-market/` y `/stay/near-san-pedro-station/`, con prefijos de idioma existentes.

## Verificación
- `tests/Unit/seo-location.test.ts`: artefactos, sitemap, alternates y enlaces internos; ejecutar después del build.
- `astro check`, build y auditorías SEO existentes en un entorno aislado.
- Revisión de las páginas en navegador local, sin enviar reservas.
