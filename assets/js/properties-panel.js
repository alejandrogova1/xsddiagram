/*
 * Right side properties panel: name/type header, Attributes/Element tabs,
 * enumerations and the description box. Mirrors the desktop element panel.
 */
(function (global) {
    'use strict';

    var $name, $type, $attrBody, $elemBody, $enum, $doc, $attrDetail;
    var currentAttributes = [];
    var selectedAttrIndex = -1;

    var FACET_LABELS = {
        pattern: 'Patr\u00f3n',
        minLength: 'Longitud m\u00ednima',
        maxLength: 'Longitud m\u00e1xima',
        length: 'Longitud',
        minInclusive: 'M\u00ednimo inclusive',
        maxInclusive: 'M\u00e1ximo inclusive',
        minExclusive: 'M\u00ednimo exclusive',
        maxExclusive: 'M\u00e1ximo exclusive',
        totalDigits: 'D\u00edgitos totales',
        fractionDigits: 'D\u00edgitos fraccionarios',
        whiteSpace: 'Espacio en blanco'
    };

    function init() {
        $name = document.getElementById('prop-name');
        $type = document.getElementById('prop-type');
        $attrBody = document.getElementById('attributes-body');
        $elemBody = document.getElementById('elements-body');
        $enum = document.getElementById('enum-list');
        $doc = document.getElementById('doc-box');
        $attrDetail = document.getElementById('attr-detail');

        $attrBody.addEventListener('click', function (e) {
            var row = e.target.closest('tr.attr-row');
            if (!row) { return; }
            selectAttributeRow(parseInt(row.getAttribute('data-index'), 10));
        });
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function clearAttributeDetail() {
        selectedAttrIndex = -1;
        currentAttributes = [];
        if ($attrDetail) {
            $attrDetail.innerHTML =
                '<div class="attr-detail-empty text-muted small">Selecciona un atributo para ver su detalle.</div>';
        }
    }

    function clear() {
        $name.textContent = '\u2014';
        $type.textContent = '';
        $attrBody.innerHTML = '<tr class="empty-row"><td colspan="4">Sin atributos</td></tr>';
        $elemBody.innerHTML = '<tr class="empty-row"><td colspan="2">Sin elementos hijos</td></tr>';
        $enum.innerHTML = '<span class="text-muted small">\u2014</span>';
        $doc.innerHTML = '<span class="text-muted small">Selecciona un nodo del diagrama.</span>';
        clearAttributeDetail();
    }

    function fill(props) {
        $name.textContent = props.name || '(sin nombre)';
        $type.textContent = props.type ? 'tipo: ' + props.type : (props.itemType || '');

        clearAttributeDetail();

        if (props.attributes && props.attributes.length) {
            currentAttributes = props.attributes;
            var rows = '';
            props.attributes.forEach(function (a, i) {
                rows += '<tr class="attr-row" data-index="' + i + '"><td>' + esc(a.name) +
                    '</td><td>' + esc(a.type) + '</td><td>' + esc(a.use) +
                    '</td><td>' + esc(a.default) + '</td></tr>';
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
            $doc.innerHTML = '<span class="text-muted small">Sin documentaci\u00f3n.</span>';
        }
    }

    function selectAttributeRow(index) {
        if (index < 0 || index >= currentAttributes.length) { return; }
        selectedAttrIndex = index;

        var rows = $attrBody.querySelectorAll('tr.attr-row');
        rows.forEach(function (row) {
            row.classList.toggle('selected', parseInt(row.getAttribute('data-index'), 10) === index);
        });

        showAttributeDetail(currentAttributes[index]);
    }

    function addDetailRow(label, value) {
        if (value == null || value === '') { return ''; }
        return '<div class="attr-detail-row"><dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd></div>';
    }

    function showAttributeDetail(attr) {
        if (!$attrDetail || !attr) { return; }

        var html = '<div class="attr-detail-header">' + esc(attr.name || '(sin nombre)') + '</div>';
        html += '<dl class="attr-detail-list">';

        if (attr.typeQName) {
            html += addDetailRow('Tipo', attr.typeQName);
        } else if (attr.type) {
            html += addDetailRow('Tipo', attr.type);
        }
        if (attr.baseType && attr.baseType !== attr.type) {
            html += addDetailRow('Tipo base', attr.baseType);
        } else if (attr.baseType && !attr.typeQName && !attr.type) {
            html += addDetailRow('Tipo base', attr.baseType);
        }
        html += addDetailRow('Uso', attr.use);
        html += addDetailRow('Valor por defecto', attr.default);
        html += addDetailRow('Valor fijo', attr.fixed);
        html += addDetailRow('Forma', attr.form);

        if (attr.facets) {
            Object.keys(FACET_LABELS).forEach(function (key) {
                if (attr.facets[key] != null && attr.facets[key] !== '') {
                    html += addDetailRow(FACET_LABELS[key], attr.facets[key]);
                }
            });
        }

        html += '</dl>';

        if (attr.documentation) {
            html += '<div class="attr-detail-section"><div class="attr-detail-section-title">Descripci\u00f3n</div>';
            html += '<div class="attr-detail-doc">' + esc(attr.documentation) + '</div></div>';
        }

        if (attr.enumerations && attr.enumerations.length) {
            html += '<div class="attr-detail-section"><div class="attr-detail-section-title">Enumeraciones</div>';
            html += '<div class="enum-list">';
            attr.enumerations.forEach(function (en) {
                var title = en.documentation ? ' title="' + esc(en.documentation) + '"' : '';
                html += '<span class="badge text-bg-light border"' + title + '>' + esc(en.value) + '</span>';
            });
            html += '</div></div>';
        }

        $attrDetail.innerHTML = html;
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
