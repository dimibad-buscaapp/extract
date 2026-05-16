<?php

declare(strict_types=1);

require_once __DIR__ . '/discover.php';
require_once __DIR__ . '/crypto.php';

/**
 * Master Extrator — rastreio PHP/cURL no mesmo domínio + detecção de links de ficheiros / hospedagens.
 * Para VPS (IIS); limites evitam timeout. Mega/Drive privados podem exigir browser.
 */

/**
 * @return array<string, string>
 */
function extractor_master_asset_patterns(): array
{
    return [
        'google_drive' => '~drive\.google\.com/file/d/([a-zA-Z0-9_-]+)~',
        'mega' => '~mega\.nz/(file|folder)/([^#]+)~',
        'mediafire' => '~mediafire\.com/(file|download)/([^/]+)~',
        'dropbox' => '~dropbox\.com/(s|sh)/([^/?]+)~',
        'gofile' => '~gofile\.io/d/([a-zA-Z0-9]+)~',
        'sendspace' => '~sendspace\.com/file/[a-zA-Z0-9]+~',
        /** Páginas clássicas /v/HASH/file.html ou /d/HASH/file.html */
        'zippyshare' => '~zippyshare\.com/(?:v|d)/[a-zA-Z0-9]+~',
        'uploaded_net' => '~(?:uploaded\.(?:net|to)|ul\.to)/file/[a-zA-Z0-9_-]+~',
        /** Página clássica /file/<id>[…] */
        'rapidgator' => '~rapidgator\.(?:net|asia)/file/[a-zA-Z0-9]+(?:[^?\s]*)?~i',
        'nitroflare' => '~nitroflare\.com/view/[a-zA-Z0-9]+(?:[^?\s]*)?~i',
        /** Índices tipo /abcd12345678.html (host turbobit) */
        'turbobit' => '~turbobit\.(?:net|com|cloud)/(?:[a-zA-Z0-9]+\.html|file/[a-zA-Z0-9._-]+|download/free/[a-zA-Z0-9._-]+)~i',
        'wetransfer' => '~wetransfer\.com/downloads/~',
        'mp4' => '~\.(mp4|mkv|avi|mov|wmv|flv|webm|m4v)(\?.*)?$~i',
        'pdf' => '~\.(pdf|epub|mobi)(\?.*)?$~i',
        'zip' => '~\.(zip|rar|7z|tar|gz)(\?.*)?$~i',
        'apk' => '~\.apk(\?.*)?$~i',
        'mp3' => '~\.(mp3|wav|ogg|flac|m4a)(\?.*)?$~i',
        'image' => '~\.(jpg|jpeg|png|gif|bmp|webp)(\?.*)?$~i',
        'document' => '~\.(doc|docx|xls|xlsx|ppt|pptx|txt|rtf)(\?.*)?$~i',
    ];
}

function extractor_master_match_type(string $url): ?string
{
    foreach (extractor_master_asset_patterns() as $label => $re) {
        if (preg_match($re, $url)) {
            return $label;
        }
    }

    return null;
}

function extractor_master_service(string $url): string
{
    $u = strtolower($url);
    $map = [
        'google_drive' => 'drive.google.com',
        'mega' => 'mega.nz',
        'mediafire' => 'mediafire.com',
        'dropbox' => 'dropbox.com',
        'onedrive' => '1drv.ms',
        'pcloud' => 'pcloud.com',
        'box' => 'box.com',
        'gofile' => 'gofile.io',
        'wetransfer' => 'wetransfer.com',
        /** Mesmo código `uploaded` para espelhos (file page típico /file/…) */
        'uploaded' => 'uploaded.net',
        'sendspace' => 'sendspace.com',
        'zippyshare' => 'zippyshare.com',
        'rapidgator' => 'rapidgator.net',
        'nitroflare' => 'nitroflare.com',
        'turbobit' => 'turbobit.net',
    ];
    foreach ($map as $k => $needle) {
        if (str_contains($u, $needle)) {
            return $k;
        }
    }
    /** Espelhos Uploaded (nem todos passam só por uploaded.net na URL) */
    if (str_contains($u, 'uploaded.to') || str_contains($u, 'ul.to')) {
        return 'uploaded';
    }
    if (str_contains($u, 'rapidgator.asia')) {
        return 'rapidgator';
    }
    if (str_contains($u, 'nitro.download')) {
        return 'nitroflare';
    }
    if (str_contains($u, 'turbobit.com') || str_contains($u, 'turbobit.cloud')
        || str_contains($u, 'turbo-bit.net')) {
        return 'turbobit';
    }

    return 'direto';
}

function extractor_master_download_hint(string $url): string
{
    if (preg_match('~/file/d/([a-zA-Z0-9_-]+)~', $url, $m)) {
        return 'https://drive.google.com/uc?export=download&id=' . $m[1];
    }
    if (str_contains($url, 'dropbox.com') && str_contains($url, 'dl=0')) {
        return str_replace('dl=0', 'dl=1', $url);
    }

    return $url;
}

/**
 * @param list<string> $headers
 * @return array{ok:bool, body:string, code:int, final_url:string}
 */
function extractor_master_http_get(string $url, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $h = array_merge(
            ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            $headers
        );
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return [
            'ok' => $body !== false && $code < 400,
            'body' => is_string($body) ? $body : '',
            'code' => $code,
            'final_url' => $final !== '' ? $final : $url,
        ];
    }

    $r = extractor_http_get($url, $headers);

    return [
        'ok' => (bool) ($r['ok'] ?? false),
        'body' => (string) ($r['body'] ?? ''),
        'code' => (int) ($r['code'] ?? 0),
        'final_url' => $url,
    ];
}

/**
 * @return list<string>
 */
function extractor_master_extract_urls_from_html(string $html, string $pageUrl): array
{
    $out = [];
    $patterns = [
        '~href\s*=\s*["\']([^"\']+)["\']~i',
        '~<iframe[^>]+src\s*=\s*["\']([^"\']+)["\']~i',
        '~<(?:video|audio|source|embed)[^>]+src\s*=\s*["\']([^"\']+)["\']~i',
        '~(?:data-url|data-file|data-link)\s*=\s*["\']([^"\']+)["\']~i',
    ];
    foreach ($patterns as $re) {
        if (preg_match_all($re, $html, $m)) {
            foreach ($m[1] as $raw) {
                $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $abs = extractor_normalize_url($pageUrl, $raw);
                if ($abs !== null && str_starts_with($abs, 'http')) {
                    $out[] = $abs;
                }
            }
        }
    }

    return array_values(array_unique($out));
}

function extractor_master_same_seed_host(string $seedHost, string $url): bool
{
    $h = extractor_url_host($url);
    if ($h === null || $seedHost === '') {
        return false;
    }

    return strcasecmp($h, $seedHost) === 0;
}

function extractor_master_should_enqueue_page(string $url, string $seedHost): bool
{
    if (!extractor_master_same_seed_host($seedHost, $url)) {
        return false;
    }
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if (preg_match('~\.(pdf|zip|rar|7z|mp4|mkv|m3u8|ts|jpg|png|gif|webp|mp3|apk|exe)(\?|$)~i', $path)) {
        return false;
    }

    return true;
}

function extractor_master_build_cookie_headers(array $site): array
{
    $headers = [];
    $ck = trim((string) ($site['cookie_enc'] ?? ''));
    if ($ck !== '') {
        try {
            $plain = extractor_decrypt($ck);
            if ($plain !== '') {
                $headers[] = str_starts_with(strtolower($plain), 'cookie:')
                    ? trim($plain)
                    : 'Cookie: ' . $plain;
            }
        } catch (Throwable) {
        }
    }

    return $headers;
}

/**
 * @return array{ok:bool, site?:array<string,mixed>, seed?:string, seed_host?:string, headers?:list<string>, error?:string}
 */
function extractor_master_resolve_site_seed(
    PDO $pdo,
    int $siteId,
    int $userId,
    bool $super = false
): array {
    if ($super) {
        $st = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
        $st->execute([$siteId]);
    } else {
        $st = $pdo->prepare('SELECT * FROM sites WHERE id = ? AND user_id = ?');
        $st->execute([$siteId, $userId]);
    }
    $site = $st->fetch();
    if (!$site) {
        return ['ok' => false, 'error' => 'Site não encontrado'];
    }

    $seed = trim((string) ($site['content_url'] ?? ''));
    if ($seed === '') {
        $seed = trim((string) ($site['base_url'] ?? ''));
    }
    if ($seed === '' || !filter_var($seed, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'URL base ou conteúdo inválido no cadastro do site'];
    }

    $seedHost = strtolower((string) (parse_url($seed, PHP_URL_HOST) ?? ''));
    if ($seedHost === '') {
        return ['ok' => false, 'error' => 'Host da URL inicial inválido'];
    }

    return [
        'ok' => true,
        'site' => $site,
        'seed' => $seed,
        'seed_host' => $seedHost,
        'headers' => extractor_master_build_cookie_headers($site),
    ];
}

/**
 * @return array{ok:bool, run_id?:int, error?:string}
 */
function extractor_master_insert_queued_run(
    PDO $pdo,
    int $userId,
    int $siteId,
    int $maxPages,
    int $maxDepth,
    bool $super = false
): array {
    $maxPages = max(1, min(400, $maxPages));
    $maxDepth = max(0, min(6, $maxDepth));

    $resolved = extractor_master_resolve_site_seed($pdo, $siteId, $userId, $super);
    if (!$resolved['ok']) {
        return ['ok' => false, 'error' => (string) ($resolved['error'] ?? 'Erro ao validar site')];
    }

    $seed = (string) $resolved['seed'];

    $pdo->prepare(
        'INSERT INTO master_scan_runs (user_id, site_id, seed_url, created_at, pages_crawled, items_found, error, max_pages, max_depth, scan_status, progress_pct, progress_msg)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $userId,
        $siteId,
        $seed,
        time(),
        0,
        0,
        null,
        $maxPages,
        $maxDepth,
        'queued',
        0,
        'Na fila…',
    ]);

    return ['ok' => true, 'run_id' => (int) $pdo->lastInsertId()];
}

function extractor_master_set_run_progress(PDO $pdo, int $runId, int $pct, string $msg): void
{
    $pct = max(0, min(99, $pct));
    $msg = substr($msg, 0, 500);
    $pdo->prepare('UPDATE master_scan_runs SET progress_pct = ?, progress_msg = ? WHERE id = ?')
        ->execute([$pct, $msg, $runId]);
}

/**
 * Execução longa — chame session_write_close() antes se precisar de polling em paralelo.
 *
 * @return array{ok:bool, error?:string, skipped?:bool}
 */
function extractor_master_execute_run(PDO $pdo, int $runId, int $userId, bool $super = false): array
{
    ignore_user_abort(true);
    set_time_limit(0);
    ini_set('max_execution_time', '0');

    $stLoad = $pdo->prepare('SELECT * FROM master_scan_runs WHERE id = ?');
    $stLoad->execute([$runId]);
    $runRow = $stLoad->fetch();
    if (!$runRow) {
        return ['ok' => false, 'error' => 'Execução não encontrada'];
    }
    if (!$super && (int) ($runRow['user_id'] ?? 0) !== $userId) {
        return ['ok' => false, 'error' => 'Sem permissão'];
    }

    $status = (string) ($runRow['scan_status'] ?? 'done');
    if ($status === 'running') {
        return ['ok' => true, 'skipped' => true];
    }
    if ($status === 'done' || $status === 'failed') {
        return ['ok' => true, 'skipped' => true];
    }

    $upd = $pdo->prepare(
        'UPDATE master_scan_runs SET scan_status = ?, progress_pct = ?, progress_msg = ?, error = NULL WHERE id = ? AND scan_status = ?'
    );
    $upd->execute(['running', 5, 'A iniciar varredura…', $runId, 'queued']);
    if ($upd->rowCount() === 0) {
        return ['ok' => true, 'skipped' => true];
    }

    $siteId = (int) ($runRow['site_id'] ?? 0);
    $resolved = extractor_master_resolve_site_seed($pdo, $siteId, $userId, $super);
    if (!$resolved['ok']) {
        $pdo->prepare('UPDATE master_scan_runs SET scan_status = ?, error = ?, progress_msg = ? WHERE id = ?')
            ->execute(['failed', $resolved['error'] ?? '', 'Falha na validação do site', $runId]);

        return ['ok' => false, 'error' => (string) ($resolved['error'] ?? '')];
    }

    $seed = (string) $resolved['seed'];
    $seedHost = (string) $resolved['seed_host'];
    $headers = $resolved['headers'] ?? [];

    $maxPages = max(1, min(400, (int) ($runRow['max_pages'] ?? 80)));
    $maxDepth = max(0, min(6, (int) ($runRow['max_depth'] ?? 2)));

    $visited = [];
    $queue = [[$seed, 0]];
    $assets = [];
    $pagesCrawled = 0;
    $insItem = $pdo->prepare(
        'INSERT OR IGNORE INTO master_scan_items (run_id, url, download_hint, display_name, size_bytes, service, type_label, source_page)
         VALUES (?,?,?,?,?,?,?,?)'
    );

    $flushAsset = static function (string $u, string $from, string $typeLabel) use (&$assets, $insItem, $runId): void {
        if (isset($assets[$u])) {
            return;
        }
        $assets[$u] = true;
        $hint = extractor_master_download_hint($u);
        $name = basename(parse_url($u, PHP_URL_PATH) ?: '') ?: $u;
        if (strlen($name) > 200) {
            $name = substr($name, 0, 197) . '…';
        }
        $insItem->execute([
            $runId,
            $u,
            $hint,
            $name,
            0,
            extractor_master_service($u),
            $typeLabel,
            $from,
        ]);
    };

    try {
        while ($queue !== [] && $pagesCrawled < $maxPages) {
            [$u, $depth] = array_shift($queue);
            $u = preg_replace('/#.*$/', '', $u) ?? $u;
            if (isset($visited[$u])) {
                continue;
            }
            $visited[$u] = true;
            $pagesCrawled++;

            if ($pagesCrawled === 1 || $pagesCrawled % 2 === 0) {
                $n = count($assets);
                $pct = $maxPages > 0 ? (int) min(99, 5 + (89 * $pagesCrawled / $maxPages)) : 10;
                $short = substr($u, 0, 90);
                extractor_master_set_run_progress(
                    $pdo,
                    $runId,
                    $pct,
                    "Páginas {$pagesCrawled}/{$maxPages} · {$n} links · {$short}"
                );
            }

            $r = extractor_master_http_get($u, $headers);
            if (!$r['ok'] || $r['body'] === '') {
                continue;
            }
            if (!str_contains((string) $r['body'], '<') && strlen((string) $r['body']) < 500) {
                continue;
            }

            $found = extractor_master_extract_urls_from_html($r['body'], $r['final_url']);
            foreach ($found as $link) {
                $type = extractor_master_match_type($link);
                if ($type !== null) {
                    $flushAsset($link, $r['final_url'], $type);
                    continue;
                }
                if ($depth < $maxDepth && extractor_master_should_enqueue_page($link, $seedHost)) {
                    if (!isset($visited[$link])) {
                        $queue[] = [$link, $depth + 1];
                    }
                }
            }
        }

        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM master_scan_items WHERE run_id = ?');
        $stCnt->execute([$runId]);
        $total = (int) $stCnt->fetchColumn();

        $pdo->prepare(
            'UPDATE master_scan_runs SET pages_crawled = ?, items_found = ?, scan_status = ?, progress_pct = ?, progress_msg = ?, error = NULL WHERE id = ?'
        )->execute([
            $pagesCrawled,
            $total,
            'done',
            100,
            'Concluído · ' . $total . ' itens encontrados.',
            $runId,
        ]);

        return ['ok' => true];
    } catch (Throwable $e) {
        $err = substr($e->getMessage(), 0, 1000);
        $pdo->prepare(
            'UPDATE master_scan_runs SET scan_status = ?, error = ?, progress_msg = ? WHERE id = ?'
        )->execute(['failed', $err, substr($err, 0, 300), $runId]);

        return ['ok' => false, 'error' => $err];
    }
}

/**
 * @return array<string,int>
 */
function extractor_master_summarize_services(PDO $pdo, int $runId): array
{
    $svc = [];
    $st = $pdo->prepare('SELECT service, COUNT(*) AS c FROM master_scan_items WHERE run_id = ? GROUP BY service');
    $st->execute([$runId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $svc[(string) ($row['service'] ?? '')] = (int) ($row['c'] ?? 0);
    }

    return $svc;
}

/**
 * Campos neutros quando o run não existe / sem permissão (evita erro de tipagem no cliente).
 *
 * @return array{scan_status:string,progress_pct:int,progress_msg:null,pages_crawled:int,items_found:int,error:null}
 */
function extractor_master_dead_progress_fields(): array
{
    return [
        'scan_status' => 'failed',
        'progress_pct' => 0,
        'progress_msg' => null,
        'pages_crawled' => 0,
        'items_found' => 0,
        'error' => null,
    ];
}

/**
 * @return array{forbidden?:string, scan_status:string, progress_pct:int, progress_msg:string|null, pages_crawled:int, items_found:int, error:string|null, by_service?:array<string,int>}
 */
function extractor_master_run_progress_snapshot(PDO $pdo, int $runId, int $userId, bool $super = false): array
{
    $st = $pdo->prepare('SELECT * FROM master_scan_runs WHERE id = ?');
    $st->execute([$runId]);
    $run = $st->fetch();
    if (!$run) {
        return ['forbidden' => 'Execução não encontrada'] + extractor_master_dead_progress_fields();
    }
    if (!$super && (int) ($run['user_id'] ?? 0) !== $userId) {
        return ['forbidden' => 'Sem permissão'] + extractor_master_dead_progress_fields();
    }

    $status = (string) ($run['scan_status'] ?? 'done');

    $out = [
        'scan_status' => $status,
        'progress_pct' => (int) ($run['progress_pct'] ?? 0),
        'progress_msg' => $run['progress_msg'],
        'pages_crawled' => (int) ($run['pages_crawled'] ?? 0),
        'items_found' => (int) ($run['items_found'] ?? 0),
        'error' => $run['error'],
    ];

    if ($status === 'done') {
        $out['by_service'] = extractor_master_summarize_services($pdo, $runId);
    }

    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function extractor_master_list_runs(PDO $pdo, int $userId, bool $super, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    if ($super) {
        $st = $pdo->query(
            'SELECT id, user_id, site_id, seed_url, created_at, pages_crawled, items_found, error, scan_status, progress_pct, max_pages, max_depth
             FROM master_scan_runs ORDER BY id DESC LIMIT ' . $limit
        );

        return $st === false ? [] : $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $st = $pdo->prepare(
        'SELECT id, user_id, site_id, seed_url, created_at, pages_crawled, items_found, error, scan_status, progress_pct, max_pages, max_depth
         FROM master_scan_runs WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit
    );
    $st->execute([$userId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string,mixed>>
 */
function extractor_master_items_for_run(PDO $pdo, int $userId, int $runId, bool $super = false): array
{
    if ($super) {
        $st = $pdo->prepare('SELECT id FROM master_scan_runs WHERE id = ?');
        $st->execute([$runId]);
    } else {
        $st = $pdo->prepare('SELECT id FROM master_scan_runs WHERE id = ? AND user_id = ?');
        $st->execute([$runId, $userId]);
    }
    if (!$st->fetch()) {
        return [];
    }
    $st2 = $pdo->prepare('SELECT * FROM master_scan_items WHERE run_id = ? ORDER BY id ASC');
    $st2->execute([$runId]);

    return $st2->fetchAll(PDO::FETCH_ASSOC);
}
