<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/branding.php';

$kind = (string) ($_GET['k'] ?? '');
if ($kind !== 'logo' && $kind !== 'favicon') {
    http_response_code(404);
    exit;
}

$path = extractor_branding_asset_path($kind);
if ($path === null) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Cache-Control: public, max-age=86400');
readfile($path);
