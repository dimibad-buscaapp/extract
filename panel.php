<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';

if (!extractor_logged_in()) {
    extractor_redirect('login.php');
}

$pdo = extractor_pdo();
extractor_user_refresh_session($pdo);
if (!extractor_logged_in()) {
    extractor_redirect('login.php');
}
$meCredits = (int) ($_SESSION['user_credits'] ?? 0);
$meRole = (string) ($_SESSION['user_role'] ?? '');
$meName = (string) ($_SESSION['user_name'] ?? '');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    extractor_verify_csrf();
    $form = (string) ($_POST['form'] ?? '');
    if ($form === 'm3u' || $form === 'xtream' || $form === 'download') {
        require_once __DIR__ . '/includes/db.php';
        require_once __DIR__ . '/includes/users.php';
        require_once __DIR__ . '/includes/m3u.php';
    }
    if ($form === 'm3u') {
        $pdo = extractor_pdo();
        $m3uUid = extractor_user_id();
        $url = trim((string) ($_POST['m3u_url'] ?? ''));
        $text = (string) ($_POST['m3u_text'] ?? '');
        if ($url !== '') {
            $fn = EXTRACTOR_DATA . '/lista_' . date('Ymd_His') . '.m3u';
            $r = extractor_stream_url_to_file($url, $fn);
            if (!$r['ok']) {
                $_SESSION['flash'] = ['type' => 'err', 'msg' => 'M3U URL: ' . ($r['error'] ?: 'erro ao descarregar')];
                if (is_file($fn)) {
                    @unlink($fn);
                }
            } elseif (!extractor_m3u_file_valid($fn)) {
                @unlink($fn);
                $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Resposta não parece um M3U válido (#EXTM3U).'];
            } else {
                $pid = extractor_m3u_register_playlist($pdo, $m3uUid, $fn, 'Lista ' . date('d/m H:i'), $url);
                $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'M3U guardado (' . extractor_format_bytes((int) $r['bytes']) . '). Veja em «Listas guardadas» abaixo.'];
                $_SESSION['m3u_highlight'] = $pid;
            }
        } elseif ($text !== '') {
            if (strlen($text) > 4 * 1024 * 1024) {
                $_SESSION['flash'] = ['type' => 'err', 'msg' => 'M3U colado demasiado grande (máx. 4 MB). Use o campo URL.'];
            } elseif (!str_contains($text, '#EXTM3U')) {
                $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Conteúdo não parece um M3U válido.'];
            } else {
                $fn = EXTRACTOR_DATA . '/lista_' . date('Ymd_His') . '.m3u';
                file_put_contents($fn, $text);
                $pid = extractor_m3u_register_playlist($pdo, $m3uUid, $fn, 'Lista ' . date('d/m H:i'), null);
                $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'M3U guardado (' . extractor_format_bytes(strlen($text)) . '). Veja em «Listas guardadas».'];
                $_SESSION['m3u_highlight'] = $pid;
            }
        } else {
            $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Informe a URL do M3U ou cole o conteúdo.'];
        }
        header('Location: ' . extractor_url('panel.php') . '#tools');
        exit;
    }
    if ($form === 'xtream') {
        $pdo = extractor_pdo();
        $m3uUid = extractor_user_id();
        $host = trim((string) ($_POST['xt_host'] ?? ''));
        $user = trim((string) ($_POST['xt_user'] ?? ''));
        $pass = trim((string) ($_POST['xt_pass'] ?? ''));
        $port = (int) ($_POST['xt_port'] ?? 8080);
        $https = isset($_POST['xt_https']);
        if ($host === '' || $user === '' || $pass === '') {
            $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Preencha host, usuário e senha Xtream.'];
        } else {
            $scheme = $https ? 'https' : 'http';
            $base = $scheme . '://' . $host . ':' . $port;
            $api = $base . '/player_api.php?username=' . rawurlencode($user) . '&password=' . rawurlencode($pass);
            $info = extractor_http_get($api);
            if (!$info['ok']) {
                $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Xtream API: ' . ($info['error'] ?: 'falha')];
            } else {
                $m3uUrl = $base . '/get.php?username=' . rawurlencode($user) . '&password=' . rawurlencode($pass) . '&type=m3u_plus&output=ts';
                $m3uPath = EXTRACTOR_DATA . '/lista_xtream_' . date('Ymd_His') . '.m3u';
                $m3u = extractor_stream_url_to_file($m3uUrl, $m3uPath);
                if (!$m3u['ok'] || !extractor_m3u_file_valid($m3uPath)) {
                    if (is_file($m3uPath)) {
                        @unlink($m3uPath);
                    }
                    file_put_contents(EXTRACTOR_DATA . '/xtream_info_' . date('Ymd_His') . '.json', (string) $info['body']);
                    file_put_contents(EXTRACTOR_DATA . '/playlist_url_' . date('Ymd_His') . '.txt', $m3uUrl . "\n");
                    $detail = $m3u['error'] !== '' ? ' (' . $m3u['error'] . ')' : '';
                    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'M3U automática falhou' . $detail . '; JSON e URL guardados em /data.'];
                } else {
                    $pid = extractor_m3u_register_playlist($pdo, $m3uUid, $m3uPath, 'Xtream ' . date('d/m H:i'), $m3uUrl);
                    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Lista M3U Xtream guardada (' . extractor_format_bytes((int) $m3u['bytes']) . '). Veja em «Listas guardadas».'];
                    $_SESSION['m3u_highlight'] = $pid;
                }
            }
        }
        header('Location: ' . extractor_url('panel.php') . '#tools');
        exit;
    }
    if ($form === 'download') {
        $url = trim((string) ($_POST['dl_url'] ?? ''));
        $cookie = trim((string) ($_POST['dl_cookie'] ?? ''));
        $suggest = trim((string) ($_POST['dl_name'] ?? ''));
        if ($url === '') {
            $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Informe a URL do arquivo.'];
        } else {
            $headers = [];
            if ($cookie !== '') {
                $headers[] = preg_match('/^\s*Cookie\s*:/i', $cookie) ? trim($cookie) : 'Cookie: ' . $cookie;
            }
            $name = $suggest !== '' ? extractor_safe_filename($suggest) : 'download_' . date('Ymd_His');
            $dest = EXTRACTOR_DATA . '/' . $name;
            $r = extractor_stream_url_to_file($url, $dest, $headers);
            if (!$r['ok']) {
                $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Download: ' . $r['error']];
            } else {
                $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Arquivo baixado (' . $r['bytes'] . ' bytes).'];
            }
        }
        header('Location: ' . extractor_url('panel.php') . '#tools');
        exit;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$m3uHighlight = (int) ($_SESSION['m3u_highlight'] ?? 0);
unset($_SESSION['m3u_highlight']);
$csrf = extractor_csrf_token();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h(extractor_site_name()) ?> — painel</title>
  <?= extractor_favicon_link_tags() ?>
  <style>
    :root { font-family: system-ui, sans-serif; line-height: 1.45; color:#e8e8ef; background:#0e0f14; }
    body { margin:0; display:flex; min-height:100vh; }
    aside { width:220px; background:#151824; border-right:1px solid #2c3140; padding:1rem 0.75rem; display:flex; flex-direction:column; }
    .brand { font-weight:800; padding-bottom:0.75rem; border-bottom:1px solid #2c3140; display:flex; align-items:center; gap:0.5rem; text-decoration:none; color:inherit; }
    .brand-logo { max-height:2rem; width:auto; display:block; }
    .brand-logo-sidebar { max-height:1.75rem; }
    .brand-text { font-weight:800; }
    nav { display:flex; flex-direction:column; gap:0.35rem; padding:1rem 0; flex:1; }
    nav button { text-align:left; padding:0.5rem 0.6rem; border-radius:8px; border:1px solid transparent; background:transparent; color:#c8d0e0; font-weight:600; cursor:pointer; margin:0; }
    nav button.active { background:#24304f; border-color:#3b6df6; color:#fff; }
    main { flex:1; padding:1rem 1.25rem 2rem; overflow:auto; }
    h1 { margin:0 0 0.75rem; font-size:1.25rem; }
    .card { background:#1a1c26; border:1px solid #2c3140; border-radius:10px; padding:1rem; max-width:900px; margin-bottom:1rem; }
    .sec { display:none; }
    .sec.active { display:block; }
    label { display:block; margin-top:0.55rem; font-size:0.88rem; color:#b7c0d3; }
    input, select, textarea { width:100%; box-sizing:border-box; margin-top:0.2rem; padding:0.45rem; border-radius:6px; border:1px solid #2f3548; background:#12131a; color:#e8e8ef; }
    textarea { min-height:6rem; font-family:ui-monospace, monospace; font-size:0.82rem; }
    button.primary { margin-top:0.75rem; padding:0.5rem 0.85rem; border:0; border-radius:8px; background:#3b6df6; color:#fff; font-weight:600; cursor:pointer; }
    button.secondary { margin-top:0.5rem; margin-right:0.35rem; padding:0.4rem 0.65rem; border:0; border-radius:8px; background:#3a3f52; color:#fff; cursor:pointer; }
    button.secondary.force-filt.active,
    button.force-filt.active {
      border:1px solid #9a5565;
      background:#3a282c;
      color:#fde8ea;
      font-weight:600;
    }
    .muted { color:#9aa3b2; font-size:0.85rem; }
    .ok { color:#8fd68f; }
    .err { color:#ff8a8a; }
    .tbl { width:100%; border-collapse:collapse; font-size:0.88rem; margin-top:0.5rem; }
    .tbl th, .tbl td { border-bottom:1px solid #2c3140; padding:0.4rem 0.3rem; text-align:left; vertical-align:top; word-break:break-all; }
    .m3u-actions { display:flex; flex-wrap:wrap; gap:0.35rem; align-items:center; }
    .m3u-act { display:inline-flex; align-items:center; justify-content:center; gap:0.2rem; padding:0.34rem 0.62rem; border-radius:8px; font-size:0.76rem; font-weight:600; line-height:1.2; border:1px solid transparent; cursor:pointer; text-decoration:none; white-space:nowrap; transition:transform .12s ease, filter .12s ease, box-shadow .12s ease; box-shadow:0 1px 2px rgba(0,0,0,.25); }
    .m3u-act:hover { filter:brightness(1.1); transform:translateY(-1px); }
    .m3u-act:active { transform:translateY(0); filter:brightness(0.95); }
    .m3u-act-dl { background:linear-gradient(145deg,#2d4a8a,#3b6df6); color:#fff; border-color:#5a84ff; }
    .m3u-act-cat { background:linear-gradient(145deg,#1e2a42,#2a3d5c); color:#c5d8ff; border-color:#3d5278; }
    .m3u-act-nova { background:linear-gradient(145deg,#1a3d34,#248f6a); color:#d4ffe8; border-color:#2fad7a; }
    .m3u-act-conv { background:linear-gradient(145deg,#3d2e14,#8a6420); color:#ffecc8; border-color:#c49a3a; }
    .m3u-act-del { background:linear-gradient(145deg,#4a1c1c,#9b3030); color:#ffd8d8; border-color:#c44; }
    .progress { height:10px; background:#0e0f14; border:1px solid #2c3140; border-radius:999px; overflow:hidden; margin-top:0.5rem; }
    .bar { height:100%; width:0%; background:linear-gradient(90deg,#3b6df6,#6a9cff); transition:width .25s; }
    dialog { border:1px solid #2c3140; border-radius:10px; background:#1a1c26; color:#e8e8ef; padding:0; max-width:32rem; width:calc(100% - 2rem); }
    dialog::backdrop { background:rgba(0,0,0,.55); }
    .dlg-body { padding:1rem; }
    .dlg-actions { display:flex; justify-content:flex-end; gap:0.5rem; margin-top:0.75rem; }
    @media(max-width:760px){ body{flex-direction:column;} aside{width:100%;} nav{flex-direction:row; flex-wrap:wrap;} }
  </style>
</head>
<body>
  <aside>
    <?= extractor_brand_html(['href' => extractor_url('index.php'), 'class' => 'brand', 'as_sidebar' => true]) ?>
    <div class="muted" style="font-size:0.78rem;padding:0.35rem 0;border-bottom:1px solid #2c3140;">
      <div><strong><?= h($meName !== '' ? $meName : 'Conta') ?></strong></div>
      <div>Nível: <code style="color:#9ec0ff;"><?= h($meRole) ?></code></div>
      <div>Créditos: <strong id="sidebarCredits"><?= (int) $meCredits ?></strong></div>
    </div>
    <nav>
      <button type="button" data-sec="master">Master Extrator</button>
      <button type="button" data-sec="force">Descoberta Forçada</button>
      <button type="button" class="active" data-sec="extract">Extrator</button>
      <button type="button" data-sec="sites">Sites</button>
      <button type="button" data-sec="lib">Biblioteca</button>
      <button type="button" data-sec="tools">M3U / Xtream</button>
      <button type="button" data-sec="support">Suporte</button>
      <button type="button" data-sec="shop">PIX / Planos</button>
      <button type="button" data-sec="account">Conta</button>
      <?php if (extractor_is_admin_panel_user()): ?>
      <a href="<?= h(extractor_url('admin.php')) ?>" style="display:block;padding:0.5rem 0.6rem;border-radius:8px;color:#8ec5ff;font-weight:600;text-decoration:none;border:1px solid transparent;">Administração</a>
      <?php endif; ?>
    </nav>
    <p class="muted" style="margin-top:auto;font-size:0.75rem;">100% na hospedagem. Sem JavaScript no servidor alheio — só HTML estático + PHP.</p>
  </aside>
  <main>
    <h1 id="title">Extrator</h1>
    <?php if (is_array($flash ?? null)): ?>
      <p class="<?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'err' ?>"><?= h((string) ($flash['msg'] ?? '')) ?></p>
    <?php endif; ?>

    <section id="sec-master" class="sec">
      <div class="card">
        <p class="muted" style="margin-top:0;">Rastreio em <strong>profundidade</strong> apenas no mesmo domínio da URL de conteúdo do site (PHP/cURL no VPS). Reutiliza o <strong>cookie</strong> do cadastro. Detecta links para ficheiros, Google&nbsp;Drive, Mega, MediaFire, etc. e guarda histórico na base local.</p>
        <label>Site</label>
        <select id="masterSite"></select>
        <label>Máx. páginas HTML (1–400)</label>
        <input type="number" id="masterMaxPages" min="1" max="400" value="80" />
        <label>Profundidade de links internos (0–6)</label>
        <input type="number" id="masterMaxDepth" min="0" max="6" value="2" />
        <button type="button" class="primary" id="btnMasterScan">Executar varredura Master</button>
        <p class="muted" id="masterScanStatus">—</p>
        <div id="masterProgressWrap" class="progress" style="display:none;margin-top:0.65rem;"><div class="bar" id="masterBar" style="width:0%;"></div></div>
        <p class="muted" style="font-size:0.8rem;margin-top:0.35rem;">Progresso em tempo real: o pedido de varredura corre noutra ligação ao servidor; os créditos são debitados ao reservar (ver <code>credits_per_master_scan</code> no config).</p>
        <div id="masterStats" style="display:none;margin-top:0.75rem;font-size:0.88rem;"></div>
        <h2 style="margin:1rem 0 0.5rem;font-size:1rem;">Últimas execuções</h2>
        <table class="tbl" id="masterRunsTbl">
          <thead><tr><th>ID</th><th>Data</th><th>Estado</th><th>Págs.</th><th>Itens</th><th>Site</th><th>Erro</th></tr></thead>
          <tbody></tbody>
        </table>
        <h2 style="margin:1rem 0 0.5rem;font-size:1rem;">Resultados</h2>
        <p class="muted" style="font-size:0.82rem;margin:0 0 0.35rem;">Clique numa linha em «Últimas execuções» para carregar os itens (ou após uma varredura concluída).</p>
        <div style="max-height:min(50vh,22rem);overflow:auto;">
          <table class="tbl" id="masterItemsTbl">
            <thead><tr><th>Nome</th><th>Serviço</th><th>Tipo</th><th>URL</th><th>Dica download</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </section>

    <section id="sec-force" class="sec">
      <div class="card">
        <p class="muted" style="margin-top:0;"><strong>Modo pesado:</strong> rastreio BFS amplíssimo apenas no mesmo host da URL do site — sem controlo de páginas/profundidade na UI — com um <strong>teto interno (~65k páginas / nível 512)</strong> contra loops e esgotamento do servidor em IIS/PHP.</p>
        <p class="muted">Recolhe <strong>href/iframes/scripts</strong>, tenta extrair URLs só em <strong>JavaScript</strong> (marca <code>needs_inspection</code> com troço de script), executa <strong>POST</strong> de formulários com campos recolhidos e regista respostas em <code>master_force_attempts</code>. Créditos: <code>credits_per_force_scan</code> (por omissão igual ao Master).</p>
        <label>Site</label>
        <select id="forceSite"></select>
        <button type="button" class="primary" id="btnForceScan" style="background:linear-gradient(145deg,#4a1e28,#a33);border:0;">Iniciar Descoberta Forçada</button>
        <p class="muted" id="forceScanStatus">—</p>
        <div id="forceProgressWrap" class="progress" style="display:none;margin-top:0.65rem;"><div class="bar" id="forceBar" style="width:0%;"></div></div>
        <h2 style="margin:1rem 0 0.5rem;font-size:1rem;">Últimas execuções (forçada)</h2>
        <table class="tbl" id="forceRunsTbl">
          <thead><tr><th>ID</th><th>Data</th><th>Estado</th><th>Págs.</th><th>Reg.</th><th>Tecto pág./prof.</th><th>Site</th><th>Erro</th></tr></thead>
          <tbody></tbody>
        </table>
        <h2 style="margin:1rem 0 0.5rem;font-size:1rem;">Resultados</h2>
        <p class="muted" style="font-size:0.82rem;margin:0 0 0.35rem;">Clique numa execução acima. Filtre por tipo; «Inspeccionar» mostra contexto JS quando o URL veio inferido de script. Lista carregada limitada a 8000 registos por pedido (consulte a base para runs muito grandes).</p>
        <div style="display:flex;flex-wrap:wrap;gap:0.35rem;margin:0.5rem 0;" id="forceFilterBtns">
          <button type="button" class="secondary force-filt active" data-fk="direct">Directos / suspeitos</button>
          <button type="button" class="secondary force-filt" data-fk="external">Externos / hospedagens</button>
          <button type="button" class="secondary force-filt" data-fk="forms">POST / formulários</button>
          <button type="button" class="secondary force-filt" data-fk="pending">Inspeccionar (JS)</button>
        </div>
        <p class="muted" style="font-size:0.8rem;margin:0.25rem 0;">Resumo da última execução concluída: <span id="forceByKind">—</span></p>
        <button type="button" class="secondary" id="btnForceCopyExternal" style="display:none;">Copiar URLs externas / hospedagens</button>
        <div style="max-height:min(50vh,24rem);overflow:auto;">
          <table class="tbl" id="forceItemsTbl">
            <thead><tr><th>Tipo</th><th>Kind</th><th>URL / acção</th><th>Dica / POST</th><th>JS / notas</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </section>

    <section id="sec-extract" class="sec active">
      <div class="card">
        <p class="muted">1) Escolha um site cadastrado (URL + cookie/senha gravados). 2) Descobrir links na página. 3) Baixar cada ficheiro — pedidos curtos, compatível com limites da hospedagem.</p>
        <label>Site</label>
        <select id="exSite"></select>
        <button type="button" class="primary" id="btnDiscover">Descobrir ficheiros</button>
        <div class="progress"><div class="bar" id="bar"></div></div>
        <p class="muted" id="progText">—</p>
        <button type="button" class="primary" id="btnDownloadAll" disabled>Baixar todos (sequencial)</button>
        <pre id="log" style="max-height:180px;overflow:auto;background:#0e0f14;padding:0.5rem;border-radius:6px;font-size:0.78rem;"></pre>
      </div>
    </section>

    <section id="sec-sites" class="sec">
      <div class="card info-box" style="margin-bottom:0.75rem;padding:0.75rem 1rem;background:#151a28;border:1px solid #2c4058;border-radius:8px;">
        <strong>Descoberta em todos os domínios</strong>
        <p class="muted" style="margin:0.35rem 0 0;font-size:0.85rem;">A varredura lista ficheiros em <strong>qualquer domínio</strong> encontrado na página (CDN, armazenamento externo, outro site do curso, etc.) — ideal para reunir materiais de vários endereços num só painel.</p>
      </div>
      <div class="card">
        <button type="button" class="primary" id="btnNewSite">Novo site</button>
        <table class="tbl" id="sitesTbl"><thead><tr><th>Nome</th><th>URL</th><th></th></tr></thead><tbody></tbody></table>
      </div>
    </section>

    <section id="sec-lib" class="sec">
      <div class="card">
        <p class="muted" style="margin:0 0 0.5rem;">Ficheiros descarregados do Extrator ou dos canais M3U. Listas .m3u completas estão em <strong>M3U / Xtream → Listas guardadas</strong>.</p>
        <button type="button" class="secondary" id="btnRefreshLib">Atualizar</button>
        <table class="tbl" id="libTbl"><thead><tr><th>ID</th><th>Ficheiro</th><th>Tamanho</th><th></th></tr></thead><tbody></tbody></table>
      </div>
    </section>

    <section id="sec-tools" class="sec">
      <div class="card">
        <h2 style="margin-top:0;">M3U</h2>
        <p class="muted" style="margin:0 0 0.75rem;">Listas grandes são descarregadas directamente para <code>/data</code> (sem carregar tudo na memória). Limite: <code>max_download_bytes</code> no config (predef. 200 MB).</p>
        <form method="post" action="<?= h(extractor_url('panel.php')) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="form" value="m3u" />
          <label>URL M3U</label>
          <input name="m3u_url" type="url" />
          <label>Ou cole M3U</label>
          <textarea name="m3u_text"></textarea>
          <button class="primary" type="submit">Guardar</button>
        </form>
      </div>
      <div class="card">
        <h2 style="margin-top:0;">Listas guardadas</h2>
        <p class="muted" style="margin:0 0 0.5rem;"><strong>M3U</strong> = original · <strong>Analisar</strong> = categorias via API (igual Litoral Flix) · <strong>Converter</strong> = M3U nova só com player_api.php (1117 canais · 25938 filmes · 7960 séries)</p>
        <button type="button" class="secondary" id="btnRefreshM3u">Actualizar</button>
        <table class="tbl" id="m3uTbl" style="margin-top:0.5rem;">
          <thead><tr><th>Nome</th><th>Tamanho</th><th>Itens</th><th>Acções</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="card">
        <h2 style="margin-top:0;">Xtream</h2>
        <form method="post" action="<?= h(extractor_url('panel.php')) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="form" value="xtream" />
          <label>Host</label>
          <input name="xt_host" required />
          <label>Porta</label>
          <input name="xt_port" type="number" value="8080" />
          <label><input type="checkbox" name="xt_https" value="1" /> HTTPS</label>
          <label>User</label>
          <input name="xt_user" required />
          <label>Pass</label>
          <input name="xt_pass" type="password" required />
          <button class="primary" type="submit">Obter lista</button>
        </form>
      </div>
      <div class="card">
        <h2 style="margin-top:0;">Download URL única</h2>
        <form method="post" action="<?= h(extractor_url('panel.php')) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="form" value="download" />
          <label>URL</label>
          <input name="dl_url" type="url" required />
          <label>Cookie opcional</label>
          <input name="dl_cookie" />
          <label>Nome opcional</label>
          <input name="dl_name" />
          <button class="primary" type="submit">Baixar</button>
        </form>
      </div>
    </section>

    <section id="sec-shop" class="sec">
      <div class="card">
        <p class="muted">Gere uma cobrança PIX (Mercado Pago ou Asaas, conforme configurado em Admin → Financeiro). Sem API configurada, o pedido fica em modo demonstração.</p>
        <label>Plano</label>
        <select id="shopPlan"></select>
        <button type="button" class="primary" id="btnPix">Gerar cobrança PIX</button>
        <p id="pixOut" class="muted" style="white-space:pre-wrap;margin-top:0.75rem;"></p>
        <button type="button" class="secondary" id="btnPixStatus" disabled>Verificar pagamento</button>
      </div>
    </section>

    <section id="sec-account" class="sec">
      <div class="card">
        <h2 style="margin-top:0;">Alterar e-mail de login</h2>
        <p class="muted">E-mail actual: <strong><?= h((string) ($_SESSION['user_email'] ?? '')) ?></strong></p>
        <label>Novo e-mail</label>
        <input type="email" id="emNew" autocomplete="email" />
        <label>Senha actual (confirmação)</label>
        <input type="password" id="emCur" autocomplete="current-password" />
        <button type="button" class="primary" id="btnEmSave">Guardar novo e-mail</button>
        <p id="emMsg" class="muted" style="margin-top:0.5rem;"></p>
      </div>
      <div class="card">
        <h2 style="margin-top:0;">Alterar senha</h2>
        <p class="muted">Depois dos testes, use uma senha forte.</p>
        <label>Senha actual</label>
        <input type="password" id="pwCur" autocomplete="current-password" />
        <label>Nova senha (mín. 10 caracteres)</label>
        <input type="password" id="pwNew" autocomplete="new-password" minlength="10" />
        <label>Confirmar nova senha</label>
        <input type="password" id="pwNew2" autocomplete="new-password" minlength="10" />
        <button type="button" class="primary" id="btnPwSave">Guardar nova senha</button>
        <p id="pwMsg" class="muted" style="margin-top:0.5rem;"></p>
      </div>
    </section>

    <section id="sec-support" class="sec">
      <div class="card">
        <p class="muted">Abra um ticket para a equipa (Master / Super Master) responder no painel de administração.</p>
        <button type="button" class="primary" id="btnNewTicket">Novo ticket</button>
        <button type="button" class="secondary" id="btnRefreshTickets">Actualizar</button>
        <table class="tbl" id="ticketsTbl">
          <thead><tr><th>ID</th><th>Assunto</th><th>Prioridade</th><th>Estado</th><th>Actualizado</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </section>
  </main>

  <dialog id="ticketDlg">
    <div class="dlg-body">
      <h2 style="margin-top:0;">Novo ticket</h2>
      <label>Prioridade</label>
      <select id="tkPri">
        <option value="low">Baixa</option>
        <option value="normal" selected>Normal</option>
        <option value="high">Alta</option>
        <option value="urgent">Urgente</option>
      </select>
      <label>Assunto</label>
      <input id="tkSub" maxlength="200" />
      <label>Mensagem</label>
      <textarea id="tkBody" maxlength="8000"></textarea>
      <div class="dlg-actions">
        <button type="button" class="secondary" id="tkCancel">Cancelar</button>
        <button type="button" class="primary" id="tkSend">Enviar</button>
      </div>
    </div>
  </dialog>

  <dialog id="dlg">
    <div class="dlg-body">
      <h2 id="dlgTitle" style="margin-top:0;">Site</h2>
      <input type="hidden" id="sid" />
      <label>Nome</label>
      <input id="sname" />
      <label>URL base (login ou início)</label>
      <input id="sbase" type="url" />
      <label>URL conteúdo (opcional, senão usa a base)</label>
      <input id="scontent" type="url" />
      <label>Utilizador (opcional)</label>
      <input id="suser" />
      <label>Senha do site (opcional se usar só cookie)</label>
      <input id="spass" type="password" />
      <label>Cookie de sessão (opcional; DevTools → rede)</label>
      <input id="scook" placeholder="PHPSESSID=..." />
      <div class="dlg-actions">
        <button type="button" class="secondary" id="dlgCancel">Cancelar</button>
        <button type="button" class="primary" id="dlgSave">Guardar</button>
      </div>
    </div>
  </dialog>

  <dialog id="dlgForceUrls">
    <div class="dlg-body" style="min-width:min(92vw,36rem);">
      <h2 style="margin-top:0;">URLs externas / hospedagens</h2>
      <textarea id="forceUrlsTa" readonly style="min-height:14rem;"></textarea>
      <div class="dlg-actions">
        <button type="button" class="secondary" id="dlgForceUrlsClose">Fechar</button>
        <button type="button" class="primary" id="dlgForceUrlsCopy">Copiar</button>
      </div>
    </div>
  </dialog>

  <dialog id="dlgCatalog">
    <div class="dlg-body" style="max-width:42rem;width:calc(100% - 2rem);">
      <h2 id="catDlgTitle" style="margin-top:0;">Catálogo</h2>
      <p class="muted" id="catDlgMeta" style="font-size:0.82rem;"></p>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
        <select id="m3uFilter" style="max-width:10rem;">
          <option value="all" selected>Todos</option>
          <option value="vod">Só VOD</option>
          <option value="live">Só ao vivo</option>
        </select>
        <input type="search" id="catSearch" placeholder="Pesquisar nome ou grupo…" style="flex:1;min-width:10rem;" />
      </div>
      <div style="max-height:18rem;overflow:auto;border:1px solid #2c3140;border-radius:6px;">
        <table class="tbl" id="m3uEntriesTbl" style="margin:0;font-size:0.82rem;">
          <thead><tr><th></th><th>Grupo</th><th>Tipo</th><th>Nome</th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.5rem;">
        <button type="button" class="secondary" id="catLoadMore">Carregar mais</button>
        <button type="button" class="secondary" id="catLoadAll">Carregar todo o catálogo</button>
        <button type="button" class="primary" id="m3uDlVod">Baixar VOD seleccionados</button>
      </div>
      <p class="muted" id="m3uDlgStatus" style="font-size:0.82rem;margin:0.5rem 0 0;"></p>
      <div class="dlg-actions">
        <button type="button" class="secondary" id="m3uDlgClose">Fechar</button>
      </div>
    </div>
  </dialog>

  <dialog id="dlgM3uJob">
    <div class="dlg-body" style="min-width:20rem;max-width:28rem;">
      <h2 id="m3uJobTitle" style="margin-top:0;">A processar lista</h2>
      <p class="muted" id="m3uJobMsg" style="font-size:0.85rem;margin:0.35rem 0 0.75rem;">A iniciar…</p>
      <div class="progress" style="height:12px;margin-bottom:0.75rem;"><div class="bar" id="m3uJobBar" style="width:0%;"></div></div>
      <p class="muted" id="m3uJobDetail" style="font-size:0.78rem;min-height:2.5rem;"></p>
      <div id="m3uJobDone" style="display:none;">
        <a class="btn btn-primary" id="m3uJobDownload" href="#" download style="display:inline-block;padding:0.5rem 1rem;border-radius:8px;background:#3b6df6;color:#fff;text-decoration:none;font-weight:600;">Descarregar nova M3U</a>
      </div>
      <div class="dlg-actions" style="margin-top:0.75rem;">
        <button type="button" class="secondary" id="m3uJobClose" disabled>Fechar</button>
      </div>
    </div>
  </dialog>

  <dialog id="dlgM3uAnalyze">
    <div class="dlg-body" style="min-width:min(92vw,42rem);max-width:48rem;">
      <h2 id="m3uAnalyzeTitle" style="margin-top:0;">Categorias da lista</h2>
      <p class="muted" id="m3uAnalyzeMeta" style="font-size:0.85rem;">—</p>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;margin-top:0.75rem;">
        <div><strong>Canais</strong><div id="m3uAnalyzeLive" class="muted" style="max-height:12rem;overflow:auto;font-size:0.82rem;margin-top:0.35rem;"></div></div>
        <div><strong>Filmes</strong><div id="m3uAnalyzeMovie" class="muted" style="max-height:12rem;overflow:auto;font-size:0.82rem;margin-top:0.35rem;"></div></div>
        <div><strong>Séries</strong><div id="m3uAnalyzeSeries" class="muted" style="max-height:12rem;overflow:auto;font-size:0.82rem;margin-top:0.35rem;"></div></div>
      </div>
      <div class="dlg-actions" style="margin-top:0.75rem;">
        <button type="button" class="secondary" id="m3uAnalyzeClose">Fechar</button>
      </div>
    </div>
  </dialog>

  <script>
    const CSRF = <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>;
    const M3U_HIGHLIGHT = <?= json_encode($m3uHighlight, JSON_THROW_ON_ERROR) ?>;
    const API_URL = <?= json_encode(extractor_url('api.php'), JSON_THROW_ON_ERROR) ?>;
    const DOWNLOAD_URL = <?= json_encode(extractor_url('download.php'), JSON_THROW_ON_ERROR) ?>;
    const IS_SUPER = <?= json_encode($meRole === 'super_master', JSON_THROW_ON_ERROR) ?>;
    const api = async (action, extra = {}) => {
      const r = await fetch(API_URL, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ action, csrf: CSRF, ...extra }) });
      const t = await r.text();
      let j; try { j = JSON.parse(t); } catch { throw new Error(t); }
      if (!j.ok) throw new Error(j.error || 'erro');
      if (typeof j.credits === 'number') {
        const el = document.getElementById('sidebarCredits');
        if (el) el.textContent = String(j.credits);
      }
      return j;
    };

    const title = document.getElementById('title');
    document.querySelectorAll('nav button').forEach(b => b.addEventListener('click', () => {
      document.querySelectorAll('nav button').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      document.querySelectorAll('.sec').forEach(s => s.classList.remove('active'));
      const id = 'sec-' + b.dataset.sec;
      const el = document.getElementById(id);
      if (el) el.classList.add('active');
      const map = { master:'Master Extrator', force:'Descoberta Forçada', extract:'Extrator', sites:'Sites', lib:'Biblioteca', tools:'M3U / Xtream', support:'Suporte', shop:'PIX / Planos', account:'Conta' };
      title.textContent = map[b.dataset.sec] || 'Extrator';
      if (b.dataset.sec === 'master') {
        stopMasterPoll();
        loadMasterRuns().catch(e => alert(e.message));
      }
      if (b.dataset.sec === 'force') {
        stopForcePoll();
        loadForceRuns().catch(e => alert(e.message));
      }
      if (b.dataset.sec === 'sites') loadSites();
      if (b.dataset.sec === 'lib') loadLib();
      if (b.dataset.sec === 'tools') loadM3uList().catch(e => alert(e.message));
      if (b.dataset.sec === 'support') loadTickets().catch(e => alert(e.message));
      if (b.dataset.sec === 'shop') loadShop().catch(e => alert(e.message));
      if (b.dataset.sec === 'account') {
        document.getElementById('pwMsg').textContent = '';
        document.getElementById('emMsg').textContent = '';
      }
    }));

    async function loadSites() {
      const j = await api('sites_list');
      const tb = document.querySelector('#sitesTbl tbody');
      tb.innerHTML = '';
      const sel = document.getElementById('exSite');
      const masterSel = document.getElementById('masterSite');
      const forceSel = document.getElementById('forceSite');
      sel.innerHTML = '';
      if (masterSel) masterSel.innerHTML = '';
      if (forceSel) forceSel.innerHTML = '';
      for (const s of j.sites) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${escapeHtml(s.name)}</td><td>${escapeHtml(s.base_url)}</td><td><button data-e="${s.id}">Editar</button> <button data-d="${s.id}">Apagar</button></td>`;
        tr.querySelector('[data-e]').addEventListener('click', () => openDlg(s.id));
        tr.querySelector('[data-d]').addEventListener('click', async () => { if (!confirm('Apagar?')) return; await api('site_delete', { id: s.id }); loadSites(); });
        tb.appendChild(tr);
        const o = document.createElement('option');
        o.value = s.id; o.textContent = s.name;
        sel.appendChild(o);
        if (masterSel) {
          const om = document.createElement('option');
          om.value = s.id; om.textContent = s.name;
          masterSel.appendChild(om);
        }
        if (forceSel) {
          const of = document.createElement('option');
          of.value = s.id; of.textContent = s.name;
          forceSel.appendChild(of);
        }
      }
    }

    function fmtRunDate(ts) {
      const n = Number(ts);
      if (!n) return '—';
      return new Date(n * 1000).toLocaleString('pt-BR');
    }

    function renderMasterStats(byService) {
      const el = document.getElementById('masterStats');
      if (!el) return;
      const entries = Object.entries(byService || {});
      if (!entries.length) {
        el.style.display = 'none';
        el.textContent = '';
        return;
      }
      el.style.display = 'block';
      el.innerHTML = '<strong>Por serviço/tipo:</strong> ' + entries.map(([k, v]) => escapeHtml(k) + ': ' + String(v)).join(' · ');
    }

    function renderMasterItems(items) {
      const tb = document.querySelector('#masterItemsTbl tbody');
      if (!tb) return;
      tb.innerHTML = '';
      if (!items || !items.length) {
        tb.innerHTML = '<tr><td colspan="5" class="muted">Nenhum item. Execute uma varredura ou escolha uma execução na tabela acima.</td></tr>';
        return;
      }
      for (const it of items) {
        const tr = document.createElement('tr');
        const tdName = document.createElement('td');
        tdName.textContent = (it.display_name || '—').slice(0, 120);
        const tdSvc = document.createElement('td');
        tdSvc.textContent = it.service || '';
        const tdTyp = document.createElement('td');
        tdTyp.textContent = it.type_label || '';
        const tdUrl = document.createElement('td');
        const aU = document.createElement('a');
        aU.href = it.url || '#';
        aU.target = '_blank';
        aU.rel = 'noopener';
        aU.textContent = 'Abrir';
        tdUrl.appendChild(aU);
        const tdHint = document.createElement('td');
        const hint = (it.download_hint || it.url || '');
        const aH = document.createElement('a');
        aH.href = hint || '#';
        aH.target = '_blank';
        aH.rel = 'noopener';
        aH.textContent = 'Dica';
        tdHint.appendChild(aH);
        tr.append(tdName, tdSvc, tdTyp, tdUrl, tdHint);
        tb.appendChild(tr);
      }
    }

    let masterPollTimer = null;
    let masterSelectedRunId = 0;
    let masterPollAttempts = 0;

    function stopMasterPoll() {
      if (masterPollTimer) {
        clearInterval(masterPollTimer);
        masterPollTimer = null;
      }
      masterPollAttempts = 0;
    }

    function setMasterProgress(pct, visible) {
      const w = document.getElementById('masterProgressWrap');
      const b = document.getElementById('masterBar');
      if (!w || !b) return;
      w.style.display = visible ? 'block' : 'none';
      const p = Math.max(0, Math.min(100, Number(pct) || 0));
      b.style.width = p + '%';
    }

    function fmtMasterStatus(s) {
      const m = { queued: 'Fila', running: 'A correr…', done: 'OK', failed: 'Falhou' };
      return m[s] || (s ? String(s) : 'OK');
    }

    async function loadMasterRuns() {
      const j = await api('master_runs', { limit: 25 });
      const tb = document.querySelector('#masterRunsTbl tbody');
      if (!tb) return;
      tb.innerHTML = '';
      const runs = j.runs || [];
      if (!runs.length) {
        tb.innerHTML = '<tr><td colspan="7" class="muted">Nenhuma execução ainda.</td></tr>';
        renderMasterItems([]);
        return;
      }
      for (const r of runs) {
        const tr = document.createElement('tr');
        tr.style.cursor = 'pointer';
        const err = (r.error || '').slice(0, 80);
        let siteCell = r.site_id != null ? '#' + r.site_id : '—';
        if (IS_SUPER && r.user_id != null) {
          siteCell += ' · u' + r.user_id;
        }
        const st = escapeHtml(fmtMasterStatus(r.scan_status || 'done'));
        tr.innerHTML = `<td>${r.id}</td><td>${escapeHtml(fmtRunDate(r.created_at))}</td><td>${st}</td><td>${r.pages_crawled ?? 0}</td><td>${r.items_found ?? 0}</td><td>${escapeHtml(siteCell)}</td><td class="muted" style="font-size:0.8rem;">${escapeHtml(err || '—')}</td>`;
        tr.addEventListener('click', async () => {
          masterSelectedRunId = Number(r.id);
          document.querySelectorAll('#masterRunsTbl tbody tr').forEach(x => { x.style.background = ''; });
          tr.style.background = 'rgba(59,109,246,0.12)';
          try {
            const ji = await api('master_run_items', { run_id: masterSelectedRunId });
            renderMasterItems(ji.items || []);
          } catch (e) { alert(e.message); }
        });
        tb.appendChild(tr);
      }
    }

    document.getElementById('btnMasterScan').addEventListener('click', async () => {
      const siteId = parseInt(document.getElementById('masterSite').value || '0', 10);
      if (!siteId) { alert('Cadastre e seleccione um site.'); return; }
      const max_pages = Math.min(400, Math.max(1, parseInt(document.getElementById('masterMaxPages').value || '80', 10)));
      const max_depth = Math.min(6, Math.max(0, parseInt(document.getElementById('masterMaxDepth').value || '2', 10)));
      const st = document.getElementById('masterScanStatus');
      const btn = document.getElementById('btnMasterScan');

      stopMasterPoll();
      btn.disabled = true;
      setMasterProgress(0, true);
      document.getElementById('masterStats').style.display = 'none';
      st.textContent = 'A reservar execução…';

      try {
        const res = await api('master_scan_reserve', { site_id: siteId, max_pages, max_depth });
        const runId = Number(res.run_id) || 0;
        if (!runId) throw new Error('run_id inválido');
        masterSelectedRunId = runId;
        st.textContent = 'Execução #' + runId + ' — a iniciar em segundo plano…';

        const tick = async () => {
          try {
            masterPollAttempts++;
            if (masterPollAttempts > 500) {
              stopMasterPoll();
              st.textContent = 'Demasiado tempo em «Fila» ou «A correr». Recarregue a página ou execute de novo — veja também a última execução na tabela.';
              setMasterProgress(0, false);
              btn.disabled = false;
              await loadMasterRuns();
              return;
            }
            const p = await api('master_scan_progress', { run_id: runId });
            setMasterProgress(p.progress_pct ?? 0, true);
            const state = (p.scan_status || '') + '';
            let line = (p.progress_msg || '') + '';
            if (state === 'queued') line = (line || 'Na fila…');
            st.textContent = line + ' [' + fmtMasterStatus(state) + ']';

            if (state === 'done') {
              stopMasterPoll();
              renderMasterStats(p.by_service || {});
              const ji = await api('master_run_items', { run_id: runId });
              renderMasterItems(ji.items || []);
              await loadMasterRuns();
              setMasterProgress(100, true);
              st.textContent = 'Concluído: ' + (p.items_found ?? 0) + ' itens (execução #' + runId + ').';
              btn.disabled = false;
              return;
            }
            if (state === 'failed') {
              stopMasterPoll();
              st.textContent = 'Falhou: ' + (p.error || '—');
              setMasterProgress(0, false);
              btn.disabled = false;
              await loadMasterRuns();
            }
          } catch (e) {
            stopMasterPoll();
            st.textContent = 'Erro ao consultar progresso: ' + e.message;
            setMasterProgress(0, false);
            btn.disabled = false;
          }
        };

        masterPollAttempts = 0;
        masterPollTimer = setInterval(tick, 1200);
        tick();

        fetch(API_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'master_scan_execute', csrf: CSRF, run_id: runId }),
        }).then(async (r) => {
          const t = await r.text();
          let j;
          try { j = JSON.parse(t); } catch { throw new Error(t.slice(0, 200)); }
          if (!j.ok) throw new Error(j.error || 'exec');
        }).catch((err) => console.error('[master_scan_execute]', err));
      } catch (e) {
        stopMasterPoll();
        st.textContent = 'Erro: ' + e.message;
        setMasterProgress(0, false);
        btn.disabled = false;
      }
    });

    /* Descoberta Forçada */
    let forcePollTimer = null;
    let forcePollAttempts = 0;
    let forceSelectedRunId = 0;
    /** @type {any[]} */
    let forceItemsCache = [];
    let forceActiveFilter = 'direct';

    function stopForcePoll() {
      if (forcePollTimer) {
        clearInterval(forcePollTimer);
        forcePollTimer = null;
      }
      forcePollAttempts = 0;
    }

    function setForceProgress(pct, visible) {
      const w = document.getElementById('forceProgressWrap');
      const b = document.getElementById('forceBar');
      if (!w || !b) return;
      w.style.display = visible ? 'block' : 'none';
      const p = Math.max(0, Math.min(100, Number(pct) || 0));
      b.style.width = p + '%';
    }

    function forceItemMatchesFilter(it, fk) {
      const k = it.kind || '';
      if (fk === 'direct') return k === 'direct_download' || k === 'suspected_download';
      if (fk === 'external') return k === 'external_service' || k === 'external_link';
      if (fk === 'forms') return k === 'form_post';
      if (fk === 'pending') return k === 'needs_inspection';
      return true;
    }

    function renderForceByKindSummary(obj) {
      const el = document.getElementById('forceByKind');
      if (!el) return;
      const e = obj || {};
      const parts = Object.entries(e).map(([a, v]) => escapeHtml(a) + ': ' + String(v));
      el.innerHTML = parts.length ? parts.join(' · ') : '—';
    }

    function renderForceItems() {
      const tb = document.querySelector('#forceItemsTbl tbody');
      if (!tb) return;
      tb.innerHTML = '';
      const fk = forceActiveFilter;
      const rows = (forceItemsCache || []).filter(it => forceItemMatchesFilter(it, fk));
      const btnExt = document.getElementById('btnForceCopyExternal');
      if (btnExt) btnExt.style.display = fk === 'external' && rows.length ? 'inline-block' : 'none';

      if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="5" class="muted">Nada neste filtro.</td></tr>';
        return;
      }
      for (const it of rows) {
        const tr = document.createElement('tr');
        const postStr = (it.post_data && String(it.post_data).length) ? String(it.post_data).slice(0, 400) : '';
        const hint = (it.download_hint || '').slice(0, 500);
        const js = (it.js_context || '').slice(0, 800);

        const tdExt = document.createElement('td');
        tdExt.textContent = it.external_service ? String(it.external_service) : '—';

        const tdKind = document.createElement('td');
        const code = document.createElement('code');
        code.style.fontSize = '0.76rem';
        code.textContent = it.kind || '';
        tdKind.appendChild(code);

        const tdUrl = document.createElement('td');
        const a = document.createElement('a');
        a.href = it.url || '#';
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = 'Abrir';
        tdUrl.appendChild(a);

        const tdHint = document.createElement('td');
        tdHint.className = 'muted';
        tdHint.style.fontSize = '0.82rem';
        tdHint.style.maxWidth = '14rem';
        tdHint.textContent = postStr || hint || '—';

        const tdJs = document.createElement('td');
        tdJs.className = 'muted';
        tdJs.style.fontSize = '0.76rem';
        tdJs.style.maxWidth = '12rem';
        tdJs.textContent = js || '—';

        tr.append(tdExt, tdKind, tdUrl, tdHint, tdJs);
        tb.appendChild(tr);
      }
    }

    async function loadForceItemsForRun(runId) {
      const ji = await api('master_force_items', { run_id: runId });
      forceItemsCache = ji.items || [];
      renderForceItems();
    }

    async function loadForceRuns() {
      const j = await api('master_force_runs', { limit: 25 });
      const tb = document.querySelector('#forceRunsTbl tbody');
      if (!tb) return;
      tb.innerHTML = '';
      const runs = j.runs || [];
      if (!runs.length) {
        tb.innerHTML = '<tr><td colspan="8" class="muted">Nenhuma execução forçada ainda.</td></tr>';
        forceItemsCache = [];
        renderForceItems();
        renderForceByKindSummary(null);
        return;
      }
      for (const r of runs) {
        const tr = document.createElement('tr');
        tr.style.cursor = 'pointer';
        const err = (r.error || '').slice(0, 70);
        let siteCell = r.site_id != null ? '#' + r.site_id : '—';
        if (IS_SUPER && r.user_id != null) siteCell += ' · u' + r.user_id;
        const st = escapeHtml(fmtMasterStatus(r.scan_status || 'done'));
        const ceil = (r.max_pages_ceiling ?? '—') + ' / ' + (r.max_depth_ceiling ?? '—');
        tr.innerHTML = `<td>${r.id}</td><td>${escapeHtml(fmtRunDate(r.created_at))}</td><td>${st}</td><td>${r.pages_crawled ?? 0}</td><td>${r.items_found ?? 0}</td><td class="muted">${escapeHtml(ceil)}</td><td>${escapeHtml(siteCell)}</td><td class="muted" style="font-size:0.78rem;">${escapeHtml(err || '—')}</td>`;
        tr.addEventListener('click', async () => {
          forceSelectedRunId = Number(r.id);
          document.querySelectorAll('#forceRunsTbl tbody tr').forEach(x => { x.style.background = ''; });
          tr.style.background = 'rgba(200,50,80,0.14)';
          if (document.getElementById('btnForceCopyExternal')) {
            document.getElementById('btnForceCopyExternal').style.display = 'none';
          }
          try {
            await loadForceItemsForRun(forceSelectedRunId);
            if ((r.scan_status || '') === 'done') {
              const p = await api('master_force_progress', { run_id: forceSelectedRunId });
              renderForceByKindSummary(p.by_kind || {});
            } else {
              renderForceByKindSummary(null);
            }
          } catch (e) { alert(e.message); }
        });
        tb.appendChild(tr);
      }
    }

    document.querySelectorAll('.force-filt').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.force-filt').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        forceActiveFilter = btn.getAttribute('data-fk') || 'direct';
        renderForceItems();
      });
    });

    const dlgForceUrls = document.getElementById('dlgForceUrls');
    document.getElementById('dlgForceUrlsClose').addEventListener('click', () => dlgForceUrls.close());
    document.getElementById('dlgForceUrlsCopy').addEventListener('click', async () => {
      const ta = document.getElementById('forceUrlsTa');
      try {
        ta.select();
        await navigator.clipboard.writeText(ta.value);
      } catch {
        document.execCommand('copy');
      }
    });

    document.getElementById('btnForceCopyExternal').addEventListener('click', () => {
      const lines = [];
      for (const it of forceItemsCache) {
        const k = it.kind || '';
        if (k === 'external_service' || k === 'external_link') {
          const hint = (it.download_hint || '').trim();
          const u = it.url || '';
          lines.push(u + (hint && hint !== u ? '\n  ' + hint : ''));
        }
      }
      document.getElementById('forceUrlsTa').value = lines.join('\n\n');
      dlgForceUrls.showModal();
    });

    document.getElementById('btnForceScan').addEventListener('click', async () => {
      const siteId = parseInt(document.getElementById('forceSite').value || '0', 10);
      if (!siteId) { alert('Cadastre e seleccione um site.'); return; }
      const st = document.getElementById('forceScanStatus');
      const btn = document.getElementById('btnForceScan');
      stopForcePoll();
      btn.disabled = true;
      setForceProgress(0, true);
      st.textContent = 'A reservar descoberta forçada…';

      try {
        const res = await api('master_force_reserve', { site_id: siteId });
        const runId = Number(res.run_id) || 0;
        if (!runId) throw new Error('run_id inválido');
        forceSelectedRunId = runId;
        st.textContent = 'Execução forçada #' + runId + ' — a iniciar em segundo plano…';

        const tick = async () => {
          try {
            forcePollAttempts++;
            if (forcePollAttempts > 800) {
              stopForcePoll();
              st.textContent = 'Tempo de espera elevado. Consulte a tabela de execuções ou recarregue.';
              setForceProgress(0, false);
              btn.disabled = false;
              await loadForceRuns();
              return;
            }
            const p = await api('master_force_progress', { run_id: runId });
            setForceProgress(p.progress_pct ?? 0, true);
            const state = (p.scan_status || '') + '';
            let line = (p.progress_msg || '') + '';
            if (state === 'queued') line = (line || 'Na fila…');
            st.textContent = line + ' [' + fmtMasterStatus(state) + ']';

            if (state === 'done') {
              stopForcePoll();
              renderForceByKindSummary(p.by_kind || {});
              await loadForceItemsForRun(runId);
              await loadForceRuns();
              setForceProgress(100, true);
              st.textContent = 'Concluído: ' + (p.items_found ?? 0) + ' registos (#' + runId + ').';
              btn.disabled = false;
              return;
            }
            if (state === 'failed') {
              stopForcePoll();
              st.textContent = 'Falhou: ' + (p.error || '—');
              setForceProgress(0, false);
              btn.disabled = false;
              await loadForceRuns();
            }
          } catch (e) {
            stopForcePoll();
            st.textContent = 'Erro ao consultar progresso: ' + e.message;
            setForceProgress(0, false);
            btn.disabled = false;
          }
        };

        forcePollAttempts = 0;
        forcePollTimer = setInterval(tick, 1200);
        tick();

        fetch(API_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'master_force_execute', csrf: CSRF, run_id: runId }),
        }).then(async (r) => {
          const t = await r.text();
          let j;
          try { j = JSON.parse(t); } catch { throw new Error(t.slice(0, 200)); }
          if (!j.ok) throw new Error(j.error || 'exec');
        }).catch((err) => console.error('[master_force_execute]', err));
      } catch (e) {
        stopForcePoll();
        st.textContent = 'Erro: ' + e.message;
        setForceProgress(0, false);
        btn.disabled = false;
      }
    });

    function escapeHtml(t){ const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }

    const dlg = document.getElementById('dlg');
    document.getElementById('btnNewSite').addEventListener('click', () => openDlg(0));
    document.getElementById('dlgCancel').addEventListener('click', () => dlg.close());

    async function openDlg(id) {
      document.getElementById('dlgTitle').textContent = id ? 'Editar site' : 'Novo site';
      document.getElementById('sid').value = id || '';
      if (!id) {
        ['sname','sbase','scontent','suser','spass','scook'].forEach(k => document.getElementById(k).value='');
      } else {
        const j = await api('sites_list');
        const s = j.sites.find(x => String(x.id) === String(id));
        if (!s) return;
        document.getElementById('sname').value = s.name;
        document.getElementById('sbase').value = s.base_url;
        document.getElementById('scontent').value = s.content_url || '';
        document.getElementById('suser').value = s.username || '';
        document.getElementById('spass').value = '';
        document.getElementById('scook').value = '';
      }
      dlg.showModal();
    }

    document.getElementById('dlgSave').addEventListener('click', async () => {
      const id = parseInt(document.getElementById('sid').value || '0', 10);
      const body = {
        name: document.getElementById('sname').value.trim(),
        base_url: document.getElementById('sbase').value.trim(),
        content_url: document.getElementById('scontent').value.trim(),
        username: document.getElementById('suser').value.trim(),
        password: document.getElementById('spass').value,
        cookie: document.getElementById('scook').value.trim(),
      };
      if (id) body.id = id;
      try { await api('site_save', body); dlg.close(); loadSites(); } catch(e) { alert(e.message); }
    });

    let discovered = [];
    const logEl = document.getElementById('log');
    const bar = document.getElementById('bar');
    const prog = document.getElementById('progText');

    document.getElementById('btnDiscover').addEventListener('click', async () => {
      const siteId = parseInt(document.getElementById('exSite').value || '0', 10);
      if (!siteId) { alert('Cadastre um site.'); return; }
      logEl.textContent = '';
      bar.style.width = '5%';
      prog.textContent = 'A descobrir…';
      try {
        const j = await api('discover', { site_id: siteId });
        discovered = j.urls || [];
        logEl.textContent = discovered.join('\n');
        prog.textContent = 'Encontrados: ' + discovered.length + ' (página: ' + j.page + ')';
        bar.style.width = '15%';
        document.getElementById('btnDownloadAll').disabled = discovered.length === 0;
      } catch (e) {
        prog.textContent = e.message;
        bar.style.width = '0%';
      }
    });

    document.getElementById('btnDownloadAll').addEventListener('click', async () => {
      const siteId = parseInt(document.getElementById('exSite').value || '0', 10);
      if (!discovered.length) return;
      let ok = 0;
      for (let i = 0; i < discovered.length; i++) {
        const u = discovered[i];
        prog.textContent = 'A baixar ' + (i+1) + '/' + discovered.length;
        bar.style.width = (15 + (85 * (i+1) / discovered.length)) + '%';
        try {
          await api('download_one', { url: u, site_id: siteId });
          ok++;
        } catch (e) {
          logEl.textContent += '\nERRO ' + u + ' -> ' + e.message;
        }
      }
      prog.textContent = 'Concluído. OK: ' + ok + '/' + discovered.length;
      bar.style.width = '100%';
    });

    async function loadLib() {
      const j = await api('files_list', { limit: 200 });
      const tb = document.querySelector('#libTbl tbody');
      tb.innerHTML = '';
      for (const f of j.files) {
        const name = (f.local_path || '').split(/[/\\\\]/).pop();
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${f.id}</td><td>${escapeHtml(name)}</td><td>${f.bytes}</td><td><a href="${DOWNLOAD_URL}?id=${f.id}">Download</a></td>`;
        tb.appendChild(tr);
      }
    }

    document.getElementById('btnRefreshLib').addEventListener('click', () => loadLib().catch(e => alert(e.message)));

    function fmtBytes(n) {
      n = Number(n) || 0;
      if (n < 1024) return n + ' B';
      if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
      if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
      return (n / 1073741824).toFixed(2) + ' GB';
    }

    let m3uDlgId = 0;
    let m3uDlgOffset = 0;
    let m3uDlgTotal = 0;
    let m3uDlgCounts = { vod: 0, live: 0, total: 0 };
    let m3uCatalogRows = [];
    const m3uPageSize = 200;
    const m3uFilterEl = document.getElementById('m3uFilter');
    const catSearchEl = document.getElementById('catSearch');

    function m3uSelectedUrls(vodOnly) {
      const urls = [];
      document.querySelectorAll('.m3u-ch:checked').forEach(cb => {
        if (vodOnly && cb.dataset.kind !== 'vod') return;
        if (cb.dataset.url) urls.push(cb.dataset.url);
      });
      return urls;
    }

    async function loadM3uList() {
      const j = await api('m3u_list');
      const tb = document.querySelector('#m3uTbl tbody');
      if (!tb) return;
      tb.innerHTML = '';
      for (const p of j.playlists || []) {
        const tr = document.createElement('tr');
        if (M3U_HIGHLIGHT && Number(p.id) === Number(M3U_HIGHLIGHT)) {
          tr.style.background = 'rgba(59,109,246,0.15)';
        }
        const dlUrl = DOWNLOAD_URL + '?m3u_id=' + encodeURIComponent(p.id);
        const name = escapeHtml(p.label || p.file_name);
        tr.innerHTML = '<td>' + name + '</td><td>' + fmtBytes(p.bytes) + '</td><td>' + (p.entry_count || 0) + '</td><td class="m3u-actions"></td>';
        const actions = tr.querySelector('.m3u-actions');
        const mkBtn = (cls, label, attrs) => {
          const el = document.createElement(attrs.tag || 'button');
          el.type = 'button';
          el.className = 'm3u-act ' + cls;
          el.textContent = label;
          Object.entries(attrs).forEach(([k, v]) => { if (k !== 'tag') el.setAttribute(k, v); });
          return el;
        };
        const aDl = document.createElement('a');
        aDl.href = dlUrl;
        aDl.className = 'm3u-act m3u-act-dl';
        aDl.title = 'Ficheiro original';
        aDl.textContent = 'M3U';
        actions.appendChild(aDl);
        const bCat = mkBtn('m3u-act-cat', 'Catálogo', { 'data-cat': String(p.id) });
        const bAnal = mkBtn('m3u-act-anal', 'Analisar', { 'data-anal': String(p.id) });
        const bNova = mkBtn('m3u-act-nova', 'Nova M3U', { 'data-nova': String(p.id) });
        const bConv = mkBtn('m3u-act-conv', 'Converter', { 'data-conv': String(p.id) });
        const bDel = mkBtn('m3u-act-del', 'Apagar', { 'data-m3u-del': String(p.id) });
        actions.append(bCat, bAnal, bNova, bConv, bDel);
        bCat.addEventListener('click', () => openCatalog(Number(p.id), p.label || p.file_name));
        bAnal.addEventListener('click', () => openM3uAnalyze(Number(p.id), p.label || p.file_name));
        bNova.addEventListener('click', () => runM3uExportJob(Number(p.id), 'all_open', 'Nova M3U'));
        bConv.addEventListener('click', () => runM3uExportJob(Number(p.id), 'convert', 'Converter (API Litoral Flix)'));
        bDel.addEventListener('click', async () => {
          if (!confirm('Apagar esta lista?')) return;
          try {
            await api('m3u_delete', { id: p.id });
            await loadM3uList();
          } catch (e) { alert(e.message); }
        });
        tb.appendChild(tr);
      }
      if ((j.playlists || []).length === 0) {
        tb.innerHTML = '<tr><td colspan="4" class="muted">Nenhuma lista. Guarde uma URL M3U acima ou clique Actualizar para importar ficheiros antigos em /data.</td></tr>';
      }
    }

    document.getElementById('btnRefreshM3u').addEventListener('click', () => loadM3uList().catch(e => alert(e.message)));

    const dlgCatalog = document.getElementById('dlgCatalog');
    const dlgM3uAnalyze = document.getElementById('dlgM3uAnalyze');
    const dlgM3uJob = document.getElementById('dlgM3uJob');
    const m3uJobBar = document.getElementById('m3uJobBar');
    const m3uJobMsg = document.getElementById('m3uJobMsg');
    const m3uJobDetail = document.getElementById('m3uJobDetail');
    const m3uJobDone = document.getElementById('m3uJobDone');
    const m3uJobDownload = document.getElementById('m3uJobDownload');
    const m3uJobClose = document.getElementById('m3uJobClose');
    document.getElementById('m3uDlgClose').addEventListener('click', () => dlgCatalog.close());
    document.getElementById('m3uAnalyzeClose').addEventListener('click', () => dlgM3uAnalyze.close());
    m3uJobClose.addEventListener('click', () => dlgM3uJob.close());

    function renderAnalyzeCol(el, items) {
      if (!el) return;
      if (!items || !items.length) {
        el.innerHTML = '<div class="muted">Nenhuma categoria.</div>';
        return;
      }
      el.innerHTML = items.map(c =>
        '<div style="padding:0.15rem 0;">' + escapeHtml(c.name) +
        ' <span style="opacity:0.65;">(' + (c.count || 0) + ')</span></div>'
      ).join('');
    }

    async function openM3uAnalyze(id, label) {
      document.getElementById('m3uAnalyzeTitle').textContent = 'Categorias — ' + (label || 'Lista');
      document.getElementById('m3uAnalyzeMeta').textContent = 'A analisar…';
      renderAnalyzeCol(document.getElementById('m3uAnalyzeLive'), []);
      renderAnalyzeCol(document.getElementById('m3uAnalyzeMovie'), []);
      renderAnalyzeCol(document.getElementById('m3uAnalyzeSeries'), []);
      dlgM3uAnalyze.showModal();
      try {
        const j = await api('m3u_analyze', { id });
        const c = j.categories || {};
        renderAnalyzeCol(document.getElementById('m3uAnalyzeLive'), c.live || []);
        renderAnalyzeCol(document.getElementById('m3uAnalyzeMovie'), c.movie || []);
        renderAnalyzeCol(document.getElementById('m3uAnalyzeSeries'), c.series || []);
        const n = (c.live || []).length + (c.movie || []).length + (c.series || []).length;
        const tot = [...(c.live || []), ...(c.movie || []), ...(c.series || [])].reduce((s, x) => s + (x.count || 0), 0);
        let meta = n + ' categorias · ' + tot + ' itens';
        if (c.api_ok && c.counts) {
          meta = 'API Litoral Flix: ' + (c.counts.live || 0) + ' canais · '
            + (c.counts.movie || 0) + ' filmes · ' + (c.counts.series_shows || 0) + ' séries';
        } else if (!c.api_ok) {
          meta += ' (fallback M3U — use Converter com API)';
        }
        document.getElementById('m3uAnalyzeMeta').textContent = meta;
      } catch (e) {
        document.getElementById('m3uAnalyzeMeta').textContent = 'Erro: ' + e.message;
      }
    }

    function renderCatalogTable() {
      const q = (catSearchEl && catSearchEl.value || '').trim().toLowerCase();
      const tb = document.querySelector('#m3uEntriesTbl tbody');
      if (!tb) return;
      tb.innerHTML = '';
      const rows = q ? m3uCatalogRows.filter(e =>
        (e.title || '').toLowerCase().includes(q) ||
        (e.group || '').toLowerCase().includes(q) ||
        (e.url || '').toLowerCase().includes(q)
      ) : m3uCatalogRows;
      for (const e of rows) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="checkbox" class="m3u-ch" /></td><td></td><td></td><td></td>';
        tr.cells[1].textContent = e.group || '—';
        tr.cells[2].textContent = e.kind === 'vod' ? 'VOD' : 'Live';
        tr.cells[3].textContent = e.title || '';
        tr.cells[3].title = (e.path ? e.path + '\n' : '') + (e.url || '');
        const cb = tr.querySelector('.m3u-ch');
        cb.dataset.url = e.url;
        cb.dataset.kind = e.kind || 'live';
        tb.appendChild(tr);
      }
      const c = m3uDlgCounts;
      const shown = rows.length;
      const loaded = m3uCatalogRows.length;
      document.getElementById('catDlgMeta').textContent =
        'Em memória: ' + loaded + ' de ' + m3uDlgTotal + ' (' + (c.vod || 0) + ' VOD, ' + (c.live || 0) + ' ao vivo)' +
        (q ? ' · filtro: ' + shown + ' visíveis' : '');
    }

    async function fetchCatalogChunk(append) {
      const filter = m3uFilterEl ? m3uFilterEl.value : 'all';
      const j = await api('m3u_entries', { id: m3uDlgId, offset: m3uDlgOffset, limit: m3uPageSize, filter });
      if (j.counts) m3uDlgCounts = j.counts;
      m3uDlgTotal = Number(j.total || 0);
      if (append) {
        m3uCatalogRows = m3uCatalogRows.concat(j.entries || []);
      } else {
        m3uCatalogRows = j.entries || [];
      }
      m3uDlgOffset = m3uCatalogRows.length;
      renderCatalogTable();
      return j;
    }

    async function runM3uExportJob(playlistId, mode, titleLabel) {
      document.getElementById('m3uJobTitle').textContent = titleLabel || 'Exportar';
      m3uJobMsg.textContent = 'A iniciar…';
      m3uJobDetail.textContent = '';
      m3uJobBar.style.width = '0%';
      m3uJobDone.style.display = 'none';
      m3uJobClose.disabled = true;
      dlgM3uJob.showModal();
      try {
        const begin = await api('m3u_export_begin', { id: playlistId, mode });
        const jobId = begin.job_id;
        m3uJobMsg.textContent = (begin.xtream && begin.xtream.message) || begin.message || 'A processar…';
        let last;
        for (;;) {
          last = await api('m3u_export_step', { job_id: jobId });
          m3uJobBar.style.width = Math.min(100, last.percent || 0) + '%';
          m3uJobMsg.textContent = last.message || '';
          const stats = last.stats || {};
          m3uJobDetail.textContent = 'Processados ' + (last.processed || 0) + ' / ' + (last.total || '?') +
            ' · escritos ' + (last.written || 0) +
            (last.skipped ? ' · dupes ' + last.skipped : '') +
            (mode === 'convert' ? (' · filmes ' + (stats.movie || 0) + ' · séries ' + (stats.series || 0) + ' · canais ' + (stats.live || 0)) : '');
          if (last.done) break;
        }
        if (last.download_url) {
          m3uJobDownload.href = last.download_url;
          m3uJobDownload.textContent = mode === 'convert' ? 'Descarregar M3U (player)' : 'Descarregar nova M3U';
          m3uJobDone.style.display = 'block';
        }
        m3uJobClose.disabled = false;
        await loadM3uList();
      } catch (e) {
        m3uJobMsg.textContent = 'Erro: ' + e.message;
        m3uJobClose.disabled = false;
      }
    }

    function openCatalog(id, label) {
      m3uDlgId = id;
      m3uDlgOffset = 0;
      m3uCatalogRows = [];
      document.getElementById('catDlgTitle').textContent = label || 'Catálogo';
      document.getElementById('m3uDlgStatus').textContent = 'A carregar…';
      if (catSearchEl) catSearchEl.value = '';
      if (m3uFilterEl) m3uFilterEl.value = 'all';
      dlgCatalog.showModal();
      fetchCatalogChunk(false).then(() => {
        document.getElementById('m3uDlgStatus').textContent = '';
      }).catch(e => {
        document.getElementById('m3uDlgStatus').textContent = e.message;
      });
    }

    if (m3uFilterEl) {
      m3uFilterEl.addEventListener('change', () => {
        m3uDlgOffset = 0;
        m3uCatalogRows = [];
        fetchCatalogChunk(false).catch(e => alert(e.message));
      });
    }
    if (catSearchEl) {
      catSearchEl.addEventListener('input', () => renderCatalogTable());
    }

    document.getElementById('catLoadMore').addEventListener('click', () => {
      if (m3uDlgOffset >= m3uDlgTotal) return;
      document.getElementById('m3uDlgStatus').textContent = 'A carregar…';
      fetchCatalogChunk(true).then(() => {
        document.getElementById('m3uDlgStatus').textContent = '';
      }).catch(e => {
        document.getElementById('m3uDlgStatus').textContent = e.message;
      });
    });

    document.getElementById('catLoadAll').addEventListener('click', async () => {
      const st = document.getElementById('m3uDlgStatus');
      st.textContent = 'A carregar catálogo completo…';
      m3uDlgOffset = 0;
      m3uCatalogRows = [];
      try {
        while (m3uDlgOffset < m3uDlgTotal) {
          await fetchCatalogChunk(true);
          st.textContent = 'Carregados ' + m3uCatalogRows.length + ' / ' + m3uDlgTotal + '…';
          if ((m3uCatalogRows.length === 0 && m3uDlgTotal > 0) || m3uDlgOffset >= m3uDlgTotal) break;
        }
        st.textContent = 'Catálogo completo: ' + m3uCatalogRows.length + ' itens.';
      } catch (e) { st.textContent = e.message; }
    });

    document.getElementById('m3uDlVod').addEventListener('click', async () => {
      const urls = m3uSelectedUrls(true);
      if (!urls.length) { alert('Seleccione itens VOD no catálogo.'); return; }
      const st = document.getElementById('m3uDlgStatus');
      st.textContent = 'A descarregar ' + urls.length + ' VOD para arquivo…';
      try {
        const j = await api('m3u_vod_download', { urls });
        const ok = (j.results || []).filter(r => r.ok).length;
        st.textContent = 'Arquivo: ' + ok + '/' + urls.length + ' na Biblioteca.';
      } catch (e) { st.textContent = e.message; }
    });

    if (location.hash === '#tools' || M3U_HIGHLIGHT) {
      document.querySelector('nav button[data-sec="tools"]')?.click();
    }

    let lastPaymentId = 0;

    async function loadShop() {
      const j = await api('plans_buyable');
      const sel = document.getElementById('shopPlan');
      sel.innerHTML = '';
      for (const p of j.plans) {
        const o = document.createElement('option');
        o.value = p.code;
        o.textContent = p.display_name + ' — ' + (p.price_formatted || ('R$ ' + Number(p.price_monthly).toFixed(2).replace('.', ',')));
        sel.appendChild(o);
      }
      document.getElementById('pixOut').textContent = '';
      document.getElementById('btnPixStatus').disabled = true;
      lastPaymentId = 0;
    }

    document.getElementById('btnPix').addEventListener('click', async () => {
      const plan_code = document.getElementById('shopPlan').value;
      const out = document.getElementById('pixOut');
      try {
        const j = await api('pix_create', { plan_code });
        lastPaymentId = j.payment_id;
        document.getElementById('btnPixStatus').disabled = !lastPaymentId;
        let t = 'Pagamento #' + j.payment_id + ' — ' + (j.amount_formatted || ('R$ ' + Number(j.amount).toFixed(2))) + '\n';
        if (j.demo_mode) {
          t += 'Modo demonstração: configure Mercado Pago ou Asaas em Admin → Financeiro.\n';
        }
        t += (j.pix_copy_paste ? ('Copia e cola PIX:\n' + j.pix_copy_paste) : '(sem código PIX — verifique as credenciais do provedor)');
        out.textContent = t;
      } catch (e) { alert(e.message); }
    });

    document.getElementById('btnPixStatus').addEventListener('click', async () => {
      if (!lastPaymentId) return;
      try {
        const j = await api('pix_status', { payment_id: lastPaymentId });
        const p = j.payment;
        document.getElementById('pixOut').textContent = 'Estado: ' + p.status + (p.paid_at ? (' — pago em ' + new Date(Number(p.paid_at)*1000).toLocaleString('pt-BR')) : '');
      } catch (e) { alert(e.message); }
    });

    document.getElementById('btnRefreshTickets').addEventListener('click', () => loadTickets().catch(e => alert(e.message)));

    async function loadTickets() {
      const j = await api('ticket_list');
      const tb = document.querySelector('#ticketsTbl tbody');
      tb.innerHTML = '';
      for (const t of j.tickets) {
        const tr = document.createElement('tr');
        const u = new Date(Number(t.updated_at) * 1000).toLocaleString('pt-BR');
        tr.innerHTML = `<td>${t.id}</td><td>${escapeHtml(t.subject)}</td><td>${escapeHtml(t.priority)}</td><td>${escapeHtml(t.status)}</td><td>${u}</td><td><button type="button" data-v="${t.id}">Ver</button></td>`;
        tr.querySelector('[data-v]').addEventListener('click', () => viewTicket(Number(t.id)));
        tb.appendChild(tr);
      }
    }

    async function viewTicket(id) {
      const j = await api('ticket_get', { id });
      const t = j.ticket;
      let text = '#' + t.id + ' ' + t.subject + '\nEstado: ' + t.status + '\n\n' + t.body;
      for (const m of j.messages) {
        text += '\n---\nUtilizador ' + m.author_user_id + ':\n' + m.body;
      }
      alert(text.length > 3500 ? text.slice(0, 3500) + '…' : text);
      const r = prompt('Responder (deixe vazio para cancelar):');
      if (r && r.trim()) {
        await api('ticket_reply', { id, body: r.trim() });
        await loadTickets();
      }
    }

    const ticketDlg = document.getElementById('ticketDlg');
    document.getElementById('btnNewTicket').addEventListener('click', () => { ticketDlg.showModal(); });
    document.getElementById('tkCancel').addEventListener('click', () => ticketDlg.close());
    document.getElementById('tkSend').addEventListener('click', async () => {
      const subject = document.getElementById('tkSub').value.trim();
      const body = document.getElementById('tkBody').value.trim();
      const priority = document.getElementById('tkPri').value;
      if (!subject || !body) { alert('Preencha assunto e mensagem.'); return; }
      try {
        await api('ticket_create', { subject, body, priority });
        ticketDlg.close();
        document.getElementById('tkSub').value = '';
        document.getElementById('tkBody').value = '';
        await loadTickets();
      } catch (e) { alert(e.message); }
    });

    document.getElementById('btnEmSave').addEventListener('click', async () => {
      const new_email = document.getElementById('emNew').value.trim();
      const current_password = document.getElementById('emCur').value;
      const el = document.getElementById('emMsg');
      el.textContent = '';
      try {
        await api('account_email_change', { new_email, current_password });
        el.className = 'ok';
        el.textContent = 'E-mail actualizado. Recarregue a página para ver o e-mail actualizado no menu.';
        document.getElementById('emNew').value = '';
        document.getElementById('emCur').value = '';
      } catch (e) {
        el.className = 'err';
        el.textContent = e.message;
      }
    });

    document.getElementById('btnPwSave').addEventListener('click', async () => {
      const current_password = document.getElementById('pwCur').value;
      const new_password = document.getElementById('pwNew').value;
      const new_password_confirm = document.getElementById('pwNew2').value;
      const el = document.getElementById('pwMsg');
      el.textContent = '';
      try {
        await api('account_password_change', { current_password, new_password, new_password_confirm });
        el.className = 'ok';
        el.textContent = 'Senha alterada com sucesso.';
        document.getElementById('pwCur').value = '';
        document.getElementById('pwNew').value = '';
        document.getElementById('pwNew2').value = '';
      } catch (e) {
        el.className = 'err';
        el.textContent = e.message;
      }
    });

    loadSites().catch(e => console.error(e));
  </script>
  <p style="margin:1rem 1.25rem;"><a href="<?= h(extractor_url('index.php')) ?>?logout=1" style="color:#8ec5ff;">Sair</a> · <a href="<?= h(extractor_url('index.php')) ?>" style="color:#8ec5ff;">Início</a></p>
</body>
</html>
