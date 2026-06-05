<?php

declare(strict_types=1);

/**
 * Loads an XSD document (from raw content or a URL), recursively resolves its
 * `xs:include` / `xs:import` dependencies and merges every schema into a single
 * wrapper DOM document. Each XML-Schema element receives a stable `xsdid`
 * attribute so individual particles can be addressed across requests.
 */
final class XsdLoader
{
    private DOMDocument $merged;
    private DOMElement $root;

    /** @var array<string,bool> resolved locations already loaded (cycle guard) */
    private array $loaded = [];

    /** @var list<string> non fatal problems collected while loading */
    private array $warnings = [];

    private int $documentCount = 0;

    public function __construct()
    {
        $this->merged = new DOMDocument('1.0', 'UTF-8');
        $this->root = $this->merged->createElement('schemas');
        $this->merged->appendChild($this->root);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Build the merged schema starting from raw XSD content.
     */
    public function loadFromString(string $content, string $baseUri): void
    {
        $this->loadSchema($content, $baseUri);
        $this->assignIds();
    }

    /**
     * Build the merged schema starting from a remote URL.
     */
    public function loadFromUrl(string $url): void
    {
        $content = $this->fetch($url);
        $this->loaded[$url] = true;
        $this->loadSchema($content, $url);
        $this->assignIds();
    }

    public function toXml(): string
    {
        return $this->merged->saveXML() ?: '';
    }

    private function loadSchema(string $content, string $baseUri): void
    {
        if (++$this->documentCount > XSD_MAX_DOCUMENTS) {
            $this->warnings[] = 'Se alcanzó el máximo de documentos de esquema (' . XSD_MAX_DOCUMENTS . ').';
            return;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok || $doc->documentElement === null) {
            throw new RuntimeException('El documento no es un XML válido: ' . $baseUri);
        }

        $schema = $doc->documentElement;
        if ($schema->namespaceURI !== XSD_NS || $schema->localName !== 'schema') {
            throw new RuntimeException('El documento no es un esquema XSD (falta el elemento xs:schema): ' . $baseUri);
        }

        $imported = $this->merged->importNode($schema, true);
        if (!$imported instanceof DOMElement) {
            throw new RuntimeException('No se pudo importar el esquema: ' . $baseUri);
        }
        $imported->setAttribute('data-base', $baseUri);
        $imported->setAttribute('data-tns', $schema->getAttribute('targetNamespace'));
        $this->root->appendChild($imported);

        $this->resolveDependencies($schema, $baseUri);
    }

    private function resolveDependencies(DOMElement $schema, string $baseUri): void
    {
        foreach ($schema->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->namespaceURI !== XSD_NS) {
                continue;
            }
            if ($child->localName !== 'include' && $child->localName !== 'import' && $child->localName !== 'redefine') {
                continue;
            }
            $location = $child->getAttribute('schemaLocation');
            if ($location === '') {
                continue;
            }

            $resolved = $this->resolveUri($location, $baseUri);
            if ($resolved === null) {
                $this->warnings[] = 'No se pudo resolver la dependencia: ' . $location;
                continue;
            }
            if (isset($this->loaded[$resolved])) {
                continue;
            }
            $this->loaded[$resolved] = true;

            try {
                $dependencyContent = $this->fetch($resolved);
                $this->loadSchema($dependencyContent, $resolved);
            } catch (Throwable $e) {
                $this->warnings[] = 'Dependencia omitida (' . $location . '): ' . $e->getMessage();
            }
        }
    }

    /**
     * Resolve a (possibly relative) schemaLocation against the base URI.
     */
    private function resolveUri(string $location, string $baseUri): ?string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (preg_match('#^https?://#i', $baseUri)) {
            return $this->joinUrl($baseUri, $location);
        }
        // Local base: only allow resolving siblings that actually exist.
        $candidate = dirname($baseUri) . '/' . $location;
        $real = realpath($candidate);

        return $real !== false ? $real : null;
    }

    private function joinUrl(string $base, string $relative): string
    {
        if (preg_match('#^https?://#i', $relative)) {
            return $relative;
        }
        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $relative;
        }
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';

        if (str_starts_with($relative, '/')) {
            $newPath = $relative;
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', $path) ?? '/';
            $newPath = $dir . $relative;
        }
        // Normalise ../ and ./ segments.
        $segments = [];
        foreach (explode('/', $newPath) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
    }

    /**
     * Fetch content from a local path or an http(s) URL with safety limits.
     */
    private function fetch(string $uri): string
    {
        if (preg_match('#^https?://#i', $uri)) {
            return $this->fetchRemote($uri);
        }
        if (!is_file($uri) || !is_readable($uri)) {
            throw new RuntimeException('Archivo no encontrado: ' . $uri);
        }
        if (filesize($uri) > XSD_MAX_BYTES) {
            throw new RuntimeException('El archivo excede el tamaño máximo permitido.');
        }
        $content = file_get_contents($uri);
        if ($content === false) {
            throw new RuntimeException('No se pudo leer el archivo: ' . $uri);
        }

        return $content;
    }

    private function fetchRemote(string $url): string
    {
        $this->assertSafeUrl($url);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => XSD_HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => XSD_HTTP_TIMEOUT,
            CURLOPT_USERAGENT => 'XSDDiagramWeb/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow) {
                return ($dlNow > XSD_MAX_BYTES || $dlTotal > XSD_MAX_BYTES) ? 1 : 0;
            },
        ]);

        $content = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($content === false) {
            throw new RuntimeException('Error de descarga: ' . ($error !== '' ? $error : 'desconocido'));
        }
        if ($code >= 400) {
            throw new RuntimeException('El servidor respondió con el código HTTP ' . $code . '.');
        }
        if (strlen((string) $content) > XSD_MAX_BYTES) {
            throw new RuntimeException('El recurso remoto excede el tamaño máximo permitido.');
        }

        return (string) $content;
    }

    /**
     * Basic SSRF mitigation: reject obviously private/reserved destinations.
     */
    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('URL inválida.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException('Solo se permiten URLs http(s).');
        }
        if (XSD_ALLOW_PRIVATE_HOSTS) {
            return;
        }

        $host = $parts['host'];
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            // Could not resolve to an IP; let curl try but it is suspicious.
            return;
        }
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            throw new RuntimeException('El destino apunta a una dirección privada o reservada.');
        }
    }

    /**
     * Walk every XML-Schema element and stamp a sequential, stable id.
     */
    private function assignIds(): void
    {
        $id = 0;
        $stack = [$this->root];
        while ($stack) {
            $node = array_pop($stack);
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->namespaceURI === XSD_NS) {
                    $child->setAttribute('xsdid', (string) $id++);
                }
                $stack[] = $child;
            }
        }
    }
}
