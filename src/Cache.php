<?php

declare(strict_types=1);

/**
 * Very small token based cache that stores the merged XSD (a single XML
 * document with stable `xsdid` attributes) on disk so that follow-up requests
 * (expand / node) do not have to download and merge the schema again.
 */
final class Cache
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__) . '/storage/cache';
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    /**
     * Persist the merged schema XML and return the access token.
     */
    public function store(string $xml): string
    {
        $this->gc();
        $token = bin2hex(random_bytes(16));
        $path = $this->pathFor($token);
        if (file_put_contents($path, $xml, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo guardar el esquema en la caché.');
        }

        return $token;
    }

    /**
     * Load the merged schema XML for a token, or null when missing/invalid.
     */
    public function load(string $token): ?string
    {
        if (!$this->isValidToken($token)) {
            return null;
        }
        $path = $this->pathFor($token);
        if (!is_file($path)) {
            return null;
        }
        $xml = file_get_contents($path);

        return $xml === false ? null : $xml;
    }

    private function pathFor(string $token): string
    {
        return $this->dir . '/' . $token . '.xml';
    }

    private function isValidToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
    }

    /**
     * Remove cached schemas older than two hours to keep the folder tidy.
     */
    private function gc(): void
    {
        $threshold = time() - 7200;
        foreach (glob($this->dir . '/*.xml') ?: [] as $file) {
            if (@filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }
}
