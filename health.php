<?php

declare(strict_types=1);

/**
 * Página de diagnóstico — abra no browser após git pull para confirmar que o código está actualizado.
 * Ex.: http://SEU-IP/health.php  — apague ou bloqueie em produção se preferir.
 */
require __DIR__ . '/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$checks = [];

$buildId = defined('EXTRACTOR_BUILD_ID') ? (string) EXTRACTOR_BUILD_ID : '(bootstrap antigo — falta git pull completo)';
$checks['build_id'] = [
    'ok' => defined('EXTRACTOR_BUILD_ID'),
    'label' => 'Versão do código (BUILD_ID)',
    'detail' => $buildId,
];

$panelPhp = is_file(__DIR__ . '/panel.php') ? (string) file_get_contents(__DIR__ . '/panel.php') : '';
$apiPhp = is_file(__DIR__ . '/api.php') ? (string) file_get_contents(__DIR__ . '/api.php') : '';
$masterExtratorOk = is_file(__DIR__ . '/includes/master_extractor.php')
    && str_contains($panelPhp, 'id="sec-master"')
    && str_contains($panelPhp, 'master_scan_reserve')
    && str_contains($apiPhp, 'master_scan_reserve')
    && str_contains($apiPhp, 'master_extractor.php');
$checks['master_extrator_deploy'] = [
    'ok' => $masterExtratorOk,
    'label' => 'Master Extrator (painel + API)',
    'detail' => $masterExtratorOk
        ? 'OK — botão Master Extrator, sec-master e endpoints API presentes.'
        : 'Em FALHA: copie do projecto actual — includes/master_extractor.php, panel.php, api.php, bootstrap.php e includes/db.php (tabelas/colunas master_scan_*). Ou faça git pull na pasta física correcta do site no IIS.',
];

$forceOk = is_file(__DIR__ . '/includes/master_force.php')
    && is_file(__DIR__ . '/includes/master_force_helpers.php')
    && str_contains($panelPhp, 'id="sec-force"')
    && str_contains($panelPhp, 'master_force_reserve')
    && str_contains($apiPhp, 'master_force_reserve')
    && str_contains($apiPhp, 'master_force.php');
$checks['force_discovery_deploy'] = [
    'ok' => $forceOk,
    'label' => 'Descoberta Forçada',
    'detail' => $forceOk
        ? 'OK — includes/master_force*.php, sec-force e endpoints API.'
        : 'Em FALHA: atualize panel.php, api.php, includes/db.php, includes/master_force.php e helpers.',
];

$checks['urls_helper'] = [
    'ok' => function_exists('extractor_url'),
    'label' => 'Helper extractor_url (includes/urls.php)',
    'detail' => function_exists('extractor_url') ? 'OK — caminhos relativos à subpasta' : 'FALTA — faça git pull',
];

$checks['index_hero'] = [
    'ok' => is_file(__DIR__ . '/index.php') && str_contains((string) file_get_contents(__DIR__ . '/index.php'), 'Organize e'),
    'label' => 'Landing nova (index.php)',
    'detail' => 'Texto "Organize e descarregue" presente no ficheiro',
];

$idxRaw = is_file(__DIR__ . '/index.php') ? (string) file_get_contents(__DIR__ . '/index.php') : '';
$checks['precos_brl'] = [
    'ok' => $idxRaw !== '' && str_contains($idxRaw, 'extractor_money') && !str_contains($idxRaw, '€'),
    'label' => 'Preços em R$ (sem €)',
    'detail' => str_contains($idxRaw, '€') ? 'Ainda há € no index — faça git pull' : 'OK — extractor_money()',
];

$checks['mercadopago_module'] = [
    'ok' => is_file(__DIR__ . '/includes/mercadopago.php'),
    'label' => 'API Mercado Pago (includes/mercadopago.php)',
    'detail' => is_file(__DIR__ . '/includes/mercadopago.php') ? 'Presente' : 'FALTA — git pull origin main',
];

$checks['payment_settings_module'] = [
    'ok' => is_file(__DIR__ . '/includes/payment_settings.php'),
    'label' => 'Config pagamentos (payment_settings.php)',
    'detail' => is_file(__DIR__ . '/includes/payment_settings.php') ? 'Presente' : 'FALTA — git pull',
];

$checks['branding_module'] = [
    'ok' => is_file(__DIR__ . '/includes/branding.php'),
    'label' => 'Aparência / CMS (branding.php)',
    'detail' => is_file(__DIR__ . '/includes/branding.php') ? 'Presente — Admin → Aparência' : 'FALTA — git pull',
];

$checks['config'] = [
    'ok' => extractor_config_exists(),
    'label' => 'config.local.php',
    'detail' => extractor_config_exists() ? 'Existe' : 'Ausente — copie de config.example.php',
];

$checks['pdo_sqlite'] = [
    'ok' => extension_loaded('pdo_sqlite'),
    'label' => 'Extensão PHP pdo_sqlite',
    'detail' => extension_loaded('pdo_sqlite') ? 'Carregada' : 'Não carregada — edite php.ini',
];

$dataWritable = is_dir(EXTRACTOR_DATA) && is_writable(EXTRACTOR_DATA);
$sessWritable = is_dir(EXTRACTOR_DATA . '/sessions') && is_writable(EXTRACTOR_DATA . '/sessions');
$checks['data_writable'] = [
    'ok' => $dataWritable,
    'label' => 'Pasta data/ gravável',
    'detail' => $dataWritable ? 'OK' : 'Sem permissão — icacls no IIS AppPool',
];
$checks['sessions_writable'] = [
    'ok' => $sessWritable,
    'label' => 'Pasta data/sessions/ gravável',
    'detail' => $sessWritable ? 'OK' : 'Sem permissão — registo/login podem falhar',
];

$dbOk = false;
$dbErr = '';
if ($checks['config']['ok'] && $checks['pdo_sqlite']['ok']) {
    try {
        require_once __DIR__ . '/includes/db.php';
        extractor_pdo();
        $dbOk = true;
    } catch (Throwable $e) {
        $dbErr = $e->getMessage();
    }
}
$checks['database'] = [
    'ok' => $dbOk,
    'label' => 'Base SQLite (app.sqlite)',
    'detail' => $dbOk ? 'Ligação OK' : ($dbErr !== '' ? $dbErr : 'Não testado'),
];

$base = extractor_base_path();
$checks['base_path'] = [
    'ok' => true,
    'label' => 'Caminho base do site (subpasta)',
    'detail' => $base === '' ? '(raiz — ex. C:\\Apps\\Extrator)' : $base,
];

$allOk = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $allOk = false;
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
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Diagnóstico — Extrator</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #0a0c14; color: #e9ecf5; padding: 1.5rem; max-width: 42rem; margin: 0 auto; }
    h1 { font-size: 1.25rem; }
    table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.9rem; }
    th, td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid #333; vertical-align: top; }
    .ok { color: #4ade80; font-weight: 700; }
    .fail { color: #f87171; font-weight: 700; }
    .box { background: #161a26; border: 1px solid #333; border-radius: 10px; padding: 1rem; margin: 1rem 0; }
    a { color: #5b7cfa; }
    code { font-size: 0.85rem; }
  </style>
</head>
<body>
  <h1>Diagnóstico do Extrator</h1>
  <p>Se <strong>BUILD_ID</strong> não for <code><?= h(EXTRACTOR_BUILD_ID) ?></code>, o servidor <strong>não tem o código mais recente</strong> — execute <code>git pull</code> em <code>C:\Apps\Extrator</code>.</p>
  <p class="<?= $allOk ? 'ok' : 'fail' ?>"><?= $allOk ? 'Tudo OK para uso básico.' : 'Há problemas — corrija os itens em vermelho.' ?></p>
  <table>
    <thead><tr><th>Estado</th><th>Verificação</th><th>Detalhe</th></tr></thead>
    <tbody>
      <?php foreach ($checks as $c): ?>
      <tr>
        <td class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? 'OK' : 'FALHA' ?></td>
        <td><?= h($c['label']) ?></td>
        <td><?= h($c['detail']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="box">
    <strong>No VPS (PowerShell):</strong>
    <pre style="margin:0.5rem 0 0;overflow:auto;font-size:0.8rem;">cd C:\Apps\Extrator
&amp; "C:\Program Files\Git\bin\git.exe" pull origin main
&amp; "C:\Program Files\Git\bin\git.exe" log -1 --oneline</pre>
    <p style="margin:0.75rem 0 0;font-size:0.85rem;color:#8b95b0;">O último commit deve incluir <code>URLs base para subpasta</code> ou BUILD_ID acima.</p>
  </div>
  <p><a href="<?= h(extractor_url('index.php')) ?>">Ir para a página inicial</a> · <a href="<?= h(extractor_url('register.php')) ?>">Registo</a></p>
</body>
</html>
