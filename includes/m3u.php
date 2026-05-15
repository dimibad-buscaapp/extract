<?php

declare(strict_types=1);

/**
 * @return array{path: string, name: string}|null
 */
function extractor_m3u_resolve_path(PDO $pdo, int $playlistId, int $uid, bool $super): ?array
{
    if ($playlistId < 1) {
        return null;
    }
    $st = $pdo->prepare('SELECT user_id, file_name FROM m3u_playlists WHERE id = ?');
    $st->execute([$playlistId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    if (!$super && (int) ($row['user_id'] ?? 0) !== $uid) {
        return null;
    }
    $name = basename((string) ($row['file_name'] ?? ''));
    if ($name === '' || !preg_match('/^[a-zA-Z0-9._-]+\.m3u8?$/i', $name)) {
        return null;
    }
    $path = EXTRACTOR_DATA . '/' . $name;
    $realBase = realpath(EXTRACTOR_DATA);
    $realFile = realpath($path);
    if ($realBase === false || $realFile === false || !is_file($realFile)) {
        return null;
    }
    $prefix = $realBase . DIRECTORY_SEPARATOR;
    if (!str_starts_with($realFile, $prefix)) {
        return null;
    }

    return ['path' => $realFile, 'name' => $name];
}

function extractor_m3u_count_entries(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return 0;
    }
    $n = 0;
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line !== '' && preg_match('#^https?://#i', $line)) {
            $n++;
        }
    }
    fclose($fh);

    return $n;
}

/**
 * @return list<array{title: string, url: string}>
 */
function extractor_m3u_list_entries(string $path, int $offset = 0, int $limit = 100): array
{
    if (!is_file($path) || $limit < 1) {
        return [];
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    $out = [];
    $skipped = 0;
    $pendingTitle = '';
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#EXTINF:')) {
            $pos = strrpos($line, ',');
            $pendingTitle = $pos !== false ? trim(substr($line, $pos + 1)) : 'Canal';
            continue;
        }
        if ($line[0] === '#') {
            continue;
        }
        if (!preg_match('#^https?://#i', $line)) {
            continue;
        }
        if ($skipped < $offset) {
            $skipped++;
            $pendingTitle = '';
            continue;
        }
        $out[] = [
            'title' => $pendingTitle !== '' ? $pendingTitle : ('Canal ' . (count($out) + $offset + 1)),
            'url' => $line,
        ];
        $pendingTitle = '';
        if (count($out) >= $limit) {
            break;
        }
    }
    fclose($fh);

    return $out;
}

function extractor_m3u_register_playlist(PDO $pdo, int $userId, string $absolutePath, string $label, ?string $sourceUrl = null): int
{
    $real = realpath($absolutePath);
    if ($real === false || !is_file($real)) {
        throw new RuntimeException('Ficheiro M3U não encontrado.');
    }
    $realBase = realpath(EXTRACTOR_DATA);
    if ($realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('M3U fora da pasta data/.');
    }
    $fileName = basename($real);
    $bytes = (int) filesize($real);
    $count = extractor_m3u_count_entries($real);
    $now = time();

    $st = $pdo->prepare('SELECT id FROM m3u_playlists WHERE file_name = ?');
    $st->execute([$fileName]);
    $existing = $st->fetch();
    if ($existing) {
        $id = (int) $existing['id'];
        $pdo->prepare(
            'UPDATE m3u_playlists SET user_id=?, label=?, source_url=?, bytes=?, entry_count=?, created_at=? WHERE id=?'
        )->execute([
            $userId,
            $label,
            $sourceUrl,
            $bytes,
            $count,
            $now,
            $id,
        ]);

        return $id;
    }

    $pdo->prepare(
        'INSERT INTO m3u_playlists (user_id, label, file_name, source_url, bytes, entry_count, created_at) VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $userId,
        $label,
        $fileName,
        $sourceUrl,
        $bytes,
        $count,
        $now,
    ]);

    return (int) $pdo->lastInsertId();
}

function extractor_m3u_import_orphans(PDO $pdo, int $userId): int
{
    $imported = 0;
    foreach (glob(EXTRACTOR_DATA . '/*.m3u') ?: [] as $path) {
        $base = basename($path);
        if (!preg_match('/^lista(_xtream)?_/i', $base)) {
            continue;
        }
        $st = $pdo->prepare('SELECT id FROM m3u_playlists WHERE file_name = ?');
        $st->execute([$base]);
        if ($st->fetch()) {
            continue;
        }
        try {
            extractor_m3u_register_playlist($pdo, $userId, $path, $base, null);
            $imported++;
        } catch (Throwable) {
            continue;
        }
    }

    return $imported;
}
