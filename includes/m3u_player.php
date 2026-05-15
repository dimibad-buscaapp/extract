<?php

declare(strict_types=1);

/**
 * Separadores de pasta IPTV (não usar hífen — parte títulos como "Smallville - S01").
 *
 * @return list<string>
 */
function extractor_m3u_split_group_path(string $group): array
{
    $parts = preg_split('#\s*[\|/\\\\»›>,;]+\s*#u', trim($group)) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
}

function extractor_m3u_is_series_bucket(string $part): bool
{
    return (bool) preg_match('#\b(s[eé]rie?s?|seriado|series|telenovela|novelas?|anime|dorama)\b#iu', $part);
}

function extractor_m3u_is_metadata_part(string $part): bool
{
    $p = trim($part);
    if ($p === '') {
        return true;
    }

    return (bool) preg_match(
        '#^(?:'
        . 'S\d{1,2}(?:\s*E\d{1,3})?'
        . '|T\d{1,2}\s*E\d{1,3}'
        . '|\d{1,2}x\d{1,3}'
        . '|Temporada\s*\d+'
        . '|Season\s*\d+'
        . '|Ep(?:\.|is[oó]dio)?\s*\d+'
        . '|Epis[oó]dio\s*\d+'
        . '|\d{1,3}'
        . ')$#iu',
        $p
    );
}

/**
 * @return array{show: string, season: int, episode: int}|null
 */
function extractor_m3u_parse_series_title(string $title): ?array
{
    $title = trim($title);
    if ($title === '') {
        return null;
    }

    $patterns = [
        '/^(.+?)\s+S(\d{1,2})\s*E(\d{1,3})\s*$/iu',
        '/^(.+?)\s+(\d{1,2})x(\d{1,3})\s*$/iu',
        '/^(.+?)\s+T(\d{1,2})\s*E(\d{1,3})\s*$/iu',
        '/^(.+?)\s+Temporada\s+(\d{1,2})\s+Ep(?:\.|is[oó]dio)?\s*(\d{1,3})\s*$/iu',
        '/^(.+?)\s+Season\s+(\d{1,2})\s+Episode\s+(\d{1,3})\s*$/iu',
        '/^(.+?)\s*[-–—]\s*S(\d{1,2})\s*E(\d{1,3})\s*$/iu',
        '/^(.+?)\s*[-–—]\s*(\d{1,2})x(\d{1,3})\s*$/iu',
        '/^(.+?)\s*[-–—]\s*S(\d{1,2})\s*[-–—]\s*E(\d{1,3})\s*$/iu',
        '/^(.+?)\s*[-–—]\s*Temporada\s+(\d{1,2})\s*[-–—]\s*Ep(?:\.|is[oó]dio)?\s*(\d{1,3})\s*$/iu',
    ];

    foreach ($patterns as $pat) {
        if (preg_match($pat, $title, $m)) {
            return [
                'show' => trim($m[1]),
                'season' => (int) $m[2],
                'episode' => (int) $m[3],
            ];
        }
    }

    if (preg_match('/^(.+?)\s+S(\d{1,2})\s*E(\d{1,3})\b/iu', $title, $m)) {
        return [
            'show' => trim($m[1]),
            'season' => (int) $m[2],
            'episode' => (int) $m[3],
        ];
    }
    if (preg_match('/^(.+?)\s+(\d{1,2})x(\d{1,3})\b/i', $title, $m)) {
        return [
            'show' => trim($m[1]),
            'season' => (int) $m[2],
            'episode' => (int) $m[3],
        ];
    }

    return null;
}

function extractor_m3u_series_display_title(string $show, int $season, int $episode): string
{
    return $show
        . ' - S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT)
        . ' - E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
}

function extractor_m3u_season_folder_label(int $season): string
{
    return 'S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT);
}

/**
 * @param list<string> $parts
 * @return array{show: string, categories: list<string>, season: int|null}
 */
function extractor_m3u_series_path_from_group(array $parts, ?array $parsed, string $fallbackTitle): array
{
    $seriesIdx = null;
    foreach ($parts as $i => $p) {
        if (extractor_m3u_is_series_bucket($p)) {
            $seriesIdx = $i;
            break;
        }
    }

    $middle = $seriesIdx !== null ? array_slice($parts, $seriesIdx + 1) : $parts;
    $middle = array_values(array_filter($middle, static fn (string $p): bool => !extractor_m3u_is_metadata_part($p)));

    $show = trim((string) ($parsed['show'] ?? ''));
    $seasonFromGroup = null;

    if ($show !== '') {
        $middle = array_values(array_filter(
            $middle,
            static fn (string $p): bool => strcasecmp($p, $show) !== 0
                && !str_contains(strtolower($p), strtolower($show))
        ));
    }

    foreach ($middle as $i => $p) {
        if (preg_match('#\b(?:temporada|season)\s*(\d{1,2})\b#iu', $p, $sm)) {
            $seasonFromGroup = (int) $sm[1];
            unset($middle[$i]);
        }
    }
    $middle = array_values($middle);

    if ($show === '' && $middle !== []) {
        $show = (string) array_pop($middle);
    }
    if ($show === '') {
        $show = trim(preg_replace(
            '#\s*[-–—]?\s*(?:S\d{1,2}\s*E\d{1,3}|\d{1,2}x\d{1,3}|Temporada\s*\d+.*)$#iu',
            '',
            $fallbackTitle
        ) ?? $fallbackTitle);
    }
    if ($show === '') {
        $show = 'Série';
    }

    return [
        'show' => $show,
        'categories' => $middle,
        'season' => $seasonFromGroup,
    ];
}

/**
 * @param array{title: string, url: string, group?: string, logo?: string, kind?: string} $entry
 * @return array{
 *   type: string,
 *   groups: list<string>,
 *   display_title: string,
 *   dedupe_key: string
 * }|null
 */
function extractor_m3u_classify_as_series(array $entry): ?array
{
    $title = trim((string) ($entry['title'] ?? 'Item'));
    $url = trim((string) ($entry['url'] ?? ''));
    $group = trim((string) ($entry['group'] ?? ''));
    $kind = (string) ($entry['kind'] ?? extractor_m3u_entry_kind($url, $title));
    $u = strtolower($url);
    $parts = extractor_m3u_split_group_path($group);

    $parsed = extractor_m3u_parse_series_title($title);
    $isSeriesUrl = (bool) preg_match('#/(series|serie|serials?)(/|$)#', $u);
    $hasSeriesBucket = false;
    foreach ($parts as $p) {
        if (extractor_m3u_is_series_bucket($p)) {
            $hasSeriesBucket = true;
            break;
        }
    }

    if (!$isSeriesUrl && !$hasSeriesBucket && $parsed === null) {
        return null;
    }
    if ($kind !== 'vod' && !$isSeriesUrl) {
        return null;
    }

    $path = extractor_m3u_series_path_from_group($parts, $parsed, $title);
    $show = $path['show'];
    $season = (int) ($parsed['season'] ?? $path['season'] ?? 1);
    $episode = (int) ($parsed['episode'] ?? 0);

    if ($episode < 1 && preg_match('/\b(?:ep|eps|epis[oó]dio)\s*(\d{1,3})\b/iu', $title, $em)) {
        $episode = (int) $em[1];
    }
    if ($episode < 1 && preg_match('/\bE(\d{1,3})\b/i', $title, $em)) {
        $episode = (int) $em[1];
    }
    if ($episode < 1) {
        $episode = 1;
    }
    if ($season < 1) {
        $season = 1;
    }

    $categories = $path['categories'];
    $groups = array_merge(
        ['Séries'],
        $categories,
        [$show, extractor_m3u_season_folder_label($season)]
    );

    return [
        'type' => 'series',
        'groups' => $groups,
        'display_title' => extractor_m3u_series_display_title($show, $season, $episode),
        'dedupe_key' => 's|' . md5(strtolower($show) . '|' . $season . '|' . $episode . '|' . $url),
    ];
}

/**
 * @param array{title: string, url: string, group?: string, logo?: string, kind?: string} $entry
 * @return array{
 *   type: string,
 *   groups: list<string>,
 *   display_title: string,
 *   dedupe_key: string
 * }
 */
function extractor_m3u_classify_player(array $entry): array
{
    $series = extractor_m3u_classify_as_series($entry);
    if ($series !== null) {
        return $series;
    }

    $title = trim((string) ($entry['title'] ?? 'Item'));
    $url = trim((string) ($entry['url'] ?? ''));
    $group = trim((string) ($entry['group'] ?? ''));
    $kind = (string) ($entry['kind'] ?? extractor_m3u_entry_kind($url, $title));
    $u = strtolower($url);
    $parts = extractor_m3u_split_group_path($group);

    $movieIdx = null;
    foreach ($parts as $i => $p) {
        if (preg_match('#\b(filmes?|movies?|cinema)\b#iu', $p)) {
            $movieIdx = $i;
            break;
        }
    }

    $isMovie = $movieIdx !== null
        || preg_match('#/(movie|movies|filme|filmes|vod)(/|$)#', $u)
        || ($kind === 'vod' && preg_match('#\.(mp4|mkv|avi|m4v|webm)(\?|$)#i', $u) && !preg_match('#/(live|stream)/#', $u))
        || ($kind === 'vod' && preg_match('/\b(19|20)\d{2}\b/', $title));

    if ($isMovie) {
        $root = 'Filmes';
        $middle = $movieIdx !== null ? array_slice($parts, $movieIdx + 1) : $parts;
        $middle = array_values(array_filter($middle, static fn (string $p): bool => !extractor_m3u_is_metadata_part($p)));
        $groups = array_merge([$root], $middle);
        if ($title !== '' && ($middle === [] || strcasecmp(end($middle), $title) !== 0)) {
            $groups[] = $title;
        }

        return [
            'type' => 'movie',
            'groups' => $groups,
            'display_title' => $title,
            'dedupe_key' => 'm|' . md5($url),
        ];
    }

    $liveRoot = 'Canais ao vivo';
    $middle = $parts;
    $liveIdx = null;
    foreach ($parts as $i => $p) {
        if (preg_match('#\b(canais?|tv|live|ao\s*vivo)\b#iu', $p)) {
            $liveIdx = $i;
            break;
        }
    }
    if ($liveIdx !== null) {
        $liveRoot = $parts[$liveIdx];
        $middle = array_slice($parts, $liveIdx + 1);
    } elseif ($group !== '') {
        $liveRoot = $parts[0] ?? $group;
        $middle = array_slice($parts, 1);
    }
    $middle = array_values(array_filter($middle, static fn (string $p): bool => !extractor_m3u_is_metadata_part($p)));
    $groups = array_merge([$liveRoot], $middle);

    return [
        'type' => 'live',
        'groups' => $groups,
        'display_title' => $title,
        'dedupe_key' => 'l|' . md5($url),
    ];
}

/**
 * @param array{last: list<string>} $state
 * @param array{groups: list<string>, display_title: string} $classified
 */
function extractor_m3u_writer_append_player($fh, array &$state, array $classified, array $entry): bool
{
    if (!is_resource($fh)) {
        return false;
    }
    $url = trim((string) ($entry['url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }

    $path = array_values(array_filter(
        (array) ($classified['groups'] ?? []),
        static fn (string $s): bool => trim($s) !== ''
    ));
    $last = (array) ($state['last'] ?? []);
    $depth = count($path);
    for ($i = 0; $i < $depth; $i++) {
        if (!isset($last[$i]) || $last[$i] !== $path[$i]) {
            for ($j = $i; $j < $depth; $j++) {
                fwrite($fh, '#EXTGRP:' . str_replace(["\r", "\n"], ' ', $path[$j]) . "\n");
            }
            break;
        }
    }
    $state['last'] = $path;

    $title = str_replace(["\r", "\n", ','], ' ', (string) $classified['display_title']);
    $grpParts = $path;
    if (count($grpParts) > 3) {
        $grpParts = array_slice($path, 0, 3);
    }
    $grp = str_replace('"', "'", implode(' / ', $grpParts) ?: 'Geral');
    $logo = trim((string) ($entry['logo'] ?? ''));
    $logoAttr = $logo !== '' ? ' tvg-logo="' . str_replace('"', "'", $logo) . '"' : '';
    fwrite($fh, '#EXTINF:-1 group-title="' . $grp . '"' . $logoAttr . ',' . $title . "\n");
    fwrite($fh, $url . "\n");

    return true;
}

/**
 * @param array{last: list<string>} $state
 */
function extractor_m3u_writer_append_simple($fh, array &$state, array $entry): bool
{
    if (!is_resource($fh)) {
        return false;
    }
    $state['last'] = [];
    $url = trim((string) ($entry['url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    $title = str_replace(["\r", "\n", ','], ' ', (string) ($entry['title'] ?? 'Item'));

    fwrite($fh, '#EXTINF:-1,' . trim($title) . "\n");
    fwrite($fh, $url . "\n");

    return true;
}
