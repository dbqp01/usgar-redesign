<!--Reglas Globales de codificacion-->
Para las mejores prácticas, un par de cosas:

1. **Arquitectura SOLID Completa:** 
   * **SRP & DIP:** Cada clase o módulo debe tener una única responsabilidad y depender estrictamente de abstracciones, no de implementaciones.
   * **OCP, LSP & ISP:** Diseña código abierto a la extensión pero cerrado a la modificación, asegura que las clases derivadas no rompan el comportamiento esperado y segrega las interfaces para que sean específicas y limpias.

2. **Seguridad Absoluta y Cero Secretos:**
   * Evita fugas de información crítica aislando completamente la capa interna de la carpeta `public/` o directorios accesibles al cliente.
   * Jamás expongas llaves API, credenciales o tokens en texto plano; gestiona todo mediante variables de entorno inyectadas de forma segura.

3. **Abstracción Justa y Equilibrada (Anti-Falta de Abstracción):**
   * Aplica el principio **DRY (Don't Repeat Yourself)** para eliminar la duplicación de código mediante abstracciones limpias cuando sea la mejor solución.
   * Sigue la **Ley de Deméter (LoD)** para mantener un bajo acoplamiento, asegurando que los objetos solo interactúen con sus dependencias directas y no con capas internas ajenas.

4. **Simplicidad Resiliente (Anti-Sobreingeniería):**
   * Enfócate en la solución más simple para un problema aplicando **KISS (Keep It Simple, Stupid)** y **YAGNI (You Aren't Gonna Need It)**; no crees código complejo para necesidades que no existen hoy.
   * A pesar de la simplicidad, incluye un manejo de errores robusto para fallos clásicos (red, nulos, BD) y centraliza las excepciones mediante un manejo de errores global que no filtre datos sensibles en producción.

5. **Ejecución Inteligente y Conexión MCP (La más importante):**
   * **SIEMPRE USA CONTEXT7, SEQUENTIAL THINKING** y demás conexiones MCP para estructurar el razonamiento por pasos antes de dar resultados globales.
   * Dado que tu entrenamiento base no cuenta con las versiones más recientes y las tecnologías actuales son superiores, **usa siempre un validador MCP** para verificar el estado de las versiones vigentes en este año 2026, asimilar sus cambios disruptivos y actualizar la información en tiempo real antes de escribir código.
<!--Reglas Globales de codificacion-->