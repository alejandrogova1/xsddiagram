# Changelog

Todos los cambios notables de **XSD Diagram Web** se documentan en este archivo.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es/1.1.0/) y el proyecto usa [Versionado Semántico](https://semver.org/lang/es/).

## [1.1.0] - 2026-06-05

### Añadido

- Panel de **detalle de atributos** en la pestaña *Atributos*: al hacer clic en una fila se muestran tipo, tipo base, uso, valor por defecto/fijo, forma, facetas de restricción, documentación y enumeraciones.
- Resolución en backend (`DiagramBuilder`) de metadatos ampliados por atributo: `typeQName`, `fixed`, `form`, `documentation`, `baseType`, `facets` y `enumerations`.
- Generación **automática del diagrama** al cargar un esquema, usando la raíz seleccionada en el desplegable (sin pulsar *Agregar al diagrama*).

### Cambiado

- Altura del panel de pestañas laterales aumentada para acomodar la tabla de atributos y su detalle.
- Texto de ayuda del lienzo vacío actualizado para reflejar la carga automática del diagrama.

## [1.0.0] - 2026-06-05

Primera versión pública de la aplicación web.

### Añadido

- Interfaz web con Bootstrap 5, jQuery 4 y SweetAlert2.
- Carga de esquemas XSD por **archivo subido** o por **URL**, con resolución recursiva de `xs:include` / `xs:import`.
- Parser nativo en PHP (`XsdLoader`, `XsdSchema`, `DiagramBuilder`) con caché en disco (`storage/cache/`).
- API REST: `api/load.php`, `api/expand.php` y `api/node.php`.
- Diagrama interactivo en SVG: expandir/colapsar nodos, zoom (botones y `Ctrl`+rueda), panorámica y ajuste a ventana.
- Panel derecho de propiedades con pestañas *Atributos* / *Elemento*, enumeraciones y descripción.
- Barra de estado con XPath del nodo seleccionado.
- Exportación a **SVG** y **PNG** (con confirmación para imágenes muy grandes).
- Esquema de ejemplo en `samples/purchaseorder.xsd`.
- Medidas de seguridad: validación de subidas, límite de tamaño (8 MB), mitigación básica de SSRF y `.htaccess` en la caché.

[1.1.0]: https://github.com/alejandrogova1/xsddiagram/compare/98a4322...7f02e24
[1.0.0]: https://github.com/alejandrogova1/xsddiagram/commit/98a4322
