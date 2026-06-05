/*
 * Right side properties panel: name/type header, Attributes/Element tabs,
 * enumerations and the description box. Mirrors the desktop element panel.
 */
(function (global) {
    'use strict';

    var $name, $type, $attrBody, $elemBody, $enum, $doc;

    function init() {
        $name = document.getElementById('prop-name');
        $type = document.getElementById('prop-type');
        $attrBody = document.getElementById('attributes-body');
        $elemBody = document.getElementById('elements-body');
        $enum = document.getElementById('enum-list');
        $doc = document.getElementById('doc-box');
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function clear() {
        $name.textContent = '\u2014';
        $type.textContent = '';
        $attrBody.innerHTML = '<tr class="empty-row"><td colspan="4">Sin atributos</td></tr>';
        $elemBody.innerHTML = '<tr class="empty-row"><td colspan="2">Sin elementos hijos</td></tr>';
        $enum.innerHTML = '<span class="text-muted small">\u2014</span>';
        $doc.innerHTML = '<span class="text-muted small">Selecciona un nodo del diagrama.</span>';
    }

    function fill(props) {
        $name.textContent = props.name || '(sin nombre)';
        $type.textContent = props.type ? 'tipo: ' + props.type : (props.itemType || '');

        // Attributes
        if (props.attributes && props.attributes.length) {
            var rows = '';
            props.attributes.forEach(function (a) {
                rows += '<tr><td>' + esc(a.name) + '</td><td>' + esc(a.type) +
                    '</td><td>' + esc(a.use) + '</td><td>' + esc(a.default) + '</td></tr>';
            });
            $attrBody.innerHTML = rows;
        } else {
            $attrBody.innerHTML = '<tr class="empty-row"><td colspan="4">Sin atributos</td></tr>';
        }

        // Child elements
        if (props.childElements && props.childElements.length) {
            var erows = '';
            props.childElements.forEach(function (e) {
                erows += '<tr><td>' + esc(e.name) + '</td><td>' + esc(e.type) + '</td></tr>';
            });
            $elemBody.innerHTML = erows;
        } else {
            $elemBody.innerHTML = '<tr class="empty-row"><td colspan="2">Sin elementos hijos</td></tr>';
        }

        // Enumerations
        if (props.enumerations && props.enumerations.length) {
            var badges = '';
            props.enumerations.forEach(function (en) {
                var title = en.documentation ? ' title="' + esc(en.documentation) + '"' : '';
                badges += '<span class="badge text-bg-light border"' + title + '>' + esc(en.value) + '</span>';
            });
            $enum.innerHTML = badges;
        } else {
            $enum.innerHTML = '<span class="text-muted small">\u2014</span>';
        }

        // Description
        if (props.documentation) {
            $doc.textContent = props.documentation;
        } else {
            $doc.innerHTML = '<span class="text-muted small">Sin documentación.</span>';
        }
    }

    /**
     * Build the XPath of a node from its diagram parent chain (element names
     * only), matching the desktop status bar (e.g. /COLLADA/scene).
     */
    function xpath(node) {
        var parts = [];
        var current = node;
        while (current) {
            if (current.data.itemType === 'element' && current.data.name) {
                parts.unshift(current.data.name);
            }
            current = current.parent;
        }
        return '/' + parts.join('/');
    }

    global.XSD = global.XSD || {};
    global.XSD.panel = { init: init, clear: clear, fill: fill, xpath: xpath };

})(window);
