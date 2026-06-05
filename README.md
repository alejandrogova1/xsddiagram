# XSD Diagram Web

Versión web del visor de diagramas XSD [dgis/xsddiagram](https://github.com/dgis/xsddiagram).
Sube un archivo `.xsd` o indica una URL y obtén un **diagrama interactivo** (expandir/colapsar
nodos, zoom, panorámica y exportación) directamente en el navegador.

El esquema se parsea de forma **nativa en PHP** y los hijos de cada nodo se resuelven bajo
demanda; el **layout** y el **renderizado SVG** se hacen en el cliente, portando el algoritmo
del proyecto de escritorio original.

## Características

- Carga por **archivo subido** o por **URL** (resuelve `xs:include` / `xs:import` recursivamente).
- Diagrama **interactivo**: expandir/colapsar con el botón `+/-`, zoom (botones, `Ctrl`+rueda),
  panorámica (arrastrar el lienzo) y ajuste a la ventana.
- Elementos, tipos complejos/simples, compositores `sequence` / `choice` / `all` / `group`,
  referencias, ocurrencias (`0..1`, `1..∞`), contenido simple y documentación.
- **Panel derecho de propiedades** (estilo escritorio): Nombre/Tipo, pestañas
  **Atributos** / **Elemento**, **Enumeraciones** y **Descripción**.
- **Barra de estado** con el XPath del nodo seleccionado.
- Exportación a **SVG** y **PNG** (con confirmación para imágenes muy grandes).
- Mensajes de usuario con **SweetAlert2**.

## Requisitos

- **PHP 8.5** (funciona también en 8.2+). Extensiones: `dom`, `simplexml`, `libxml`,
  `curl`, `gd`, `mbstring` (todas estándar).
- La carpeta `storage/cache/` debe tener permisos de escritura para el usuario del servidor.

## Frontend (incluido en `assets/vendor/`)

- Bootstrap **5.3.8**
- jQuery **4.0.0**
- SweetAlert2 **11.x**

## Cómo ejecutar

### Opción recomendada: servidor embebido de PHP 8.5

El Apache que trae XAMPP suele usar PHP 8.2. Para garantizar PHP 8.5, sirve el proyecto con el
servidor embebido desde la raíz del proyecto:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/xsddiagram
php -S localhost:8000
```

Luego abre <http://localhost:8000/>.

### Opción XAMPP / Apache

Coloca el proyecto en `htdocs` (ya lo está) y abre
<http://localhost/xsddiagram/>. Si necesitas PHP 8.5 con Apache, configura el módulo/handler
de PHP 8.5 en tu instalación.

## Uso

1. Elige un archivo XSD (botón **Elegir XSD…** → **Cargar archivo**) o pega una URL y pulsa
   **Cargar URL**.
2. Selecciona el **elemento raíz** en el desplegable, ajusta el **nivel** de expansión inicial
   y pulsa **Agregar al diagrama**.
3. Explora: pulsa los `+/-` para expandir/colapsar, haz clic en un nodo para ver sus
   propiedades en el panel derecho, usa el zoom y exporta a SVG/PNG.

Hay un esquema de ejemplo en [`samples/purchaseorder.xsd`](samples/purchaseorder.xsd).

## Estructura del proyecto

```
index.php                 Interfaz (Bootstrap, pantalla completa)
assets/
  css/app.css             Estilos del layout y del diagrama
  js/diagram.js           Motor de layout (port de GenerateMeasure/GenerateLocation)
  js/svg-renderer.js      Renderizador SVG (port de DiagramSvgRenderer)
  js/properties-panel.js  Panel derecho de propiedades + XPath
  js/app.js               Controlador (carga, expand/colapse, zoom, pan, export)
  vendor/                 Bootstrap, jQuery, SweetAlert2
src/
  bootstrap.php           Configuración y utilidades comunes
  XsdLoader.php           Carga y fusiona el XSD + dependencias (asigna xsdid)
  XsdSchema.php           Índice de definiciones + resolución de QName + lookup por xsdid
  DiagramBuilder.php      Expansión de nodos, atributos y enumeraciones
  Cache.php               Caché por token del esquema fusionado
api/
  load.php                Carga (archivo/URL) → { token, roots }
  expand.php              Hijos de un nodo → { children }
  node.php                Propiedades de un nodo → { properties }
storage/cache/            Esquemas en caché (no servir públicamente)
```

## Seguridad

- Validación de subidas y límite de tamaño (8 MB por defecto, ver `src/bootstrap.php`).
- Descargas remotas solo por `http(s)` con mitigación básica de **SSRF** (se bloquean
  direcciones privadas/reservadas salvo que se active `XSD_ALLOW_PRIVATE_HOSTS`).
- La carpeta `storage/cache/` incluye un `.htaccess` que niega el acceso web directo.

## Créditos / licencia

Este proyecto incluye código portado del algoritmo de diagramación de XSD Diagram de
Régis Cosnier y colaboradores ([dgis/xsddiagram](https://github.com/dgis/xsddiagram)),
ofrecido a elección bajo **GPL-2.0 / LGPL-3.0 / MS-PL**.

Como obra derivada, **XSD Diagram Web** se distribuye bajo la **Microsoft Public License
(MS-PL)**, una de las opciones permitidas por el proyecto original. Consulta el archivo
[`LICENSE`](LICENSE) para los términos completos.

Las librerías de frontend incluidas en `assets/vendor/` conservan sus propias licencias
(Bootstrap, jQuery y SweetAlert2 son MIT).
