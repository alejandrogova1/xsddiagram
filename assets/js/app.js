/*
 * Application controller: wires the UI (Bootstrap + jQuery), talks to the PHP
 * API, drives the layout engine / SVG renderer and the properties panel.
 * User messages go through SweetAlert2.
 */
(function ($, global) {
    'use strict';

    var XSD = global.XSD;

    var state = {
        token: null,
        roots: [],          // root item data from the server
        diagram: new XSD.Diagram(),
        renderer: null,
        selected: null
    };

    var $svg, $canvasWrap, $status, $statusInfo;

    $(function () {
        XSD.panel.init();
        $svg = document.getElementById('diagram');
        $canvasWrap = document.getElementById('canvas-wrap');
        $status = document.getElementById('status-path');
        $statusInfo = document.getElementById('status-info');

        state.renderer = new XSD.SvgRenderer($svg);
        state.renderer.onSelect = onSelectNode;
        state.renderer.onToggle = onToggleNode;

        bindUi();
    });

    // ---- UI bindings ----------------------------------------------------

    function bindUi() {
        var fileInput = document.getElementById('file-input');
        fileInput.addEventListener('change', function () {
            document.getElementById('file-label').textContent =
                fileInput.files.length ? fileInput.files[0].name : 'Elegir XSD\u2026';
        });

        $('#form-file').on('submit', function (e) {
            e.preventDefault();
            if (!fileInput.files.length) {
                Swal.fire('Falta el archivo', 'Selecciona un archivo XSD primero.', 'info');
                return;
            }
            var fd = new FormData();
            fd.append('file', fileInput.files[0]);
            loadSchema(fd);
        });

        $('#form-url').on('submit', function (e) {
            e.preventDefault();
            var url = $('#url-input').val().trim();
            if (!url) {
                Swal.fire('Falta la URL', 'Escribe la URL de un XSD.', 'info');
                return;
            }
            var fd = new FormData();
            fd.append('url', url);
            loadSchema(fd);
        });

        $('#btn-add').on('click', addSelectedRoot);
        $('#btn-clear').on('click', clearDiagram);

        $('#opt-documentation, #opt-type, #opt-occurrence, #opt-compact').on('change', function () {
            var o = state.diagram.options;
            o.showDocumentation = $('#opt-documentation').is(':checked');
            o.showType = $('#opt-type').is(':checked');
            o.alwaysShowOccurence = $('#opt-occurrence').is(':checked');
            o.compactLayoutDensity = $('#opt-compact').is(':checked');
            relayout();
        });

        $('#btn-zoom-in').on('click', function () { setZoom(state.diagram.scale * 1.2); });
        $('#btn-zoom-out').on('click', function () { setZoom(state.diagram.scale / 1.2); });
        $('#btn-zoom-reset').on('click', function () { setZoom(1); });
        $('#btn-zoom-fit').on('click', zoomToFit);

        $('#btn-export-svg').on('click', exportSvg);
        $('#btn-export-png').on('click', exportPng);

        // Ctrl + wheel zoom
        $canvasWrap.addEventListener('wheel', function (e) {
            if (!e.ctrlKey) { return; }
            e.preventDefault();
            setZoom(state.diagram.scale * (e.deltaY < 0 ? 1.1 : 1 / 1.1));
        }, { passive: false });

        enablePanning();
    }

    // ---- Loading --------------------------------------------------------

    function loadSchema(formData) {
        info('Cargando esquema\u2026');
        Swal.fire({ title: 'Cargando esquema\u2026', didOpen: function () { Swal.showLoading(); }, allowOutsideClick: false });

        $.ajax({
            url: 'api/load.php', method: 'POST', data: formData,
            processData: false, contentType: false, dataType: 'json'
        }).done(function (res) {
            Swal.close();
            if (!res || !res.ok) { return fail(res && res.error); }
            state.token = res.token;
            state.roots = res.roots;
            populateRoots(res.roots);
            clearDiagram();
            enableControls(true);
            info('Esquema cargado: ' + (res.source || '') + ' \u2014 ' + res.roots.length + ' raíces.');
            addSelectedRoot();
            if (res.warnings && res.warnings.length) {
                Swal.fire({
                    icon: 'warning', title: 'Cargado con avisos',
                    html: '<div style="text-align:left;max-height:40vh;overflow:auto">' +
                        res.warnings.map(function (w) { return '\u2022 ' + escapeHtml(w); }).join('<br>') + '</div>'
                });
            }
        }).fail(function (xhr) {
            Swal.close();
            fail(parseError(xhr));
        });
    }

    function populateRoots(roots) {
        var $sel = $('#root-select');
        $sel.empty();
        roots.forEach(function (r, i) {
            $sel.append($('<option>').val(i).text(r.label));
        });
        $sel.prop('disabled', roots.length === 0);
    }

    // ---- Diagram operations ---------------------------------------------

    function addSelectedRoot() {
        if (!state.roots.length) { return; }
        var idx = parseInt($('#root-select').val(), 10);
        if (isNaN(idx) || !state.roots[idx]) { return; }

        var data = JSON.parse(JSON.stringify(state.roots[idx]));
        var node = state.diagram.addRoot(data);

        var level = Math.max(0, parseInt($('#expand-level').val(), 10) || 0);
        info('Construyendo diagrama\u2026');
        expandToLevel(node, level).then(function () {
            relayout();
            scrollToNode(node);
            $canvasWrap.classList.add('has-content');
            info('Listo');
        }).catch(function (err) { fail(err && err.message); });
    }

    function clearDiagram() {
        state.diagram.clear();
        state.selected = null;
        XSD.panel.clear();
        $status.textContent = '/';
        $canvasWrap.classList.remove('has-content');
        render();
    }

    function ensureExpanded(node) {
        if (!node.data.hasChildElements) { return Promise.resolve(); }
        if (node.loaded) { node.showChildElements = true; return Promise.resolve(); }
        return $.ajax({
            url: 'api/expand.php', method: 'POST', dataType: 'json',
            data: { token: state.token, xsdid: node.data.xsdid }
        }).then(function (res) {
            if (!res || !res.ok) { throw new Error(res && res.error || 'Error al expandir.'); }
            node.children = (res.children || []).map(function (c) { return XSD.buildNode(c, node); });
            node.loaded = true;
            node.showChildElements = true;
        });
    }

    function expandToLevel(node, level) {
        if (level <= 0) { return Promise.resolve(); }
        return ensureExpanded(node).then(function () {
            var tasks = node.children.map(function (child) { return expandToLevel(child, level - 1); });
            return Promise.all(tasks);
        });
    }

    function onToggleNode(node) {
        if (!node.data.hasChildElements) { return; }
        if (node.loaded) {
            node.showChildElements = !node.showChildElements;
            relayout();
            scrollToNode(node);
        } else {
            info('Expandiendo\u2026');
            ensureExpanded(node).then(function () {
                relayout();
                scrollToNode(node);
                info('Listo');
            }).catch(function (err) { fail(err && err.message); });
        }
    }

    function onSelectNode(node) {
        if (state.selected) { state.selected.selected = false; }
        state.selected = node;
        node.selected = true;
        render();
        $status.textContent = XSD.panel.xpath(node);

        $.ajax({
            url: 'api/node.php', method: 'POST', dataType: 'json',
            data: { token: state.token, xsdid: node.data.xsdid }
        }).done(function (res) {
            if (res && res.ok) { XSD.panel.fill(res.properties); }
        });
    }

    // ---- Rendering ------------------------------------------------------

    function render() {
        state.renderer.render(state.diagram);
    }

    function relayout() {
        state.diagram.layout();
        render();
    }

    // ---- Zoom & pan -----------------------------------------------------

    function setZoom(scale) {
        scale = Math.min(8, Math.max(0.1, scale));
        state.diagram.scale = scale;
        render();
        $('#btn-zoom-reset').text(Math.round(scale * 100) + '%');
    }

    function zoomToFit() {
        if (!state.diagram.roots.length) { return; }
        var bb = state.diagram.boundingBox;
        var w = $canvasWrap.clientWidth - 20;
        var h = $canvasWrap.clientHeight - 20;
        setZoom(Math.min(w / bb.w, h / bb.h, 1));
    }

    function scrollToNode(node) {
        if (!node.location) { return; }
        var s = state.diagram.scale;
        $canvasWrap.scrollLeft = Math.max(0, node.location.x * s - 80);
        $canvasWrap.scrollTop = Math.max(0, node.location.y * s - $canvasWrap.clientHeight / 2);
    }

    function enablePanning() {
        var panning = false, startX, startY, startLeft, startTop;
        $canvasWrap.addEventListener('mousedown', function (e) {
            if (e.target.closest('.node-hit, .expand-btn')) { return; }
            panning = true;
            startX = e.clientX; startY = e.clientY;
            startLeft = $canvasWrap.scrollLeft; startTop = $canvasWrap.scrollTop;
            $canvasWrap.classList.add('panning');
        });
        window.addEventListener('mousemove', function (e) {
            if (!panning) { return; }
            $canvasWrap.scrollLeft = startLeft - (e.clientX - startX);
            $canvasWrap.scrollTop = startTop - (e.clientY - startY);
        });
        window.addEventListener('mouseup', function () {
            panning = false;
            $canvasWrap.classList.remove('panning');
        });

        // Resizable side panel
        var resizer = document.getElementById('resizer');
        var resizing = false;
        resizer.addEventListener('mousedown', function (e) { resizing = true; e.preventDefault(); });
        window.addEventListener('mousemove', function (e) {
            if (!resizing) { return; }
            var width = Math.min(640, Math.max(240, window.innerWidth - e.clientX));
            document.documentElement.style.setProperty('--side-width', width + 'px');
        });
        window.addEventListener('mouseup', function () { resizing = false; });
    }

    // ---- Export ---------------------------------------------------------

    function serializeSvg() {
        var clone = $svg.cloneNode(true);
        // Drop interactive-only hit rectangles.
        Array.prototype.forEach.call(clone.querySelectorAll('.node-hit, .expand-btn'), function (n) {
            n.parentNode.removeChild(n);
        });
        clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        return '<?xml version="1.0" standalone="no"?>\n' + new XMLSerializer().serializeToString(clone);
    }

    function exportSvg() {
        if (!state.diagram.roots.length) { return; }
        download(new Blob([serializeSvg()], { type: 'image/svg+xml' }), 'diagram.svg');
    }

    function exportPng() {
        if (!state.diagram.roots.length) { return; }
        var bb = state.diagram.boundingBox;
        var s = state.diagram.scale;
        var w = Math.round(bb.w * s), h = Math.round(bb.h * s);

        var go = function () {
            var svgData = serializeSvg();
            var url = URL.createObjectURL(new Blob([svgData], { type: 'image/svg+xml' }));
            var img = new Image();
            img.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                var ctx = canvas.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, w, h);
                ctx.drawImage(img, 0, 0, w, h);
                URL.revokeObjectURL(url);
                canvas.toBlob(function (blob) { download(blob, 'diagram.png'); }, 'image/png');
            };
            img.onerror = function () { URL.revokeObjectURL(url); fail('No se pudo generar el PNG.'); };
            img.src = url;
        };

        if (w * h > 8000 * 8000 || w > 16000 || h > 16000) {
            Swal.fire({
                icon: 'warning', title: 'Imagen muy grande',
                text: 'El PNG resultante es de ' + w + '\u00d7' + h + ' px. ¿Generarlo de todos modos?',
                showCancelButton: true, confirmButtonText: 'Generar', cancelButtonText: 'Cancelar'
            }).then(function (r) { if (r.isConfirmed) { go(); } });
        } else {
            go();
        }
    }

    function download(blob, filename) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    // ---- Helpers --------------------------------------------------------

    function enableControls(enabled) {
        $('#btn-add, #btn-clear, #btn-export-svg, #btn-export-png, #root-select').prop('disabled', !enabled);
    }

    function info(msg) { $statusInfo.textContent = msg; }

    function fail(msg) {
        info('Error');
        Swal.fire('Error', msg || 'Ocurrió un error inesperado.', 'error');
    }

    function parseError(xhr) {
        try { return (xhr.responseJSON && xhr.responseJSON.error) || JSON.parse(xhr.responseText).error; }
        catch (e) { return 'No se pudo contactar al servidor (' + (xhr.status || 0) + ').'; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

})(jQuery, window);
