<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function extractor_m3u_split_group_path(string $group): array
{
    $parts = preg_split('#\s*[\|/\\\\»›>\-–—]\s*#u', trim($group)) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
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
        '/^(.+?)\s*[-–—]\s*Temporada\s+(\d{1,2})\s*[-–—]\s*Ep(?:\.|is[oó]dio)?\s*(\d{1,3})\s*$/iu',
        '/\bS(\d{1,2})\s*E(\d{1,3})\b/iu',
        '/\b(\d{1,2})x(\d{1,3})\b/i',
    ];

    foreach ($patterns as $i => $pat) {
        if (!preg_match($pat, $title, $m)) {
            continue;
        }
        if ($i < 8) {
            return [
                'show' => trim($m[1]),
                'season' => (int) $m[2],
                'episode' => (int) $m[3],
            ];
        }
        $show = trim(preg_replace('/\s*[-–—]\s*S\d.*$/iu', '', $title) ?? $title);
        $show = trim(preg_replace('/\s+\d{1,2}x\d{1,3}.*$/i', '', $show) ?? $show);
        if ($show === '') {
            $show = trim($title);
        }

        return [
            'show' => $show,
            'season' => (int) $m[1],
            'episode' => (int) $m[2],
        ];
    }

    return null;
}

function extractor_m3u_series_display_title(string $show, int $season, int $episode): string
{
    return $show . ' - Temporada ' . $season . ' - Episódio ' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
}

/**
 * @param list<string> $parts
 * @return array{category: string, subcategory: string, subsub: string, show: string}|null
 */
function extractor_m3u_series_from_group_parts(array $parts, string $fallbackTitle): ?array
{
    $seriesIdx = null;
    foreach ($parts as $i => $p) {
        if (preg_match('#\b(s[eé]rie?s?|seriado|series|telenovela|novelas?|anime|dorama)\b#iu', $p)) {
            $seriesIdx = $i;
            break;
        }
    }
    if ($seriesIdx === null) {
        return null;
    }

    $category = $parts[0] ?? 'Séries';
    $rest = array_slice($parts, $seriesIdx + 1);
    $show = $rest[0] ?? $fallbackTitle;
    if ($show === '' || preg_match('#\b(s[eé]rie?s?|seriado)\b#iu', $show)) {
        $show = $fallbackTitle !== '' ? $fallbackTitle : 'Série';
    }
    $subsub = $rest[1] ?? '';
    if ($subsub !== '' && preg_match('#\b(temporada|season|t)\s*(\d+)#iu', $subsub, $tm)) {
        $subsub = 'Temporada ' . (int) $tm[2];
    } elseif ($subsub !== '' && !preg_match('#\b(temporada|season)\b#iu', $subsub)) {
        $subsub = '';
    }

    return [
        'category' => $category,
        'subcategory' => $show,
        'subsub' => $subsub,
        'show' => $show,
    ];
}

/**
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
    $kind = (string) ($entry['kind'] ?? extractor_m3u_entry_kind($url, $title));
    $u = strtolower($url);
    $parts = extractor_m3u_split_group_path($group);

    $series = extractor_m3u_parse_series_title($title);
    $fromGroup = extractor_m3u_series_from_group_parts($parts, $title);
    $isSeriesUrl = (bool) preg_match('#/(series|serie|serials?)(/|$)#', $u);
    $isSeriesKind = $kind === 'vod' && (
        $isSeriesUrl
        || $fromGroup !== null
        || preg_match('#\b(s[eé]rie?s?|seriado|telenovela|novelas?|anime|dorama)\b#iu', strtolower($group))
    );

    if ($series !== null || $isSeriesKind) {
        $show = $series['show'] ?? ($fromGroup['show'] ?? $title);
        $season = (int) ($series['season'] ?? 1);
        $episode = (int) ($series['episode'] ?? 0);

        if ($fromGroup !== null) {
            $category = $fromGroup['category'];
            $subcategory = $fromGroup['subcategory'] !== '' ? $fromGroup['subcategory'] : $show;
            if ($fromGroup['subsub'] !== '') {
                if (preg_match('/(\d+)/', $fromGroup['subsub'], $sm)) {
                    $season = (int) $sm[1];
                }
            }
        } elseif ($parts !== []) {
            $category = $parts[0];
            $subcategory = count($parts) > 1 ? $parts[1] : $show;
        } else {
            $category = 'Séries';
            $subcategory = $show;
        }

        if ($episode < 1 && preg_match('/\b(?:ep|eps|epis[oó]dio)\s*(\d{1,3})\b/iu', $title, $em)) {
            $episode = (int) $em[1];
        }
        if ($episode < 1 && preg_match('/\b(\d{1,3})\s*$/', $title, $em)) {
            $episode = (int) $em[1];
        }
        if ($episode < 1) {
            $episode = 1;
        }

        $subsub = 'Temporada ' . $season;
        $display = extractor_m3u_series_display_title($subcategory, $season, $episode);

        return [
            'type' => 'series',
            'category' => $category,
            'subcategory' => $subcategory,
            'subsub' => $subsub,
            'display_title' => $display,
            'dedupe_key' => 's|' . md5(strtolower($subcategory) . '|' . $season . '|' . $episode . '|' . $url),
        ];
    }

    $movieFromGroup = null;
    foreach ($parts as $i => $p) {
        if (preg_match('#\b(filmes?|movies?|cinema|vod)\b#iu', $p)) {
            $movieFromGroup = [
                'category' => $parts[0] ?? 'Filmes',
                'subcategory' => $parts[$i + 1] ?? '',
                'subsub' => $parts[$i + 2] ?? '',
            ];
            break;
        }
    }

    if ($movieFromGroup !== null
        || preg_match('#/(movie|movies|filme|filmes|vod)(/|$)#', $u)
        || ($kind === 'vod' && preg_match('#\.(mp4|mkv|avi|m4v|webm)(\?|$)#i', $u) && !preg_match('#/(live|stream)/#', $u))
        || preg_match('/\b(19|20)\d{2}\b/', $title)) {
        $category = $movieFromGroup['category'] ?? ($parts[0] ?? 'Filmes');
        $subcategory = trim((string) ($movieFromGroup['subcategory'] ?? ''));
        $subsub = trim((string) ($movieFromGroup['subsub'] ?? ''));

        return [
            'type' => 'movie',
            'category' => $category,
            'subcategory' => $subcategory,
            'subsub' => $subsub,
            'display_title' => $title,
            'dedupe_key' => 'm|' . md5($url),
        ];
    }

    if ($parts !== []) {
        $category = $parts[0];
        $subcategory = $parts[1] ?? '';
        $subsub = $parts[2] ?? '';
    } else {
        $category = $group !== '' ? $group : 'Canais ao vivo';
        $subcategory = '';
        $subsub = '';
    }

    return [
        'type' => 'live',
        'category' => $category,
        'subcategory' => $subcategory,
        'subsub' => $subsub,
        'display_title' => $title,
        'dedupe_key' => 'l|' . md5($url),
    ];
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
    $grpParts = array_filter([$classified['category'], $classified['subcategory']], static fn (string $s): bool => trim($s) !== '');
    $grp = str_replace('"', "'", implode(' / ', $grpParts) ?: (string) $classified['category']);
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
