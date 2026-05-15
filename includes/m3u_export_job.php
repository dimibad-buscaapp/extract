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

function extractor_m3u_job_seen_path(string $jobId): string
{
    $safe = preg_replace('/[^a-f0-9]/', '', strtolower($jobId));

    return extractor_m3u_jobs_dir() . '/' . $safe . '.seen';
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
        json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
        LOCK_EX
    );
}

/**
 * @return array{job_id: string, total: int}
 */
function extractor_m3u_job_begin(int $userId, int $playlistId, string $mode, string $sourcePath): array
{
    if (!in_array($mode, ['all_open', 'convert'], true)) {
        throw new RuntimeException('Modo de exportação inválido.');
    }
    $jobId = bin2hex(random_bytes(8));
    $dest = EXTRACTOR_DATA . '/job_' . $jobId . '.m3u';
    $total = extractor_m3u_count_entries($sourcePath);
    file_put_contents($dest, "#EXTM3U\n");
    $job = [
        'job_id' => $jobId,
        'user_id' => $userId,
        'playlist_id' => $playlistId,
        'mode' => $mode,
        'source_path' => $sourcePath,
        'dest_path' => $dest,
        'entry_offset' => 0,
        'total' => $total,
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

    return ['job_id' => $jobId, 'total' => $total];
}

/**
 * @return array<string, mixed>
 */
function extractor_m3u_job_step(string $jobId, int $userId, PDO $pdo): array
{
    set_time_limit(120);
    $job = extractor_m3u_job_load($jobId, $userId);
    if (!empty($job['done'])) {
        return extractor_m3u_job_status_payload($job);
    }
    if (!empty($job['error'])) {
        throw new RuntimeException((string) $job['error']);
    }

    $batch = 350;
    $source = (string) $job['source_path'];
    $offset = (int) $job['entry_offset'];
    $entries = extractor_m3u_list_entries($source, $offset, $batch, 'all');
    if ($entries === []) {
        return extractor_m3u_job_finish($job, $pdo);
    }

    $fh = fopen((string) $job['dest_path'], 'ab');
    if ($fh === false) {
        throw new RuntimeException('Não foi possível escrever o ficheiro M3U.');
    }
    $seenPath = extractor_m3u_job_seen_path($jobId);
    $seen = extractor_m3u_seen_load($seenPath);
    $writerState = ['last' => (array) ($job['writer_last'] ?? [])];
    $mode = (string) $job['mode'];

    foreach ($entries as $entry) {
        if ($mode === 'convert') {
            $classified = extractor_m3u_classify_player($entry);
            $key = $classified['dedupe_key'];
            if (isset($seen[$key])) {
                $job['skipped'] = (int) $job['skipped'] + 1;
                continue;
            }
            if (extractor_m3u_writer_append_player($fh, $writerState, $classified, $entry)) {
                $seen[$key] = true;
                extractor_m3u_seen_add($seenPath, $key);
                $job['written'] = (int) $job['written'] + 1;
                $t = $classified['type'];
                if (isset($job['stats'][$t])) {
                    $job['stats'][$t] = (int) $job['stats'][$t] + 1;
                }
            }
        } else {
            $key = 'o|' . md5($entry['url']);
            if (isset($seen[$key])) {
                $job['skipped'] = (int) $job['skipped'] + 1;
                continue;
            }
            if (extractor_m3u_writer_append_simple($fh, $writerState, $entry)) {
                $seen[$key] = true;
                extractor_m3u_seen_add($seenPath, $key);
                $job['written'] = (int) $job['written'] + 1;
            }
        }
    }
    fclose($fh);

    $job['entry_offset'] = $offset + count($entries);
    $job['writer_last'] = $writerState['last'];
    extractor_m3u_job_save($job);

    if (count($entries) < $batch) {
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
        throw new RuntimeException('Nenhum item exportado.');
    }
    $mode = (string) $job['mode'];
    $label = $mode === 'convert'
        ? 'Player ' . date('d/m H:i')
        : 'Nova M3U ' . date('d/m H:i');
    $newId = extractor_m3u_register_playlist($pdo, (int) $job['user_id'], (string) $job['dest_path'], $label, null);
    $job['done'] = true;
    $job['playlist_new_id'] = $newId;
    $job['download_url'] = extractor_absolute_url('download.php?m3u_id=' . $newId);
    extractor_m3u_job_save($job);

    return extractor_m3u_job_status_payload($job);
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function extractor_m3u_job_status_payload(array $job): array
{
    $total = max(1, (int) ($job['total'] ?? 1));
    $processed = (int) ($job['entry_offset'] ?? 0);
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
    }

    return [
        'ok' => true,
        'job_id' => $job['job_id'],
        'percent' => $percent,
        'message' => $msg,
        'written' => (int) ($job['written'] ?? 0),
        'skipped' => (int) ($job['skipped'] ?? 0),
        'total' => (int) ($job['total'] ?? 0),
        'processed' => $processed,
        'stats' => $job['stats'] ?? [],
        'done' => !empty($job['done']),
        'download_url' => (string) ($job['download_url'] ?? ''),
        'playlist_id' => (int) ($job['playlist_new_id'] ?? 0),
    ];
}
