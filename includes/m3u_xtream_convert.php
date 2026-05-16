<?php

declare(strict_types=1);

/**
 * Conversor Xtream — réplica do Litoral Flix (com.example.unitv).
 * Catálogo 100% via player_api.php; a M3U original só serve para detectar credenciais.
 */

require_once __DIR__ . '/m3u.php';
require_once __DIR__ . '/m3u_player.php';
require_once __DIR__ . '/m3u_xtream_tree.php';
require_once __DIR__ . '/m3u_job_seen.php';

/** Referência do app para validação (contagens típicas). */
const EXTRACTOR_XTREAM_REF_LIVE = 1117;
const EXTRACTOR_XTREAM_REF_MOVIES = 25938;
const EXTRACTOR_XTREAM_REF_SERIES_SHOWS = 7960;

/**
 * @return array{base_url: string, username: string, password: string}|null
 */
function extractor_m3u_xtream_detect_credentials(string $text): ?array
{
    $text = (string) $text;
    if ($text === '') {
        return null;
    }

    if (preg_match('~^(https?://[^/\s]+)/get\.php\?[^\s]*username=([^&\s]+)&password=([^&\s]+)~i', $text, $m)
        || preg_match('~^(https?://[^/\s]+)/get\.php\?[^\s]*password=([^&\s]+)&username=([^&\s]+)~i', $text, $m2)) {
        if (!isset($m)) {
            return [
                'base_url' => rtrim($m2[1], '/'),
                'username' => rawurldecode($m2[3]),
                'password' => rawurldecode($m2[2]),
            ];
        }

        return [
            'base_url' => rtrim($m[1], '/'),
            'username' => rawurldecode($m[2]),
            'password' => rawurldecode($m[3]),
        ];
    }

    if (preg_match('~(https?://[^/\s]+)/(?:live|movie|movies|vod|series)/([^/\s]+)/([^/\s]+)/\d+~i', $text, $m)) {
        return [
            'base_url' => rtrim($m[1], '/'),
            'username' => rawurldecode($m[2]),
            'password' => rawurldecode($m[3]),
        ];
    }

    return null;
}

function extractor_m3u_xtream_scan_credentials_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $cred = extractor_m3u_xtream_detect_credentials(extractor_m3u_xtream_sample_file($path, 524288));
    if ($cred !== null) {
        return $cred;
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    $chunk = '';
    while (!feof($fh) && strlen($chunk) < 3145728) {
        $chunk .= (string) fread($fh, 131072);
        $cred = extractor_m3u_xtream_detect_credentials($chunk);
        if ($cred !== null) {
            fclose($fh);

            return $cred;
        }
    }
    fclose($fh);

    return null;
}

function extractor_m3u_xtream_sample_file(string $path, int $maxBytes = 524288): string
{
    if (!is_file($path)) {
        return '';
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    $data = (string) fread($fh, $maxBytes);
    fclose($fh);

    return $data;
}

/**
 * @return list<array<string, mixed>>
 */
function extractor_m3u_xtream_rows_to_list(mixed $data): array
{
    if (!is_array($data)) {
        return [];
    }
    if ($data === []) {
        return [];
    }
    if (array_is_list($data)) {
        return $data;
    }
    foreach (['livestreams', 'vod_streams', 'series', 'episodes', 'data', 'streams'] as $key) {
        if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
            return $data[$key];
        }
    }
    $out = [];
    foreach ($data as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * @param array{base_url: string, username: string, password: string} $cred
 */
function extractor_m3u_xtream_api_url(array $cred, string $action, string $extra = ''): string
{
    return $cred['base_url'] . '/player_api.php?username=' . rawurlencode($cred['username'])
        . '&password=' . rawurlencode($cred['password'])
        . '&action=' . rawurlencode($action)
        . $extra;
}

function extractor_m3u_xtream_api_get(string $url, int $timeout = 120): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'LitoralFlix/1.0',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            return null;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "User-Agent: LitoralFlix/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || $body === '') {
            return null;
        }
    }

    $j = json_decode((string) $body, true);

    return is_array($j) ? $j : null;
}

/**
 * @param array{base_url: string, username: string, password: string} $cred
 */
function extractor_m3u_xtream_probe(array $cred): bool
{
    $j = extractor_m3u_xtream_api_get(extractor_m3u_xtream_api_url($cred, ''), 30);

    return is_array($j) && !empty($j['user_info'])
        && (string) ($j['user_info']['auth'] ?? '') === '1';
}

/**
 * @param array{base_url: string, username: string, password: string} $cred
 * @return list<array<string, mixed>>
 */
function extractor_m3u_xtream_api_list(array $cred, string $action, string $extra = ''): array
{
    $j = extractor_m3u_xtream_api_get(extractor_m3u_xtream_api_url($cred, $action, $extra), 180);

    return extractor_m3u_xtream_rows_to_list($j);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, string>
 */
function extractor_m3u_xtream_index_categories(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $id = (string) ($row['category_id'] ?? $row['categoryId'] ?? '');
        $name = trim((string) ($row['category_name'] ?? $row['categoryName'] ?? $row['name'] ?? ''));
        if ($id !== '' && $name !== '') {
            $out[$id] = $name;
        }
    }

    return $out;
}

/**
 * URL de play igual ao APK: live .m3u8, movie/series .mp4 (ou container_extension).
 *
 * @param array{base_url: string, username: string, password: string} $cred
 */
function extractor_m3u_xtream_build_play_url(array $cred, string $type, string $streamId, string $ext = ''): string
{
    $base = rtrim($cred['base_url'], '/');
    $user = rawurlencode($cred['username']);
    $pass = rawurlencode($cred['password']);
    $seg = match ($type) {
        'movie' => 'movie',
        'series' => 'series',
        default => 'live',
    };
    if ($ext === '') {
        $ext = $seg === 'live' ? 'm3u8' : 'mp4';
    }

    return $base . '/' . $seg . '/' . $user . '/' . $pass . '/' . $streamId . '.' . $ext;
}

function extractor_m3u_xtream_dedupe_key(string $type, string $streamId): string
{
    return $type . '|id|' . $streamId;
}

/**
 * @return array{type: string, groups: list<string>, group_title: string, display_title: string, dedupe_key: string}
 */
function extractor_m3u_xtream_row_live(string $category, string $name, string $url, string $logo): array
{
    $category = $category !== '' ? $category : 'Canais';

    return [
        'type' => 'live',
        'groups' => ['Canais', $category, $name],
        'group_title' => 'Canais / ' . $category,
        'display_title' => $name,
        'dedupe_key' => extractor_m3u_xtream_dedupe_key('live', extractor_m3u_xtream_id_from_url($url)),
    ];
}

/**
 * @return array{type: string, groups: list<string>, group_title: string, display_title: string, dedupe_key: string}
 */
function extractor_m3u_xtream_row_movie(string $category, string $name, string $url, string $logo): array
{
    $category = $category !== '' ? $category : 'Filmes';

    return [
        'type' => 'movie',
        'groups' => ['Filmes', $category, $name],
        'group_title' => 'Filmes / ' . $category,
        'display_title' => $name,
        'dedupe_key' => extractor_m3u_xtream_dedupe_key('movie', extractor_m3u_xtream_id_from_url($url)),
    ];
}

/**
 * @return array{type: string, groups: list<string>, group_title: string, display_title: string, dedupe_key: string}
 */
function extractor_m3u_xtream_row_series_episode(
    string $category,
    string $show,
    int $season,
    int $episode,
    string $url,
    string $logo
): array {
    $category = $category !== '' ? $category : 'Séries';

    return [
        'type' => 'series',
        'groups' => ['Séries', $category, $show, extractor_m3u_season_folder_label($season)],
        'group_title' => 'Séries / ' . $category,
        'display_title' => extractor_m3u_series_display_title($show, $season, $episode),
        'dedupe_key' => extractor_m3u_xtream_dedupe_key('series', extractor_m3u_xtream_id_from_url($url)),
    ];
}

function extractor_m3u_xtream_id_from_url(string $url): string
{
    if (preg_match('#/(\d+)(?:\.[a-z0-9]+)?(?:\?|$)#i', $url, $m)) {
        return $m[1];
    }

    return md5($url);
}

/**
 * @param array{base_url: string, username: string, password: string} $cred
 */
function extractor_m3u_xtream_ingest_live(PDO $treeDb, ?PDO $seenDb, array $cred): int
{
    $cats = extractor_m3u_xtream_index_categories(extractor_m3u_xtream_api_list($cred, 'get_live_categories'));
    $count = 0;
    foreach (extractor_m3u_xtream_api_list($cred, 'get_live_streams') as $row) {
        $sid = (string) ($row['stream_id'] ?? $row['streamId'] ?? $row['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $name = trim((string) ($row['name'] ?? 'Canal'));
        $cid = (string) ($row['category_id'] ?? $row['categoryId'] ?? '');
        $category = $cats[$cid] ?? 'Sem categoria';
        $icon = trim((string) ($row['stream_icon'] ?? $row['streamIcon'] ?? ''));
        $url = extractor_m3u_xtream_build_play_url($cred, 'live', $sid, 'm3u8');
        $entry = ['title' => $name, 'url' => $url, 'group' => $category, 'logo' => $icon];
        $classified = extractor_m3u_xtream_row_live($category, $name, $url, $icon);
        if ($seenDb !== null && !extractor_m3u_job_seen_is_new($seenDb, $classified['dedupe_key'])) {
            continue;
        }
        if (extractor_m3u_tree_ingest($treeDb, $classified, $entry)) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param array{base_url: string, username: string, password: string} $cred
 */
function extractor_m3u_xtream_ingest_movies(PDO $treeDb, ?PDO $seenDb, array $cred): int
{
    $cats = extractor_m3u_xtream_index_categories(extractor_m3u_xtream_api_list($cred, 'get_vod_categories'));
    $count = 0;
    foreach (extractor_m3u_xtream_api_list($cred, 'get_vod_streams') as $row) {
        $sid = (string) ($row['stream_id'] ?? $row['streamId'] ?? $row['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $name = trim((string) ($row['name'] ?? 'Filme'));
        $cid = (string) ($row['category_id'] ?? $row['categoryId'] ?? '');
        $category = $cats[$cid] ?? 'Sem categoria';
        $ext = trim((string) ($row['container_extension'] ?? $row['containerExtension'] ?? 'mp4'));
        $icon = trim((string) ($row['stream_icon'] ?? $row['streamIcon'] ?? ''));
        $url = extractor_m3u_xtream_build_play_url($cred, 'movie', $sid, $ext !== '' ? $ext : 'mp4');
        $entry = ['title' => $name, 'url' => $url, 'group' => $category, 'logo' => $icon];
        $classified = extractor_m3u_xtream_row_movie($category, $name, $url, $icon);
        if ($seenDb !== null && !extractor_m3u_job_seen_is_new($seenDb, $classified['dedupe_key'])) {
            continue;
        }
        if (extractor_m3u_tree_ingest($treeDb, $classified, $entry)) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param array{base_url: string, username: string, password: string} $cred
 * @return array<string, array{name: string, category: string, cover: string}>
 */
function extractor_m3u_xtream_load_series_meta(array $cred): array
{
    $seriesCats = extractor_m3u_xtream_index_categories(extractor_m3u_xtream_api_list($cred, 'get_series_categories'));
    $meta = [];
    foreach (extractor_m3u_xtream_api_list($cred, 'get_series') as $row) {
        $sid = (string) ($row['series_id'] ?? $row['seriesId'] ?? $row['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $cid = (string) ($row['category_id'] ?? $row['categoryId'] ?? '');
        $meta[$sid] = [
            'name' => trim((string) ($row['name'] ?? 'Série')),
            'category' => $seriesCats[$cid] ?? 'Sem categoria',
            'cover' => trim((string) ($row['cover'] ?? $row['stream_icon'] ?? '')),
        ];
    }

    return $meta;
}

/**
 * @param array{base_url: string, username: string, password: string} $cred
 * @param array<string, array{name: string, category: string, cover: string}> $seriesMeta
 */
function extractor_m3u_xtream_ingest_series_batch(
    PDO $treeDb,
    ?PDO $seenDb,
    array $cred,
    array $seriesMeta,
    array $seriesIds,
    int $offset,
    int $limit
): int {
    $slice = array_slice($seriesIds, $offset, $limit);
    $count = 0;
    foreach ($slice as $seriesId) {
        $meta = $seriesMeta[$seriesId] ?? ['name' => 'Série', 'category' => 'Sem categoria', 'cover' => ''];
        $info = extractor_m3u_xtream_api_get(
            extractor_m3u_xtream_api_url($cred, 'get_series_info', '&series_id=' . rawurlencode((string) $seriesId)),
            90
        );
        if (!is_array($info)) {
            continue;
        }
        $show = $meta['name'];
        $category = $meta['category'];
        $episodes = $info['episodes'] ?? [];
        if (!is_array($episodes)) {
            continue;
        }
        foreach ($episodes as $seasonKey => $eps) {
            if (!is_array($eps)) {
                continue;
            }
            $season = (int) preg_replace('/\D/', '', (string) $seasonKey);
            if ($season < 1) {
                $season = 1;
            }
            foreach ($eps as $ep) {
                if (!is_array($ep)) {
                    continue;
                }
                $eid = (string) ($ep['id'] ?? '');
                if ($eid === '') {
                    continue;
                }
                $epNum = max(1, (int) ($ep['episode_num'] ?? $ep['episodeNum'] ?? 1));
                $ext = trim((string) ($ep['container_extension'] ?? $ep['containerExtension'] ?? 'mp4'));
                $url = extractor_m3u_xtream_build_play_url($cred, 'series', $eid, $ext !== '' ? $ext : 'mp4');
                $entry = [
                    'title' => extractor_m3u_series_display_title($show, $season, $epNum),
                    'url' => $url,
                    'group' => $category,
                    'logo' => $meta['cover'],
                ];
                $classified = extractor_m3u_xtream_row_series_episode($category, $show, $season, $epNum, $url, $meta['cover']);
                if ($seenDb !== null && !extractor_m3u_job_seen_is_new($seenDb, $classified['dedupe_key'])) {
                    continue;
                }
                if (extractor_m3u_tree_ingest($treeDb, $classified, $entry)) {
                    $count++;
                }
            }
        }
    }

    return $count;
}

/**
 * Contagens como no app (sem carregar episódios).
 *
 * @param array{base_url: string, username: string, password: string} $cred
 * @return array{live: int, movie: int, series_shows: int, ok: bool}
 */
function extractor_m3u_xtream_api_counts(array $cred): array
{
    if (!extractor_m3u_xtream_probe($cred)) {
        return ['live' => 0, 'movie' => 0, 'series_shows' => 0, 'ok' => false];
    }

    return [
        'live' => count(extractor_m3u_xtream_api_list($cred, 'get_live_streams')),
        'movie' => count(extractor_m3u_xtream_api_list($cred, 'get_vod_streams')),
        'series_shows' => count(extractor_m3u_xtream_api_list($cred, 'get_series')),
        'ok' => true,
    ];
}

/**
 * Categorias para Analisar — só API (igual Litoral Flix).
 *
 * @return array{live: list<array{name: string, count: int}>, movie: list<array{name: string, count: int}>, series: list<array{name: string, count: int}>, api_ok: bool, counts?: array<string, int>}
 */
function extractor_m3u_xtream_analyze_from_api(array $cred): array
{
    if (!extractor_m3u_xtream_probe($cred)) {
        return ['live' => [], 'movie' => [], 'series' => [], 'api_ok' => false];
    }

    $out = ['live' => [], 'movie' => [], 'series' => [], 'api_ok' => true];

    $liveCats = extractor_m3u_xtream_index_categories(extractor_m3u_xtream_api_list($cred, 'get_live_categories'));
    $liveBuckets = [];
    foreach (extractor_m3u_xtream_api_list($cred, 'get_live_streams') as $row) {
        $cid = (string) ($row['category_id'] ?? $row['categoryId'] ?? '');
        $name = $liveCats[$cid] ?? 'Sem categoria';
        $liveBuckets[$name] = ($liveBuckets[$name] ?? 0) + 1;
    }
    foreach ($liveBuckets as $name => $count) {
        $out['live'][] = ['name' => $name, 'count' => $count];
    }

    $vodCats = extractor_m3u_xtream_index_categories(extractor_m3u_xtream_api_list($cred, 'get_vod_categories'));
    $movieBuckets = [];
    foreach (extractor_m3u_xtream_api_list($cred, 'get_vod_streams') as $row) {
        $cid = (string) ($row['category_id'] ?? $row['categoryId'] ?? '');
        $name = $vodCats[$cid] ?? 'Sem categoria';
        $movieBuckets[$name] = ($movieBuckets[$name] ?? 0) + 1;
    }
    foreach ($movieBuckets as $name => $count) {
        $out['movie'][] = ['name' => $name, 'count' => $count];
    }

    $seriesCats = extractor_m3u_xtream_index_categories(extractor_m3u_xtream_api_list($cred, 'get_series_categories'));
    $seriesBuckets = [];
    foreach (extractor_m3u_xtream_api_list($cred, 'get_series') as $row) {
        $cid = (string) ($row['category_id'] ?? $row['categoryId'] ?? '');
        $name = $seriesCats[$cid] ?? 'Sem categoria';
        $seriesBuckets[$name] = ($seriesBuckets[$name] ?? 0) + 1;
    }
    foreach ($seriesBuckets as $name => $count) {
        $out['series'][] = ['name' => $name, 'count' => $count];
    }

    foreach (['live', 'movie', 'series'] as $t) {
        usort($out[$t], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    }

    $out['counts'] = [
        'live' => array_sum($liveBuckets),
        'movie' => array_sum($movieBuckets),
        'series_shows' => array_sum($seriesBuckets),
    ];

    return $out;
}

/**
 * Início do Converter — só API.
 *
 * @return array<string, mixed>
 */
function extractor_m3u_xtream_prepare(string $m3uPath, string $jobId, PDO $treeDb, ?PDO $seenDb): array
{
    $cred = extractor_m3u_xtream_scan_credentials_file($m3uPath);
    if ($cred === null) {
        return [
            'api_ok' => false,
            'phase' => 'failed',
            'message' => 'Não encontrei credenciais Xtream na M3U (URL com /live/, /movie/ ou get.php?username=).',
        ];
    }
    if (!extractor_m3u_xtream_probe($cred)) {
        return [
            'api_ok' => false,
            'phase' => 'failed',
            'message' => 'Servidor Xtream não respondeu (player_api.php). Use a mesma lista que funciona no Litoral Flix.',
        ];
    }

    set_time_limit(300);
    $live = extractor_m3u_xtream_ingest_live($treeDb, $seenDb, $cred);
    $movie = extractor_m3u_xtream_ingest_movies($treeDb, $seenDb, $cred);
    $seriesMeta = extractor_m3u_xtream_load_series_meta($cred);
    $seriesIds = array_keys($seriesMeta);
    $metaPath = extractor_m3u_jobs_dir() . '/' . preg_replace('/[^a-f0-9]/', '', strtolower($jobId)) . '.series-meta.json';
    file_put_contents($metaPath, json_encode($seriesMeta, JSON_UNESCAPED_UNICODE), LOCK_EX);

    return [
        'api_ok' => true,
        'phase' => $seriesIds !== [] ? 'series' : 'done',
        'cred' => $cred,
        'series_ids' => $seriesIds,
        'series_meta_path' => $metaPath,
        'series_offset' => 0,
        'series_per_step' => 25,
        'live' => $live,
        'movie' => $movie,
        'series_shows' => count($seriesIds),
        'series_episodes' => 0,
        'ref' => [
            'live' => EXTRACTOR_XTREAM_REF_LIVE,
            'movie' => EXTRACTOR_XTREAM_REF_MOVIES,
            'series_shows' => EXTRACTOR_XTREAM_REF_SERIES_SHOWS,
        ],
        'message' => sprintf(
            'API Litoral Flix: %d canais, %d filmes, %d séries (ref. app: %d / %d / %d) — a carregar episódios…',
            $live,
            $movie,
            count($seriesIds),
            EXTRACTOR_XTREAM_REF_LIVE,
            EXTRACTOR_XTREAM_REF_MOVIES,
            EXTRACTOR_XTREAM_REF_SERIES_SHOWS
        ),
    ];
}

/**
 * @param array<string, mixed> $job
 */
function extractor_m3u_xtream_job_series_step(array &$job, PDO $treeDb, ?PDO $seenDb): bool
{
    $xt = (array) ($job['xtream'] ?? []);
    if (($xt['phase'] ?? '') !== 'series' || empty($xt['api_ok'])) {
        return true;
    }

    $cred = (array) ($xt['cred'] ?? []);
    if (empty($cred['base_url'])) {
        $job['xtream']['phase'] = 'failed';
        $job['xtream']['message'] = 'Credenciais Xtream perdidas no job.';

        return true;
    }

    $ids = (array) ($xt['series_ids'] ?? []);
    $meta = [];
    $metaPath = (string) ($xt['series_meta_path'] ?? '');
    if ($metaPath !== '' && is_file($metaPath)) {
        $decoded = json_decode((string) file_get_contents($metaPath), true);
        $meta = is_array($decoded) ? $decoded : [];
    }
    $offset = (int) ($xt['series_offset'] ?? 0);
    $limit = (int) ($xt['series_per_step'] ?? 25);

    if ($offset >= count($ids)) {
        $job['xtream']['phase'] = 'done';

        return true;
    }

    set_time_limit(180);
    $added = extractor_m3u_xtream_ingest_series_batch($treeDb, $seenDb, $cred, $meta, $ids, $offset, $limit);
    $job['written'] = (int) $job['written'] + $added;
    $job['stats']['series'] = (int) ($job['stats']['series'] ?? 0) + $added;
    $job['xtream']['series_offset'] = $offset + $limit;
    $job['xtream']['series_episodes'] = (int) ($job['xtream']['series_episodes'] ?? 0) + $added;
    $done = min($offset + $limit, count($ids));
    $job['xtream']['message'] = 'Episódios: série ' . $done . ' / ' . count($ids)
        . ' (' . (int) $job['stats']['series'] . ' episódios)';

    if ($job['xtream']['series_offset'] >= count($ids)) {
        $job['xtream']['phase'] = 'done';
        $live = (int) ($job['stats']['live'] ?? 0);
        $movie = (int) ($job['stats']['movie'] ?? 0);
        $eps = (int) ($job['stats']['series'] ?? 0);
        $shows = (int) ($job['xtream']['series_shows'] ?? 0);
        $job['xtream']['message'] = sprintf(
            'Concluído API: %d canais, %d filmes, %d séries, %d episódios na M3U',
            $live,
            $movie,
            $shows,
            $eps
        );
    }

    return false;
}

/**
 * @return 'live'|'movie'|'series'|null
 */
function extractor_m3u_xtream_type_from_url(string $url): ?string
{
    $u = strtolower($url);
    if (preg_match('#/(series)(/|$)#', $u)) {
        return 'series';
    }
    if (preg_match('#/(movie|movies|vod)(/|$)#', $u)) {
        return 'movie';
    }
    if (preg_match('#/(live)(/|$)#', $u)) {
        return 'live';
    }

    return null;
}
