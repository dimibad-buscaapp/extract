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
    }
    if ($form === 'm3u') {
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
                $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'M3U salvo em /data (' . extractor_format_bytes((int) $r['bytes']) . ').'];
            }
        } elseif ($text !== '') {
            if (strlen($text) > 4 * 1024 * 1024) {
                $_SESSION['flash'] = ['type' => 'err', 'msg' => 'M3U colado demasiado grande (máx. 4 MB). Use o campo URL.'];
            } elseif (!str_contains($text, '#EXTM3U')) {
                $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Conteúdo não parece um M3U válido.'];
            } else {
                $fn = EXTRACTOR_DATA . '/lista_' . date('Ymd_His') . '.m3u';
                file_put_contents($fn, $text);
                $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'M3U salvo (' . extractor_format_bytes(strlen($text)) . ').'];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'err', 'msg' => 'Informe a URL do M3U ou cole o conteúdo.'];
        }
        header('Location: ' . extractor_url('panel.php') . '#tools');
        exit;
    }
    if ($form === 'xtream') {
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
                    $_SESSION['flash'] = ['type' => 'ok', 'msg' => 'Lista M3U Xtream salva (' . extractor_format_bytes((int) $m3u['bytes']) . ').'];
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
    .muted { color:#9aa3b2; font-size:0.85rem; }
    .ok { color:#8fd68f; }
    .err { color:#ff8a8a; }
    .tbl { width:100%; border-collapse:collapse; font-size:0.88rem; margin-top:0.5rem; }
    .tbl th, .tbl td { border-bottom:1px solid #2c3140; padding:0.4rem 0.3rem; text-align:left; vertical-align:top; word-break:break-all; }
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

  <script>
    const CSRF = <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>;
    const API_URL = <?= json_encode(extractor_url('api.php'), JSON_THROW_ON_ERROR) ?>;
    const DOWNLOAD_URL = <?= json_encode(extractor_url('download.php'), JSON_THROW_ON_ERROR) ?>;
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
      const map = { extract:'Extrator', sites:'Sites', lib:'Biblioteca', tools:'M3U / Xtream', support:'Suporte', shop:'PIX / Planos', account:'Conta' };
      title.textContent = map[b.dataset.sec] || 'Extrator';
      if (b.dataset.sec === 'sites') loadSites();
      if (b.dataset.sec === 'lib') loadLib();
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
      sel.innerHTML = '';
      for (const s of j.sites) {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${escapeHtml(s.name)}</td><td>${escapeHtml(s.base_url)}</td><td><button data-e="${s.id}">Editar</button> <button data-d="${s.id}">Apagar</button></td>`;
        tr.querySelector('[data-e]').addEventListener('click', () => openDlg(s.id));
        tr.querySelector('[data-d]').addEventListener('click', async () => { if (!confirm('Apagar?')) return; await api('site_delete', { id: s.id }); loadSites(); });
        tb.appendChild(tr);
        const o = document.createElement('option');
        o.value = s.id; o.textContent = s.name;
        sel.appendChild(o);
      }
    }
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
