<?php

declare(strict_types=1);

/**
 * Turns XSD DOM nodes into the lightweight item structures consumed by the
 * front-end (one item == one box in the diagram) and resolves the children of a
 * node on demand. This is a PHP port of the relevant parts of
 * XSDDiagrams/Rendering/Diagram.cs (Add*, ExpandChildren, ExpandComplexType,
 * GetChildrenInfo) and XSDDiagrams/DiagramHelpers.cs (attribute resolution).
 */
final class DiagramBuilder
{
    public function __construct(private XsdSchema $schema)
    {
    }

    // ---------------------------------------------------------------------
    //  Roots
    // ---------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public function roots(): array
    {
        $items = [];
        foreach ($this->schema->roots() as $node) {
            $ns = $this->schema->namespaceOf($node);
            $item = $node->localName === 'complexType'
                ? $this->makeComplexTypeItem($node, $ns)
                : $this->makeElementItem($node, $ns);
            $item['kind'] = $node->localName === 'complexType' ? 'type' : 'element';
            $item['label'] = ($item['kind'] === 'type' ? 'type: ' : 'element: ') . $item['name']
                . ($ns !== '' ? '  (' . $ns . ')' : '');
            $items[] = $item;
        }

        return $items;
    }

    // ---------------------------------------------------------------------
    //  Expansion
    // ---------------------------------------------------------------------

    /**
     * Resolve the direct children of a node (compositors are pre-expanded one
     * level, exactly like the desktop ExpandChildren behaviour).
     *
     * @return list<array<string,mixed>>
     */
    public function expand(DOMElement $node, string $ns): array
    {
        return match (true) {
            $node->localName === 'element' => $this->expandElement($node, $ns),
            $node->localName === 'complexType' => $this->expandComplexType($node, $ns),
            in_array($node->localName, ['sequence', 'choice', 'all', 'group'], true) => $this->expandGroup($node, $ns),
            default => [],
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function expandElement(DOMElement $elNode, string $ns): array
    {
        $effective = $this->resolveElementRef($elNode);
        $inline = $this->firstXsChild($effective, 'complexType');
        if ($inline !== null) {
            return $this->expandComplexType($inline, $ns);
        }
        $type = $effective->getAttribute('type');
        if ($type !== '') {
            [$tns, $tlocal] = $this->schema->resolveQName($effective, $type);
            $typeNode = $this->schema->lookup('type', $tns, $tlocal);
            if ($typeNode !== null) {
                return $this->expandAnnotated($typeNode, $tns);
            }
        }

        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function expandComplexType(DOMElement $ctNode, string $ns): array
    {
        $result = [];
        foreach ($this->xsChildren($ctNode) as $child) {
            $local = $child->localName;
            if (in_array($local, ['sequence', 'choice', 'all', 'group'], true)) {
                $result[] = $this->compositorWithChildren($child, $local, $ns);
            } elseif ($local === 'complexContent') {
                $this->expandContent($child, $ns, $result);
            }
        }

        return $result;
    }

    /**
     * Handle complexContent extension/restriction (base chasing + own particles).
     *
     * @param list<array<string,mixed>> $result
     */
    private function expandContent(DOMElement $content, string $ns, array &$result): void
    {
        $extension = $this->firstXsChild($content, 'extension');
        $restriction = $this->firstXsChild($content, 'restriction');
        $derive = $extension ?? $restriction;
        if ($derive === null) {
            return;
        }

        $base = $derive->getAttribute('base');
        $baseHandled = false;
        if ($base !== '') {
            [$bns, $blocal] = $this->schema->resolveQName($derive, $base);
            $baseNode = $this->schema->lookup('type', $bns, $blocal);
            if ($baseNode !== null) {
                foreach ($this->expandAnnotated($baseNode, $bns) as $item) {
                    $result[] = $item;
                }
                $baseHandled = true;
            }
        }

        // Own particles declared inside the extension/restriction.
        $ownNs = $base !== '' ? ($bns ?? $ns) : $ns;
        if ($restriction !== null && $baseHandled) {
            return; // restriction shows the base content only when the base resolved
        }
        foreach ($this->xsChildren($derive) as $particle) {
            if (in_array($particle->localName, ['sequence', 'choice', 'all', 'group'], true)) {
                $result[] = $this->compositorWithChildren($particle, $particle->localName, $ownNs);
            }
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function expandAnnotated(DOMElement $node, string $ns): array
    {
        return match ($node->localName) {
            'element' => [$this->makeElementItem($node, $ns)],
            'group' => [$this->makeGroupItem($node, 'group', $ns)],
            'complexType' => $this->expandComplexType($node, $ns),
            default => [],
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function expandGroup(DOMElement $groupNode, string $ns): array
    {
        $effective = $groupNode;
        $effectiveNs = $ns;
        $ref = $groupNode->getAttribute('ref');
        if ($ref !== '') {
            [$rns, $rlocal] = $this->schema->resolveQName($groupNode, $ref);
            $refNode = $this->schema->lookup('group', $rns, $rlocal);
            if ($refNode !== null) {
                $effective = $refNode;
                $effectiveNs = $rns;
            }
        }

        $result = [];
        foreach ($this->xsChildren($effective) as $child) {
            $result[] = match ($child->localName) {
                'element' => $this->makeElementItem($child, $effectiveNs),
                'any' => $this->makeAnyItem($child, $effectiveNs),
                'group' => $this->makeGroupItem($child, 'group', $effectiveNs),
                'all' => $this->makeGroupItem($child, 'all', $effectiveNs),
                'choice' => $this->makeGroupItem($child, 'choice', $effectiveNs),
                'sequence' => $this->makeGroupItem($child, 'sequence', $effectiveNs),
                default => null,
            };
        }

        return array_values(array_filter($result, static fn ($item) => $item !== null));
    }

    /**
     * Build a compositor item with its first level of children already resolved.
     *
     * @return array<string,mixed>
     */
    private function compositorWithChildren(DOMElement $node, string $groupType, string $ns): array
    {
        $item = $this->makeGroupItem($node, $groupType, $ns);
        $item['children'] = $this->expandGroup($node, $ns);
        $item['showChildElements'] = true;

        return $item;
    }

    // ---------------------------------------------------------------------
    //  Item factories
    // ---------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public function makeElementItem(DOMElement $elNode, string $ns): array
    {
        $isReference = false;
        $effective = $elNode;
        $ref = $elNode->getAttribute('ref');
        if ($ref !== '') {
            $isReference = true;
            [$rns, $rlocal] = $this->schema->resolveQName($elNode, $ref);
            $refNode = $this->schema->lookup('element', $rns, $rlocal);
            if ($refNode !== null) {
                $effective = $refNode;
                $name = $refNode->getAttribute('name');
            } else {
                $name = $rlocal;
            }
        } else {
            $name = $elNode->getAttribute('name');
        }

        [$hasChildren, $isSimple] = $this->childrenInfoElement($effective);
        $doc = $this->annotationText($elNode) ?? ($effective !== $elNode ? $this->annotationText($effective) : null);

        return [
            'xsdid' => (int) $effective->getAttribute('xsdid'),
            'name' => $name,
            'namespace' => $ns,
            'type' => $this->typeAnnotation($effective),
            'itemType' => 'element',
            'groupType' => null,
            'minOccurrence' => $this->parseOccurs($elNode, 'minOccurs'),
            'maxOccurrence' => $this->parseOccurs($elNode, 'maxOccurs'),
            'isReference' => $isReference,
            'isSimpleContent' => $isSimple,
            'hasChildElements' => $hasChildren,
            'documentation' => $doc,
            'children' => null,
            'showChildElements' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function makeComplexTypeItem(DOMElement $ctNode, string $ns): array
    {
        [$hasChildren, $isSimple] = $this->childrenInfoComplexType($ctNode);

        return [
            'xsdid' => (int) $ctNode->getAttribute('xsdid'),
            'name' => $ctNode->getAttribute('name'),
            'namespace' => $ns,
            'type' => '',
            'itemType' => 'type',
            'groupType' => null,
            'minOccurrence' => 1,
            'maxOccurrence' => 1,
            'isReference' => false,
            'isSimpleContent' => $isSimple,
            'hasChildElements' => $hasChildren,
            'documentation' => $this->annotationText($ctNode),
            'children' => null,
            'showChildElements' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeGroupItem(DOMElement $groupNode, string $groupType, string $ns): array
    {
        $isReference = false;
        $effective = $groupNode;
        $name = '';
        $itemNs = $ns;
        $ref = $groupNode->getAttribute('ref');
        if ($ref !== '') {
            $isReference = true;
            [$rns, $rlocal] = $this->schema->resolveQName($groupNode, $ref);
            $name = $rlocal;
            $itemNs = $rns;
            $refNode = $this->schema->lookup('group', $rns, $rlocal);
            if ($refNode !== null) {
                $effective = $refNode;
            }
        } elseif ($groupType === 'group') {
            $name = $groupNode->getAttribute('name');
        }

        return [
            'xsdid' => (int) $effective->getAttribute('xsdid'),
            'name' => $name,
            'namespace' => $itemNs,
            'type' => '',
            'itemType' => 'group',
            'groupType' => $groupType,
            'minOccurrence' => $this->parseOccurs($groupNode, 'minOccurs'),
            'maxOccurrence' => $this->parseOccurs($groupNode, 'maxOccurs'),
            'isReference' => $isReference,
            'isSimpleContent' => false,
            'hasChildElements' => true,
            'documentation' => $this->annotationText($groupNode),
            'children' => null,
            'showChildElements' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function makeAnyItem(DOMElement $anyNode, string $ns): array
    {
        $nsAttr = $anyNode->getAttribute('namespace');

        return [
            'xsdid' => (int) $anyNode->getAttribute('xsdid'),
            'name' => 'any ' . ($nsAttr !== '' ? $nsAttr : '##any'),
            'namespace' => $ns,
            'type' => '',
            'itemType' => 'group',
            'groupType' => null,
            'minOccurrence' => $this->parseOccurs($anyNode, 'minOccurs'),
            'maxOccurrence' => $this->parseOccurs($anyNode, 'maxOccurs'),
            'isReference' => false,
            'isSimpleContent' => false,
            'hasChildElements' => false,
            'documentation' => $this->annotationText($anyNode),
            'children' => null,
            'showChildElements' => false,
        ];
    }

    // ---------------------------------------------------------------------
    //  GetChildrenInfo port
    // ---------------------------------------------------------------------

    /**
     * @return array{0:bool,1:bool} [hasChildren, isSimpleContent]
     */
    private function childrenInfoElement(DOMElement $elNode): array
    {
        $inline = $this->firstXsChild($elNode, 'complexType');
        if ($inline !== null) {
            return $this->childrenInfoComplexType($inline);
        }
        $type = $elNode->getAttribute('type');
        if ($type !== '') {
            [$tns, $tlocal] = $this->schema->resolveQName($elNode, $type);
            $typeNode = $this->schema->lookup('type', $tns, $tlocal);
            if ($typeNode !== null) {
                if ($typeNode->localName === 'simpleType') {
                    return [false, true];
                }
                return $this->childrenInfoComplexType($typeNode);
            }
        }

        return [false, true];
    }

    /**
     * @return array{0:bool,1:bool}
     */
    private function childrenInfoComplexType(DOMElement $ct): array
    {
        $mixed = $ct->getAttribute('mixed') === 'true';
        $hasSimpleContent = false;
        foreach ($this->xsChildren($ct) as $child) {
            $local = $child->localName;
            if (in_array($local, ['sequence', 'choice', 'all', 'group', 'complexType'], true)) {
                return [true, $mixed];
            }
            if ($local === 'complexContent') {
                $extension = $this->firstXsChild($child, 'extension');
                if ($extension !== null) {
                    $hasChildren = false;
                    foreach (['all', 'group', 'choice', 'sequence'] as $particle) {
                        if ($this->firstXsChild($extension, $particle) !== null) {
                            $hasChildren = true;
                            break;
                        }
                    }
                    if (!$hasChildren) {
                        $base = $extension->getAttribute('base');
                        if ($base !== '') {
                            [$bns, $blocal] = $this->schema->resolveQName($extension, $base);
                            $baseNode = $this->schema->lookup('type', $bns, $blocal);
                            if ($baseNode !== null && $baseNode->localName === 'complexType') {
                                return $this->childrenInfoComplexType($baseNode);
                            }
                        }
                    }
                    return [$hasChildren, $mixed];
                }
                return [true, $mixed];
            }
            if ($local === 'simpleContent') {
                $hasSimpleContent = true;
            }
        }

        return [false, $hasSimpleContent ? true : $mixed];
    }

    // ---------------------------------------------------------------------
    //  Node properties (right side panel)
    // ---------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public function properties(DOMElement $node, string $ns): array
    {
        $effective = $node;
        if ($node->localName === 'element') {
            $effective = $this->resolveElementRef($node);
        }

        $childElements = [];
        foreach ($this->expand($effective, $ns) as $item) {
            $this->collectElements($item, $childElements);
        }

        return [
            'name' => $effective->getAttribute('name') ?: $node->getAttribute('name'),
            'type' => $this->typeAnnotation($effective),
            'namespace' => $ns,
            'itemType' => $node->localName,
            'documentation' => $this->annotationText($node) ?? $this->annotationText($effective),
            'attributes' => $this->annotatedAttributes($effective, $ns),
            'childElements' => $childElements,
            'enumerations' => $this->enumerations($effective),
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param list<array<string,string>> $out
     */
    private function collectElements(array $item, array &$out): void
    {
        if (($item['itemType'] ?? '') === 'element') {
            $out[] = ['name' => (string) $item['name'], 'type' => (string) $item['type']];
            return;
        }
        foreach ((array) ($item['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectElements($child, $out);
            }
        }
    }

    /**
     * @return list<array<string,string>>
     */
    private function annotatedAttributes(DOMElement $node, string $ns): array
    {
        $list = [];
        if ($node->localName === 'element') {
            $inline = $this->firstXsChild($node, 'complexType');
            if ($inline !== null) {
                $this->complexTypeAttributes($inline, $ns, $list);
            } else {
                $type = $node->getAttribute('type');
                if ($type !== '') {
                    [$tns, $tlocal] = $this->schema->resolveQName($node, $type);
                    $typeNode = $this->schema->lookup('type', $tns, $tlocal);
                    if ($typeNode !== null && $typeNode->localName === 'complexType') {
                        $this->complexTypeAttributes($typeNode, $tns, $list);
                    }
                }
            }
        } elseif ($node->localName === 'complexType') {
            $this->complexTypeAttributes($node, $ns, $list);
        }

        return $list;
    }

    /**
     * @param list<array<string,string>> $list
     */
    private function complexTypeAttributes(DOMElement $ct, string $ns, array &$list): void
    {
        foreach ($this->xsChildren($ct) as $child) {
            switch ($child->localName) {
                case 'attribute':
                    $this->parseAttribute($child, $list);
                    break;
                case 'attributeGroup':
                    $this->parseAttributeGroup($child, $list);
                    break;
                case 'anyAttribute':
                    $list[] = ['name' => '*', 'type' => 'any', 'use' => '', 'default' => ''];
                    break;
                case 'complexContent':
                case 'simpleContent':
                    $derive = $this->firstXsChild($child, 'extension') ?? $this->firstXsChild($child, 'restriction');
                    if ($derive === null) {
                        break;
                    }
                    $base = $derive->getAttribute('base');
                    if ($base !== '') {
                        [$bns, $blocal] = $this->schema->resolveQName($derive, $base);
                        $baseNode = $this->schema->lookup('type', $bns, $blocal);
                        if ($baseNode !== null && $baseNode->localName === 'complexType') {
                            $this->complexTypeAttributes($baseNode, $bns, $list);
                        }
                    }
                    foreach ($this->xsChildren($derive) as $inner) {
                        if ($inner->localName === 'attribute') {
                            $this->parseAttribute($inner, $list);
                        } elseif ($inner->localName === 'attributeGroup') {
                            $this->parseAttributeGroup($inner, $list);
                        }
                    }
                    break;
            }
        }
    }

    /**
     * @param list<array<string,string>> $list
     */
    private function parseAttribute(DOMElement $attr, array &$list): void
    {
        $ref = $attr->getAttribute('ref');
        if ($ref !== '') {
            [$rns, $rlocal] = $this->schema->resolveQName($attr, $ref);
            $refNode = $this->schema->lookup('attribute', $rns, $rlocal);
            if ($refNode !== null) {
                $before = count($list);
                $this->parseAttribute($refNode, $list);
                $use = $attr->getAttribute('use');
                if ($use !== '' && count($list) > $before) {
                    $list[count($list) - 1]['use'] = $use;
                }
                return;
            }
            $list[] = ['name' => $rlocal, 'type' => '', 'use' => $attr->getAttribute('use') ?: 'optional', 'default' => ''];
            return;
        }

        $type = '';
        $typeAttr = $attr->getAttribute('type');
        if ($typeAttr !== '') {
            [, $type] = $this->schema->resolveQName($attr, $typeAttr);
        } else {
            $simpleType = $this->firstXsChild($attr, 'simpleType');
            if ($simpleType !== null) {
                $restriction = $this->firstXsChild($simpleType, 'restriction');
                if ($restriction !== null && $restriction->getAttribute('base') !== '') {
                    [, $type] = $this->schema->resolveQName($restriction, $restriction->getAttribute('base'));
                }
            }
        }

        $list[] = [
            'name' => $attr->getAttribute('name'),
            'type' => $type,
            'use' => $attr->getAttribute('use') ?: 'optional',
            'default' => $attr->getAttribute('default'),
        ];
    }

    /**
     * @param list<array<string,string>> $list
     */
    private function parseAttributeGroup(DOMElement $group, array &$list): void
    {
        $ref = $group->getAttribute('ref');
        if ($ref === '') {
            return;
        }
        [$rns, $rlocal] = $this->schema->resolveQName($group, $ref);
        $refNode = $this->schema->lookup('attributeGroup', $rns, $rlocal);
        if ($refNode === null) {
            return;
        }
        foreach ($this->xsChildren($refNode) as $child) {
            if ($child->localName === 'attribute') {
                $this->parseAttribute($child, $list);
            } elseif ($child->localName === 'attributeGroup') {
                $this->parseAttributeGroup($child, $list);
            }
        }
    }

    /**
     * @return list<array<string,string>>
     */
    private function enumerations(DOMElement $node): array
    {
        $simpleType = null;
        if ($node->localName === 'simpleType') {
            $simpleType = $node;
        } else {
            $simpleType = $this->firstXsChild($node, 'simpleType');
            if ($simpleType === null) {
                $type = $node->getAttribute('type');
                if ($type !== '') {
                    [$tns, $tlocal] = $this->schema->resolveQName($node, $type);
                    $typeNode = $this->schema->lookup('type', $tns, $tlocal);
                    if ($typeNode !== null && $typeNode->localName === 'simpleType') {
                        $simpleType = $typeNode;
                    }
                }
            }
        }
        if ($simpleType === null) {
            return [];
        }
        $restriction = $this->firstXsChild($simpleType, 'restriction');
        if ($restriction === null) {
            return [];
        }
        $values = [];
        foreach ($this->xsChildren($restriction) as $facet) {
            if ($facet->localName === 'enumeration') {
                $values[] = [
                    'value' => $facet->getAttribute('value'),
                    'documentation' => $this->annotationText($facet) ?? '',
                ];
            }
        }

        return $values;
    }

    // ---------------------------------------------------------------------
    //  Low level helpers
    // ---------------------------------------------------------------------

    private function resolveElementRef(DOMElement $elNode): DOMElement
    {
        $ref = $elNode->getAttribute('ref');
        if ($ref === '') {
            return $elNode;
        }
        [$rns, $rlocal] = $this->schema->resolveQName($elNode, $ref);
        $refNode = $this->schema->lookup('element', $rns, $rlocal);

        return $refNode ?? $elNode;
    }

    /**
     * @return list<DOMElement>
     */
    private function xsChildren(DOMElement $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === XSD_NS) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function firstXsChild(DOMElement $node, string $localName): ?DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === XSD_NS && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }

    private function typeAnnotation(DOMElement $node): string
    {
        $type = $node->getAttribute('type');
        if ($type === '') {
            return '';
        }
        $idx = strrpos($type, ':');

        return $idx === false ? $type : substr($type, $idx + 1);
    }

    private function parseOccurs(DOMElement $node, string $attr): int
    {
        $value = $node->hasAttribute($attr) ? trim($node->getAttribute($attr)) : '1';
        if ($value === '' ) {
            return 1;
        }

        return ctype_digit($value) ? (int) $value : -1;
    }

    private function annotationText(DOMElement $node): ?string
    {
        $annotation = $this->firstXsChild($node, 'annotation');
        if ($annotation === null) {
            return null;
        }
        $parts = [];
        foreach ($this->xsChildren($annotation) as $child) {
            if ($child->localName === 'documentation') {
                $text = trim(preg_replace('/\s+/', ' ', $child->textContent) ?? '');
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }
        $joined = trim(implode(' ', $parts));

        return $joined === '' ? null : $joined;
    }
}
