<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido.', 405);
}

try {
    $token = (string) request_param('token', '');
    $xsdid = request_param('xsdid', null);
    if ($token === '' || $xsdid === null || !ctype_digit($xsdid)) {
        json_error('Parámetros inválidos.');
    }

    $cache = new Cache();
    $xml = $cache->load($token);
    if ($xml === null) {
        json_error('La sesión del esquema expiró. Vuelve a cargar el XSD.', 410);
    }

    $schema = XsdSchema::fromXml($xml);
    $node = $schema->nodeById((int) $xsdid);
    if ($node === null) {
        json_error('Nodo no encontrado.', 404);
    }

    $builder = new DiagramBuilder($schema);
    $children = $builder->expand($node, $schema->namespaceOf($node));

    json_response(['ok' => true, 'children' => $children]);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
