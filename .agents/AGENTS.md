Propósito
Desarrollo de un sitio web transaccional para USGAR Hotels (Cusco, Perú), enfocado en turistas internacionales. El sistema procesa reservas directas y sincroniza disponibilidad con plataformas externas.
Tecnología e Infraestructura
Frontend: Astro v7 (estático), Tailwind CSS v4, Leaflet (OpenStreetMap).
Backend: PHP 8 como Front Controller proxy seguro para enrutar llamadas a APIs externas y proteger credenciales del cliente.
Servidor: Hosting compartido en Hostinger (PHP nativo y MySQL).
Arquitectura: Multitienda. Frontend en subdominios por hotel; QloApps centralizado como sistema de gestión (PMS) y base de datos única.
Integraciones, API y Lógica de Reserva
Endpoints REST: La API PHP expone rutas para inventario, creación de reservas, extensión de bloqueos, webhooks de pagos y limpieza de carritos expirados mediante tareas programadas (cron).
Disponibilidad: Channex sincroniza inventario en tiempo real con agencias de viaje en línea (OTAs).
Reservas: QloApps aplica un bloqueo temporal de inventario de 15 minutos al iniciar la compra. Si el pago falla, el sistema permite extender el bloqueo por 15 minutos adicionales.
Pagos: Mercado Pago. Los webhooks de confirmación son gestionados por el backend.
Seguridad: Uso estricto de variables de entorno y tokens secretos. Las peticiones no autenticadas a la API son rechazadas para proteger los datos.
Diseño y Experiencia de Usuario
Visual: Tema claro y oscuro. Paleta basada en morados, amarillos y verdes. Tipografías AkhirTahun para títulos y Montserrat para cuerpo.
Interfaz: Navbar adaptable, widget de reservas, galerías limitadas a 4 imágenes por habitación y transiciones fluidas.
Rendimiento y SEO: Compresión de imágenes, carga diferida, datos estructurados (Schema.org), soporte multilenguaje y URLs descriptivas.
Estructura del Sitio y Operación
Páginas: Inicio, 4 tipos de habitaciones (Doble Superior, Familiar Superior, Matrimonial Superior, Triple Estándar), Checkout, Explora Cusco y Contacto.
Gestión de Datos: Las ocupaciones máximas y precios se obtienen dinámicamente desde el backend, sin valores hardcodeados en el frontend.
Organización: Directorios específicos para reglas de agentes de IA, código fuente Astro y PHP, assets locales y scripts de administración y auditoría.