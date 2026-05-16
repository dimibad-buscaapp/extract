<?php

declare(strict_types=1);

require_once __DIR__ . '/m3u_player.php';

function extractor_m3u_tree_db_path(string $jobId): string
{
    $safe = preg_replace('/[^a-f0-9]/', '', strtolower($jobId));
    $dir = EXTRACTOR_DATA . '/m3u_jobs';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    return $dir . '/' . $safe . '.tree.sqlite';
}

function extractor_m3u_tree_db(string $jobId): PDO
{
    $pdo = new PDO('sqlite:' . extractor_m3u_tree_db_path($jobId));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            content_type TEXT NOT NULL,
            sort_platform TEXT NOT NULL DEFAULT "",
            sort_show TEXT NOT NULL DEFAULT "",
            sort_season INTEGER NOT NULL DEFAULT 0,
            sort_episode INTEGER NOT NULL DEFAULT 0,
            classified_json TEXT NOT NULL,
            entry_json TEXT NOT NULL,
            dedupe_key TEXT NOT NULL UNIQUE
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_items_series
         ON items(content_type, sort_platform, sort_show, sort_season, sort_episode)'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_items_other
         ON items(content_type, sort_platform)'
    );

    return $pdo;
}

/**
 * @param array{type: string, groups: list<string>, group_title?: string, display_title: string, dedupe_key: string} $classified
 * @param array{title: string, url: string, group?: string, logo?: string} $entry
 */
function extractor_m3u_tree_ingest(PDO $db, array $classified, array $entry): bool
{
    $type = (string) ($classified['type'] ?? '');
    $key = (string) ($classified['dedupe_key'] ?? '');
    if ($key === '') {
        return false;
    }

    $groups = array_values(array_filter(
        (array) ($classified['groups'] ?? []),
        static fn (string $s): bool => trim($s) !== ''
    ));

    $sortPlatform = 'Sem grupo';
    $sortShow = '';
    $sortSeason = 0;
    $sortEpisode = 0;

    if ($type === 'series') {
        $sortPlatform = (string) ($groups[1] ?? 'Sem grupo');
        $sortShow = (string) ($groups[2] ?? '');
        $seasonLabel = (string) ($groups[3] ?? '');
        if (preg_match('/(\d{1,2})/', $seasonLabel, $sm)) {
            $sortSeason = (int) $sm[1];
        }
        $disp = (string) ($classified['display_title'] ?? '');
        if (preg_match('/\bE(\d{1,3})\b/i', $disp, $em)) {
            $sortEpisode = (int) $em[1];
        }
    } elseif ($type === 'movie') {
        $sortPlatform = (string) ($groups[1] ?? ($groups[0] ?? 'Filmes'));
    } else {
        $sortPlatform = (string) ($groups[1] ?? $groups[0] ?? 'Canais ao vivo');
    }

    $st = $db->prepare(
        'INSERT OR IGNORE INTO items
        (content_type, sort_platform, sort_show, sort_season, sort_episode, classified_json, entry_json, dedupe_key)
        VALUES (?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        $type,
        $sortPlatform,
        $sortShow,
        $sortSeason,
        $sortEpisode,
        json_encode($classified, JSON_UNESCAPED_UNICODE),
        json_encode($entry, JSON_UNESCAPED_UNICODE),
        $key,
    ]);

    return $st->rowCount() > 0;
}

/**
 * Escreve M3U ordenado como player Xtream: canais → filmes → séries (plataforma → série → temporada).
 */
function extractor_m3u_tree_write_m3u(PDO $db, string $destPath): int
{
    file_put_contents($destPath, "#EXTM3U\n");
    $fh = fopen($destPath, 'ab');
    if ($fh === false) {
        throw new RuntimeException('Não foi possível criar o M3U convertido.');
    }

    $state = ['last' => []];
    $count = 0;

    $sections = [
        ['live', 'SELECT classified_json, entry_json FROM items WHERE content_type = ? ORDER BY sort_platform COLLATE NOCASE, json_extract(entry_json, "$.title") COLLATE NOCASE'],
        ['movie', 'SELECT classified_json, entry_json FROM items WHERE content_type = ? ORDER BY sort_platform COLLATE NOCASE, json_extract(entry_json, "$.title") COLLATE NOCASE'],
        ['series', 'SELECT classified_json, entry_json FROM items WHERE content_type = ? ORDER BY sort_platform COLLATE NOCASE, sort_show COLLATE NOCASE, sort_season, sort_episode'],
    ];

    foreach ($sections as [$type, $sql]) {
        $state['last'] = [];
        $st = $db->prepare($sql);
        $st->execute([$type]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $classified = json_decode((string) ($row['classified_json'] ?? ''), true);
            $entry = json_decode((string) ($row['entry_json'] ?? ''), true);
            if (!is_array($classified) || !is_array($entry)) {
                continue;
            }
            if (extractor_m3u_writer_append_player($fh, $state, $classified, $entry)) {
                $count++;
            }
        }
    }

    fclose($fh);

    return $count;
}

function extractor_m3u_tree_stats(PDO $db): array
{
    $out = ['movie' => 0, 'series' => 0, 'live' => 0];
    $st = $db->query('SELECT content_type, COUNT(*) AS c FROM items GROUP BY content_type');
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $t = (string) ($row['content_type'] ?? '');
        if (isset($out[$t])) {
            $out[$t] = (int) ($row['c'] ?? 0);
        }
    }

    return $out;
}
