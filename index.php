<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>XSD Diagram Web</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div id="app">
    <!-- Topbar -->
    <header class="topbar border-bottom">
        <div class="topbar-row">
            <span class="brand">XSD&nbsp;Diagram&nbsp;Web</span>

            <div class="vr"></div>

            <!-- Source: file -->
            <form id="form-file" class="inline-form" autocomplete="off">
                <label class="btn btn-outline-secondary btn-sm mb-0" for="file-input">
                    <span id="file-label">Elegir XSD&hellip;</span>
                </label>
                <input class="d-none" type="file" id="file-input" accept=".xsd,.xml,text/xml,application/xml">
                <button class="btn btn-primary btn-sm" type="submit">Cargar archivo</button>
            </form>

            <div class="vr"></div>

            <!-- Source: url -->
            <form id="form-url" class="inline-form" autocomplete="off">
                <input class="form-control form-control-sm url-input" type="url" id="url-input"
                       placeholder="https://&hellip;/esquema.xsd">
                <button class="btn btn-primary btn-sm" type="submit">Cargar URL</button>
            </form>
        </div>

        <div class="topbar-row second-row">
            <!-- Root selector -->
            <label class="row-label" for="root-select">Raíz</label>
            <select class="form-select form-select-sm root-select" id="root-select" disabled>
                <option value="">(carga un XSD primero)</option>
            </select>
            <div class="input-group input-group-sm expand-level" title="Nivel de expansión inicial">
                <span class="input-group-text">Nivel</span>
                <input type="number" class="form-control" id="expand-level" value="1" min="0" max="15">
            </div>
            <button class="btn btn-success btn-sm" id="btn-add" disabled>Agregar al diagrama</button>
            <button class="btn btn-outline-danger btn-sm" id="btn-clear" disabled>Limpiar</button>

            <div class="vr"></div>

            <!-- Options -->
            <div class="form-check form-check-inline form-switch option">
                <input class="form-check-input" type="checkbox" id="opt-documentation">
                <label class="form-check-label" for="opt-documentation">Documentación</label>
            </div>
            <div class="form-check form-check-inline form-switch option">
                <input class="form-check-input" type="checkbox" id="opt-type">
                <label class="form-check-label" for="opt-type">Tipo</label>
            </div>
            <div class="form-check form-check-inline form-switch option">
                <input class="form-check-input" type="checkbox" id="opt-occurrence">
                <label class="form-check-label" for="opt-occurrence">Ocurrencias</label>
            </div>
            <div class="form-check form-check-inline form-switch option">
                <input class="form-check-input" type="checkbox" id="opt-compact">
                <label class="form-check-label" for="opt-compact">Compacto</label>
            </div>

            <div class="vr"></div>

            <!-- Zoom -->
            <div class="btn-group btn-group-sm zoom-group" role="group" aria-label="Zoom">
                <button class="btn btn-outline-secondary" id="btn-zoom-out" title="Alejar">&minus;</button>
                <button class="btn btn-outline-secondary" id="btn-zoom-reset" title="Restablecer zoom">100%</button>
                <button class="btn btn-outline-secondary" id="btn-zoom-in" title="Acercar">+</button>
                <button class="btn btn-outline-secondary" id="btn-zoom-fit" title="Ajustar a la ventana">Ajustar</button>
            </div>

            <div class="vr"></div>

            <!-- Export -->
            <div class="btn-group btn-group-sm" role="group" aria-label="Exportar">
                <button class="btn btn-outline-primary" id="btn-export-svg" disabled>Exportar SVG</button>
                <button class="btn btn-outline-primary" id="btn-export-png" disabled>Exportar PNG</button>
            </div>
        </div>
    </header>

    <!-- Body -->
    <main class="body">
        <section class="canvas-wrap" id="canvas-wrap">
            <div class="canvas-empty" id="canvas-empty">
                <div class="text-center text-muted">
                    <h4>Sube un archivo XSD o indica una URL</h4>
                    <p>Luego elige un elemento raíz y pulsa <em>Agregar al diagrama</em>.</p>
                </div>
            </div>
            <svg id="diagram" xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>
        </section>

        <div class="resizer" id="resizer" title="Arrastra para redimensionar"></div>

        <!-- Right side properties panel -->
        <aside class="side-panel" id="side-panel">
            <div class="side-header">
                <div class="prop-name" id="prop-name">&mdash;</div>
                <div class="prop-type" id="prop-type"></div>
            </div>

            <ul class="nav nav-tabs nav-fill" id="side-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-attributes-btn" data-bs-toggle="tab"
                            data-bs-target="#tab-attributes" type="button" role="tab">Atributos</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-elements-btn" data-bs-toggle="tab"
                            data-bs-target="#tab-elements" type="button" role="tab">Elemento</button>
                </li>
            </ul>

            <div class="tab-content side-tab-content">
                <div class="tab-pane fade show active" id="tab-attributes" role="tabpanel">
                    <table class="table table-sm table-striped prop-table">
                        <thead>
                        <tr><th>Nombre</th><th>Tipo</th><th>Uso</th><th>Default</th></tr>
                        </thead>
                        <tbody id="attributes-body">
                        <tr class="empty-row"><td colspan="4">Sin atributos</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="tab-pane fade" id="tab-elements" role="tabpanel">
                    <table class="table table-sm table-striped prop-table">
                        <thead>
                        <tr><th>Nombre</th><th>Tipo</th></tr>
                        </thead>
                        <tbody id="elements-body">
                        <tr class="empty-row"><td colspan="2">Sin elementos hijos</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="side-section">
                <div class="side-section-title">Enumeraciones</div>
                <div class="enum-list" id="enum-list"><span class="text-muted small">&mdash;</span></div>
            </div>

            <div class="side-section flex-grow-1">
                <div class="side-section-title">Descripción</div>
                <div class="doc-box" id="doc-box"><span class="text-muted small">Selecciona un nodo del diagrama.</span></div>
            </div>
        </aside>
    </main>

    <!-- Status bar -->
    <footer class="statusbar border-top">
        <span id="status-path" class="status-path">/</span>
        <span id="status-info" class="status-info ms-auto">Listo</span>
    </footer>
</div>

<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/sweetalert2/sweetalert2.min.js"></script>
<script src="assets/js/diagram.js"></script>
<script src="assets/js/svg-renderer.js"></script>
<script src="assets/js/properties-panel.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
