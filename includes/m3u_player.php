<?php

declare(strict_types=1);

/**
 * @return list<string>
 */
function extractor_m3u_split_group_path(string $group): array
{
    $parts = preg_split('#\s*[\|/\\\\»›>,;]+\s*#u', trim($group)) ?: [];

    return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
}

function extractor_m3u_is_series_bucket(string $part): bool
{
    return (bool) preg_match('#\b(s[eé]rie?s?|seriado|series)\b#iu', $part);
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
        . ')$#iu',
        $p
    );
}

/**
 * Plataformas / géneros principais (nível abaixo de «Séries») — não confundir com nome de série.
 */
function extractor_m3u_is_series_main_category(string $part): bool
{
    $p = trim($part);
    if ($p === '' || extractor_m3u_is_metadata_part($p)) {
        return false;
    }
    $n = mb_strtolower($p);

    static $exact = [
        'netflix', 'prime video', 'amazon prime', 'amazon', 'disney+', 'disney plus', 'disney',
        'hbo', 'hbo max', 'max', 'globoplay', 'globo play', 'paramount+', 'paramount plus', 'paramount',
        'star+', 'star plus', 'apple tv', 'apple tv+', 'crunchyroll', 'discovery+', 'pluto tv',
        'ação', 'acao', 'aventura', 'comédia', 'comedia', 'drama', 'terror', 'suspense', 'romance',
        'ficção científica', 'ficcao cientifica', 'sci-fi', 'fantasia', 'policial', 'guerra',
        'documentário', 'documentario', 'infantil', 'kids', 'família', 'familia',
        'novelas', 'telenovelas', 'telenovela', 'anime', 'animes', 'dorama', 'doramas',
        'nacionais', 'internacionais', 'legendados', 'dublados', 'variados', 'outros', 'geral',
        'amc', 'fx', 'syfy', 'bbc', 'national geographic',
    ];
    if (in_array($n, $exact, true)) {
        return true;
    }

    return (bool) preg_match(
        '#\b('
        . 'netflix|prime\s*video|amazon|disney|hbo|globoplay|paramount|star\+?|apple\s*tv'
        . '|crunchyroll|discovery|pluto|amc|national\s*geographic'
        . '|novelas?|telenovelas?|animes?|doramas?'
        . '|ação|acao|com[eê]dias?|dramas?|terrores?|romances?|infantis?'
        . ')\b#iu',
        $p
    );
}

function extractor_m3u_normalize_category_label(string $part): string
{
    $map = [
        'netflix' => 'Netflix',
        'prime video' => 'Prime Video',
        'amazon prime' => 'Amazon Prime',
        'disney+' => 'Disney+',
        'disney plus' => 'Disney+',
        'hbo max' => 'HBO Max',
        'globoplay' => 'Globoplay',
        'globo play' => 'Globoplay',
        'star+' => 'Star+',
        'apple tv+' => 'Apple TV+',
        'acao' => 'Ação',
        'ação' => 'Ação',
        'comedia' => 'Comédia',
        'comédia' => 'Comédia',
        'novelas' => 'Novelas',
        'telenovelas' => 'Novelas',
        'animes' => 'Anime',
        'geral' => 'Geral',
    ];
    $k = mb_strtolower(trim($part));

    return $map[$k] ?? (mb_strlen($part) > 2 ? mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1) : $part);
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
    $s = str_pad((string) $season, 2, '0', STR_PAD_LEFT);

    return 'Temporada ' . $s . ' (S' . $s . ')';
}

function extractor_m3u_episode_folder_label(int $episode): string
{
    $e = str_pad((string) $episode, 2, '0', STR_PAD_LEFT);

    return 'Episódio ' . $e . ' (E' . $e . ')';
}

function extractor_m3u_names_match(string $a, string $b): bool
{
    $a = mb_strtolower(trim($a));
    $b = mb_strtolower(trim($b));
    if ($a === '' || $b === '') {
        return false;
    }

    return $a === $b || str_starts_with($a, $b) || str_starts_with($b, $a);
}

/**
 * Separa plataforma/género (Netflix, Ação…) do nome da série (Smallville…).
 *
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

    $raw = $seriesIdx !== null ? array_slice($parts, $seriesIdx + 1) : $parts;
    $seasonFromGroup = null;
    $candidates = [];

    foreach ($raw as $p) {
        if (extractor_m3u_is_metadata_part($p)) {
            continue;
        }
        if (preg_match('#\b(?:temporada|season)\s*(\d{1,2})\b#iu', $p, $sm)) {
            $seasonFromGroup = (int) $sm[1];
            continue;
        }
        $candidates[] = $p;
    }

    $show = trim((string) ($parsed['show'] ?? ''));
    $categories = [];
    $showParts = [];

    foreach ($candidates as $p) {
        if ($show !== '' && extractor_m3u_names_match($p, $show)) {
            $showParts[] = $p;
            continue;
        }
        if (extractor_m3u_is_series_main_category($p)) {
            $categories[] = extractor_m3u_normalize_category_label($p);
            continue;
        }
        $showParts[] = $p;
    }

    if ($show === '' && $showParts !== []) {
        $show = (string) $showParts[array_key_last($showParts)];
        $showParts = array_slice($showParts, 0, -1);
    }

    foreach ($showParts as $p) {
        if (extractor_m3u_is_series_main_category($p)) {
            $categories[] = extractor_m3u_normalize_category_label($p);
        } elseif (!extractor_m3u_names_match($p, $show)) {
            $categories[] = $p;
        }
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

    $categories = array_values(array_unique(array_filter(
        $categories,
        static fn (string $c): bool => $c !== ''
            && !extractor_m3u_names_match($c, $show)
            && !extractor_m3u_is_metadata_part($c)
    )));

    if ($categories === []) {
        $categories = ['Geral'];
    }

    return [
        'show' => $show,
        'categories' => $categories,
        'season' => $seasonFromGroup,
    ];
}

/**
 * @param array{title: string, url: string, group?: string, logo?: string, kind?: string} $entry
 * @return array{type: string, groups: list<string>, display_title: string, dedupe_key: string}|null
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

    if ($parsed === null && !$isSeriesUrl && !$hasSeriesBucket) {
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

    $groups = array_merge(
        ['Séries'],
        $path['categories'],
        [
            $show,
            extractor_m3u_season_folder_label($season),
            extractor_m3u_episode_folder_label($episode),
        ]
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
 * @return array{type: string, groups: list<string>, display_title: string, dedupe_key: string}
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
        $middle = $movieIdx !== null ? array_slice($parts, $movieIdx + 1) : $parts;
        $middle = array_values(array_filter($middle, static fn (string $p): bool => !extractor_m3u_is_metadata_part($p)));
        $groups = array_merge(['Filmes'], $middle);
        if ($title !== '' && ($middle === [] || strcasecmp((string) end($middle), $title) !== 0)) {
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
    $grp = str_replace('"', "'", implode(' / ', $path) ?: 'Geral');
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
