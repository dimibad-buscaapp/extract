<?php

declare(strict_types=1);

/**
 * Classificação estilo player IPTV / Xtream (filmes, séries agrupadas, canais).
 *
 * @param array{title: string, url: string, group?: string, logo?: string, kind?: string} $entry
 * @return array{
 *   type: string,
 *   category: string,
 *   subcategory: string,
 *   subsub: string,
 *   display_title: string,
 *   dedupe_key: string
 * }
 */
function extractor_m3u_classify_player(array $entry): array
{
    $title = trim((string) ($entry['title'] ?? 'Item'));
    $url = trim((string) ($entry['url'] ?? ''));
    $group = trim((string) ($entry['group'] ?? ''));
    $u = strtolower($url);
    $g = strtolower($group);

    $series = extractor_m3u_parse_series_title($title);
    if ($series !== null) {
        $show = $series['show'];
        $season = (int) $series['season'];
        $ep = (int) $series['episode'];

        return [
            'type' => 'series',
            'category' => 'Séries',
            'subcategory' => $show,
            'subsub' => 'Temporada ' . $season,
            'display_title' => 'Episódio ' . str_pad((string) $ep, 2, '0', STR_PAD_LEFT),
            'dedupe_key' => 's|' . md5(strtolower($show) . '|' . $season . '|' . $ep . '|' . $url),
        ];
    }

    if (preg_match('#/(series|serie|serials?)(/|$)#', $u) || preg_match('#\b(série|series|seriado)\b#iu', $g)) {
        $show = $title !== '' ? $title : 'Série';
        if (preg_match('/\s*[-–]\s*/', $show)) {
            $show = trim(explode('-', $show)[0]);
        }

        return [
            'type' => 'series',
            'category' => 'Séries',
            'subcategory' => $show,
            'subsub' => 'Outros episódios',
            'display_title' => $title,
            'dedupe_key' => 's|' . md5(strtolower($show) . '|' . $url),
        ];
    }

    if (preg_match('#/(movie|movies|filme|filmes|vod)(/|$)#', $u)
        || preg_match('#\b(filme|filmes|movies|cinema)\b#iu', $g)
        || (preg_match('#\.(mp4|mkv|avi|m4v|webm)(\?|$)#i', $u) && !preg_match('#/(live|stream)/#', $u))
        || preg_match('/\b(19|20)\d{2}\b/', $title)) {
        $cat = 'Filmes';
        if (preg_match('#\b(filme|filmes|movies?)\b#iu', $g)) {
            $cat = trim($group) !== '' ? $group : 'Filmes';
        }

        return [
            'type' => 'movie',
            'category' => $cat,
            'subcategory' => '',
            'subsub' => '',
            'display_title' => $title,
            'dedupe_key' => 'm|' . md5($url),
        ];
    }

    $liveCat = $group !== '' ? $group : 'Canais ao vivo';
    if (preg_match('#\b(canal|canais|tv|live|ao vivo)\b#iu', $g)) {
        $liveCat = $group;
    }

    return [
        'type' => 'live',
        'category' => $liveCat,
        'subcategory' => '',
        'subsub' => '',
        'display_title' => $title,
        'dedupe_key' => 'l|' . md5($url),
    ];
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

    return null;
}

/**
 * @param array{last: list<string>} $state
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
        [$classified['category'], $classified['subcategory'], $classified['subsub']],
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
    $grp = str_replace('"', "'", (string) $classified['category']);
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

function extractor_m3u_seen_load(string $seenPath): array
{
    if (!is_file($seenPath)) {
        return [];
    }
    $lines = file($seenPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_flip($lines ?: []);
}

function extractor_m3u_seen_add(string $seenPath, string $key): void
{
    file_put_contents($seenPath, $key . "\n", FILE_APPEND | LOCK_EX);
}
