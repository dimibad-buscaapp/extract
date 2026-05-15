<?php

declare(strict_types=1);

/**
 * Caminhos relativos à raiz do site (funciona na raiz do VPS ou numa subpasta, ex. public_html).
 */
function extractor_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = dirname($script);
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        $base = '';
    } else {
        $base = rtrim($dir, '/');
    }

    return $base;
}

function extractor_url(string $path = ''): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $base = extractor_base_path();
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }

    return ($base === '' ? '' : $base) . '/' . $path;
}

function extractor_redirect(string $path): never
{
    header('Location: ' . extractor_url($path));
    exit;
}

function extractor_absolute_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . extractor_url($path);
}

function extractor_prepare_runtime(): void
{
    foreach ([EXTRACTOR_DATA, EXTRACTOR_DATA . '/out', EXTRACTOR_DATA . '/sessions'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
    }
}
