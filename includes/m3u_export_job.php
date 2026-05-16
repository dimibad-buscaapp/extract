<?php

declare(strict_types=1);

require_once __DIR__ . '/m3u.php';
require_once __DIR__ . '/m3u_panel.php';
require_once __DIR__ . '/m3u_player.php';
require_once __DIR__ . '/m3u_job_seen.php';
require_once __DIR__ . '/m3u_xtream_convert.php';
require_once __DIR__ . '/m3u_xtream_tree.php';

function extractor_m3u_job_path(string $jobId): string
{
    $safe = preg_replace('/[^a-f0-9]/', '', strtolower($jobId));

    return extractor_m3u_jobs_dir() . '/' . $safe . '.json';
}

/**
 * @return array<string, mixed>
 */
function extractor_m3u_job_load(string $jobId, int $userId): array
{
    $path = extractor_m3u_job_path($jobId);
    if (!is_file($path)) {
        throw new RuntimeException('Tarefa não encontrada.');
    }
    $job = json_decode((string) file_get_contents($path), true);
    if (!is_array($job) || (int) ($job['user_id'] ?? 0) !== $userId) {
        throw new RuntimeException('Sem permissão para esta tarefa.');
    }

    return $job;
}

/**
 * @param array<string, mixed> $job
 */
function extractor_m3u_job_save(array $job): void
{
    $jobId = (string) ($job['job_id'] ?? '');
    file_put_contents(
        extractor_m3u_job_path($jobId),
        json_encode($job, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/**
 * @return array{job_id: string, total: int}
 */
function extractor_m3u_job_begin(int $userId, int $playlistId, string $mode, string $sourcePath, int $totalHint = 0): array
{
    if (!in_array($mode, ['all_open', 'convert'], true)) {
        throw new RuntimeException('Modo de exportação inválido.');
    }
    $jobId = bin2hex(random_bytes(8));
    $dest = EXTRACTOR_DATA . '/job_' . $jobId . '.m3u';
    if ($mode !== 'convert') {
        file_put_contents($dest, "#EXTM3U\n");
    }
    $job = [
        'job_id' => $jobId,
        'user_id' => $userId,
        'playlist_id' => $playlistId,
        'mode' => $mode,
        'source_path' => $sourcePath,
        'dest_path' => $dest,
        'read_state' => extractor_m3u_read_state_default(),
        'total' => 1,
        'scanned' => 0,
        'written' => 0,
        'skipped' => 0,
        'stats' => ['movie' => 0, 'series' => 0, 'live' => 0],
        'writer_last' => [],
        'done' => false,
        'error' => '',
        'download_url' => '',
        'playlist_new_id' => 0,
    ];

    if ($mode === 'convert') {
        set_time_limit(300);
        $treeDb = extractor_m3u_tree_db($jobId);
        $seenDb = extractor_m3u_job_seen_db($jobId);
        $job['xtream'] = extractor_m3u_xtream_prepare($sourcePath, $jobId, $treeDb, $seenDb);
        if (empty($job['xtream']['api_ok'])) {
            @unlink(extractor_m3u_tree_db_path($jobId));
            @unlink(extractor_m3u_job_seen_db_path($jobId));
            throw new RuntimeException((string) ($job['xtream']['message'] ?? 'API Xtream indisponível.'));
        }
        $job['stats']['live'] = (int) ($job['xtream']['live'] ?? 0);
        $job['stats']['movie'] = (int) ($job['xtream']['movie'] ?? 0);
        $job['stats']['series'] = 0;
        $job['written'] = $job['stats']['live'] + $job['stats']['movie'];
        $seriesTotal = count((array) ($job['xtream']['series_ids'] ?? []));
        $job['total'] = max(1, $seriesTotal > 0 ? $seriesTotal : 1);
    } elseif ($totalHint > 0) {
        $job['total'] = $totalHint;
    }

    extractor_m3u_job_save($job);

    $out = ['job_id' => $jobId, 'total' => (int) $job['total']];
    if ($mode === 'convert') {
        $out['xtream'] = $job['xtream'];
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function extractor_m3u_job_step(string $jobId, int $userId, PDO $pdo): array
{
    set_time_limit(180);
    $job = extractor_m3u_job_load($jobId, $userId);
    if (!empty($job['done'])) {
        return extractor_m3u_job_status_payload($job);
    }
    if (!empty($job['error'])) {
        throw new RuntimeException((string) $job['error']);
    }

    $mode = (string) $job['mode'];

    if ($mode === 'convert') {
        $phase = (string) (($job['xtream']['phase'] ?? ''));
        if ($phase === 'failed') {
            throw new RuntimeException((string) ($job['xtream']['message'] ?? 'Conversão falhou.'));
        }
        if ($phase === 'done') {
            return extractor_m3u_job_finish($job, $pdo);
        }

        $treeDb = extractor_m3u_tree_db($jobId);
        $seenDb = extractor_m3u_job_seen_db($jobId);

        for ($s = 0; $s < 3; $s++) {
            extractor_m3u_xtream_job_series_step($job, $treeDb, $seenDb);
            if (($job['xtream']['phase'] ?? '') !== 'series') {
                break;
            }
        }
        extractor_m3u_job_save($job);

        if (($job['xtream']['phase'] ?? '') === 'series') {
            return extractor_m3u_job_status_payload($job);
        }

        return extractor_m3u_job_finish($job, $pdo);
    }

    $fh = fopen((string) $job['dest_path'], 'ab');
    if ($fh === false) {
        throw new RuntimeException('Não foi possível escrever o ficheiro M3U.');
    }

    $fileDone = false;
    for ($i = 0; $i < 3; $i++) {
        if (extractor_m3u_job_process_batch_open($job, $fh)) {
            $fileDone = true;
            break;
        }
    }
    fclose($fh);
    extractor_m3u_job_save($job);

    if ($fileDone) {
        return extractor_m3u_job_finish($job, $pdo);
    }

    return extractor_m3u_job_status_payload($job);
}

/**
 * Nova M3U aberta — leitura incremental do ficheiro.
 *
 * @param array<string, mixed> $job
 */
function extractor_m3u_job_process_batch_open(array &$job, $fh): bool
{
    $source = (string) $job['source_path'];
    $readState = (array) ($job['read_state'] ?? extractor_m3u_read_state_default());
    $chunk = extractor_m3u_read_batch($source, $readState, 6000, 'all');
    $entries = $chunk['entries'];
    $job['read_state'] = $chunk['read_state'];
    $job['scanned'] = (int) $job['scanned'] + count($entries);

    if ($entries === []) {
        return $chunk['eof'];
    }

    $writerState = ['last' => (array) ($job['writer_last'] ?? [])];
    foreach ($entries as $entry) {
        if (is_resource($fh) && extractor_m3u_writer_append_simple($fh, $writerState, $entry)) {
            $job['written'] = (int) $job['written'] + 1;
        }
    }
    $job['writer_last'] = $writerState['last'];

    return $chunk['eof'];
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function extractor_m3u_job_finish(array $job, PDO $pdo): array
{
    $jobId = (string) ($job['job_id'] ?? '');
    $mode = (string) ($job['mode'] ?? '');

    if ($mode === 'convert') {
        $treeDb = extractor_m3u_tree_db($jobId);
        $written = extractor_m3u_tree_write_m3u($treeDb, (string) $job['dest_path']);
        $job['written'] = $written;
        $job['stats'] = extractor_m3u_tree_stats($treeDb);
        @unlink(extractor_m3u_tree_db_path($jobId));
        $metaPath = (string) (($job['xtream']['series_meta_path'] ?? ''));
        if ($metaPath !== '' && is_file($metaPath)) {
            @unlink($metaPath);
        }
    }

    $written = (int) $job['written'];
    if ($written < 1) {
        @unlink((string) $job['dest_path']);
        @unlink(extractor_m3u_job_seen_db_path($jobId));
        @unlink(extractor_m3u_tree_db_path($jobId));
        throw new RuntimeException('Nenhum item exportado.');
    }

    $label = $mode === 'convert'
        ? 'Litoral Flix ' . date('d/m H:i')
        : 'Nova M3U ' . date('d/m H:i');
    $newId = extractor_m3u_register_playlist(
        $pdo,
        (int) $job['user_id'],
        (string) $job['dest_path'],
        $label,
        null,
        $written
    );
    $job['done'] = true;
    $job['playlist_new_id'] = $newId;
    $job['download_url'] = extractor_absolute_url('download.php?m3u_id=' . $newId);
    extractor_m3u_job_save($job);
    @unlink(extractor_m3u_job_seen_db_path($jobId));

    return extractor_m3u_job_status_payload($job);
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function extractor_m3u_job_status_payload(array $job): array
{
    $xt = (array) ($job['xtream'] ?? []);
    $total = max(1, (int) ($job['total'] ?? 1));
    $processed = (int) ($job['scanned'] ?? 0);
    if (($xt['phase'] ?? '') === 'series' && !empty($xt['series_ids'])) {
        $processed = (int) ($xt['series_offset'] ?? 0);
        $total = max($total, count($xt['series_ids']));
    }
    $percent = min(100, (int) round(($processed / $total) * 100));
    if (!empty($job['done'])) {
        $percent = 100;
    }

    $mode = (string) ($job['mode'] ?? '');
    $msg = !empty($xt['message'])
        ? (string) $xt['message']
        : ($mode === 'convert' ? 'A converter via API Litoral Flix…' : 'A gerar M3U aberta…');

    if (!empty($job['done']) && $mode === 'convert') {
        $st = $job['stats'] ?? [];
        $shows = (int) ($xt['series_shows'] ?? 0);
        $msg = sprintf(
            'Pronto: %d canais · %d filmes · %d séries · %d episódios na M3U',
            (int) ($st['live'] ?? 0),
            (int) ($st['movie'] ?? 0),
            $shows,
            (int) ($st['series'] ?? 0)
        );
    }

    return [
        'ok' => true,
        'job_id' => $job['job_id'],
        'percent' => $percent,
        'message' => $msg,
        'written' => (int) ($job['written'] ?? 0),
        'skipped' => (int) ($job['skipped'] ?? 0),
        'total' => $total,
        'processed' => $processed,
        'stats' => $job['stats'] ?? [],
        'done' => !empty($job['done']),
        'download_url' => (string) ($job['download_url'] ?? ''),
        'playlist_id' => (int) ($job['playlist_new_id'] ?? 0),
        'xtream' => $xt ?: null,
    ];
}
