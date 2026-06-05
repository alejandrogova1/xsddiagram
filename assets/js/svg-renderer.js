/*
 * SVG renderer for the diagram. Port of XSDDiagrams/Rendering/DiagramSvgRenderer.cs.
 *
 * Everything is drawn at unscaled coordinates inside a single <g> that carries a
 * scale() transform, so zooming is a single attribute change and the exported
 * SVG keeps crisp vector output.
 */
(function (global) {
    'use strict';

    var SVGNS = 'http://www.w3.org/2000/svg';
    var STROKE = 'rgb(0,0,0)';

    function el(name, attrs) {
        var node = document.createElementNS(SVGNS, name);
        if (attrs) {
            for (var k in attrs) {
                if (Object.prototype.hasOwnProperty.call(attrs, k)) {
                    node.setAttribute(k, attrs[k]);
                }
            }
        }
        return node;
    }

    function line(parent, x1, y1, x2, y2, opts) {
        parent.appendChild(el('line', {
            x1: x1, y1: y1, x2: x2, y2: y2,
            stroke: STROKE,
            'stroke-width': (opts && opts.width) || 1,
            'stroke-linecap': (opts && opts.round) ? 'round' : 'butt',
            'stroke-dasharray': (opts && opts.dashed) ? '4,1' : 'none'
        }));
    }

    function dot(parent, cx, cy, r) {
        parent.appendChild(el('ellipse', { cx: cx, cy: cy, rx: r, ry: r, fill: STROKE }));
    }

    function polygon(parent, points, dashed, cls) {
        var d = '';
        for (var i = 0; i < points.length; i++) {
            d += (i === 0 ? 'M' : 'L') + points[i][0] + ' ' + points[i][1] + ' ';
        }
        d += 'Z';
        parent.appendChild(el('path', {
            d: d, fill: 'rgb(255,255,255)', stroke: STROKE, 'stroke-width': 1,
            'stroke-dasharray': dashed ? '4,1' : 'none',
            'class': cls || ''
        }));
    }

    function rect(parent, box, dashed, cls) {
        parent.appendChild(el('rect', {
            x: box.x, y: box.y, width: box.w, height: box.h,
            fill: 'rgb(255,255,255)', stroke: STROKE, 'stroke-width': 1,
            'stroke-dasharray': dashed ? '4,1' : 'none',
            'class': cls || ''
        }));
    }

    function text(parent, str, x, y, font, anchor) {
        var t = el('text', {
            x: x, y: y,
            'font-family': 'Arial',
            'font-size': font.size,
            'font-weight': font.bold ? 'bold' : 'normal',
            'text-anchor': anchor || 'middle',
            'dominant-baseline': 'central',
            fill: STROKE
        });
        t.textContent = str;
        parent.appendChild(t);
        return t;
    }

    function SvgRenderer(svg) {
        this.svg = svg;
        this.onSelect = null;
        this.onToggle = null;
    }

    SvgRenderer.prototype.render = function (diagram) {
        var svg = this.svg;
        while (svg.firstChild) { svg.removeChild(svg.firstChild); }

        var scale = diagram.scale;
        var w = Math.max(1, Math.round(diagram.boundingBox.w * scale));
        var h = Math.max(1, Math.round(diagram.boundingBox.h * scale));
        svg.setAttribute('width', w);
        svg.setAttribute('height', h);
        svg.setAttribute('viewBox', '0 0 ' + diagram.boundingBox.w + ' ' + diagram.boundingBox.h);

        var g = el('g', {});
        for (var i = 0; i < diagram.roots.length; i++) {
            this.renderNode(g, diagram.roots[i], diagram);
        }
        svg.appendChild(g);
    };

    SvgRenderer.prototype.renderNode = function (g, node, diagram) {
        var data = node.data;
        var opt = diagram.options;
        var showDoc = opt.showDocumentation && node.docBoxAbs;

        // 1. Children first
        if (node.showChildElements) {
            for (var i = 0; i < node.children.length; i++) {
                this.renderNode(g, node.children[i], diagram);
            }
        }

        var eb = node.elementBoxAbs;
        var loc = node.location;
        var size = node.size;

        // 2. Connectors to children
        if (node.showChildElements && node.children.length > 0) {
            if (node.children.length === 1 && !showDoc) {
                var midY = loc.y + size.h / 2;
                line(g, loc.x + size.w, midY, node.children[0].location.x, midY, { round: true });
            } else {
                var first = node.children[0];
                var last = node.children[node.children.length - 1];
                var vertical = first.bb.x;
                for (var c = 0; c < node.children.length; c++) {
                    var child = node.children[c];
                    var cMidY = child.location.y + child.size.h / 2;
                    line(g, vertical, cMidY, child.location.x, cMidY, { round: true });
                }
                var parentMidY = loc.y + size.h / 2;
                var firstMidY = Math.min(first.location.y + first.size.h / 2, parentMidY);
                var lastMidY = Math.max(last.location.y + last.size.h / 2, parentMidY);
                line(g, vertical, firstMidY, vertical, lastMidY, { round: true });
                line(g, loc.x + size.w, parentMidY, vertical, parentMidY, { round: true });
            }
        }

        // 3. Shape
        var nodeGroup = el('g', { 'class': 'di-node' + (node.selected ? ' di-selected' : '') });
        var dashed = data.minOccurrence === 0;
        var doubled = !(data.maxOccurrence === 1);

        if (data.itemType === 'element') {
            if (doubled) { rect(nodeGroup, { x: eb.x + 3, y: eb.y + 3, w: eb.w, h: eb.h }, dashed, 'di-box'); }
            rect(nodeGroup, eb, dashed, 'di-box');
        } else if (data.itemType === 'type') {
            var tp = typePolygon(eb);
            if (doubled) { polygon(nodeGroup, shift(tp, 3), dashed, 'di-box'); }
            polygon(nodeGroup, tp, dashed, 'di-box');
        } else { // group
            var gp = groupPolygon(eb);
            if (doubled) { polygon(nodeGroup, shift(gp, 3), dashed, 'di-box'); }
            polygon(nodeGroup, gp, dashed, 'di-box');
            drawGroupSymbol(nodeGroup, node);
        }
        g.appendChild(nodeGroup);

        // 4. Label
        if (data.name && data.name.length > 0) {
            var label = (opt.showType && data.type) ? data.name + ':' + data.type : data.name;
            text(nodeGroup, label, eb.x + eb.w / 2, eb.y + eb.h / 2, { size: 10, bold: true });
        }

        // 5. Documentation
        if (showDoc && data.documentation) {
            this.drawDocumentation(g, node);
        }

        // 6. Occurrences
        if (opt.alwaysShowOccurence || data.maxOccurrence > 1 || data.maxOccurrence === -1) {
            var occ = data.minOccurrence + '..' + (data.maxOccurrence === -1 ? '\u221e' : data.maxOccurrence);
            var compact = opt.compactLayoutDensity;
            var ox = loc.x + size.w + (compact ? 23 : -10);
            var oy = loc.y + size.h + (compact ? -17 : 10);
            text(g, occ, ox, oy, { size: 9, bold: false }, 'end');
        }

        // 7. Simple content marker
        if (data.isSimpleContent) {
            var p = { x: eb.x + 2, y: eb.y + 2 };
            line(g, p.x, p.y, p.x + 8, p.y);
            line(g, p.x, p.y + 2, p.x + 6, p.y + 2);
            line(g, p.x, p.y + 4, p.x + 6, p.y + 4);
            line(g, p.x, p.y + 6, p.x + 6, p.y + 6);
        }

        // 8. Reference arrow
        if (data.isReference) {
            this.drawReferenceArrow(g, node);
        }

        // 9. Expand / collapse button
        if (data.hasChildElements) {
            this.drawExpandButton(g, node);
        }

        // 10. Transparent hit area for selection
        var hit = el('rect', {
            x: eb.x, y: eb.y, width: eb.w, height: eb.h,
            'class': 'node-hit', fill: 'transparent'
        });
        var self = this;
        hit.addEventListener('click', function (ev) {
            ev.stopPropagation();
            if (self.onSelect) { self.onSelect(node); }
        });
        g.appendChild(hit);
    };

    SvgRenderer.prototype.drawExpandButton = function (g, node) {
        var box = node.expandBoxAbs;
        rect(g, box, false, '');
        var midX = box.x + box.w / 2;
        var midY = box.y + box.h / 2;
        var pad = 2;
        line(g, box.x + pad, midY, box.x + box.w - pad, midY);
        if (!node.showChildElements) {
            line(g, midX, box.y + pad, midX, box.y + box.h - pad);
        }
        // hit area
        var hit = el('rect', {
            x: box.x - 1, y: box.y - 1, width: box.w + 2, height: box.h + 2,
            'class': 'expand-btn', fill: 'transparent'
        });
        var self = this;
        hit.addEventListener('click', function (ev) {
            ev.stopPropagation();
            if (self.onToggle) { self.onToggle(node); }
        });
        g.appendChild(hit);
    };

    SvgRenderer.prototype.drawReferenceArrow = function (g, node) {
        var eb = node.elementBoxAbs;
        var base = { x: eb.x + 1, y: eb.y + eb.h - 1 };
        var target = { x: base.x + 3, y: base.y - 3 };
        if (node.data.itemType === 'group') {
            var bevel = eb.h * 0.30;
            var off = bevel * 0.4242640687;
            base.x += off; base.y -= off;
            target.x += off; target.y -= off;
        }
        g.appendChild(el('line', {
            x1: base.x, y1: base.y, x2: target.x, y2: target.y,
            stroke: STROKE, 'stroke-width': 2
        }));
        var d = 'M' + target.x + ' ' + target.y +
            ' L' + (target.x + 2) + ' ' + (target.y + 2) +
            ' L' + (target.x + 3) + ' ' + (target.y - 3) +
            ' L' + (target.x - 2) + ' ' + (target.y - 2) + ' Z';
        g.appendChild(el('path', { d: d, fill: STROKE }));
    };

    SvgRenderer.prototype.drawDocumentation = function (g, node) {
        var box = node.docBoxAbs;
        var words = String(node.data.documentation).split(/\s+/);
        var maxWidth = box.w;
        var lineHeight = 13;
        var lines = [];
        var current = '';
        for (var i = 0; i < words.length; i++) {
            var trial = current ? current + ' ' + words[i] : words[i];
            if (measureDoc(trial) > maxWidth && current) {
                lines.push(current);
                current = words[i];
            } else {
                current = trial;
            }
        }
        if (current) { lines.push(current); }

        var maxLines = Math.max(1, Math.floor(box.h / lineHeight));
        if (lines.length > maxLines) {
            lines = lines.slice(0, maxLines);
            lines[lines.length - 1] += '\u2026';
        }

        var t = el('text', {
            'font-family': 'Arial', 'font-size': 9, fill: STROKE,
            'text-anchor': 'start'
        });
        for (var l = 0; l < lines.length; l++) {
            var tspan = el('tspan', { x: box.x, y: box.y + (l + 1) * lineHeight });
            tspan.textContent = lines[l];
            t.appendChild(tspan);
        }
        g.appendChild(t);
    };

    var docCanvas = document.createElement('canvas').getContext('2d');
    function measureDoc(s) { docCanvas.font = '9px Arial'; return docCanvas.measureText(s).width; }

    // ---- Shape helpers --------------------------------------------------

    function shift(points, d) {
        var out = [];
        for (var i = 0; i < points.length; i++) { out.push([points[i][0] + d, points[i][1] + d]); }
        return out;
    }

    function typePolygon(eb) {
        var bevel = eb.h * 0.30;
        var left = eb.x, right = eb.x + eb.w, top = eb.y, bottom = eb.y + eb.h;
        return [
            [left + bevel, top],
            [right, top],
            [right, bottom],
            [left + bevel, bottom],
            [left, bottom - bevel],
            [left, top + bevel]
        ];
    }

    function groupPolygon(eb) {
        var bevel = eb.h * 0.30;
        var left = eb.x, right = eb.x + eb.w, top = eb.y, bottom = eb.y + eb.h;
        return [
            [left + bevel, top],
            [right - bevel, top],
            [right, top + bevel],
            [right, bottom - bevel],
            [right - bevel, bottom],
            [left + bevel, bottom],
            [left, bottom - bevel],
            [left, top + bevel]
        ];
    }

    function drawGroupSymbol(parent, node) {
        var eb = node.elementBoxAbs;
        var gt = node.data.groupType;
        var yMid = eb.y + eb.h / 2;
        var xMid = eb.x + eb.w / 2;

        if (gt === 'sequence') {
            line(parent, eb.x + 3, yMid, eb.x + eb.w - 3, yMid);
            dot(parent, xMid - 5, yMid, 2);
            dot(parent, xMid, yMid, 2);
            dot(parent, xMid + 5, yMid, 2);
        } else if (gt === 'choice') {
            var yUp = yMid - 4, yDown = yMid + 4;
            var xL2 = xMid - 4, xL1 = xL2 - 4, xL0 = xL1 - 4;
            var xR0 = xMid + 4, xR1 = xR0 + 4, xR2 = xR1 + 4;
            line(parent, xL0, yMid, xL1, yMid);
            line(parent, xL1, yMid, xL2, yUp);
            line(parent, xR0, yUp, xR1, yUp);
            line(parent, xR0, yMid, xR2, yMid);
            line(parent, xR0, yDown, xR1, yDown);
            line(parent, xR1, yUp, xR1, yDown);
            dot(parent, xMid, yUp, 2);
            dot(parent, xMid, yMid, 2);
            dot(parent, xMid, yDown, 2);
        } else if (gt === 'all') {
            var aUp = yMid - 4, aDown = yMid + 4;
            var aL2 = xMid - 4, aL1 = aL2 - 4, aL0 = aL1 - 4;
            var aR0 = xMid + 4, aR1 = aR0 + 4, aR2 = aR1 + 4;
            line(parent, aL2, aUp, aL1, aUp);
            line(parent, aL2, yMid, aL0, yMid);
            line(parent, aL2, aDown, aL1, aDown);
            line(parent, aL1, aUp, aL1, aDown);
            line(parent, aR0, aUp, aR1, aUp);
            line(parent, aR0, yMid, aR2, yMid);
            line(parent, aR0, aDown, aR1, aDown);
            line(parent, aR1, aUp, aR1, aDown);
            dot(parent, xMid, aUp, 2);
            dot(parent, xMid, yMid, 2);
            dot(parent, xMid, aDown, 2);
        }
    }

    global.XSD = global.XSD || {};
    global.XSD.SvgRenderer = SvgRenderer;

})(window);
