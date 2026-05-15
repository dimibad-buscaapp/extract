<?php

declare(strict_types=1);

/**
 * Ficheiros descarregados com link público (token) — para M3U exportada noutro servidor IPTV.
 */
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/db.php';

$token = trim((string) ($_GET['t'] ?? ''));
$fileId = (int) ($_GET['f'] ?? 0);
if ($token === '' || $fileId < 1 || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(404);
    exit('Não encontrado');
}

$pdo = extractor_pdo();
$st = $pdo->prepare('SELECT local_path FROM files WHERE id = ? AND public_token = ?');
$st->execute([$fileId, $token]);
$row = $st->fetch();
if (!$row || empty($row['local_path'])) {
    http_response_code(404);
    exit('Não encontrado');
}

$path = (string) $row['local_path'];
$realOut = realpath(EXTRACTOR_DATA . '/out');
$realFile = realpath($path);
if ($realOut === false || $realFile === false || !is_file($realFile)) {
    http_response_code(404);
    exit('Não encontrado');
}
$prefix = $realOut . DIRECTORY_SEPARATOR;
if (!str_starts_with($realFile, $prefix)) {
    http_response_code(404);
    exit('Não encontrado');
}

$name = basename($realFile);
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$mime = 'application/octet-stream';
if (in_array($ext, ['mp4', 'm4v', 'webm'], true)) {
    $mime = 'video/mp4';
} elseif ($ext === 'mkv') {
    $mime = 'video/x-matroska';
} elseif ($ext === 'mp3') {
    $mime = 'audio/mpeg';
} elseif ($ext === 'ts') {
    $mime = 'video/mp2t';
}

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . (string) filesize($realFile));
if (isset($_SERVER['HTTP_RANGE'])) {
    $size = filesize($realFile);
    if ($size !== false && preg_match('/bytes=(\d+)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int) $m[1];
        $end = $m[2] !== '' ? (int) $m[2] : $size - 1;
        if ($start <= $end && $end < $size) {
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            header('Content-Length: ' . (string) ($end - $start + 1));
            $fh = fopen($realFile, 'rb');
            if ($fh !== false) {
                fseek($fh, $start);
                $left = $end - $start + 1;
                while ($left > 0 && !feof($fh)) {
                    $chunk = fread($fh, min(8192, $left));
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    $left -= strlen($chunk);
                }
                fclose($fh);
            }
            exit;
        }
    }
}

readfile($realFile);
exit;
