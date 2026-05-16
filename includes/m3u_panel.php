<?php

declare(strict_types=1);

require_once __DIR__ . '/m3u.php';

/**
 * Lógica de categorias alinhada ao importador M3U Aberta (processar.php).
 */

function extractor_m3u_panel_normalize_label(string $part): string
{
    if (extractor_m3u_is_generic_group_label($part)) {
        return '';
    }
    $map = [
        'netflix' => 'Netflix',
        'amazon prime video' => 'Amazon Prime Video',
        'prime video' => 'Prime Video',
        'disney+' => 'Disney+',
        'hbo max' => 'HBO Max',
        'globoplay' => 'Globoplay',
        'novelas' => 'Novelas',
        'doramas' => 'Doramas',
        'outras produtoras' => 'Outras Produtoras',
    ];
    $k = mb_strtolower(trim($part));

    return $map[$k] ?? (mb_strlen($part) > 2 ? mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1) : $part);
}

/**
 * @return 'live'|'movie'|'series'|null
 */
function extractor_m3u_detect_strong_type(string $url, string $name): ?string
{
    $urlLower = strtolower($url);

    if (str_contains($urlLower, '/series/')) {
        return 'series';
    }
    if (str_contains($urlLower, '/movie/') || str_contains($urlLower, '/movies/') || str_contains($urlLower, '/vod/')) {
        return 'movie';
    }
    if (str_contains($urlLower, '/live/')) {
        return 'live';
    }

    if (preg_match('/\bS\d{1,3}\s*E\d{1,4}\b/i', $name)
        || preg_match('/\b\d{1,2}x\d{1,3}\b/', $name)
        || preg_match('/s\d{1,2}e\d{1,2}/', $urlLower)
        || preg_match('/\b(temporada|season)\s*\d+/iu', $name)) {
        return 'series';
    }

    if (preg_match('/\((?:19|20)\d{2}\)/', $name)) {
        return 'movie';
    }
    if (preg_match('/\[(?:L|D|Dub|Leg|Dublado|Legendado|Nacional)\]/iu', $name)) {
        return 'movie';
    }
    if (preg_match('/\s-\s\d{4}\s*\(L\)/i', $name)) {
        return 'movie';
    }

    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    if (in_array($ext, ['mkv', 'mp4', 'avi', 'mov', 'm4v', 'webm', 'mpg', 'mpeg'], true)) {
        return 'movie';
    }
    if (in_array($ext, ['ts', 'm3u8'], true)) {
        return 'live';
    }

    return null;
}

/**
 * @return 'live'|'movie'|'series'|null
 */
function extractor_m3u_detect_by_group_keyword(string $group): ?string
{
    $g = mb_strtolower(trim($group));
    if ($g === '') {
        return null;
    }
    if (preg_match('/(s[eé]ries?|novelas?|temporadas?|anime|doramas?|epis[oó]dios?)/u', $g)) {
        return 'series';
    }
    if (preg_match('/(filmes?|movies?|lan[cç]amentos?|vod|dublad[oa]s?|legendad[oa]s?)/u', $g)) {
        return 'movie';
    }
    if (preg_match('/(canais?|aberta|ao\s*vivo|pay.?per.?view|ppv|jornal|notici|esportes?|futebol)/u', $g)) {
        return 'live';
    }

    return null;
}

/**
 * @return 'live'|'movie'|'series'
 */
function extractor_m3u_detect_content_type(string $url, string $name = '', string $group = ''): string
{
    $strong = extractor_m3u_detect_strong_type($url, $name);
    if ($strong !== null) {
        return $strong;
    }
    $kw = extractor_m3u_detect_by_group_keyword($group);

    return $kw ?? 'live';
}

/**
 * Tipo dominante por group-title (como no painel M3U Aberta).
 *
 * @param list<array{name?: string, title?: string, url?: string, group?: string}> $items
 * @return array<string, 'live'|'movie'|'series'>
 */
function extractor_m3u_compute_group_types(array $items): array
{
    $byGroup = [];
    foreach ($items as $it) {
        $g = trim((string) ($it['group'] ?? ''));
        if ($g === '' || extractor_m3u_is_generic_group_label($g)) {
            $g = 'Sem grupo';
        }
        $byGroup[$g][] = $it;
    }

    $types = [];
    foreach ($byGroup as $g => $list) {
        $counts = ['live' => 0, 'movie' => 0, 'series' => 0];
        foreach ($list as $it) {
            $name = (string) ($it['name'] ?? $it['title'] ?? '');
            $strong = extractor_m3u_detect_strong_type((string) ($it['url'] ?? ''), $name);
            if ($strong !== null) {
                $counts[$strong]++;
            }
        }
        arsort($counts);
        $top = (string) key($counts);
        if ($counts[$top] > 0) {
            $types[$g] = $top;
        } else {
            $kw = extractor_m3u_detect_by_group_keyword($g);
            $types[$g] = $kw ?? 'live';
        }
    }

    return $types;
}

/**
 * Uma passagem no ficheiro para mapa group-title → tipo (live/movie/series).
 *
 * @return array<string, 'live'|'movie'|'series'>
 */
function extractor_m3u_compute_group_types_file(string $path): array
{
    $items = [];
    extractor_m3u_foreach($path, static function (array $e) use (&$items): void {
        $g = trim((string) ($e['group'] ?? ''));
        $items[] = [
            'title' => (string) ($e['title'] ?? ''),
            'url' => (string) ($e['url'] ?? ''),
            'group' => ($g !== '' && !extractor_m3u_is_generic_group_label($g)) ? $g : 'Sem grupo',
        ];
    });

    return extractor_m3u_compute_group_types($items);
}

/**
 * Plataforma/género principal para listagem (Netflix, Prime, Ação…) — nunca título de série/episódio.
 *
 * @return 'live'|'movie'|'series'
 */
function extractor_m3u_analyze_bucket_name(string $group, string $type): string
{
    $parts = extractor_m3u_split_group_parts($group);
    $parts = array_values(array_filter($parts, static function (string $p): bool {
        if ($p === '' || extractor_m3u_is_generic_group_label($p)) {
            return false;
        }
        if (preg_match('#^(?:S\d|T\d|\d+x\d|Temporada|Season|Epis[oó]dio)#iu', $p)) {
            return false;
        }
        if (preg_match('#\b(epis[oó]dio|temporada)\s*\(\s*[SsEe]?0?#iu', $p)) {
            return false;
        }

        return true;
    }));

    if ($parts === []) {
        return 'Sem grupo';
    }

    if ($type === 'series') {
        foreach ($parts as $i => $p) {
            if (preg_match('#\b(s[eé]rie?s?|seriado)\b#iu', $p) && isset($parts[$i + 1])) {
                $plat = extractor_m3u_panel_normalize_label($parts[$i + 1]);

                return $plat !== '' ? $plat : $parts[$i + 1];
            }
        }
        foreach ($parts as $p) {
            if (extractor_m3u_panel_looks_like_platform($p)) {
                $plat = extractor_m3u_panel_normalize_label($p);

                return $plat !== '' ? $plat : $p;
            }
        }

        return extractor_m3u_panel_normalize_label($parts[0]) ?: $parts[0];
    }

    if ($type === 'movie') {
        foreach ($parts as $i => $p) {
            if (preg_match('#\b(filmes?|movies?|cinema)\b#iu', $p) && isset($parts[$i + 1])) {
                $plat = extractor_m3u_panel_normalize_label($parts[$i + 1]);

                return $plat !== '' ? $plat : $parts[$i + 1];
            }
        }

        return extractor_m3u_panel_normalize_label($parts[0]) ?: $parts[0];
    }

    foreach ($parts as $i => $p) {
        if (preg_match('#\b(canais?|tv|live|ao\s*vivo)\b#iu', $p) && isset($parts[$i + 1])) {
            $plat = extractor_m3u_panel_normalize_label($parts[$i + 1]);

            return $plat !== '' ? $plat : $parts[$i + 1];
        }
    }

    return extractor_m3u_panel_normalize_label($parts[0]) ?: $parts[0];
}

function extractor_m3u_panel_looks_like_platform(string $part): bool
{
    $n = mb_strtolower(trim($part));
    if ($n === '') {
        return false;
    }

    return (bool) preg_match(
        '#\b('
        . 'netflix|prime\s*video|amazon|disney|hbo|globoplay|paramount|star\+?|apple\s*tv'
        . '|crunchyroll|discovery|pluto|amc|funimation|lionsgate|claro|directv|sbt'
        . '|novelas?|telenovelas?|animes?|doramas?'
        . '|ação|acao|com[eê]dias?|dramas?|terrores?|outras\s*produtoras'
        . ')\b#iu',
        $n
    );
}

/**
 * Nome da categoria/subcategoria como no painel (preserva «Netflix», «Amazon Prime Video», etc.).
 */
function extractor_m3u_panel_category_label(string $group): string
{
    $group = trim($group);
    if ($group === '' || extractor_m3u_is_generic_group_label($group)) {
        return 'Sem grupo';
    }

    $parts = extractor_m3u_split_group_parts($group);
    if ($parts === []) {
        return 'Sem grupo';
    }

    if (count($parts) === 1) {
        $label = extractor_m3u_panel_normalize_label($parts[0]);

        return $label !== '' ? $label : $parts[0];
    }

    if (preg_match('#\b(s[eé]rie?s?|filmes?|movies?|cinema|canais?|tv|live|ao\s*vivo)\b#iu', $parts[0])) {
        if (isset($parts[1])) {
            $label = extractor_m3u_panel_normalize_label($parts[1]);

            return $label !== '' ? $label : $parts[1];
        }
    }

    $label = extractor_m3u_panel_normalize_label($parts[0]);

    return $label !== '' ? $label : $parts[0];
}

/**
 * Chave estável para lookup em group_types (alinha com compute_group_types).
 */
function extractor_m3u_panel_group_key(string $group): string
{
    $group = trim($group);
    if ($group === '' || extractor_m3u_is_generic_group_label($group)) {
        return 'Sem grupo';
    }

    return $group;
}

/**
 * Analisa categorias para UI (como m3u_aberta.php).
 *
 * @return array{live: list<array{name: string, count: int}>, movie: list<array{name: string, count: int}>, series: list<array{name: string, count: int}>}
 */
function extractor_m3u_analyze_categories(string $path): array
{
    require_once __DIR__ . '/m3u_xtream_convert.php';
    $cred = extractor_m3u_xtream_scan_credentials_file($path);
    if ($cred !== null) {
        $fromApi = extractor_m3u_xtream_analyze_from_api($cred);
        if (!empty($fromApi['api_ok'])) {
            return $fromApi;
        }
    }

    $buckets = ['live' => [], 'movie' => [], 'series' => []];

    extractor_m3u_foreach($path, static function (array $e) use (&$buckets): void {
        $url = (string) ($e['url'] ?? '');
        $title = (string) ($e['title'] ?? '');
        $group = trim((string) ($e['group'] ?? ''));
        $type = extractor_m3u_detect_strong_type($url, $title)
            ?? extractor_m3u_detect_by_group_keyword($group)
            ?? extractor_m3u_detect_content_type($url, $title, $group);
        if (!isset($buckets[$type])) {
            $type = 'live';
        }
        $name = extractor_m3u_analyze_bucket_name($group, $type);
        $buckets[$type][$name] = ($buckets[$type][$name] ?? 0) + 1;
    });

    $out = ['live' => [], 'movie' => [], 'series' => []];
    foreach ($buckets as $type => $map) {
        foreach ($map as $name => $count) {
            $out[$type][] = [
                'name' => $name,
                'count' => (int) $count,
            ];
        }
        usort($out[$type], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    }

    $out['api_ok'] = false;

    return $out;
}
