/*
 * Layout engine for the XSD diagram.
 *
 * This is a JavaScript port of the two layout passes of the original
 * XSDDiagrams/Rendering/DiagramItem.cs (GenerateMeasure / GenerateLocation) and
 * the Diagram.Layout() driver. Text is measured with a canvas so the boxes fit
 * exactly like the desktop viewer.
 */
(function (global) {
    'use strict';

    // Constants taken verbatim from DiagramItem.cs
    var DEFAULT_SIZE = { w: 100, h: 25 };
    var GROUP_SIZE = { w: 40, h: 20 };
    var MARGIN = { w: 10, h: 5 };
    var PADDING = { w: 10, h: 15 };
    var PADDING_COMPACT = { w: 10, h: 4 };
    var CHILD_EXPAND_BUTTON_SIZE = 10;
    var DOCUMENTATION_MIN_WIDTH = 100;

    var FONT_NAME = 'bold 10px Arial';
    var FONT_DOC = '10px Arial';
    var NAME_LINE_HEIGHT = 15;   // matches the ~25px default box height
    var DOC_LINE_HEIGHT = 13;

    // Shared canvas used for text measurement.
    var measureCanvas = document.createElement('canvas');
    var measureCtx = measureCanvas.getContext('2d');
    var measureCache = {};

    function measureWidth(text, font) {
        var key = font + '\u0000' + text;
        var cached = measureCache[key];
        if (cached !== undefined) { return cached; }
        measureCtx.font = font;
        var w = measureCtx.measureText(text).width;
        measureCache[key] = w;
        return w;
    }

    var nodeSeq = 0;

    /**
     * Create a diagram node wrapping a server item.
     */
    function createNode(data, parent) {
        return {
            uid: ++nodeSeq,
            data: data,
            parent: parent || null,
            children: [],
            loaded: false,
            showChildElements: false,
            selected: false,
            depth: 0,
            // layout (filled by the passes below)
            size: { w: 0, h: 0 },
            bb: { x: 0, y: 0, w: 0, h: 0 },
            location: { x: 0, y: 0 },
            elementBox: { x: 0, y: 0, w: 0, h: 0 },
            expandBox: { x: 0, y: 0, w: 0, h: 0 },
            docBox: null
        };
    }

    /**
     * Build a subtree from a server item that already carries pre-expanded
     * children (compositors come back expanded one level).
     */
    function buildNode(data, parent) {
        var node = createNode(data, parent);
        if (data.children && data.children.length) {
            node.loaded = true;
            node.showChildElements = !!data.showChildElements;
            for (var i = 0; i < data.children.length; i++) {
                node.children.push(buildNode(data.children[i], node));
            }
        }
        return node;
    }

    function Diagram() {
        this.roots = [];
        this.scale = 1.0;
        this.options = {
            showDocumentation: false,
            showType: false,
            alwaysShowOccurence: false,
            compactLayoutDensity: false
        };
        this.boundingBox = { w: 100, h: 100 };
    }

    Diagram.prototype.padding = function () {
        return this.options.compactLayoutDensity ? PADDING_COMPACT : PADDING;
    };

    Diagram.prototype.clear = function () {
        this.roots = [];
        this.boundingBox = { w: 100, h: 100 };
    };

    Diagram.prototype.addRoot = function (data) {
        var node = buildNode(data, null);
        this.roots.push(node);
        return node;
    };

    // ---- Pass 1: measure ------------------------------------------------

    Diagram.prototype.generateMeasure = function (node) {
        var opt = this.options;
        var padding = this.padding();
        var data = node.data;

        if (node.parent) { node.depth = node.parent.depth + 1; }

        var size;
        if (data.itemType === 'group') {
            size = { w: GROUP_SIZE.w, h: GROUP_SIZE.h };
        } else {
            size = { w: DEFAULT_SIZE.w, h: DEFAULT_SIZE.h };
        }

        if (data.name && data.name.length > 0) {
            var label = (opt.showType && data.type) ? data.name + ':' + data.type : data.name;
            var w = measureWidth(label, FONT_NAME);
            size = { w: w, h: NAME_LINE_HEIGHT };
            size.w += 2 * MARGIN.w + (data.hasChildElements ? CHILD_EXPAND_BUTTON_SIZE : 0);
            size.h += 2 * MARGIN.h;
        }

        var childBBW = 0, childBBH = 0;
        if (node.showChildElements) {
            for (var i = 0; i < node.children.length; i++) {
                var child = node.children[i];
                this.generateMeasure(child);
                childBBW = Math.max(childBBW, child.bb.w);
                childBBH += child.bb.h;
            }
        }

        var bb = { x: 0, y: 0, w: 0, h: 0 };
        bb.w = size.w + 2 * padding.w + childBBW;
        bb.h = Math.max(size.h + 2 * padding.h, childBBH);

        var docBox = null;
        if (opt.showDocumentation && data.documentation) {
            if (size.w < DOCUMENTATION_MIN_WIDTH) {
                var off = DOCUMENTATION_MIN_WIDTH - size.w;
                size.w += off;
                bb.w += off;
            }
            var docTextWidth = measureWidth(data.documentation, FONT_DOC);
            var documentationWidth = Math.max(1.0, size.w + padding.w);
            var documentationHeight = (Math.ceil(docTextWidth / documentationWidth) + 1.8) * DOC_LINE_HEIGHT;
            docBox = { x: 0, y: 0, w: documentationWidth, h: documentationHeight };
            bb.h = Math.max(size.h + 2 * padding.h + docBox.h + 2 * padding.h, childBBH);
        }

        node.size = size;
        node.bb = bb;
        node.docBox = docBox;
        node.elementBox = {
            x: 0, y: 0,
            w: size.w - (data.hasChildElements ? CHILD_EXPAND_BUTTON_SIZE / 2 : 0),
            h: size.h
        };
        if (data.hasChildElements) {
            node.expandBox = {
                x: node.elementBox.w - CHILD_EXPAND_BUTTON_SIZE / 2,
                y: (node.elementBox.h - CHILD_EXPAND_BUTTON_SIZE) / 2,
                w: CHILD_EXPAND_BUTTON_SIZE,
                h: CHILD_EXPAND_BUTTON_SIZE
            };
        }
    };

    // ---- Pass 2: place --------------------------------------------------

    Diagram.prototype.generateLocation = function (node) {
        var opt = this.options;
        var padding = this.padding();

        node.location.x = node.bb.x + padding.w;
        node.location.y = node.bb.y + (node.bb.h - node.size.h) / 2;
        if (opt.showDocumentation && node.docBox) {
            node.location.y = node.bb.y + (node.bb.h - (2 * padding.h + node.docBox.h)) / 2;
        }

        if (node.showChildElements) {
            var childrenHeight = 0;
            for (var i = 0; i < node.children.length; i++) {
                childrenHeight += node.children[i].bb.h;
            }
            var childrenX = node.bb.x + 2 * padding.w + node.size.w;
            var childrenY = node.bb.y + Math.max(0, (node.bb.h - childrenHeight) / 2);
            for (var j = 0; j < node.children.length; j++) {
                var child = node.children[j];
                child.bb.x = childrenX;
                child.bb.y = childrenY;
                this.generateLocation(child);
                childrenY += child.bb.h;
            }
        }

        // Absolute boxes
        node.elementBoxAbs = {
            x: node.location.x, y: node.location.y,
            w: node.elementBox.w, h: node.elementBox.h
        };
        if (node.data.hasChildElements) {
            node.expandBoxAbs = {
                x: node.location.x + node.expandBox.x,
                y: node.location.y + node.expandBox.y,
                w: node.expandBox.w, h: node.expandBox.h
            };
        }
        if (node.docBox) {
            node.docBoxAbs = {
                x: node.location.x,
                y: node.location.y + node.elementBox.h + padding.h,
                w: node.docBox.w, h: node.docBox.h
            };
        }
    };

    // ---- Driver ---------------------------------------------------------

    Diagram.prototype.layout = function () {
        var padding = this.padding();
        var i, root;

        for (i = 0; i < this.roots.length; i++) {
            this.generateMeasure(this.roots[i]);
        }

        var maxRight = 100;
        var currentY = padding.h;
        for (i = 0; i < this.roots.length; i++) {
            root = this.roots[i];
            root.bb.x = padding.w;
            root.bb.y = currentY;
            this.generateLocation(root);
            currentY += root.bb.h;
            maxRight = Math.max(maxRight, root.bb.x + root.bb.w);
        }

        this.boundingBox = {
            w: maxRight + padding.w,
            h: currentY + padding.h
        };
    };

    /**
     * Depth first walk over the currently visible nodes.
     */
    Diagram.prototype.eachVisible = function (cb) {
        var stack = this.roots.slice().reverse();
        while (stack.length) {
            var node = stack.pop();
            cb(node);
            if (node.showChildElements) {
                for (var i = node.children.length - 1; i >= 0; i--) {
                    stack.push(node.children[i]);
                }
            }
        }
    };

    global.XSD = global.XSD || {};
    global.XSD.Diagram = Diagram;
    global.XSD.buildNode = buildNode;

    global.XSD.layoutConstants = {
        MARGIN: MARGIN, PADDING: PADDING, PADDING_COMPACT: PADDING_COMPACT,
        CHILD_EXPAND_BUTTON_SIZE: CHILD_EXPAND_BUTTON_SIZE,
        FONT_NAME: FONT_NAME, FONT_DOC: FONT_DOC,
        DOC_LINE_HEIGHT: DOC_LINE_HEIGHT
    };

})(window);
