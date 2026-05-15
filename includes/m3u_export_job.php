<?php

declare(strict_types=1);

require_once __DIR__ . '/m3u.php';
require_once __DIR__ . '/m3u_player.php';

function extractor_m3u_jobs_dir(): string
{
    $dir = EXTRACTOR_DATA . '/m3u_jobs';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    return $dir;
}

function extractor_m3u_job_path(string $jobId): string
{
    $safe = preg_replace('/[^a-f0-9]/', '', strtolower($jobId));

    return extractor_m3u_jobs_dir() . '/' . $safe . '.json';
}

function extractor_m3u_job_seen_db_path(string $jobId): string
{
    $safe = preg_replace('/[^a-f0-9]/', '', strtolower($jobId));

    return extractor_m3u_jobs_dir() . '/' . $safe . '.seen.sqlite';
}

function extractor_m3u_job_seen_db(string $jobId): PDO
{
    $pdo = new PDO('sqlite:' . extractor_m3u_job_seen_db_path($jobId));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS seen (k TEXT PRIMARY KEY)');

    return $pdo;
}

function extractor_m3u_job_seen_is_new(PDO $seenDb, string $key): bool
{
    $st = $seenDb->prepare('INSERT OR IGNORE INTO seen (k) VALUES (?)');
    $st->execute([$key]);
    return $st->rowCount() > 0;
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
    file_put_contents($dest, "#EXTM3U\n");
    $job = [
        'job_id' => $jobId,
        'user_id' => $userId,
        'playlist_id' => $playlistId,
        'mode' => $mode,
        'source_path' => $sourcePath,
        'dest_path' => $dest,
        'read_state' => extractor_m3u_read_state_default(),
        'total' => $totalHint > 0 ? $totalHint : 1,
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
    extractor_m3u_job_save($job);

    return ['job_id' => $jobId, 'total' => (int) $job['total']];
}

/**
 * @param array<string, mixed> $job
 */
function extractor_m3u_job_process_batch(array &$job, $fh, ?PDO $seenDb): bool
{
    $mode = (string) $job['mode'];
    $source = (string) $job['source_path'];
    $batchSize = $mode === 'convert' ? 2500 : 6000;
    $readState = (array) ($job['read_state'] ?? extractor_m3u_read_state_default());
    $chunk = extractor_m3u_read_batch($source, $readState, $batchSize, 'all');
    $entries = $chunk['entries'];
    $job['read_state'] = $chunk['read_state'];
    $job['scanned'] = (int) $job['scanned'] + count($entries);

    if ($entries === []) {
        return $chunk['eof'];
    }

    $writerState = ['last' => (array) ($job['writer_last'] ?? [])];

    foreach ($entries as $entry) {
        if ($mode === 'convert') {
            $classified = extractor_m3u_classify_player($entry);
            $key = $classified['dedupe_key'];
            if ($seenDb !== null && !extractor_m3u_job_seen_is_new($seenDb, $key)) {
                $job['skipped'] = (int) $job['skipped'] + 1;
                continue;
            }
            if (extractor_m3u_writer_append_player($fh, $writerState, $classified, $entry)) {
                $job['written'] = (int) $job['written'] + 1;
                $t = $classified['type'];
                if (isset($job['stats'][$t])) {
                    $job['stats'][$t] = (int) $job['stats'][$t] + 1;
                }
            }
        } elseif (extractor_m3u_writer_append_simple($fh, $writerState, $entry)) {
            $job['written'] = (int) $job['written'] + 1;
        }
    }

    $job['writer_last'] = $writerState['last'];

    return $chunk['eof'];
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

    $fh = fopen((string) $job['dest_path'], 'ab');
    if ($fh === false) {
        throw new RuntimeException('Não foi possível escrever o ficheiro M3U.');
    }

    $seenDb = (string) $job['mode'] === 'convert' ? extractor_m3u_job_seen_db($jobId) : null;
    $loops = (string) $job['mode'] === 'convert' ? 2 : 3;
    $fileDone = false;

    for ($i = 0; $i < $loops; $i++) {
        if (extractor_m3u_job_process_batch($job, $fh, $seenDb)) {
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
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function extractor_m3u_job_finish(array $job, PDO $pdo): array
{
    $written = (int) $job['written'];
    if ($written < 1) {
        @unlink((string) $job['dest_path']);
        @unlink(extractor_m3u_job_seen_db_path((string) $job['job_id']));
        throw new RuntimeException('Nenhum item exportado.');
    }
    $mode = (string) $job['mode'];
    $label = $mode === 'convert'
        ? 'Player ' . date('d/m H:i')
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
    @unlink(extractor_m3u_job_seen_db_path((string) $job['job_id']));

    return extractor_m3u_job_status_payload($job);
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function extractor_m3u_job_status_payload(array $job): array
{
    $total = max(1, (int) ($job['total'] ?? 1));
    $processed = (int) ($job['scanned'] ?? 0);
    $percent = min(100, (int) round(($processed / $total) * 100));
    if (!empty($job['done'])) {
        $percent = 100;
    }
    $mode = (string) ($job['mode'] ?? '');
    $msg = $mode === 'convert'
        ? 'A organizar como player (filmes, séries, canais)…'
        : 'A gerar M3U aberta…';
    if (!empty($job['done'])) {
        $msg = 'Concluído — ' . (int) $job['written'] . ' itens';
        if ((int) ($job['skipped'] ?? 0) > 0) {
            $msg .= ' (' . (int) $job['skipped'] . ' duplicados ignorados)';
        }
        if ($mode === 'convert') {
            $st = $job['stats'] ?? [];
            $msg .= ' · ' . (int) ($st['movie'] ?? 0) . ' filmes, ' . (int) ($st['series'] ?? 0) . ' séries, ' . (int) ($st['live'] ?? 0) . ' canais';
        }
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
    ];
}
