<?php

declare(strict_types=1);

function extractor_m3u_jobs_dir(): string
{
    $dir = EXTRACTOR_DATA . '/m3u_jobs';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    return $dir;
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
