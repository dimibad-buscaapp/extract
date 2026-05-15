<?php

declare(strict_types=1);

/**
 * Diagnóstico SEM dependências — use se health.php der erro 500.
 * http://SEU-IP/diag.php
 */
header('Content-Type: text/html; charset=utf-8');

$root = __DIR__;
$data = $root . DIRECTORY_SEPARATOR . 'data';
$sess = $data . DIRECTORY_SEPARATOR . 'sessions';

$checks = [
    ['ok' => is_file($root . '/health.php'), 'label' => 'health.php existe', 'detail' => is_file($root . '/health.php') ? 'sim' : 'não — git pull ou script atualizar'],
    ['ok' => is_file($root . '/includes/urls.php'), 'label' => 'includes/urls.php', 'detail' => is_file($root . '/includes/urls.php') ? 'sim' : 'FALTA — causa comum de erro 500'],
    ['ok' => is_file($root . '/bootstrap.php'), 'label' => 'bootstrap.php', 'detail' => 'presente'],
    ['ok' => extension_loaded('pdo_sqlite'), 'label' => 'PHP pdo_sqlite', 'detail' => extension_loaded('pdo_sqlite') ? 'activo' : 'activar no php.ini'],
    ['ok' => is_dir($data) && is_writable($data), 'label' => 'data/ gravável', 'detail' => is_writable($data) ? 'sim' : 'icacls IIS AppPool'],
    ['ok' => is_dir($sess) && is_writable($sess), 'label' => 'data/sessions/ gravável', 'detail' => is_dir($sess) ? (is_writable($sess) ? 'sim' : 'criar permissões') : 'criar pasta'],
    ['ok' => is_file($root . '/config.local.php'), 'label' => 'config.local.php', 'detail' => is_file($root . '/config.local.php') ? 'sim' : 'copiar de config.example.php'],
];

$bootstrapSnippet = '';
$bf = $root . '/bootstrap.php';
if (is_file($bf)) {
    $bootstrapSnippet = file_get_contents($bf) ?: '';
}
$hasBuild = str_contains($bootstrapSnippet, 'EXTRACTOR_BUILD_ID');
$hasUrlsRequire = str_contains($bootstrapSnippet, 'includes/urls.php');

$checks[] = [
    'ok' => $hasBuild && $hasUrlsRequire && is_file($root . '/includes/urls.php'),
    'label' => 'bootstrap + urls alinhados',
    'detail' => !$hasUrlsRequire ? 'bootstrap antigo' : (!is_file($root . '/includes/urls.php') ? 'bootstrap novo mas falta urls.php' : 'OK'),
];

if (is_file($bf) && is_file($root . '/includes/urls.php') && extension_loaded('pdo_sqlite') && is_file($root . '/config.local.php')) {
    try {
        require $bf;
        require_once $root . '/includes/db.php';
        extractor_pdo();
        $checks[] = ['ok' => true, 'label' => 'SQLite (app.sqlite)', 'detail' => 'ligação OK'];
    } catch (Throwable $e) {
        $checks[] = ['ok' => false, 'label' => 'SQLite (app.sqlite)', 'detail' => $e->getMessage()];
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <title>Diag simples — Extrator</title>
  <style>
    body{font-family:system-ui,sans-serif;background:#0a0c14;color:#eee;padding:1.5rem;max-width:44rem;margin:0 auto}
    table{width:100%;border-collapse:collapse;font-size:0.9rem}
    td,th{padding:0.45rem;border-bottom:1px solid #333;text-align:left}
    .ok{color:#4ade80;font-weight:700}.fail{color:#f87171;font-weight:700}
    pre{background:#111;padding:0.75rem;font-size:0.78rem;overflow:auto}
  </style>
</head>
<body>
  <h1>Diagnóstico simples</h1>
  <p>Se só descarregou <code>health.php</code> do GitHub, o site pode dar <strong>500</strong> porque faltam outros ficheiros (<code>includes/urls.php</code>, etc.). Use o script <code>atualizar-vps.ps1</code> ou <code>git pull</code>.</p>
  <table>
    <?php foreach ($checks as $c): ?>
    <tr>
      <td class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? 'OK' : 'FALHA' ?></td>
      <td><?= h($c['label']) ?></td>
      <td><?= h($c['detail']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <h2>Corrigir (PowerShell no VPS)</h2>
  <pre>cd C:\Apps\Extrator
# Opção A — Git (melhor)
&amp; "C:\Program Files\Git\bin\git.exe" pull origin main

# Opção B — script na pasta tools (depois do pull uma vez)
# .\tools\atualizar-vps.ps1</pre>
  <p><a href="index.php">index.php</a></p>
</body>
</html>
