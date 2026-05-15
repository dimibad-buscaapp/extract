<?php

declare(strict_types=1);

/**
 * @return 'vod'|'live'
 */
function extractor_m3u_entry_kind(string $url, string $title = ''): string
{
    $u = strtolower($url);
    $t = strtolower($title);

    if (preg_match('#/(live|stream|broadcast)(/|$)#', $u)) {
        return 'live';
    }
    if (preg_match('#/(movie|series|vod|filme|filmes|serie|series)(/|$)#', $u)) {
        return 'vod';
    }
    if (preg_match('#\.(mp4|mkv|avi|mov|wmv|flv|webm|mp3|m4v|pdf|zip|rar)(\?|$)#i', $u)) {
        return 'vod';
    }
    if (preg_match('#[?&]type=(movie|series|vod)#i', $u)) {
        return 'vod';
    }
    if (preg_match('#\.ts(\?|$)#', $u) && !preg_match('#/(movie|series|vod)/#', $u)) {
        return 'live';
    }
    if (preg_match('#\.m3u8(\?|$)#', $u) && preg_match('#/(live)/#', $u)) {
        return 'live';
    }
    if (str_contains($t, 'vod') || str_contains($t, 'filme') || str_contains($t, 'série') || str_contains($t, 'serie')) {
        return 'vod';
    }

    return 'live';
}

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

/**
 * @return array{title: string, group: string, logo: string}
 */
function extractor_m3u_parse_extinf(string $line): array
{
    $title = 'Item';
    $pos = strrpos($line, ',');
    if ($pos !== false) {
        $title = trim(substr($line, $pos + 1));
    }
    $group = 'Geral';
    if (preg_match('/group-title="([^"]*)"/i', $line, $m)) {
        $group = trim($m[1]) !== '' ? trim($m[1]) : 'Geral';
    }
    $logo = '';
    if (preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) {
        $logo = trim($m[1]);
    }

    return ['title' => $title, 'group' => $group, 'logo' => $logo];
}

/**
 * @param callable(array{title: string, url: string, kind: string, group: string, logo: string}): void $callback
 */
function extractor_m3u_foreach(string $path, callable $callback, string $filter = 'all'): void
{
    if (!is_file($path)) {
        return;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return;
    }
    $grpStack = [];
    $pendingMeta = ['title' => 'Item', 'group' => 'Geral', 'logo' => ''];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#EXTGRP:')) {
            $grpStack[] = trim(substr($line, 8));
            continue;
        }
        if (str_starts_with($line, '#EXTINF:')) {
            $pendingMeta = extractor_m3u_parse_extinf($line);
            continue;
        }
        if ($line[0] === '#') {
            continue;
        }
        if (!preg_match('#^https?://#i', $line)) {
            continue;
        }
        $kind = extractor_m3u_entry_kind($line, $pendingMeta['title']);
        if ($filter === 'vod' && $kind !== 'vod') {
            $pendingMeta = ['title' => 'Item', 'group' => 'Geral', 'logo' => ''];
            continue;
        }
        if ($filter === 'live' && $kind !== 'live') {
            $pendingMeta = ['title' => 'Item', 'group' => 'Geral', 'logo' => ''];
            continue;
        }
        $callback([
            'title' => $pendingMeta['title'] !== '' ? $pendingMeta['title'] : 'Item',
            'url' => $line,
            'kind' => $kind,
            'group' => extractor_m3u_catalog_group_resolve($grpStack, (string) ($pendingMeta['group'] ?? '')),
            'logo' => $pendingMeta['logo'],
            'path' => implode(' / ', $grpStack),
        ]);
        $pendingMeta = ['title' => 'Item', 'group' => 'Geral', 'logo' => ''];
        $grpStack = [];
    }
    fclose($fh);
}

/**
 * @return array{vod: int, live: int, total: int}
 */
function extractor_m3u_count_kinds(string $path): array
{
    $vod = 0;
    $live = 0;
    extractor_m3u_foreach($path, static function (array $e) use (&$vod, &$live): void {
        if ($e['kind'] === 'vod') {
            $vod++;
        } else {
            $live++;
        }
    });

    return ['vod' => $vod, 'live' => $live, 'total' => $vod + $live];
}

function extractor_m3u_count_entries(string $path): int
{
    return extractor_m3u_count_kinds($path)['total'];
}

/**
 * Estado de leitura incremental (byte_offset evita reler o ficheiro desde o início).
 *
 * @return array{byte_offset: int, pending_group: string, pending_meta: array{title: string, group: string, logo: string}}
 */
function extractor_m3u_read_state_default(): array
{
    return [
        'byte_offset' => 0,
        'pending_group' => '',
        'pending_meta' => ['title' => 'Item', 'group' => 'Geral', 'logo' => ''],
        'grp_stack' => [],
    ];
}

/**
 * Grupo resumido para catálogo / listagem (não o caminho completo até episódio).
 *
 * @param list<string> $stack
 */
function extractor_m3u_catalog_group_from_stack(array $stack, string $fallback = 'Geral'): string
{
    $stack = array_values(array_filter(array_map('trim', $stack), static fn (string $s): bool => $s !== ''));
    if ($stack === []) {
        return $fallback;
    }
    $root = $stack[0];
    if (preg_match('#\b(s[eé]rie?s?)\b#iu', $root)) {
        return isset($stack[1]) ? 'Séries / ' . $stack[1] : 'Séries';
    }

    return implode(' / ', array_slice($stack, 0, 2));
}

/**
 * @param list<string> $stack
 */
function extractor_m3u_catalog_group_resolve(array $stack, string $extinfGroup, string $fallback = 'Geral'): string
{
    $extinfGroup = trim($extinfGroup);
    if ($extinfGroup !== '' && str_contains($extinfGroup, '/')) {
        $parts = preg_split('#\s*/\s*#', $extinfGroup) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));
        if (count($parts) >= 2 && preg_match('#\b(s[eé]rie?s?)\b#iu', $parts[0])) {
            return $parts[0] . ' / ' . $parts[1];
        }
        if (count($parts) >= 1) {
            return implode(' / ', array_slice($parts, 0, 2));
        }
    }

    return extractor_m3u_catalog_group_from_stack($stack, $fallback);
}

/**
 * @param array{byte_offset: int, pending_group: string, pending_meta: array{title: string, group: string, logo: string}} $readState
 * @return array{
 *   entries: list<array{title: string, url: string, kind: string, group: string, logo: string}>,
 *   read_state: array{byte_offset: int, pending_group: string, pending_meta: array{title: string, group: string, logo: string}},
 *   eof: bool
 * }
 */
function extractor_m3u_read_batch(string $path, array $readState, int $limit, string $filter = 'all'): array
{
    if (!is_file($path) || $limit < 1) {
        return ['entries' => [], 'read_state' => $readState, 'eof' => true];
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return ['entries' => [], 'read_state' => $readState, 'eof' => true];
    }

    $byteOffset = max(0, (int) ($readState['byte_offset'] ?? 0));
    if ($byteOffset > 0) {
        fseek($fh, $byteOffset);
        fgets($fh);
    }

    $grpStack = (array) ($readState['grp_stack'] ?? []);
    $pendingMeta = (array) ($readState['pending_meta'] ?? ['title' => 'Item', 'group' => 'Geral', 'logo' => '']);
    $out = [];

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#EXTGRP:')) {
            $grpStack[] = trim(substr($line, 8));
            continue;
        }
        if (str_starts_with($line, '#EXTINF:')) {
            $pendingMeta = extractor_m3u_parse_extinf($line);
            continue;
        }
        if ($line[0] === '#') {
            continue;
        }
        if (!preg_match('#^https?://#i', $line)) {
            continue;
        }
        $kind = extractor_m3u_entry_kind($line, $pendingMeta['title']);
        if ($filter === 'vod' && $kind !== 'vod') {
            $pendingMeta = ['title' => 'Item', 'group' => 'Geral', 'logo' => ''];
            continue;
        }
        if ($filter === 'live' && $kind !== 'live') {
            $pendingMeta = ['title' => 'Item', 'group' => 'Geral', 'logo' => ''];
            continue;
        }
        $out[] = [
            'title' => $pendingMeta['title'] !== '' ? $pendingMeta['title'] : 'Item',
            'url' => $line,
            'kind' => $kind,
            'group' => extractor_m3u_catalog_group_resolve($grpStack, (string) ($pendingMeta['group'] ?? '')),
            'logo' => $pendingMeta['logo'],
            'path' => implode(' / ', $grpStack),
        ];
        $pendingMeta = ['title' => 'Item', 'group' => 'Geral', 'logo' => ''];
        $grpStack = [];
        if (count($out) >= $limit) {
            break;
        }
    }

    $eof = feof($fh);
    $readState = [
        'byte_offset' => (int) ftell($fh),
        'pending_group' => '',
        'pending_meta' => $pendingMeta,
        'grp_stack' => $grpStack,
    ];
    fclose($fh);

    return ['entries' => $out, 'read_state' => $readState, 'eof' => $eof];
}

/**
 * @return list<array{title: string, url: string, kind: string}>
 */
function extractor_m3u_list_entries(string $path, int $offset = 0, int $limit = 100, string $filter = 'all'): array
{
    if (!is_file($path) || $limit < 1) {
        return [];
    }
    $out = [];
    $skipped = 0;
    extractor_m3u_foreach($path, static function (array $e) use (&$out, &$skipped, $offset, $limit): void {
        if ($skipped < $offset) {
            $skipped++;

            return;
        }
        if (count($out) >= $limit) {
            return;
        }
        $out[] = $e;
    }, $filter);

    return $out;
}

/**
 * @param list<array{title: string, url: string}> $entries
 */
function extractor_m3u_format_playlist(array $entries): string
{
    $lines = ['#EXTM3U'];
    foreach ($entries as $e) {
        $title = str_replace(["\r", "\n", ','], ' ', (string) ($e['title'] ?? 'Item'));
        $title = trim($title) !== '' ? trim($title) : 'Item';
        $url = trim((string) ($e['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $lines[] = '#EXTINF:-1,' . $title;
        $lines[] = $url;
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Lista normalizada para players IPTV (grupos + metadados).
 *
 * @param list<array{title: string, url: string, kind?: string, group?: string, logo?: string}> $entries
 */
function extractor_m3u_format_playlist_catalog(array $entries): string
{
    $byGroup = [];
    foreach ($entries as $e) {
        $g = trim((string) ($e['group'] ?? ''));
        if ($g === '') {
            $g = ($e['kind'] ?? '') === 'vod' ? 'VOD' : 'Ao vivo';
        }
        $byGroup[$g][] = $e;
    }
    ksort($byGroup, SORT_NATURAL | SORT_FLAG_CASE);
    $lines = ['#EXTM3U'];
    foreach ($byGroup as $group => $items) {
        $lines[] = '#EXTGRP:' . str_replace(["\r", "\n"], ' ', $group);
        foreach ($items as $e) {
            $title = str_replace(["\r", "\n", ','], ' ', (string) ($e['title'] ?? 'Item'));
            $title = trim($title) !== '' ? trim($title) : 'Item';
            $url = trim((string) ($e['url'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $grp = str_replace('"', "'", $group);
            $logo = trim((string) ($e['logo'] ?? ''));
            $logoAttr = $logo !== '' ? ' tvg-logo="' . str_replace('"', "'", $logo) . '"' : '';
            $lines[] = '#EXTINF:-1 group-title="' . $grp . '"' . $logoAttr . ',' . $title;
            $lines[] = $url;
        }
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param list<string> $urls
 * @return list<array{title: string, url: string, kind: string}>
 */
function extractor_m3u_entries_by_urls(string $path, array $urls): array
{
    $want = array_flip(array_map('strval', $urls));
    if ($want === []) {
        return [];
    }
    $found = [];
    extractor_m3u_foreach($path, static function (array $e) use (&$found, $want): void {
        if (isset($want[$e['url']]) && !isset($found[$e['url']])) {
            $found[$e['url']] = $e;
        }
    });

    return array_values($found);
}

/**
 * @return list<array{title: string, url: string}>
 */
function extractor_m3u_map_local_files(PDO $pdo, int $userId, array $entries): array
{
    $st = $pdo->prepare(
        'SELECT id, public_token, source_url FROM files WHERE user_id = ? AND source_url = ? AND public_token IS NOT NULL AND public_token != \'\' ORDER BY id DESC LIMIT 1'
    );
    $out = [];
    foreach ($entries as $e) {
        $st->execute([$userId, $e['url']]);
        $row = $st->fetch();
        if (!$row) {
            continue;
        }
        $out[] = [
            'title' => $e['title'],
            'url' => extractor_absolute_url('media.php?t=' . rawurlencode((string) $row['public_token']) . '&f=' . (int) $row['id']),
        ];
    }

    return $out;
}

function extractor_m3u_register_playlist(
    PDO $pdo,
    int $userId,
    string $absolutePath,
    string $label,
    ?string $sourceUrl = null,
    ?int $entryCountOverride = null,
): int {
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
    if ($entryCountOverride !== null && $entryCountOverride > 0) {
        $count = $entryCountOverride;
    } else {
        $counts = extractor_m3u_count_kinds($real);
        $count = $counts['total'];
    }
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
        if (!preg_match('/^(lista(_xtream)?_|export_)/i', $base)) {
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

function extractor_file_ensure_public_token(PDO $pdo, int $fileId): string
{
    $st = $pdo->prepare('SELECT public_token FROM files WHERE id = ?');
    $st->execute([$fileId]);
    $row = $st->fetch();
    $tok = trim((string) ($row['public_token'] ?? ''));
    if ($tok !== '') {
        return $tok;
    }
    $tok = bin2hex(random_bytes(16));
    $pdo->prepare('UPDATE files SET public_token = ? WHERE id = ?')->execute([$tok, $fileId]);

    return $tok;
}
