<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido.', 405);
}

try {
    $loader = new XsdLoader();
    $source = '';

    if (isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $tmp = (string) $_FILES['file']['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            json_error('Subida inválida.');
        }
        if (($_FILES['file']['size'] ?? 0) > XSD_MAX_BYTES) {
            json_error('El archivo excede el tamaño máximo permitido (' . (XSD_MAX_BYTES / 1048576) . ' MB).');
        }
        $content = file_get_contents($tmp);
        if ($content === false || trim($content) === '') {
            json_error('No se pudo leer el archivo subido.');
        }
        $name = (string) ($_FILES['file']['name'] ?? 'upload.xsd');
        $loader->loadFromString($content, $name);
        $source = $name;
    } else {
        $url = trim((string) request_param('url', ''));
        if ($url === '') {
            json_error('Proporciona un archivo XSD o una URL.');
        }
        if (!preg_match('#^https?://#i', $url)) {
            json_error('La URL debe comenzar con http:// o https://');
        }
        $loader->loadFromUrl($url);
        $source = $url;
    }

    $xml = $loader->toXml();
    $schema = XsdSchema::fromXml($xml);
    $builder = new DiagramBuilder($schema);
    $roots = $builder->roots();

    if (count($roots) === 0) {
        json_error('El esquema se cargó pero no contiene elementos ni tipos de nivel superior.');
    }

    $cache = new Cache();
    $token = $cache->store($xml);

    $namespaces = [];
    foreach ($schema->document()->documentElement?->childNodes ?? [] as $node) {
        if ($node instanceof DOMElement && $node->localName === 'schema') {
            $tns = $node->getAttribute('data-tns');
            if ($tns !== '' && !in_array($tns, $namespaces, true)) {
                $namespaces[] = $tns;
            }
        }
    }

    json_response([
        'ok' => true,
        'token' => $token,
        'source' => $source,
        'roots' => $roots,
        'namespaces' => $namespaces,
        'warnings' => $loader->warnings(),
    ]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 422);
}
