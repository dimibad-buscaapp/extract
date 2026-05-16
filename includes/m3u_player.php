<?php

declare(strict_types=1);

require_once __DIR__ . '/m3u.php';
require_once __DIR__ . '/m3u_panel.php';

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
        'novelas', 'novelas 2', 'telenovelas', 'telenovela', 'anime', 'animes', 'dorama', 'doramas', 'doramas 2',
        'nacionais', 'internacionais', 'legendados', 'dublados', 'variados', 'outros', 'outras produtoras',
        'amazon prime video', 'apple tv plus', 'amc plus', 'discovery plus', 'discovery+',
        'amc', 'fx', 'syfy', 'bbc', 'national geographic',
    ];
    if (in_array($n, $exact, true)) {
        return true;
    }

    return (bool) preg_match(
        '#\b('
        . 'netflix|prime\s*video|amazon|disney|hbo|globoplay|paramount|star\+?|apple\s*tv'
        . '|crunchyroll|discovery|pluto|amc|national\s*geographic|outras\s*produtoras'
        . '|novelas?|telenovelas?|animes?|doramas?'
        . '|canais|24h'
        . '|ação|acao|com[eê]dias?|dramas?|terrores?|romances?|infantis?'
        . ')\b#iu',
        $p
    );
}

function extractor_m3u_normalize_category_label(string $part): string
{
    if (extractor_m3u_is_generic_group_label($part)) {
        return '';
    }
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
        'outras produtoras' => 'Outras produtoras',
        'amazon prime video' => 'Amazon Prime Video',
        'apple tv plus' => 'Apple TV Plus',
        'amc plus' => 'AMC Plus',
        'discovery plus' => 'Discovery Plus',
        'doramas 2' => 'Doramas',
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

function extractor_m3u_normalize_show_name(string $show): string
{
    $show = trim($show);
    $show = preg_replace('/\s+A\s+S[eé]rie(\s+Animada|\s+O\s+Musical)?\s*$/iu', '', $show) ?? $show;
    $show = preg_replace('/\s+The\s+Series\s*$/iu', '', $show) ?? $show;
    $show = preg_replace('/\s+Serie\s*$/iu', '', $show) ?? $show;

    return trim($show) !== '' ? trim($show) : 'Série';
}

function extractor_m3u_series_display_title(string $show, int $season, int $episode): string
{
    $show = extractor_m3u_normalize_show_name($show);

    return $show
        . ' - S' . str_pad((string) $season, 2, '0', STR_PAD_LEFT)
        . ' - E' . str_pad((string) $episode, 2, '0', STR_PAD_LEFT);
}

function extractor_m3u_season_folder_label(int $season): string
{
    $s = str_pad((string) $season, 2, '0', STR_PAD_LEFT);

    return 'Temporada ' . $s . ' (S' . $s . ')';
}

/**
 * group-title curto para players IPTV (só até plataforma — evita 1 categoria por episódio).
 *
 * @param list<string> $categories
 */
function extractor_m3u_series_group_title(array $categories): string
{
    $platform = $categories[0] ?? 'Sem grupo';

    return 'Séries / ' . $platform;
}

/**
 * Segmento do group-title que parece nome de série/episódio (não plataforma IPTV).
 */
function extractor_m3u_looks_like_episode_or_show_segment(string $part): bool
{
    $p = trim($part);
    if ($p === '' || extractor_m3u_is_metadata_part($p)) {
        return true;
    }
    if (preg_match('/\s+A\s+S[eé]rie\b/iu', $p)) {
        return true;
    }
    if (preg_match('/\b(?:temporada|season|epis[oó]dio)\b/iu', $p)) {
        return true;
    }
    if (extractor_m3u_is_series_main_category($p)) {
        return false;
    }

    return mb_strlen($p) > 38;
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
 * Monta hierarquia de série: Séries → categoria (group-title) → série → temporada.
 *
 * @param list<string> $parts
 * @return array{show: string, category: string, season: int|null}
 */
function extractor_m3u_series_path_panel(array $parts, ?array $parsed, string $fallbackTitle, string $fullGroup): array
{
    $category = extractor_m3u_panel_category_label($fullGroup);
    $seasonFromGroup = isset($parsed['season']) ? (int) $parsed['season'] : null;

    foreach ($parts as $p) {
        if (preg_match('#\b(?:temporada|season)\s*(\d{1,2})\b#iu', $p, $sm)) {
            $seasonFromGroup = (int) $sm[1];
            break;
        }
    }

    $show = trim((string) ($parsed['show'] ?? ''));
    if ($show === '') {
        foreach (array_reverse($parts) as $p) {
            if (extractor_m3u_is_metadata_part($p) || extractor_m3u_is_series_bucket($p)) {
                continue;
            }
            if (extractor_m3u_names_match($p, $category)) {
                continue;
            }
            if (extractor_m3u_looks_like_episode_or_show_segment($p) || mb_strlen($p) > 12) {
                $show = $p;
                break;
            }
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

    return [
        'show' => extractor_m3u_normalize_show_name($show),
        'category' => $category,
        'season' => $seasonFromGroup,
    ];
}

/**
 * @param array{title: string, url: string, group?: string, path?: string, logo?: string, kind?: string} $entry
 * @param array<string, 'live'|'movie'|'series'>|null $groupTypes
 * @return array{type: string, groups: list<string>, group_title: string, display_title: string, dedupe_key: string}|null
 */
function extractor_m3u_classify_as_series(array $entry, ?array $groupTypes = null): ?array
{
    $title = trim((string) ($entry['title'] ?? 'Item'));
    $url = trim((string) ($entry['url'] ?? ''));
    $groupEffective = extractor_m3u_entry_effective_group($entry);
    $groupKey = extractor_m3u_panel_group_key($groupEffective);
    $kind = (string) ($entry['kind'] ?? extractor_m3u_entry_kind($url, $title));
    $u = strtolower($url);
    $parts = extractor_m3u_split_group_path($groupEffective);

    $parsed = extractor_m3u_parse_series_title($title);
    $isSeriesUrl = (bool) preg_match('#/(series|serie|serials?)(/|$)#', $u);

    $type = $groupTypes[$groupKey] ?? extractor_m3u_detect_content_type($url, $title, $groupKey);
    if ($type !== 'series') {
        return null;
    }
    if ($kind !== 'vod' && !$isSeriesUrl && $parsed === null) {
        return null;
    }

    $path = extractor_m3u_series_path_panel($parts, $parsed, $title, $groupEffective);
    $show = $path['show'];
    $category = $path['category'];
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

    $extgrp = array_merge(
        ['Séries'],
        [$category],
        [$show, extractor_m3u_season_folder_label($season)]
    );

    return [
        'type' => 'series',
        'groups' => $extgrp,
        'group_title' => extractor_m3u_series_group_title([$category]),
        'display_title' => extractor_m3u_series_display_title($show, $season, $episode),
        'dedupe_key' => 's|' . md5(strtolower($show) . '|' . $season . '|' . $episode . '|' . $url),
    ];
}

/**
 * Classificação estilo painel M3U Aberta: tipo por grupo dominante + group-title como categoria.
 *
 * @param array{title: string, url: string, group?: string, path?: string, logo?: string, kind?: string} $entry
 * @param array<string, 'live'|'movie'|'series'>|null $groupTypes
 * @return array{type: string, groups: list<string>, group_title?: string, display_title: string, dedupe_key: string}
 */
function extractor_m3u_classify_player(array $entry, ?array $groupTypes = null): array
{
    $title = trim((string) ($entry['title'] ?? 'Item'));
    $url = trim((string) ($entry['url'] ?? ''));
    $groupEffective = extractor_m3u_entry_effective_group($entry);
    $groupKey = extractor_m3u_panel_group_key($groupEffective);
    $category = extractor_m3u_panel_category_label($groupEffective);
    $parts = array_values(array_filter(
        extractor_m3u_split_group_path($groupEffective),
        static fn (string $p): bool => !extractor_m3u_is_generic_group_label($p)
    ));

    $type = $groupTypes[$groupKey] ?? extractor_m3u_detect_content_type($url, $title, $groupKey);

    if ($type === 'series') {
        $series = extractor_m3u_classify_as_series($entry, $groupTypes);
        if ($series !== null) {
            return $series;
        }
    }

    if ($type === 'movie') {
        $groups = ['Filmes', $category];
        if (count($parts) >= 2 && preg_match('#\b(filmes?|movies?|cinema)\b#iu', $parts[0])) {
            $groups = $parts;
        }
        if ($title !== '' && strcasecmp((string) end($groups), $title) !== 0) {
            $groups[] = $title;
        }

        return [
            'type' => 'movie',
            'groups' => $groups,
            'group_title' => 'Filmes / ' . $category,
            'display_title' => $title,
            'dedupe_key' => 'm|' . md5($url),
        ];
    }

    $groups = $parts !== [] ? $parts : [$category];
    if (count($groups) === 1 && !preg_match('#\b(canais?|tv|live)\b#iu', $groups[0])) {
        $groups = ['Canais', $category];
    }

    return [
        'type' => 'live',
        'groups' => $groups,
        'group_title' => implode(' / ', array_slice($groups, 0, 2)) ?: ('Canais / ' . $category),
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
    $grp = str_replace('"', "'", (string) ($classified['group_title'] ?? implode(' / ', array_slice($path, 0, 2)) ?: 'Outras produtoras'));
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
