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

if (!extractor_is_admin_panel_user()) {
    http_response_code(403);
    echo 'Sem permissão para o painel administrativo.';
    exit;
}

$isSuper = extractor_is_super_master();
$meName = (string) ($_SESSION['user_name'] ?? '');
$meRole = (string) ($_SESSION['user_role'] ?? '');
$csrf = extractor_csrf_token();

$billingWebhookUrl = extractor_absolute_url('billing_webhook.php');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Administração — Extrator PHP</title>
  <link rel="stylesheet" href="<?= h(extractor_url('static/admin.css')) ?>" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  <aside>
    <div class="brand">Admin</div>
    <p class="muted" style="font-size:0.78rem;margin:0 0 0.5rem;">
      <strong><?= h($meName !== '' ? $meName : 'Conta') ?></strong><br />
      <code style="color:#9ec0ff;"><?= h($meRole) ?></code>
    </p>
    <nav>
      <button type="button" class="active" data-sec="dash">Resumo</button>
      <button type="button" data-sec="users">Utilizadores</button>
      <button type="button" data-sec="tx">Transacções</button>
      <button type="button" data-sec="reports">Relatórios</button>
      <button type="button" data-sec="finance">Financeiro</button>
      <button type="button" data-sec="tickets">Tickets</button>
      <?php if ($isSuper): ?>
        <button type="button" data-sec="plans">Planos</button>
        <button type="button" data-sec="audit">Auditoria</button>
        <button type="button" data-sec="cfg">Sistema</button>
      <?php endif; ?>
    </nav>
    <div class="toplinks">
      <a href="<?= h(extractor_url('panel.php')) ?>">Painel</a> · <a href="<?= h(extractor_url('index.php')) ?>?logout=1">Sair</a>
    </div>
  </aside>
  <main>
    <h1 id="title">Resumo</h1>
    <p id="msg" class="muted" style="min-height:1.25rem;"></p>

    <section id="sec-dash" class="sec active">
      <div class="card">
        <div class="stats" id="statsGrid"></div>
        <h2>Por nível (papel)</h2>
        <table class="tbl" id="roleTbl"><thead><tr><th>Papel</th><th>Contas</th></tr></thead><tbody></tbody></table>
      </div>
    </section>

    <section id="sec-users" class="sec">
      <div class="card">
        <?php if (!$isSuper): ?>
          <p class="muted">Como Master, vê e gere apenas contas <strong>user</strong> / <strong>reseller</strong> que criou (ligadas à sua conta).</p>
        <?php endif; ?>
        <div class="toolbar">
          <button type="button" id="btnNewUser">Novo utilizador</button>
        </div>
        <table class="tbl" id="usersTbl">
          <thead>
            <tr>
              <th>ID</th><th>E-mail</th><th>Nome</th><th>Papel</th><th>Pai</th><th>Créditos</th><th>Estado</th><th>Acções</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </section>

    <section id="sec-tx" class="sec">
      <div class="card">
        <table class="tbl" id="txTbl">
          <thead>
            <tr><th>ID</th><th>Utilizador</th><th>Tipo</th><th>Δ</th><th>Saldo</th><th>Nota</th><th>Data</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </section>

    <section id="sec-reports" class="sec">
      <div class="card">
        <p class="muted">Dados derivados de utilizadores, ficheiros descarregados, consumo de créditos (<code>use</code>) e pagamentos PIX confirmados.</p>
        <div class="toolbar" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
          <button type="button" class="period active" data-days="30">30 dias</button>
          <button type="button" class="period" data-days="90">90 dias</button>
          <button type="button" class="period" data-days="365">365 dias</button>
          <button type="button" id="btnExportReport" style="background:#3a5c3a;">Exportar JSON</button>
        </div>
        <div class="charts-grid">
          <div class="chart-card"><h3>Registos vs ficheiros (dia)</h3><div class="chart-wrap"><canvas id="chDaily"></canvas></div></div>
          <div class="chart-card"><h3>Créditos consumidos por papel</h3><div class="chart-wrap"><canvas id="chRole"></canvas></div></div>
          <div class="chart-card"><h3>Top utilizadores (ficheiros)</h3><div class="chart-wrap"><canvas id="chTop"></canvas></div></div>
          <div class="chart-card"><h3>Faturamento mensal (PIX pago)</h3><div class="chart-wrap"><canvas id="chRev"></canvas></div></div>
        </div>
        <p class="muted" id="reportTotals"></p>
      </div>
    </section>

    <section id="sec-finance" class="sec">
      <div class="card">
        <?php if ($isSuper): ?>
        <h2 style="margin-top:0;">Pagamentos (PIX)</h2>
        <p class="muted">Configure Mercado Pago ou Asaas. Valores em reais (BRL). Chaves em <code>data/payment_settings.json</code> sobrepõem <code>config.local.php</code>.</p>
        <form id="payCfgForm" style="display:grid;gap:0.5rem;max-width:32rem;margin-bottom:1rem;">
          <label>Provedor activo</label>
          <select id="payProvider">
            <option value="mercadopago">Mercado Pago</option>
            <option value="asaas">Asaas</option>
            <option value="demo">Demonstração (sem API)</option>
          </select>
          <label>Mercado Pago — Access Token</label>
          <input type="password" id="mpToken" placeholder="Deixe vazio para manter o actual" autocomplete="off" />
          <span class="muted" id="mpTokenHint" style="font-size:0.8rem;"></span>
          <label>Mercado Pago — Public Key</label>
          <input type="text" id="mpPub" autocomplete="off" />
          <label><input type="checkbox" id="mpSandbox" /> Credenciais de teste (sandbox)</label>
          <label>Asaas — API Key</label>
          <input type="password" id="asaasKey" placeholder="Deixe vazio para manter o actual" autocomplete="off" />
          <span class="muted" id="asaasKeyHint" style="font-size:0.8rem;"></span>
          <label><input type="checkbox" id="asaasSandbox" /> Asaas sandbox</label>
          <label>Token webhook Asaas (opcional)</label>
          <input type="text" id="asaasWh" autocomplete="off" />
          <button type="submit" style="background:#3b6df6;color:#fff;border:0;padding:0.5rem 0.85rem;border-radius:8px;cursor:pointer;font-weight:600;width:fit-content;">Guardar configuração</button>
        </form>
        <?php endif; ?>
        <p class="muted">Webhook (POST JSON) — mesma URL no Mercado Pago e/ou Asaas:</p>
        <p><strong>URL:</strong><br /><code id="whUrl" style="font-size:0.8rem;word-break:break-all;"><?= h($billingWebhookUrl) ?></code></p>
        <button type="button" id="btnCopyWebhook" style="background:#3a3f52;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;">Copiar URL</button>
        <h2 style="margin-top:1rem;">Últimos pagamentos</h2>
        <table class="tbl" id="payTbl"><thead><tr><th>ID</th><th>User</th><th>Plano</th><th>Valor</th><th>Prov.</th><th>Estado</th><th>Criado</th></tr></thead><tbody></tbody></table>
      </div>
    </section>

    <section id="sec-tickets" class="sec">
      <div class="card">
        <div class="toolbar" style="display:flex;gap:0.5rem;flex-wrap:wrap;">
          <select id="tkFilter">
            <option value="all">Todos</option>
            <option value="open">Aberto</option>
            <option value="in_progress">Em progresso</option>
            <option value="answered">Respondido</option>
            <option value="closed">Fechado</option>
          </select>
          <button type="button" id="btnTkReload">Actualizar</button>
        </div>
        <table class="tbl" id="tkTbl">
          <thead><tr><th>ID</th><th>Utilizador</th><th>Assunto</th><th>P</th><th>Estado</th><th>Msgs</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </section>

    <?php if ($isSuper): ?>
    <section id="sec-plans" class="sec">
      <div class="card">
        <p class="muted">Crie e edite planos (preços em R$). O plano <code>super_master</code> não é editável aqui.</p>
        <button type="button" id="btnNewPlan" style="background:#3b6df6;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;margin-bottom:0.75rem;">Novo plano</button>
        <table class="tbl" id="plansTbl">
          <thead>
            <tr><th>Código</th><th>Nome</th><th>Papel</th><th>Créditos/mês</th><th>Preço (R$)</th><th>Sub-contas</th><th>Revenda</th><th></th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </section>

    <section id="sec-audit" class="sec">
      <div class="card">
        <table class="tbl" id="auditTbl">
          <thead>
            <tr><th>ID</th><th>Actor</th><th>Acção</th><th>Detalhe</th><th>IP</th><th>Data</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </section>

    <section id="sec-cfg" class="sec">
      <div class="card">
        <pre id="cfgPre" class="muted" style="white-space:pre-wrap;font-size:0.82rem;margin:0;"></pre>
      </div>
    </section>
    <?php endif; ?>
  </main>

  <dialog id="dlgPlan" style="border:1px solid #2c3140;border-radius:10px;background:#1a1c26;color:#e8e8ef;padding:0;max-width:26rem;width:calc(100% - 2rem);">
    <div style="padding:1rem;">
      <h2 id="planDlgTitle" style="margin-top:0;font-size:1rem;">Plano</h2>
      <label>Código (único, a-z)</label>
      <input type="text" id="plCode" pattern="[a-z][a-z0-9_]+" />
      <label>Nome visível</label>
      <input type="text" id="plName" />
      <label>Papel atribuído</label>
      <select id="plRole">
        <option value="user">user</option>
        <option value="reseller">reseller</option>
        <option value="master">master</option>
      </select>
      <label>Créditos por mês</label>
      <input type="number" id="plCredits" min="0" value="100" />
      <label>Preço mensal (R$)</label>
      <input type="number" id="plPrice" min="0" step="0.01" value="49.90" />
      <label>Máx. sub-contas</label>
      <input type="number" id="plSubs" min="0" value="0" />
      <label><input type="checkbox" id="plResell" /> Pode revender</label>
      <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:0.75rem;">
        <button type="button" id="plCancel" style="background:#3a3f52;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;">Cancelar</button>
        <button type="button" id="plSave" style="background:#3b6df6;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;font-weight:600;">Guardar</button>
      </div>
    </div>
  </dialog>

  <dialog id="dlgUser" style="border:1px solid #2c3140;border-radius:10px;background:#1a1c26;color:#e8e8ef;padding:0;max-width:28rem;width:calc(100% - 2rem);">
    <div style="padding:1rem;">
      <h2 style="margin-top:0;font-size:1rem;">Novo utilizador</h2>
      <label>E-mail</label>
      <input type="email" id="nuEmail" required />
      <label>Nome completo</label>
      <input type="text" id="nuName" required />
      <label>Senha (mín. 10)</label>
      <input type="password" id="nuPass" required minlength="10" />
      <label>Papel</label>
      <select id="nuRole">
        <option value="user">user</option>
        <option value="reseller">reseller</option>
        <?php if ($isSuper): ?>
        <option value="master">master</option>
        <?php endif; ?>
      </select>
      <label>Créditos iniciais</label>
      <input type="number" id="nuCredits" value="0" min="0" />
      <?php if ($isSuper): ?>
      <label>ID conta pai (opcional)</label>
      <input type="number" id="nuParent" value="" min="0" placeholder="vazio = sem pai" />
      <?php endif; ?>
      <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:0.75rem;">
        <button type="button" id="nuCancel" style="background:#3a3f52;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;">Cancelar</button>
        <button type="button" id="nuSubmit" style="background:#3b6df6;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;font-weight:600;">Criar</button>
      </div>
    </div>
  </dialog>

  <dialog id="dlgTk" style="border:1px solid #2c3140;border-radius:10px;background:#1a1c26;color:#e8e8ef;padding:0;max-width:36rem;width:calc(100% - 2rem);">
    <div style="padding:1rem;">
      <input type="hidden" id="tkViewId" />
      <h2 id="tkViewTitle" style="margin-top:0;font-size:1rem;">Ticket</h2>
      <pre id="tkViewMeta" class="muted" style="white-space:pre-wrap;font-size:0.82rem;"></pre>
      <div id="tkViewMsgs" class="muted" style="max-height:12rem;overflow:auto;font-size:0.82rem;margin:0.5rem 0;"></div>
      <label>Resposta</label>
      <textarea id="tkReply" rows="4" style="max-width:100%;width:100%;box-sizing:border-box;"></textarea>
      <label>Alterar estado</label>
      <select id="tkStSel">
        <option value="open">open</option>
        <option value="in_progress">in_progress</option>
        <option value="answered">answered</option>
        <option value="closed">closed</option>
      </select>
      <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:0.75rem;flex-wrap:wrap;">
        <button type="button" id="tkCloseDlg" style="background:#3a3f52;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;">Fechar</button>
        <button type="button" id="tkSaveSt" style="background:#6a4a2a;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;">Guardar estado</button>
        <button type="button" id="tkSendReply" style="background:#3b6df6;color:#fff;border:0;padding:0.45rem 0.75rem;border-radius:8px;cursor:pointer;font-weight:600;">Enviar resposta</button>
      </div>
    </div>
  </dialog>

  <script>
    const CSRF = <?= json_encode($csrf, JSON_THROW_ON_ERROR) ?>;
    const IS_SUPER = <?= $isSuper ? 'true' : 'false' ?>;
    let reportCharts = [];
    let reportDays = 30;

    const msg = (t, ok) => {
      const el = document.getElementById('msg');
      el.textContent = t || '';
      el.className = ok ? 'ok' : (t ? 'err' : 'muted');
    };

    const ADMIN_API_URL = <?= json_encode(extractor_url('admin_api.php'), JSON_THROW_ON_ERROR) ?>;
    const api = async (action, extra = {}) => {
      const r = await fetch(ADMIN_API_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, csrf: CSRF, ...extra }) });
      const t = await r.text();
      let j;
      try { j = JSON.parse(t); } catch { throw new Error(t); }
      if (!j.ok) throw new Error(j.error || 'erro');
      return j;
    };

    const fmtTime = (unix) => {
      if (!unix) return '—';
      const d = new Date(Number(unix) * 1000);
      return d.toLocaleString('pt-BR');
    };

    function destroyReportCharts() {
      reportCharts.forEach(c => { try { c.destroy(); } catch (e) {} });
      reportCharts = [];
    }

    document.querySelectorAll('button.period').forEach(b => b.addEventListener('click', () => {
      reportDays = parseInt(b.getAttribute('data-days') || '30', 10);
      document.querySelectorAll('button.period').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      loadReports().catch(e => msg(e.message, false));
    }));

    document.getElementById('btnExportReport').addEventListener('click', () => {
      if (!window.__lastReport) { msg('Carregue relatórios primeiro.', false); return; }
      const blob = new Blob([JSON.stringify(window.__lastReport, null, 2)], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'relatorio.json';
      a.click();
      URL.revokeObjectURL(a.href);
      msg('JSON exportado.', true);
    });

    document.getElementById('btnCopyWebhook').addEventListener('click', async () => {
      const t = <?= json_encode($billingWebhookUrl, JSON_THROW_ON_ERROR) ?>;
      try { await navigator.clipboard.writeText(t); msg('URL copiada.', true); } catch { msg(t, true); }
    });

    document.getElementById('btnTkReload').addEventListener('click', () => loadTkList().catch(e => msg(e.message, false)));

    function fmtBrl(n) {
      return 'R$ ' + Number(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    async function loadPaySettings() {
      if (!IS_SUPER) return;
      const j = await api('admin_payment_settings_get');
      const s = j.settings;
      document.getElementById('payProvider').value = s.payment_provider || 'mercadopago';
      document.getElementById('mpPub').value = s.mercadopago_public_key || '';
      document.getElementById('mpSandbox').checked = !!s.mercadopago_sandbox;
      document.getElementById('asaasSandbox').checked = !!s.asaas_sandbox;
      document.getElementById('asaasWh').value = s.asaas_webhook_token || '';
      document.getElementById('mpTokenHint').textContent = s.mercadopago_access_token_set
        ? ('Token actual: ' + (s.mercadopago_access_token_masked || 'definido'))
        : 'Nenhum token guardado.';
      document.getElementById('asaasKeyHint').textContent = s.asaas_api_key_set
        ? ('Chave actual: ' + (s.asaas_api_key_masked || 'definida'))
        : 'Nenhuma chave Asaas guardada.';
      if (j.webhook_url) document.getElementById('whUrl').textContent = j.webhook_url;
    }

    async function loadFinance() {
      if (IS_SUPER) await loadPaySettings();
      const j = await api('admin_payments_list', { limit: 80 });
      const tb = document.querySelector('#payTbl tbody');
      tb.innerHTML = '';
      for (const p of j.payments) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + p.id + '</td><td>' + escapeHtml(p.user_email || ('#' + p.user_id)) + '</td><td>' + escapeHtml(p.plan_code) + '</td><td>' + fmtBrl(p.amount) + '</td><td>' + escapeHtml(p.provider || '') + '</td><td>' + escapeHtml(p.status) + '</td><td>' + fmtTime(p.created_at) + '</td>';
        tb.appendChild(tr);
      }
    }

    async function loadTkList() {
      const st = document.getElementById('tkFilter').value;
      const j = await api('admin_tickets_list', { status: st, limit: 100 });
      const tb = document.querySelector('#tkTbl tbody');
      tb.innerHTML = '';
      for (const t of j.tickets) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + t.id + '</td><td>' + escapeHtml(t.user_email || '') + '</td><td>' + escapeHtml(t.subject) + '</td><td>' + escapeHtml(t.priority) + '</td><td>' + escapeHtml(t.status) + '</td><td>' + t.msg_count + '</td><td><button type="button" data-tk="' + t.id + '">Abrir</button></td>';
        tr.querySelector('button').addEventListener('click', () => openTk(Number(t.id)));
        tb.appendChild(tr);
      }
    }

    document.getElementById('tkFilter').addEventListener('change', () => loadTkList().catch(e => msg(e.message, false)));

    const dlgTk = document.getElementById('dlgTk');
    document.getElementById('tkCloseDlg').addEventListener('click', () => dlgTk.close());

    async function openTk(id) {
      const j = await api('admin_ticket_get', { id });
      const t = j.ticket;
      document.getElementById('tkViewId').value = String(id);
      document.getElementById('tkViewTitle').textContent = '#' + id + ' — ' + t.subject;
      document.getElementById('tkViewMeta').textContent = (t.user_email || '') + '\n' + t.body;
      document.getElementById('tkStSel').value = t.status;
      let mh = '';
      for (const m of j.messages) {
        mh += '[' + fmtTime(m.created_at) + '] ' + (m.author_email || m.author_user_id) + '\n' + m.body + '\n\n';
      }
      document.getElementById('tkViewMsgs').textContent = mh || '(sem respostas ainda)';
      document.getElementById('tkReply').value = '';
      dlgTk.showModal();
    }

    document.getElementById('tkSendReply').addEventListener('click', async () => {
      const id = parseInt(document.getElementById('tkViewId').value || '0', 10);
      const body = document.getElementById('tkReply').value.trim();
      if (!id || !body) { msg('Escreva uma resposta.', false); return; }
      try {
        await api('admin_ticket_reply', { id, body });
        msg('Resposta enviada.', true);
        dlgTk.close();
        await loadTkList();
      } catch (e) { msg(e.message, false); }
    });

    document.getElementById('tkSaveSt').addEventListener('click', async () => {
      const id = parseInt(document.getElementById('tkViewId').value || '0', 10);
      const status = document.getElementById('tkStSel').value;
      if (!id) return;
      try {
        await api('admin_ticket_set_status', { id, status });
        msg('Estado actualizado.', true);
        dlgTk.close();
        await loadTkList();
      } catch (e) { msg(e.message, false); }
    });

    async function loadReports() {
      if (typeof Chart === 'undefined') {
        msg('Chart.js não carregou. Verifique a rede / CDN.', false);
        return;
      }
      const j = await api('admin_reports', { days: reportDays });
      window.__lastReport = j;
      document.getElementById('reportTotals').textContent = 'Totais no período: registos ' + j.totals.signups + ', ficheiros ' + j.totals.files + ', créditos usados (soma) ' + j.totals.credits_used;
      destroyReportCharts();
      const daily = j.daily || [];
      const labels = daily.map(x => x.date.slice(5));
      const c1 = document.getElementById('chDaily').getContext('2d');
      reportCharts.push(new Chart(c1, {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label: 'Novos registos', data: daily.map(x => x.signups), borderColor: '#6a9cff', tension: 0.25, fill: false },
            { label: 'Ficheiros', data: daily.map(x => x.files), borderColor: '#8fd68f', tension: 0.25, fill: false },
          ],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#c8d0e0' } } }, scales: { x: { ticks: { color: '#9aa3b2' } }, y: { ticks: { color: '#9aa3b2' }, grid: { color: '#2c3140' } } } },
      }));
      const roles = (j.credits_by_role || []).map(r => r.role);
      const vals = (j.credits_by_role || []).map(r => Number(r.s));
      const c2 = document.getElementById('chRole').getContext('2d');
      reportCharts.push(new Chart(c2, {
        type: 'doughnut',
        data: { labels: roles.length ? roles : ['—'], datasets: [{ data: vals.length ? vals : [1], backgroundColor: ['#667eea','#764ba2','#4caf50','#ff9800','#2196f3'] }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#c8d0e0' } } } },
      }));
      const top = j.top_files || [];
      const c3 = document.getElementById('chTop').getContext('2d');
      reportCharts.push(new Chart(c3, {
        type: 'bar',
        data: {
          labels: top.length ? top.map(u => (u.email || u.full_name || ('#' + u.user_id)).slice(0, 18)) : ['—'],
          datasets: [{ label: 'Ficheiros', data: top.length ? top.map(u => u.c) : [0], backgroundColor: '#3b6df6' }],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: '#9aa3b2' } }, y: { ticks: { color: '#9aa3b2' }, grid: { color: '#2c3140' } } } },
      }));
      const rev = j.revenue_months || [];
      const c4 = document.getElementById('chRev').getContext('2d');
      reportCharts.push(new Chart(c4, {
        type: 'line',
        data: {
          labels: rev.map(x => x.month),
          datasets: [{ label: 'R$', data: rev.map(x => x.total), borderColor: '#ff9800', tension: 0.3, fill: true, backgroundColor: 'rgba(255,152,0,0.08)' }],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#c8d0e0' } } }, scales: { x: { ticks: { color: '#9aa3b2' } }, y: { ticks: { color: '#9aa3b2' }, grid: { color: '#2c3140' } } } },
      }));
    }

    async function loadDash() {
      const j = await api('admin_stats');
      const s = j.stats;
      const grid = document.getElementById('statsGrid');
      grid.innerHTML = '';
      const cards = [
        ['Utilizadores', s.total_users],
        ['Créditos (soma)', s.sum_credits],
        ['Transacções', s.total_credit_transactions],
        ['Sites', s.total_sites],
        ['Ficheiros', s.total_files],
      ];
      for (const [k, v] of cards) {
        const d = document.createElement('div');
        d.className = 'stat';
        d.innerHTML = '<span class="muted">' + k + '</span><strong>' + v + '</strong>';
        grid.appendChild(d);
      }
      const tb = document.querySelector('#roleTbl tbody');
      tb.innerHTML = '';
      for (const row of s.users_by_role || []) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + escapeHtml(row.role) + '</td><td>' + row.c + '</td>';
        tb.appendChild(tr);
      }
    }

    async function loadUsers() {
      const j = await api('admin_users_list');
      const tb = document.querySelector('#usersTbl tbody');
      tb.innerHTML = '';
      for (const u of j.users) {
        const tr = document.createElement('tr');
        const st = u.status === 'active' ? 'Activo' : u.status;
        const parent = u.parent_user_id != null ? String(u.parent_user_id) : '—';
        let actions = '';
        actions += '<button type="button" data-grant="' + u.id + '">Créditos</button>';
        if (u.status === 'active') {
          actions += ' <button type="button" data-susp="' + u.id + '">Suspender</button>';
        } else {
          actions += ' <button type="button" data-act="' + u.id + '">Activar</button>';
        }
        if (IS_SUPER) {
          actions += ' <button type="button" class="danger" data-del="' + u.id + '">Apagar</button>';
        }
        tr.innerHTML = '<td>' + u.id + '</td><td>' + escapeHtml(u.email) + '</td><td>' + escapeHtml(u.full_name) + '</td><td>' + escapeHtml(u.role) + '</td><td>' + parent + '</td><td>' + u.credits + '</td><td>' + escapeHtml(st) + '</td><td class="row-actions">' + actions + '</td>';
        tb.appendChild(tr);
      }
      tb.querySelectorAll('[data-grant]').forEach(b => b.addEventListener('click', () => grantCredits(Number(b.getAttribute('data-grant')))));
      tb.querySelectorAll('[data-susp]').forEach(b => b.addEventListener('click', () => setStatus(Number(b.getAttribute('data-susp')), 'suspended')));
      tb.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => setStatus(Number(b.getAttribute('data-act')), 'active')));
      tb.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => deleteUser(Number(b.getAttribute('data-del')))));
    }

    async function grantCredits(id) {
      const n = prompt('Quantidade de créditos a adicionar (inteiro ≥ 1):');
      if (!n) return;
      const amount = parseInt(n, 10);
      if (!(amount >= 1)) { msg('Quantidade inválida.', false); return; }
      try {
        await api('admin_user_grant_credits', { id, amount });
        msg('Créditos adicionados.', true);
        await loadUsers();
      } catch (e) { msg(e.message, false); }
    }

    async function setStatus(id, status) {
      try {
        await api('admin_user_set_status', { id, status });
        msg('Estado actualizado.', true);
        await loadUsers();
      } catch (e) { msg(e.message, false); }
    }

    async function deleteUser(id) {
      if (!confirm('Apagar utilizador #' + id + '? Isto remove sites, ficheiros e histórico de créditos desta conta.')) return;
      try {
        await api('admin_user_delete', { id });
        msg('Utilizador apagado.', true);
        await loadUsers();
        await loadDash();
      } catch (e) { msg(e.message, false); }
    }

    async function loadTx() {
      const j = await api('admin_transactions_list', { limit: 150 });
      const tb = document.querySelector('#txTbl tbody');
      tb.innerHTML = '';
      for (const t of j.transactions) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + t.id + '</td><td>' + escapeHtml(t.user_email || ('#' + t.user_id)) + '</td><td>' + escapeHtml(t.kind) + '</td><td>' + t.delta + '</td><td>' + t.balance_after + '</td><td>' + escapeHtml(t.description || '') + '</td><td>' + fmtTime(t.created_at) + '</td>';
        tb.appendChild(tr);
      }
    }

    const dlgPlan = document.getElementById('dlgPlan');
    let planEditNew = false;

    function openPlanForm(p, isNew) {
      planEditNew = isNew;
      document.getElementById('planDlgTitle').textContent = isNew ? 'Novo plano' : 'Editar plano';
      const codeEl = document.getElementById('plCode');
      codeEl.value = p ? p.code : '';
      codeEl.readOnly = !isNew;
      document.getElementById('plName').value = p ? p.display_name : '';
      document.getElementById('plRole').value = p ? p.role : 'user';
      document.getElementById('plCredits').value = p ? p.monthly_credits : 100;
      document.getElementById('plPrice').value = p ? p.price_monthly : 49.9;
      document.getElementById('plSubs').value = p ? p.max_subusers : 0;
      document.getElementById('plResell').checked = p ? !!Number(p.can_resell) : false;
      dlgPlan.showModal();
    }

    async function loadPlans() {
      if (!IS_SUPER) return;
      const j = await api('admin_plans_list');
      const tb = document.querySelector('#plansTbl tbody');
      tb.innerHTML = '';
      for (const p of j.plans) {
        const tr = document.createElement('tr');
        const actions = p.code === 'super_master'
          ? '<span class="muted">—</span>'
          : '<button type="button" data-ed="' + escapeHtml(p.code) + '">Editar</button> <button type="button" class="danger" data-delp="' + escapeHtml(p.code) + '">Apagar</button>';
        tr.innerHTML = '<td>' + escapeHtml(p.code) + '</td><td>' + escapeHtml(p.display_name) + '</td><td>' + escapeHtml(p.role) + '</td><td>' + p.monthly_credits + '</td><td>' + fmtBrl(p.price_monthly) + '</td><td>' + p.max_subusers + '</td><td>' + (Number(p.can_resell) ? 'sim' : 'não') + '</td><td class="row-actions">' + actions + '</td>';
        tb.appendChild(tr);
      }
      tb.querySelectorAll('[data-ed]').forEach(b => b.addEventListener('click', () => {
        const code = b.getAttribute('data-ed');
        const p = j.plans.find(x => x.code === code);
        if (p) openPlanForm(p, false);
      }));
      tb.querySelectorAll('[data-delp]').forEach(b => b.addEventListener('click', async () => {
        const code = b.getAttribute('data-delp');
        if (!confirm('Apagar plano ' + code + '?')) return;
        try {
          await api('admin_plan_delete', { code });
          msg('Plano apagado.', true);
          await loadPlans();
        } catch (e) { msg(e.message, false); }
      }));
    }

    async function loadAudit() {
      if (!IS_SUPER) return;
      const j = await api('admin_audit_list', { limit: 200 });
      const tb = document.querySelector('#auditTbl tbody');
      tb.innerHTML = '';
      for (const a of j.audit) {
        const tr = document.createElement('tr');
        tr.innerHTML = '<td>' + a.id + '</td><td>' + escapeHtml(a.actor_email || ('#' + a.actor_user_id)) + '</td><td>' + escapeHtml(a.action) + '</td><td>' + escapeHtml(a.detail || '') + '</td><td>' + escapeHtml(a.ip || '') + '</td><td>' + fmtTime(a.created_at) + '</td>';
        tb.appendChild(tr);
      }
    }

    async function loadCfg() {
      if (!IS_SUPER) return;
      const j = await api('admin_config_snapshot');
      document.getElementById('cfgPre').textContent = JSON.stringify(j.config, null, 2);
    }

    function escapeHtml(t) {
      const d = document.createElement('div');
      d.textContent = t;
      return d.innerHTML;
    }

    const title = document.getElementById('title');
    document.querySelectorAll('nav button').forEach(b => b.addEventListener('click', () => {
      document.querySelectorAll('nav button').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      document.querySelectorAll('.sec').forEach(s => s.classList.remove('active'));
      const id = 'sec-' + b.dataset.sec;
      const sec = document.getElementById(id);
      if (sec) sec.classList.add('active');
      const map = { dash: 'Resumo', users: 'Utilizadores', tx: 'Transacções', reports: 'Relatórios', finance: 'Financeiro', tickets: 'Tickets', plans: 'Planos', audit: 'Auditoria', cfg: 'Sistema' };
      title.textContent = map[b.dataset.sec] || 'Admin';
      msg('', true);
      if (b.dataset.sec === 'users') loadUsers().catch(e => msg(e.message, false));
      if (b.dataset.sec === 'tx') loadTx().catch(e => msg(e.message, false));
      if (b.dataset.sec === 'reports') loadReports().catch(e => msg(e.message, false));
      if (b.dataset.sec === 'finance') loadFinance().catch(e => msg(e.message, false));
      if (b.dataset.sec === 'tickets') loadTkList().catch(e => msg(e.message, false));
      if (b.dataset.sec === 'plans') loadPlans().catch(e => msg(e.message, false));
      if (b.dataset.sec === 'audit') loadAudit().catch(e => msg(e.message, false));
      if (b.dataset.sec === 'cfg') loadCfg().catch(e => msg(e.message, false));
    }));

    const dlg = document.getElementById('dlgUser');
    document.getElementById('btnNewUser').addEventListener('click', () => { dlg.showModal(); });
    document.getElementById('nuCancel').addEventListener('click', () => dlg.close());

    document.getElementById('nuSubmit').addEventListener('click', async () => {
      const email = document.getElementById('nuEmail').value.trim();
      const full_name = document.getElementById('nuName').value.trim();
      const password = document.getElementById('nuPass').value;
      const role = document.getElementById('nuRole').value;
      const credits = parseInt(document.getElementById('nuCredits').value || '0', 10);
      const body = { email, full_name, password, role, credits };
      if (IS_SUPER) {
        const pel = document.getElementById('nuParent');
        if (pel) {
          const p = pel.value.trim();
          if (p !== '') body.parent_user_id = parseInt(p, 10);
        }
      }
      try {
        await api('admin_user_create', body);
        msg('Utilizador criado.', true);
        dlg.close();
        document.getElementById('nuEmail').value = '';
        document.getElementById('nuName').value = '';
        document.getElementById('nuPass').value = '';
        document.getElementById('nuCredits').value = '0';
        if (IS_SUPER) {
          const pel = document.getElementById('nuParent');
          if (pel) pel.value = '';
        }
        await loadUsers();
        await loadDash();
      } catch (e) {
        msg(e.message, false);
      }
    });

    const payForm = document.getElementById('payCfgForm');
    if (payForm) {
      payForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
          await api('admin_payment_settings_save', {
            payment_provider: document.getElementById('payProvider').value,
            mercadopago_access_token: document.getElementById('mpToken').value,
            mercadopago_public_key: document.getElementById('mpPub').value,
            mercadopago_sandbox: document.getElementById('mpSandbox').checked,
            asaas_api_key: document.getElementById('asaasKey').value,
            asaas_sandbox: document.getElementById('asaasSandbox').checked,
            asaas_webhook_token: document.getElementById('asaasWh').value,
          });
          document.getElementById('mpToken').value = '';
          document.getElementById('asaasKey').value = '';
          msg('Configuração de pagamentos guardada.', true);
          await loadPaySettings();
        } catch (err) { msg(err.message, false); }
      });
    }

    const btnNewPlan = document.getElementById('btnNewPlan');
    if (btnNewPlan) btnNewPlan.addEventListener('click', () => openPlanForm(null, true));
    document.getElementById('plCancel')?.addEventListener('click', () => dlgPlan.close());
    document.getElementById('plSave')?.addEventListener('click', async () => {
      const body = {
        is_new: planEditNew,
        code: document.getElementById('plCode').value.trim().toLowerCase(),
        display_name: document.getElementById('plName').value.trim(),
        role: document.getElementById('plRole').value,
        monthly_credits: parseInt(document.getElementById('plCredits').value || '0', 10),
        price_monthly: parseFloat(document.getElementById('plPrice').value || '0'),
        max_subusers: parseInt(document.getElementById('plSubs').value || '0', 10),
        can_resell: document.getElementById('plResell').checked,
      };
      try {
        await api('admin_plan_save', body);
        msg('Plano guardado.', true);
        dlgPlan.close();
        await loadPlans();
      } catch (e) { msg(e.message, false); }
    });

    loadDash().catch(e => msg(e.message, false));
  </script>
</body>
</html>
