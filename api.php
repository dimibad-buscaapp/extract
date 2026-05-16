<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/discover.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/m3u.php';
require_once __DIR__ . '/includes/m3u_export_job.php';
require_once __DIR__ . '/includes/master_extractor.php';
require_once __DIR__ . '/includes/master_force.php';

header('Content-Type: application/json; charset=utf-8');

if (!extractor_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'nao_autenticado']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
}

$csrf = (string) ($input['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$action = (string) ($input['action'] ?? '');

try {
    $pdo = extractor_pdo();
    extractor_user_refresh_session($pdo);
    $uid = extractor_user_id();
    $super = extractor_is_super_master();
    $cfg = extractor_config();

    $outDir = EXTRACTOR_DATA . '/out';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0700, true);
    }

    if ($action === 'sites_list') {
        if ($super) {
            $rows = $pdo->query('SELECT id, user_id, name, base_url, content_url, username, same_origin_only, created_at FROM sites ORDER BY name COLLATE NOCASE')->fetchAll();
        } else {
            $st = $pdo->prepare('SELECT id, user_id, name, base_url, content_url, username, same_origin_only, created_at FROM sites WHERE user_id = ? ORDER BY name COLLATE NOCASE');
            $st->execute([$uid]);
            $rows = $st->fetchAll();
        }
        echo json_encode(['ok' => true, 'sites' => $rows, 'credits' => (int) ($_SESSION['user_credits'] ?? 0), 'role' => (string) ($_SESSION['user_role'] ?? '')]);
        exit;
    }

    if ($action === 'site_delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('id inválido');
        }
        if ($super) {
            $pdo->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
        } else {
            $pdo->prepare('DELETE FROM sites WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'site_save') {
        $id = isset($input['id']) ? (int) $input['id'] : 0;
        $name = trim((string) ($input['name'] ?? ''));
        $base = trim((string) ($input['base_url'] ?? ''));
        $content = trim((string) ($input['content_url'] ?? ''));
        $user = trim((string) ($input['username'] ?? ''));
        $pass = (string) ($input['password'] ?? '');
        $cookie = trim((string) ($input['cookie'] ?? ''));
        $same = filter_var(
            $input['same_origin_only'] ?? $input['same'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if ($name === '' || $base === '') {
            throw new RuntimeException('Nome e URL base são obrigatórios');
        }
        if (!filter_var($base, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL base inválida');
        }
        if ($content !== '' && !filter_var($content, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL de conteúdo inválida');
        }
        if ($pass === '' && $cookie === '') {
            throw new RuntimeException('Informe senha do site e/ou cookie de sessão');
        }
        $pwEnc = extractor_encrypt($pass);
        $ckEnc = $cookie !== '' ? extractor_encrypt($cookie) : null;
        $now = time();
        if ($id > 0) {
            $st = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException('Site não encontrado');
            }
            if (!$super && (int) ($row['user_id'] ?? 0) !== $uid) {
                throw new RuntimeException('Sem permissão');
            }
            if ($pass === '') {
                $pwEnc = (string) $row['password_enc'];
            }
            if ($cookie === '') {
                $ckEnc = $row['cookie_enc'] !== null && $row['cookie_enc'] !== '' ? (string) $row['cookie_enc'] : null;
            }
            $pdo->prepare(
                'UPDATE sites SET name=?, base_url=?, content_url=?, username=?, password_enc=?, cookie_enc=?, same_origin_only=? WHERE id=?'
            )->execute([
                $name,
                $base,
                $content !== '' ? $content : null,
                $user !== '' ? $user : null,
                $pwEnc,
                $ckEnc,
                $same ? 1 : 0,
                $id,
            ]);
            echo json_encode(['ok' => true, 'id' => $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO sites (user_id, name, base_url, content_url, username, password_enc, cookie_enc, same_origin_only, created_at) VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $uid,
                $name,
                $base,
                $content !== '' ? $content : null,
                $user !== '' ? $user : null,
                $pwEnc,
                $ckEnc,
                $same ? 1 : 0,
                $now,
            ]);
            $nid = (int) $pdo->lastInsertId();
            echo json_encode(['ok' => true, 'id' => $nid]);
        }
        exit;
    }

    if ($action === 'master_scan_reserve') {
        $siteId = (int) ($input['site_id'] ?? 0);
        $maxPages = (int) ($input['max_pages'] ?? 80);
        $maxDepth = (int) ($input['max_depth'] ?? 2);
        if ($siteId < 1) {
            throw new RuntimeException('site_id inválido');
        }
        $reserved = extractor_master_insert_queued_run($pdo, $uid, $siteId, $maxPages, $maxDepth, $super);
        if (!$reserved['ok']) {
            throw new RuntimeException((string) ($reserved['error'] ?? 'Não foi possível criar a varredura'));
        }
        $runId = (int) $reserved['run_id'];
        $cost = (int) ($cfg['credits_per_master_scan'] ?? 0);
        if ($cost > 0 && !extractor_credit_try_debit($pdo, $uid, $cost, 'Varredura Master (#' . $runId . ')')) {
            $pdo->prepare('DELETE FROM master_scan_runs WHERE id = ?')->execute([$runId]);
            throw new RuntimeException('Créditos insuficientes para a varredura Master.');
        }
        echo json_encode([
            'ok' => true,
            'run_id' => $runId,
            'credits' => (int) ($_SESSION['user_credits'] ?? 0),
        ]);
        exit;
    }

    if ($action === 'master_scan_execute') {
        $runId = (int) ($input['run_id'] ?? 0);
        if ($runId < 1) {
            throw new RuntimeException('run_id inválido');
        }
        if ($super) {
            $chk = $pdo->prepare('SELECT id FROM master_scan_runs WHERE id = ?');
            $chk->execute([$runId]);
        } else {
            $chk = $pdo->prepare('SELECT id FROM master_scan_runs WHERE id = ? AND user_id = ?');
            $chk->execute([$runId, $uid]);
        }
        if (!$chk->fetch()) {
            throw new RuntimeException('Execução não encontrada');
        }
        session_write_close();
        $r = extractor_master_execute_run($pdo, $runId, $uid, $super);
        if (!$r['ok']) {
            throw new RuntimeException((string) ($r['error'] ?? 'Execução falhou'));
        }
        echo json_encode([
            'ok' => true,
            'skipped' => (bool) ($r['skipped'] ?? false),
        ]);
        exit;
    }

    if ($action === 'master_scan_progress') {
        $runId = (int) ($input['run_id'] ?? 0);
        if ($runId < 1) {
            throw new RuntimeException('run_id inválido');
        }
        $snap = extractor_master_run_progress_snapshot($pdo, $runId, $uid, $super);
        if (!empty($snap['forbidden'])) {
            throw new RuntimeException((string) $snap['forbidden']);
        }
        unset($snap['forbidden']);
        echo json_encode(['ok' => true] + $snap + ['credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'master_runs') {
        $lim = min(50, max(1, (int) ($input['limit'] ?? 20)));
        $runs = extractor_master_list_runs($pdo, $uid, $super, $lim);
        echo json_encode(['ok' => true, 'runs' => $runs, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'master_run_items') {
        $runId = (int) ($input['run_id'] ?? 0);
        if ($runId < 1) {
            throw new RuntimeException('run_id inválido');
        }
        $items = extractor_master_items_for_run($pdo, $uid, $runId, $super);
        echo json_encode(['ok' => true, 'items' => $items, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'master_force_reserve') {
        $siteId = (int) ($input['site_id'] ?? 0);
        if ($siteId < 1) {
            throw new RuntimeException('site_id inválido');
        }
        $reserved = extractor_force_insert_queued_run($pdo, $uid, $siteId, $super);
        if (!$reserved['ok']) {
            throw new RuntimeException((string) ($reserved['error'] ?? 'Não foi possível criar a descoberta forçada'));
        }
        $runId = (int) $reserved['run_id'];
        $cost = (int) ($cfg['credits_per_force_scan'] ?? 0);
        if ($cost > 0 && !extractor_credit_try_debit($pdo, $uid, $cost, 'Descoberta Forçada (#' . $runId . ')')) {
            $pdo->prepare('DELETE FROM master_force_runs WHERE id = ?')->execute([$runId]);
            throw new RuntimeException('Créditos insuficientes para a Descoberta Forçada.');
        }
        echo json_encode([
            'ok' => true,
            'run_id' => $runId,
            'credits' => (int) ($_SESSION['user_credits'] ?? 0),
        ]);
        exit;
    }

    if ($action === 'master_force_execute') {
        $runId = (int) ($input['run_id'] ?? 0);
        if ($runId < 1) {
            throw new RuntimeException('run_id inválido');
        }
        if ($super) {
            $chk = $pdo->prepare('SELECT id FROM master_force_runs WHERE id = ?');
            $chk->execute([$runId]);
        } else {
            $chk = $pdo->prepare('SELECT id FROM master_force_runs WHERE id = ? AND user_id = ?');
            $chk->execute([$runId, $uid]);
        }
        if (!$chk->fetch()) {
            throw new RuntimeException('Execução não encontrada');
        }
        session_write_close();
        $r = extractor_force_execute_run($pdo, $runId, $uid, $super);
        if (!$r['ok']) {
            throw new RuntimeException((string) ($r['error'] ?? 'Execução falhou'));
        }
        echo json_encode([
            'ok' => true,
            'skipped' => (bool) ($r['skipped'] ?? false),
        ]);
        exit;
    }

    if ($action === 'master_force_progress') {
        $runId = (int) ($input['run_id'] ?? 0);
        if ($runId < 1) {
            throw new RuntimeException('run_id inválido');
        }
        $snap = extractor_force_run_progress_snapshot($pdo, $runId, $uid, $super);
        if (!empty($snap['forbidden'])) {
            throw new RuntimeException((string) $snap['forbidden']);
        }
        unset($snap['forbidden']);
        echo json_encode(['ok' => true] + $snap + ['credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'master_force_runs') {
        $lim = min(50, max(1, (int) ($input['limit'] ?? 20)));
        $runs = extractor_force_list_runs($pdo, $uid, $super, $lim);
        echo json_encode(['ok' => true, 'runs' => $runs, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'master_force_items') {
        $runId = (int) ($input['run_id'] ?? 0);
        $kindFilter = isset($input['kind']) ? trim((string) $input['kind']) : '';
        $kindFilter = $kindFilter === '' ? null : $kindFilter;
        if ($runId < 1) {
            throw new RuntimeException('run_id inválido');
        }
        $items = extractor_force_items_for_run($pdo, $uid, $runId, $super, $kindFilter);
        echo json_encode(['ok' => true, 'items' => $items, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'discover') {
        $siteId = (int) ($input['site_id'] ?? 0);
        $page = trim((string) ($input['page_url'] ?? ''));
        $cookie = trim((string) ($input['cookie'] ?? ''));
        if ($siteId > 0) {
            $st = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
            $st->execute([$siteId]);
            $s = $st->fetch();
            if (!$s) {
                throw new RuntimeException('Site não encontrado');
            }
            if (!$super && (int) ($s['user_id'] ?? 0) !== $uid) {
                throw new RuntimeException('Sem permissão');
            }
            $page = (string) ($s['content_url'] ?? '') !== '' ? (string) $s['content_url'] : (string) $s['base_url'];
            $cookie = '';
            if (!empty($s['cookie_enc'])) {
                $cookie = extractor_decrypt((string) $s['cookie_enc']);
            }
        }
        if ($page === '' || !filter_var($page, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL da página inválida');
        }

        $cost = (int) $cfg['credits_per_discover'];
        if ($cost > 0 && !extractor_credit_try_debit($pdo, $uid, $cost, 'Descoberta de links')) {
            throw new RuntimeException('Créditos insuficientes para descoberta.');
        }

        $urls = extractor_discover_links($page, $cookie);
        echo json_encode(['ok' => true, 'urls' => $urls, 'page' => $page, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'download_one') {
        $url = trim((string) ($input['url'] ?? ''));
        $siteId = (int) ($input['site_id'] ?? 0);
        $fname = trim((string) ($input['filename'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL inválida');
        }
        $headers = [];
        $cookie = trim((string) ($input['cookie'] ?? ''));
        if ($siteId > 0) {
            $st = $pdo->prepare('SELECT cookie_enc, user_id FROM sites WHERE id = ?');
            $st->execute([$siteId]);
            $row = $st->fetch();
            if (!$row) {
                throw new RuntimeException('Site não encontrado');
            }
            if (!$super && (int) ($row['user_id'] ?? 0) !== $uid) {
                throw new RuntimeException('Sem permissão');
            }
            if (!empty($row['cookie_enc'])) {
                $cookie = extractor_decrypt((string) $row['cookie_enc']);
            }
        }
        if ($cookie !== '') {
            if (preg_match('/^\s*Cookie\s*:/i', $cookie)) {
                $headers[] = trim($cookie);
            } else {
                $headers[] = 'Cookie: ' . $cookie;
            }
        }
        $baseName = $fname !== '' ? extractor_safe_filename($fname) : extractor_safe_filename(basename(parse_url($url, PHP_URL_PATH) ?: 'download.bin'));
        $dest = $outDir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $baseName;
        $r = extractor_stream_url_to_file($url, $dest, $headers);
        if (!$r['ok']) {
            throw new RuntimeException($r['error']);
        }

        $cost = (int) $cfg['credits_per_download'];
        if ($cost > 0 && !extractor_credit_try_debit($pdo, $uid, $cost, 'Download de ficheiro')) {
            @unlink($dest);
            throw new RuntimeException('Créditos insuficientes para concluir o registo do ficheiro.');
        }

        $pubTok = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO files (user_id, site_id, source_url, local_path, bytes, created_at, public_token) VALUES (?,?,?,?,?,?,?)')->execute([
            $uid,
            $siteId > 0 ? $siteId : null,
            $url,
            $dest,
            $r['bytes'],
            time(),
            $pubTok,
        ]);
        $fid = (int) $pdo->lastInsertId();
        echo json_encode(['ok' => true, 'file_id' => $fid, 'bytes' => $r['bytes'], 'name' => basename($dest), 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'm3u_list') {
        $imported = extractor_m3u_import_orphans($pdo, $uid);
        if ($super) {
            $st = $pdo->query(
                'SELECT id, user_id, label, file_name, source_url, bytes, entry_count, created_at FROM m3u_playlists ORDER BY id DESC LIMIT 100'
            );
            $rows = $st->fetchAll();
        } else {
            $st = $pdo->prepare(
                'SELECT id, user_id, label, file_name, source_url, bytes, entry_count, created_at FROM m3u_playlists WHERE user_id = ? ORDER BY id DESC LIMIT 100'
            );
            $st->execute([$uid]);
            $rows = $st->fetchAll();
        }
        echo json_encode(['ok' => true, 'playlists' => $rows, 'imported' => $imported]);
        exit;
    }

    if ($action === 'm3u_stats') {
        $id = (int) ($input['id'] ?? 0);
        $resolved = extractor_m3u_resolve_path($pdo, $id, $uid, $super);
        if ($resolved === null) {
            throw new RuntimeException('Lista não encontrada.');
        }
        $counts = extractor_m3u_count_kinds($resolved['path']);
        echo json_encode(['ok' => true, 'counts' => $counts]);
        exit;
    }

    if ($action === 'm3u_entries') {
        $id = (int) ($input['id'] ?? 0);
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $limit = min(500, max(1, (int) ($input['limit'] ?? 100)));
        $filter = (string) ($input['filter'] ?? 'all');
        if (!in_array($filter, ['all', 'vod', 'live'], true)) {
            $filter = 'all';
        }
        $resolved = extractor_m3u_resolve_path($pdo, $id, $uid, $super);
        if ($resolved === null) {
            throw new RuntimeException('Lista não encontrada.');
        }
        $entries = extractor_m3u_list_entries($resolved['path'], $offset, $limit, $filter);
        $counts = extractor_m3u_count_kinds($resolved['path']);
        $total = $filter === 'vod' ? $counts['vod'] : ($filter === 'live' ? $counts['live'] : $counts['total']);
        echo json_encode([
            'ok' => true,
            'entries' => $entries,
            'offset' => $offset,
            'total' => $total,
            'counts' => $counts,
            'filter' => $filter,
        ]);
        exit;
    }

    if ($action === 'm3u_analyze') {
        $id = (int) ($input['id'] ?? 0);
        $resolved = extractor_m3u_resolve_path($pdo, $id, $uid, $super);
        if ($resolved === null) {
            throw new RuntimeException('Lista não encontrada.');
        }
        require_once __DIR__ . '/includes/m3u_panel.php';
        $categories = extractor_m3u_analyze_categories($resolved['path']);
        echo json_encode(['ok' => true, 'categories' => $categories]);
        exit;
    }

    if ($action === 'm3u_export_begin') {
        $id = (int) ($input['id'] ?? 0);
        $mode = (string) ($input['mode'] ?? 'all_open');
        if (!in_array($mode, ['all_open', 'convert'], true)) {
            throw new RuntimeException('Modo inválido.');
        }
        $resolved = extractor_m3u_resolve_path($pdo, $id, $uid, $super);
        if ($resolved === null) {
            throw new RuntimeException('Lista não encontrada.');
        }
        $stCount = $pdo->prepare('SELECT entry_count FROM m3u_playlists WHERE id = ?');
        $stCount->execute([$id]);
        $countRow = $stCount->fetch();
        $totalHint = (int) ($countRow['entry_count'] ?? 0);
        $begin = extractor_m3u_job_begin($uid, $id, $mode, $resolved['path'], $totalHint);
        $payload = [
            'ok' => true,
            'job_id' => $begin['job_id'],
            'total' => $begin['total'],
            'message' => 'Exportação iniciada',
        ];
        if (isset($begin['xtream'])) {
            $payload['xtream'] = $begin['xtream'];
        }
        echo json_encode($payload);
        exit;
    }

    if ($action === 'm3u_export_step') {
        $jobId = (string) ($input['job_id'] ?? '');
        if ($jobId === '') {
            throw new RuntimeException('job_id em falta.');
        }
        $status = extractor_m3u_job_step($jobId, $uid, $pdo);
        echo json_encode($status);
        exit;
    }

    if ($action === 'm3u_export') {
        $id = (int) ($input['id'] ?? 0);
        $mode = (string) ($input['mode'] ?? 'vod_urls');
        $urls = $input['urls'] ?? [];
        if (!is_array($urls)) {
            $urls = [];
        }
        $resolved = extractor_m3u_resolve_path($pdo, $id, $uid, $super);
        if ($resolved === null) {
            throw new RuntimeException('Lista não encontrada.');
        }
        $exportEntries = [];
        if ($mode === 'selected' || $mode === 'selected_local') {
            $picked = extractor_m3u_entries_by_urls($resolved['path'], array_map('strval', $urls));
            if ($mode === 'selected_local') {
                $exportEntries = extractor_m3u_map_local_files($pdo, $uid, $picked);
            } else {
                $exportEntries = $picked;
            }
        } elseif ($mode === 'local') {
            $all = [];
            extractor_m3u_foreach($resolved['path'], static function (array $e) use (&$all): void {
                if ($e['kind'] === 'vod') {
                    $all[] = $e;
                }
            }, 'vod');
            $exportEntries = extractor_m3u_map_local_files($pdo, $uid, $all);
        } elseif ($mode === 'all_open' || $mode === 'convert') {
            extractor_m3u_foreach($resolved['path'], static function (array $e) use (&$exportEntries): void {
                $exportEntries[] = $e;
            }, 'all');
        } else {
            extractor_m3u_foreach($resolved['path'], static function (array $e) use (&$exportEntries): void {
                if ($e['kind'] === 'vod') {
                    $exportEntries[] = ['title' => $e['title'], 'url' => $e['url']];
                }
            }, 'vod');
        }
        if ($exportEntries === []) {
            throw new RuntimeException('Nenhum item para exportar. Para lista local, descarregue os VOD primeiro.');
        }
        $body = $mode === 'convert'
            ? extractor_m3u_format_playlist_catalog($exportEntries)
            : extractor_m3u_format_playlist(array_map(static fn (array $e): array => [
                'title' => $e['title'],
                'url' => $e['url'],
            ], $exportEntries));
        $outPath = EXTRACTOR_DATA . '/export_' . date('Ymd_His') . '.m3u';
        file_put_contents($outPath, $body);
        $label = match ($mode) {
            'convert' => 'Catálogo ' . date('d/m H:i'),
            'all_open' => 'Nova M3U ' . date('d/m H:i'),
            'local', 'selected_local' => 'M3U local ' . date('d/m H:i'),
            default => 'M3U VOD ' . date('d/m H:i'),
        };
        $newId = extractor_m3u_register_playlist($pdo, $uid, $outPath, $label, null);
        echo json_encode([
            'ok' => true,
            'playlist_id' => $newId,
            'entries' => count($exportEntries),
            'download_url' => extractor_absolute_url('download.php?m3u_id=' . $newId),
        ]);
        exit;
    }

    if ($action === 'm3u_vod_download') {
        $urls = $input['urls'] ?? [];
        if (!is_array($urls) || $urls === []) {
            throw new RuntimeException('Seleccione pelo menos um VOD.');
        }
        $urls = array_slice(array_values(array_filter(array_map('strval', $urls))), 0, 12);
        $cost = (int) $cfg['credits_per_download'];
        $results = [];
        foreach ($urls as $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $results[] = ['url' => $url, 'ok' => false, 'error' => 'URL inválida'];
                continue;
            }
            if ($cost > 0 && !extractor_credit_try_debit($pdo, $uid, $cost, 'Download VOD M3U')) {
                $results[] = ['url' => $url, 'ok' => false, 'error' => 'Créditos insuficientes'];
                break;
            }
            $baseName = extractor_safe_filename(basename(parse_url($url, PHP_URL_PATH) ?: 'vod.bin'));
            $dest = $outDir . '/vod_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . $baseName;
            $r = extractor_stream_url_to_file($url, $dest, []);
            if (!$r['ok']) {
                $results[] = ['url' => $url, 'ok' => false, 'error' => $r['error']];
                continue;
            }
            $pdo->prepare('INSERT INTO files (user_id, site_id, source_url, local_path, bytes, created_at, public_token) VALUES (?,?,?,?,?,?,?)')->execute([
                $uid,
                null,
                $url,
                $dest,
                $r['bytes'],
                time(),
                bin2hex(random_bytes(16)),
            ]);
            $fid = (int) $pdo->lastInsertId();
            $results[] = ['url' => $url, 'ok' => true, 'file_id' => $fid, 'bytes' => $r['bytes']];
        }
        echo json_encode(['ok' => true, 'results' => $results, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'm3u_delete') {
        $id = (int) ($input['id'] ?? 0);
        $resolved = extractor_m3u_resolve_path($pdo, $id, $uid, $super);
        if ($resolved === null) {
            throw new RuntimeException('Lista não encontrada.');
        }
        $pdo->prepare('DELETE FROM m3u_playlists WHERE id = ?')->execute([$id]);
        if (is_file($resolved['path'])) {
            @unlink($resolved['path']);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'files_list') {
        $lim = min(500, max(1, (int) ($input['limit'] ?? 200)));
        if ($super) {
            $st = $pdo->query('SELECT id, user_id, site_id, source_url, local_path, bytes, created_at FROM files ORDER BY id DESC LIMIT ' . $lim);
            $rows = $st->fetchAll();
        } else {
            $st = $pdo->prepare('SELECT id, user_id, site_id, source_url, local_path, bytes, created_at FROM files WHERE user_id = ? ORDER BY id DESC LIMIT ' . $lim);
            $st->execute([$uid]);
            $rows = $st->fetchAll();
        }
        echo json_encode(['ok' => true, 'files' => $rows, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'account_password_change') {
        $cur = (string) ($input['current_password'] ?? '');
        $p1 = (string) ($input['new_password'] ?? '');
        $p2 = (string) ($input['new_password_confirm'] ?? '');
        if ($cur === '' || strlen($p1) < 10) {
            throw new RuntimeException('Informe a senha actual e a nova senha (mínimo 10 caracteres).');
        }
        if ($p1 !== $p2) {
            throw new RuntimeException('A confirmação da nova senha não coincide.');
        }
        $st = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = ? AND status = ?');
        $st->execute([$uid, 'active']);
        $u = $st->fetch();
        if (!$u || !password_verify($cur, (string) $u['password_hash'])) {
            throw new RuntimeException('Senha actual incorrecta.');
        }
        $hash = password_hash($p1, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Erro ao gerar hash de senha.');
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
        echo json_encode(['ok' => true, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'account_email_change') {
        $cur = (string) ($input['current_password'] ?? '');
        $newEmail = strtolower(trim((string) ($input['new_email'] ?? '')));
        if ($cur === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe a senha actual e um e-mail válido.');
        }
        $st = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = ? AND status = ?');
        $st->execute([$uid, 'active']);
        $u = $st->fetch();
        if (!$u || !password_verify($cur, (string) $u['password_hash'])) {
            throw new RuntimeException('Senha actual incorrecta.');
        }
        try {
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$newEmail, $uid]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new RuntimeException('Este e-mail já está em uso.');
            }
            throw new RuntimeException('Não foi possível actualizar o e-mail.');
        }
        $_SESSION['user_email'] = $newEmail;
        echo json_encode(['ok' => true, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'plans_buyable') {
        $rows = extractor_plans_list($pdo);
        echo json_encode(['ok' => true, 'plans' => $rows, 'credits' => (int) ($_SESSION['user_credits'] ?? 0)]);
        exit;
    }

    if ($action === 'pix_create') {
        require_once __DIR__ . '/includes/billing.php';
        require_once __DIR__ . '/includes/payment_settings.php';
        require_once __DIR__ . '/includes/mercadopago.php';
        $pcfg = extractor_payment_config($cfg);
        $planCode = trim((string) ($input['plan_code'] ?? ''));
        if ($planCode === '' || $planCode === 'super_master') {
            throw new RuntimeException('Plano inválido.');
        }
        $pst = $pdo->prepare('SELECT code, price_monthly, monthly_credits FROM plans WHERE code = ?');
        $pst->execute([$planCode]);
        $plan = $pst->fetch();
        if (!$plan) {
            throw new RuntimeException('Plano não encontrado.');
        }
        $amount = (float) $plan['price_monthly'];
        if ($amount <= 0) {
            throw new RuntimeException('Este plano não tem preço configurado para cobrança PIX.');
        }
        $provider = (string) ($pcfg['payment_provider'] ?? 'demo');
        $now = time();
        $pdo->prepare(
            'INSERT INTO payments (user_id, plan_code, amount, currency, status, provider, created_at) VALUES (?,?,?,?,?,?,?)'
        )->execute([$uid, $planCode, $amount, 'BRL', 'pending', $provider === 'asaas' ? 'asaas' : ($provider === 'mercadopago' ? 'mercadopago' : 'demo'), $now]);
        $lid = (int) $pdo->lastInsertId();
        $pix = '';
        $demo = true;
        if ($provider === 'mercadopago' && trim((string) ($pcfg['mercadopago_access_token'] ?? '')) !== '') {
            $r = extractor_mercadopago_create_pix($pdo, $pcfg, $lid);
            $pix = $r['pix_copy_paste'];
            $demo = $pix === '';
        } elseif ($provider === 'asaas' && trim((string) ($pcfg['asaas_api_key'] ?? '')) !== '') {
            $r = extractor_asaas_create_pix_for_payment($pdo, $pcfg, $lid);
            $pix = $r['pix_copy_paste'];
            $demo = $pix === '';
        }
        echo json_encode([
            'ok' => true,
            'payment_id' => $lid,
            'amount' => $amount,
            'amount_formatted' => extractor_money($amount),
            'plan_code' => $planCode,
            'pix_copy_paste' => $pix,
            'demo_mode' => $demo,
            'provider' => $provider,
            'credits' => (int) ($_SESSION['user_credits'] ?? 0),
        ]);
        exit;
    }

    if ($action === 'pix_status') {
        require_once __DIR__ . '/includes/billing.php';
        require_once __DIR__ . '/includes/payment_settings.php';
        require_once __DIR__ . '/includes/mercadopago.php';
        $pcfg = extractor_payment_config($cfg);
        $pid = (int) ($input['payment_id'] ?? 0);
        if ($pid < 1) {
            throw new RuntimeException('ID inválido.');
        }
        $st = $pdo->prepare('SELECT * FROM payments WHERE id = ? AND user_id = ?');
        $st->execute([$pid, $uid]);
        $pay = $st->fetch();
        if (!$pay) {
            throw new RuntimeException('Pagamento não encontrado.');
        }
        if (($pay['status'] ?? '') === 'pending') {
            $prov = (string) ($pay['provider'] ?? '');
            if ($prov === 'mercadopago' && trim((string) ($pcfg['mercadopago_access_token'] ?? '')) !== '') {
                extractor_mercadopago_fulfil_if_approved($pdo, $cfg, $pid);
            } elseif ($prov === 'asaas' && trim((string) ($pcfg['asaas_api_key'] ?? '')) !== '') {
                extractor_fulfil_payment_if_paid($pdo, $pcfg, $pid);
            }
            $st->execute([$pid, $uid]);
            $pay = $st->fetch();
            extractor_user_refresh_session($pdo);
        }
        echo json_encode([
            'ok' => true,
            'payment' => $pay,
            'credits' => (int) ($_SESSION['user_credits'] ?? 0),
        ]);
        exit;
    }

    if ($action === 'ticket_create') {
        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $priority = trim((string) ($input['priority'] ?? 'normal'));
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $priority = 'normal';
        }
        if ($subject === '' || strlen($subject) > 200) {
            throw new RuntimeException('Assunto inválido (máx. 200 caracteres).');
        }
        if ($body === '' || strlen($body) > 8000) {
            throw new RuntimeException('Mensagem inválida (máx. 8000 caracteres).');
        }
        $now = time();
        $pdo->prepare(
            'INSERT INTO support_tickets (user_id, subject, body, priority, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?)'
        )->execute([$uid, $subject, $body, $priority, 'open', $now, $now]);
        $tid = (int) $pdo->lastInsertId();
        echo json_encode(['ok' => true, 'ticket_id' => $tid]);
        exit;
    }

    if ($action === 'ticket_list') {
        $st = $pdo->prepare('SELECT * FROM support_tickets WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $st->execute([$uid]);
        echo json_encode(['ok' => true, 'tickets' => $st->fetchAll()]);
        exit;
    }

    if ($action === 'ticket_get') {
        $tid = (int) ($input['id'] ?? 0);
        if ($tid < 1) {
            throw new RuntimeException('ID inválido.');
        }
        $st = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?');
        $st->execute([$tid, $uid]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('Ticket não encontrado.');
        }
        $ms = $pdo->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id = ? ORDER BY id ASC');
        $ms->execute([$tid]);
        echo json_encode(['ok' => true, 'ticket' => $t, 'messages' => $ms->fetchAll()]);
        exit;
    }

    if ($action === 'ticket_reply') {
        $tid = (int) ($input['id'] ?? 0);
        $body = trim((string) ($input['body'] ?? ''));
        if ($tid < 1 || $body === '' || strlen($body) > 8000) {
            throw new RuntimeException('Dados inválidos.');
        }
        $st = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?');
        $st->execute([$tid, $uid]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('Ticket não encontrado.');
        }
        if (($t['status'] ?? '') === 'closed') {
            throw new RuntimeException('Ticket fechado.');
        }
        $now = time();
        $pdo->prepare(
            'INSERT INTO support_ticket_messages (ticket_id, author_user_id, body, created_at) VALUES (?,?,?,?)'
        )->execute([$tid, $uid, $body, $now]);
        $pdo->prepare('UPDATE support_tickets SET status = ?, updated_at = ? WHERE id = ?')->execute(['in_progress', $now, $tid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'acao_desconhecida']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
