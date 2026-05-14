<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';

if (!extractor_logged_in()) {
    http_response_code(403);
    exit('Proibido');
}

/**
 * @return array{path: string, name: string}|null
 */
function extractor_download_resolve_path(PDO $pdo, int $uid, bool $super): ?array
{
    if (isset($_GET['id']) && (string) $_GET['id'] !== '') {
        $id = (int) $_GET['id'];
        if ($id < 1) {
            return null;
        }
        if ($super) {
            $st = $pdo->prepare('SELECT local_path FROM files WHERE id = ?');
            $st->execute([$id]);
        } else {
            $st = $pdo->prepare('SELECT local_path FROM files WHERE id = ? AND user_id = ?');
            $st->execute([$id, $uid]);
        }
        $row = $st->fetch();
        if (!$row || empty($row['local_path'])) {
            return null;
        }
        $path = (string) $row['local_path'];
        $realOut = realpath(EXTRACTOR_DATA . '/out');
        $realFile = realpath($path);
        if ($realOut === false || $realFile === false) {
            return null;
        }
        $prefix = $realOut . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realFile, $prefix)) {
            return null;
        }
        return ['path' => $realFile, 'name' => basename($realFile)];
    }

    if (!$super) {
        return null;
    }

    $f = isset($_GET['f']) ? basename((string) $_GET['f']) : '';
    if ($f === '' || str_contains($f, '..') || $f[0] === '.' || strcasecmp($f, 'app.sqlite') === 0) {
        return null;
    }
    $path = EXTRACTOR_DATA . '/' . $f;
    $realBase = realpath(EXTRACTOR_DATA);
    $realFile = realpath($path);
    if ($realBase === false || $realFile === false) {
        return null;
    }
    $prefix = $realBase . DIRECTORY_SEPARATOR;
    if (!str_starts_with($realFile, $prefix)) {
        return null;
    }
    if (str_ends_with(strtolower($realFile), 'app.sqlite')) {
        return null;
    }
    return ['path' => $realFile, 'name' => $f];
}

$pdo = extractor_pdo();
extractor_user_refresh_session($pdo);
$resolved = extractor_download_resolve_path($pdo, extractor_user_id(), extractor_is_super_master());
if ($resolved === null) {
    http_response_code(404);
    exit('Não encontrado');
}

$path = $resolved['path'];
$f = $resolved['name'];

if (!is_file($path)) {
    http_response_code(404);
    exit('Não encontrado');
}

$mime = 'application/octet-stream';
$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
if ($ext === 'm3u' || $ext === 'm3u8') {
    $mime = 'audio/x-mpegurl';
} elseif ($ext === 'json') {
    $mime = 'application/json';
} elseif ($ext === 'txt') {
    $mime = 'text/plain; charset=utf-8';
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $f . '"');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
