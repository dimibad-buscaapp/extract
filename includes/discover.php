<?php

declare(strict_types=1);

/** @return list<string> */
function extractor_interesting_extensions(): array
{
    return ['pdf', 'zip', 'apk', 'mp4', 'webm', 'mkv', 'm3u8', 'm3u', 'ts', 'mp3', 'png', 'jpg', 'jpeg', 'gif', 'json', 'xml', 'txt', 'csv', 'doc', 'docx'];
}

function extractor_url_host(string $url): ?string
{
    $h = parse_url($url, PHP_URL_HOST);
    return is_string($h) ? strtolower($h) : null;
}

function extractor_normalize_url(string $base, string $href): ?string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
        return null;
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    if (str_starts_with($href, '//')) {
        $s = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $s . ':' . $href;
    }
    $b = parse_url($base);
    if (!$b || empty($b['scheme']) || empty($b['host'])) {
        return null;
    }
    $scheme = $b['scheme'];
    $host = $b['host'];
    $port = isset($b['port']) ? ':' . $b['port'] : '';
    $path = $b['path'] ?? '/';
    if (str_starts_with($href, '/')) {
        return $scheme . '://' . $host . $port . $href;
    }
    if (!str_ends_with($path, '/')) {
        $path = dirname($path);
        if ($path === '.' || $path === '\\') {
            $path = '/';
        }
    }
    $path = rtrim($path, '/') . '/' . $href;
    return $scheme . '://' . $host . $port . $path;
}

function extractor_has_ext(string $url): bool
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $path = strtolower($path);
    foreach (extractor_interesting_extensions() as $ext) {
        if (str_ends_with($path, '.' . $ext)) {
            return true;
        }
    }
    return false;
}

/**
 * @return list<string>
 */
function extractor_discover_links(string $pageUrl, string $cookieHeader): array
{
    $headers = [];
    if ($cookieHeader !== '') {
        if (preg_match('/^\s*Cookie\s*:/i', $cookieHeader)) {
            $headers[] = trim($cookieHeader);
        } else {
            $headers[] = 'Cookie: ' . $cookieHeader;
        }
    }
    $r = extractor_http_get($pageUrl, $headers);
    if (!$r['ok'] || !is_string($r['body'])) {
        return [];
    }
    $html = $r['body'];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $out = [];
    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = $a->getAttribute('href');
        $abs = extractor_normalize_url($pageUrl, $href);
        if ($abs === null) {
            continue;
        }
        if (!preg_match('#^https?://#i', $abs)) {
            continue;
        }
        if (!extractor_has_ext($abs)) {
            continue;
        }
        $out[] = $abs;
    }
    $out = array_values(array_unique($out));
    return $out;
}
