<?php

declare(strict_types=1);

/**
 * Wraps the merged schema DOM and provides the lookups required to walk it:
 * an index of every global (top level) definition keyed by namespace + name,
 * QName resolution, and node retrieval by `xsdid`.
 */
final class XsdSchema
{
    private DOMDocument $doc;
    private DOMXPath $xpath;

    /** @var array<string,DOMElement> kind|namespace|local => node */
    private array $index = [];

    /** @var array<int,DOMElement> xsdid => node */
    private array $byId = [];

    private function __construct(DOMDocument $doc)
    {
        $this->doc = $doc;
        $this->xpath = new DOMXPath($doc);
        $this->buildIndex();
    }

    public static function fromXml(string $xml): self
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('El esquema almacenado en caché está dañado.');
        }

        return new self($doc);
    }

    public function document(): DOMDocument
    {
        return $this->doc;
    }

    /**
     * Resolve the "kind" used for a global definition. complexType and
     * simpleType share the "type" bucket, matching the original viewer.
     */
    private static function kindFor(string $localName): ?string
    {
        return match ($localName) {
            'element' => 'element',
            'complexType', 'simpleType' => 'type',
            'group' => 'group',
            'attribute' => 'attribute',
            'attributeGroup' => 'attributeGroup',
            default => null,
        };
    }

    private function buildIndex(): void
    {
        foreach ($this->doc->documentElement?->childNodes ?? [] as $schema) {
            if (!$schema instanceof DOMElement || $schema->namespaceURI !== XSD_NS || $schema->localName !== 'schema') {
                continue;
            }
            $tns = $schema->getAttribute('targetNamespace');
            foreach ($schema->childNodes as $def) {
                if (!$def instanceof DOMElement || $def->namespaceURI !== XSD_NS) {
                    continue;
                }
                if ($def->hasAttribute('xsdid')) {
                    $this->byId[(int) $def->getAttribute('xsdid')] = $def;
                }
                $kind = self::kindFor($def->localName);
                $name = $def->getAttribute('name');
                if ($kind === null || $name === '') {
                    continue;
                }
                $this->index[$kind . '|' . $tns . '|' . $name] = $def;
            }
        }
    }

    /**
     * Find an element by its xsdid (rebuilding the id map lazily for nested nodes).
     */
    public function nodeById(int $id): ?DOMElement
    {
        if (isset($this->byId[$id])) {
            return $this->byId[$id];
        }
        $nodes = $this->xpath->query('//*[@xsdid="' . $id . '"]');
        if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
            $node = $nodes->item(0);
            if ($node instanceof DOMElement) {
                $this->byId[$id] = $node;
                return $node;
            }
        }

        return null;
    }

    /**
     * Look up a global definition by kind + namespace + local name.
     */
    public function lookup(string $kind, string $namespace, string $local): ?DOMElement
    {
        return $this->index[$kind . '|' . $namespace . '|' . $local] ?? null;
    }

    /**
     * Resolve a QName attribute value to [namespaceURI, localName] using the
     * in-scope namespace declarations of the given context node.
     */
    public function resolveQName(DOMElement $context, string $value): array
    {
        $value = trim($value);
        if (str_contains($value, ':')) {
            [$prefix, $local] = explode(':', $value, 2);
            $ns = $context->lookupNamespaceURI($prefix) ?? '';
        } else {
            $local = $value;
            $ns = $context->lookupNamespaceURI(null) ?? '';
        }

        return [$ns, $local];
    }

    /**
     * The selectable root definitions (top level elements and complex types).
     *
     * @return list<DOMElement>
     */
    public function roots(): array
    {
        $roots = [];
        foreach ($this->doc->documentElement?->childNodes ?? [] as $schema) {
            if (!$schema instanceof DOMElement || $schema->localName !== 'schema') {
                continue;
            }
            foreach ($schema->childNodes as $def) {
                if (!$def instanceof DOMElement || $def->namespaceURI !== XSD_NS) {
                    continue;
                }
                if (($def->localName === 'element' || $def->localName === 'complexType') && $def->getAttribute('name') !== '') {
                    $roots[] = $def;
                }
            }
        }

        return $roots;
    }

    /**
     * Target namespace of the schema document that owns the given node.
     */
    public function namespaceOf(DOMElement $node): string
    {
        $current = $node;
        while ($current !== null) {
            if ($current->localName === 'schema' && $current->hasAttribute('data-tns')) {
                return $current->getAttribute('data-tns');
            }
            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        return '';
    }
}
