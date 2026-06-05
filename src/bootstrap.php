<?php

declare(strict_types=1);

/**
 * Common constants, configuration and helpers shared by every API endpoint.
 */

const XSD_NS = 'http://www.w3.org/2001/XMLSchema';

// Maximum size (bytes) accepted for an uploaded or downloaded XSD document.
const XSD_MAX_BYTES = 8 * 1024 * 1024; // 8 MB

// Maximum number of schema documents resolved through include/import.
const XSD_MAX_DOCUMENTS = 60;

// Network timeout (seconds) when downloading a remote schema.
const XSD_HTTP_TIMEOUT = 15;

// When false, remote schemas resolving to private/loopback addresses are blocked
// (basic SSRF mitigation). Set to true only on trusted/offline environments.
const XSD_ALLOW_PRIVATE_HOSTS = false;

require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/XsdLoader.php';
require_once __DIR__ . '/XsdSchema.php';
require_once __DIR__ . '/DiagramBuilder.php';

/**
 * Emit a JSON response and stop execution.
 *
 * @param array<string,mixed> $payload
 */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Emit a JSON error and stop execution.
 */
function json_error(string $message, int $status = 400): never
{
    json_response(['ok' => false, 'error' => $message], $status);
}

/**
 * Read a request parameter from POST (form or JSON body) or GET.
 */
function request_param(string $name, ?string $default = null): ?string
{
    if (isset($_POST[$name])) {
        return is_string($_POST[$name]) ? $_POST[$name] : null;
    }
    if (isset($_GET[$name])) {
        return is_string($_GET[$name]) ? $_GET[$name] : null;
    }
    static $jsonBody = null;
    if ($jsonBody === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $jsonBody = is_array($decoded) ? $decoded : [];
    }
    if (array_key_exists($name, $jsonBody) && is_scalar($jsonBody[$name])) {
        return (string) $jsonBody[$name];
    }

    return $default;
}
