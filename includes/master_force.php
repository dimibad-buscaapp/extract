<?php

declare(strict_types=1);

require_once __DIR__ . '/discover.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/master_force_helpers.php';

/**
 * Tecto de segurança no servidor (PHP/IIS): a UI não limita páginas/profundidade, mas evita corridas infinitas
 * ou esgotamento de disco em caso de galerias absurdamente grandes. Ajustável aqui.
 */
const EXTRACTOR_FORCE_MAX_PAGES_CEILING = 65536;

const EXTRACTOR_FORCE_MAX_DEPTH_CEILING = 512;

/** @var positive-int */
const EXTRACTOR_FORCE_USLEEP_PER_PAGE_MICROS = 35_000;

/**
 * @return array{ok:bool, run_id?:int, error?:string}
 */
function extractor_force_insert_queued_run(PDO $pdo, int $userId, int $siteId, bool $super = false): array
{
    $resolved = extractor_master_resolve_site_seed($pdo, $siteId, $userId, $super);
    if (!$resolved['ok']) {
        return ['ok' => false, 'error' => (string) ($resolved['error'] ?? 'Erro ao validar site')];
    }

    $seed = (string) $resolved['seed'];

    $pdo->prepare(
        'INSERT INTO master_force_runs (user_id, site_id, seed_url, created_at, pages_crawled, items_found,
         scan_status, progress_pct, progress_msg, max_pages_ceiling, max_depth_ceiling, error)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $userId,
        $siteId,
        $seed,
        time(),
        0,
        0,
        'queued',
        0,
        'Na fila…',
        EXTRACTOR_FORCE_MAX_PAGES_CEILING,
        EXTRACTOR_FORCE_MAX_DEPTH_CEILING,
        null,
    ]);

    return ['ok' => true, 'run_id' => (int) $pdo->lastInsertId()];
}

function extractor_force_set_run_progress(PDO $pdo, int $runId, int $pct, string $msg): void
{
    $pct = max(0, min(99, $pct));
    $msg = substr($msg, 0, 500);
    $pdo->prepare('UPDATE master_force_runs SET progress_pct = ?, progress_msg = ? WHERE id = ?')->execute([
        $pct,
        $msg,
        $runId,
    ]);
}

/**
 * @return array<string,int>
 */
function extractor_force_count_by_kind(PDO $pdo, int $runId): array
{
    $svc = [];
    $st = $pdo->prepare('SELECT kind, COUNT(*) AS c FROM master_force_items WHERE run_id = ? GROUP BY kind');
    $st->execute([$runId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $svc[(string) ($row['kind'] ?? '')] = (int) ($row['c'] ?? 0);
    }

    return $svc;
}

/**
 * @return array{forbidden?:string, scan_status:string, progress_pct:int, progress_msg:null|string, pages_crawled:int, items_found:int, error:null|string, by_kind?:array<string,int>}
 */
function extractor_force_run_progress_snapshot(PDO $pdo, int $runId, int $userId, bool $super = false): array
{
    $dead = [
        'scan_status' => 'failed',
        'progress_pct' => 0,
        'progress_msg' => null,
        'pages_crawled' => 0,
        'items_found' => 0,
        'error' => null,
    ];

    $st = $pdo->prepare('SELECT * FROM master_force_runs WHERE id = ?');
    $st->execute([$runId]);
    $run = $st->fetch(PDO::FETCH_ASSOC);
    if (!$run) {
        return ['forbidden' => 'Execução não encontrada'] + $dead;
    }
    if (!$super && (int) ($run['user_id'] ?? 0) !== $userId) {
        return ['forbidden' => 'Sem permissão'] + $dead;
    }

    $status = (string) ($run['scan_status'] ?? 'done');
    $out = [
        'scan_status' => $status,
        'progress_pct' => (int) ($run['progress_pct'] ?? 0),
        'progress_msg' => $run['progress_msg'],
        'pages_crawled' => (int) ($run['pages_crawled'] ?? 0),
        'items_found' => (int) ($run['items_found'] ?? 0),
        'error' => $run['error'],
        'max_pages_ceiling' => (int) ($run['max_pages_ceiling'] ?? EXTRACTOR_FORCE_MAX_PAGES_CEILING),
        'max_depth_ceiling' => (int) ($run['max_depth_ceiling'] ?? EXTRACTOR_FORCE_MAX_DEPTH_CEILING),
    ];

    if ($status === 'done') {
        $out['by_kind'] = extractor_force_count_by_kind($pdo, $runId);
    }

    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function extractor_force_list_runs(PDO $pdo, int $userId, bool $super, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    if ($super) {
        $st = $pdo->query(
            'SELECT id, user_id, site_id, seed_url, created_at, pages_crawled, items_found, scan_status, progress_pct, max_pages_ceiling, max_depth_ceiling, error
             FROM master_force_runs ORDER BY id DESC LIMIT ' . $limit
        );

        return $st === false ? [] : $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $st = $pdo->prepare(
        'SELECT id, user_id, site_id, seed_url, created_at, pages_crawled, items_found, scan_status, progress_pct, max_pages_ceiling, max_depth_ceiling, error
         FROM master_force_runs WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit
    );
    $st->execute([$userId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string,mixed>>
 */
function extractor_force_items_for_run(PDO $pdo, int $userId, int $runId, bool $super = false, ?string $kind = null): array
{
    if ($super) {
        $st = $pdo->prepare('SELECT id FROM master_force_runs WHERE id = ?');
        $st->execute([$runId]);
    } else {
        $st = $pdo->prepare('SELECT id FROM master_force_runs WHERE id = ? AND user_id = ?');
        $st->execute([$runId, $userId]);
    }
    if (!$st->fetch()) {
        return [];
    }

    if ($kind !== null && $kind !== '') {
        $st2 = $pdo->prepare('SELECT id, url, kind, external_service, download_hint, post_data, js_context, needs_inspection, source_page, type_label
            FROM master_force_items WHERE run_id = ? AND kind = ? ORDER BY id ASC LIMIT 8000');
        $st2->execute([$runId, $kind]);
    } else {
        $st2 = $pdo->prepare('SELECT id, url, kind, external_service, download_hint, post_data, js_context, needs_inspection, source_page, type_label
            FROM master_force_items WHERE run_id = ? ORDER BY id ASC LIMIT 8000');
        $st2->execute([$runId]);
    }

    return $st2->fetchAll(PDO::FETCH_ASSOC);
}

function extractor_force_log_attempt(
    PDO $pdo,
    int $runId,
    string $url,
    string $method,
    ?string $fieldsJson,
    int $responseCode,
    string $ctype,
    string $note
): void {
    $pdo->prepare(
        'INSERT INTO master_force_attempts (run_id, url, method, post_fields, response_code, content_type, response_note, created_at)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $runId,
        substr($url, 0, 4000),
        $method,
        $fieldsJson,
        $responseCode,
        substr($ctype, 0, 500),
        substr($note, 0, 1500),
        time(),
    ]);
}

/**
 * @return array{ok:bool, error?:string, skipped?:bool}
 */
function extractor_force_execute_run(PDO $pdo, int $runId, int $userId, bool $super = false): array
{
    ignore_user_abort(true);
    set_time_limit(0);
    ini_set('max_execution_time', '0');

    $stLoad = $pdo->prepare('SELECT * FROM master_force_runs WHERE id = ?');
    $stLoad->execute([$runId]);
    $runRow = $stLoad->fetch(PDO::FETCH_ASSOC);
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
        'UPDATE master_force_runs SET scan_status = ?, progress_pct = ?, progress_msg = ?, error = NULL WHERE id = ? AND scan_status = ?'
    );
    $upd->execute(['running', 5, 'A iniciar descoberta forçada…', $runId, 'queued']);
    if ($upd->rowCount() === 0) {
        return ['ok' => true, 'skipped' => true];
    }

    $siteId = (int) ($runRow['site_id'] ?? 0);
    $resolved = extractor_master_resolve_site_seed($pdo, $siteId, $userId, $super);
    if (!$resolved['ok']) {
        $pdo->prepare('UPDATE master_force_runs SET scan_status = ?, error = ?, progress_msg = ? WHERE id = ?')
            ->execute(['failed', $resolved['error'] ?? '', 'Falha na validação do site', $runId]);

        return ['ok' => false, 'error' => (string) ($resolved['error'] ?? '')];
    }

    $seedHost = (string) $resolved['seed_host'];
    $headers = $resolved['headers'] ?? [];
    $seed = (string) $resolved['seed'];

    $maxPages = EXTRACTOR_FORCE_MAX_PAGES_CEILING;
    $maxDepth = EXTRACTOR_FORCE_MAX_DEPTH_CEILING;

    /** @var array<string,true> */
    $visited = [];
    $queue = [[$seed, 0]];
    $pagesCrawled = 0;

    $insItem = $pdo->prepare(
        'INSERT OR IGNORE INTO master_force_items (run_id, dedupe_hash, url, kind, external_service, download_hint, post_data, js_context, needs_inspection, source_page, type_label)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );

    $flushLeaf = static function (string $u, string $from, array $classified) use ($insItem, $runId): void {
        $kind = (string) $classified['kind'];
        if ($kind === 'internal_page') {
            return;
        }

        $postJson = null;
        if ($kind === 'form_post') {
            if (!isset($classified['fields']) || !is_array($classified['fields'])) {
                return;
            }
            $postJson = json_encode($classified['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($postJson === false) {
                $postJson = '{}';
            }
            $dedupe = extractor_force_dedupe_item($kind, $u, $from, $postJson);
        } else {
            $dedupe = extractor_force_dedupe_item($kind, $u, $from, '');
        }

        $ext = isset($classified['external_service']) && is_string($classified['external_service']) ? $classified['external_service'] : null;
        $nis = (($classified['needs_inspection'] ?? 0) === 1) ? 1 : 0;
        $jsCtx = isset($classified['js_context']) && is_string($classified['js_context']) ? $classified['js_context'] : null;
        $hint = isset($classified['download_hint']) && is_string($classified['download_hint']) ? $classified['download_hint'] : '';

        $insItem->execute([
            $runId,
            $dedupe,
            substr($u, 0, 4000),
            $kind,
            $ext,
            substr($hint, 0, 8000),
            $postJson,
            $jsCtx !== null ? substr($jsCtx, 0, 16000) : null,
            $nis,
            substr($from, 0, 2000),
            (string) ($classified['type_label'] ?? ''),
        ]);
    };

    try {
        while ($queue !== [] && $pagesCrawled < $maxPages) {
            [$u, $depth] = array_shift($queue);
            $u = preg_replace('#\#.+$#', '', $u) ?? $u;
            $vk = extractor_force_visit_key($u);
            if (isset($visited[$vk])) {
                continue;
            }
            $visited[$vk] = true;
            $pagesCrawled++;

            if ($pagesCrawled === 1 || $pagesCrawled % 3 === 0) {
                $stCntNow = $pdo->prepare('SELECT COUNT(*) FROM master_force_items WHERE run_id = ?');
                $stCntNow->execute([$runId]);
                $cntNow = (int) $stCntNow->fetchColumn();
                $pct = $maxPages > 0 ? (int) min(94, 5 + (87 * $pagesCrawled / $maxPages)) : 35;
                $short = substr($u, 0, 88);
                extractor_force_set_run_progress(
                    $pdo,
                    $runId,
                    $pct,
                    "Pág. {$pagesCrawled} (≤{$maxPages}) · {$cntNow} guardados · {$short}"
                );
            }

            $r = extractor_master_http_get($u, $headers);
            usleep(EXTRACTOR_FORCE_USLEEP_PER_PAGE_MICROS);

            if (!$r['ok'] || $r['body'] === '') {
                continue;
            }

            $html = $r['body'];
            $finalUrl = $r['final_url'];

            if (!str_contains((string) $html, '<') && strlen((string) $html) < 900) {
                continue;
            }

            $tagUrls = extractor_force_wide_tag_urls((string) $html, $finalUrl);

            foreach ($tagUrls as $link) {
                $c = extractor_force_classify_leaf($link, $seedHost);
                $flushLeaf($link, $finalUrl, [
                    'kind' => $c['kind'],
                    'external_service' => $c['external_service'],
                    'download_hint' => $c['download_hint'],
                    'type_label' => $c['type_label'],
                    'needs_inspection' => 0,
                ]);

                if ($depth < $maxDepth && $c['kind'] === 'internal_page') {
                    if (extractor_master_should_enqueue_page($link, $seedHost) && !isset($visited[extractor_force_visit_key($link)])) {
                        $queue[] = [$link, $depth + 1];
                    }
                }
            }

            foreach (extractor_force_script_only_candidates((string) $html, $finalUrl, $tagUrls)['script_only'] ?? [] as $row) {
                $cu = extractor_force_classify_leaf($row['url'], $seedHost);
                if ($cu['kind'] === 'internal_page') {
                    if ($depth < $maxDepth && extractor_master_should_enqueue_page($row['url'], $seedHost)
                        && !isset($visited[extractor_force_visit_key($row['url'])])) {
                        $queue[] = [$row['url'], $depth + 1];
                    }
                    continue;
                }
                $flushLeaf($row['url'], $finalUrl, [
                    'kind' => 'needs_inspection',
                    'external_service' => $cu['external_service'],
                    'download_hint' => $cu['download_hint'],
                    'type_label' => 'js_inferido',
                    'needs_inspection' => 1,
                    'js_context' => $row['snippet'],
                ]);
            }

            foreach (extractor_force_parse_post_forms((string) $html, $finalUrl) as $fm) {
                $actionUrl = $fm['action'];
                /** @var array<string,string> $fld */
                $fld = $fm['fields'];
                $classified = [
                    'kind' => 'form_post',
                    'external_service' => null,
                    'download_hint' => '',
                    'type_label' => 'post_form',
                    'needs_inspection' => 0,
                    'fields' => $fld,
                ];
                $flushLeaf($actionUrl, $finalUrl, $classified);

                $attempt = extractor_force_http_post_attempt($actionUrl, $fld, $headers);
                extractor_force_log_attempt(
                    $pdo,
                    $runId,
                    $actionUrl,
                    'POST',
                    json_encode($fld, JSON_UNESCAPED_UNICODE),
                    $attempt['code'],
                    $attempt['content_type'],
                    $attempt['note']
                );
            }
        }

        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM master_force_items WHERE run_id = ?');
        $stCnt->execute([$runId]);
        $total = (int) $stCnt->fetchColumn();

        $pdo->prepare(
            'UPDATE master_force_runs SET pages_crawled = ?, items_found = ?, scan_status = ?, progress_pct = ?, progress_msg = ?, error = NULL WHERE id = ?'
        )->execute([
            $pagesCrawled,
            $total,
            'done',
            100,
            'Concluído · ' . $total . ' registos gravados.',
            $runId,
        ]);

        return ['ok' => true];
    } catch (Throwable $e) {
        $err = substr($e->getMessage(), 0, 1000);
        $pdo->prepare('UPDATE master_force_runs SET scan_status = ?, error = ?, progress_msg = ? WHERE id = ?')
            ->execute(['failed', $err, substr($err, 0, 300), $runId]);

        return ['ok' => false, 'error' => $err];
    }
}
