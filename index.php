<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

if (isset($_GET['diag']) || isset($_GET['health'])) {
    $healthFile = __DIR__ . '/health.php';
    if (is_file($healthFile)) {
        require $healthFile;
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Diag</title></head><body style="font-family:sans-serif;padding:2rem;background:#0a0c14;color:#eee;">';
    echo '<h1>Actualize o código no servidor</h1>';
    echo '<p>BUILD esperado: <code>' . h(EXTRACTOR_BUILD_ID) . '</code></p>';
    echo '<p>Falta <code>health.php</code> — no VPS execute:</p><pre style="background:#111;padding:1rem;">cd C:\\Apps\\Extrator\n';
    echo '& "C:\\Program Files\\Git\\bin\\git.exe" pull origin main</pre>';
    echo '<p>Extensões: pdo_sqlite=' . (extension_loaded('pdo_sqlite') ? 'sim' : 'não') . ' · data gravável=' . (is_writable(EXTRACTOR_DATA) ? 'sim' : 'não') . '</p>';
    echo '<p><a href="' . h(extractor_url('index.php')) . '">Voltar</a></p></body></html>';
    exit;
}

/** Texto amigável por plano (sem jargão técnico). */
function extractor_plan_blurb(string $code): string
{
    return match ($code) {
        'user' => 'Ideal para quem usa sozinho e quer organizar downloads com simplicidade.',
        'reseller' => 'Para quem revende acesso e gere vários clientes no mesmo painel.',
        'master' => 'Mais créditos e capacidade para equipas ou operações maiores.',
        default => 'Plano flexível para o seu dia a dia.',
    };
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
    extractor_redirect('index.php');
}

if (!extractor_config_exists()) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Configuração — Extrator</title>
  <link rel="stylesheet" href="<?= h(extractor_url('static/landing.css')) ?>" />
</head>
<body class="page-auth">
  <div class="auth-bg" aria-hidden="true"></div>
  <main class="auth-card-wrap">
    <div class="auth-card auth-wide">
      <h1>Quase pronto</h1>
      <p class="lead">O administrador do servidor precisa de concluir a configuração inicial (<code>config.local.php</code>). Depois disso esta página abre normalmente.</p>
    </div>
  </main>
</body>
</html>
    <?php
    exit;
}

try {
    extractor_config();
} catch (Throwable $e) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo h($e->getMessage());
    echo '</body></html>';
    exit;
}

if (extractor_logged_in()) {
    extractor_redirect('panel.php');
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';

$plans = [];
$userCount = 0;
try {
    $pdo = extractor_pdo();
    $plans = extractor_plans_list($pdo);
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable) {
    $plans = [];
}

header('Content-Type: text/html; charset=utf-8');
$css = extractor_url('static/landing.css');
$reg = extractor_url('register.php');
$login = extractor_url('login.php');
$home = extractor_url('index.php');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="description" content="Guarde sites, encontre ficheiros e descarregue tudo num painel simples, com créditos e suporte." />
  <title>Extrator — O seu painel de conteúdos</title>
  <link rel="stylesheet" href="<?= h($css) ?>" />
</head>
<body>
  <div class="landing-bg" aria-hidden="true"></div>
  <div class="landing-grid" aria-hidden="true"></div>

  <header class="nav-shell">
    <div class="nav-inner">
      <a class="brand" href="<?= h($home) ?>">Extrator</a>
      <nav class="nav-links" aria-label="Principal">
        <a class="btn btn-ghost" href="#como-funciona">Como funciona</a>
        <a class="btn btn-ghost" href="#planos">Planos</a>
        <a class="btn btn-ghost" href="<?= h($login) ?>">Entrar</a>
        <a class="btn btn-primary" href="<?= h($reg) ?>">Criar conta grátis</a>
      </nav>
    </div>
  </header>

  <section class="hero">
    <div>
      <p class="eyebrow">Painel online · Acesso seguro</p>
      <h1>Organize e <span class="gradient-text">descarregue</span> os seus conteúdos num só lugar</h1>
      <p class="lead">Guarde os sites que usa, encontre links de ficheiros e mantenha uma biblioteca pessoal — sem complicação. Tudo num painel claro, com créditos e suporte quando precisar.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="<?= h($reg) ?>">Começar agora</a>
        <a class="btn btn-ghost" href="<?= h($login) ?>">Já tenho conta</a>
      </div>
      <p class="trust-line"><?= $userCount < 1 ? 'Primeira conta neste servidor torna-se administrador principal.' : 'Escolha o plano que combina consigo no registo.' ?></p>
    </div>
    <div class="hero-visual">
      <div class="hero-visual-inner">
        <div class="pulse-ring" aria-hidden="true"></div>
        <h2 style="margin:0;font-size:1.15rem;">Tudo num painel</h2>
        <p style="margin:0.5rem 0 0;color:var(--muted);font-size:0.9rem;">Sites guardados · Lista de ficheiros · Descargas · Suporte</p>
      </div>
    </div>
  </section>

  <section class="section" id="como-funciona">
    <h2>Como funciona</h2>
    <p class="sub">Três passos simples — pensado para quem quer resultado, não manual técnico.</p>
    <div class="steps">
      <article class="step-card">
        <span class="step-num">1</span>
        <h3>Crie a sua conta</h3>
        <p>Registe-se com e-mail e escolha o plano. Entre no painel em segundos.</p>
      </article>
      <article class="step-card">
        <span class="step-num">2</span>
        <h3>Guarde os seus sites</h3>
        <p>Adicione as páginas de onde costuma obter conteúdos. O sistema ajuda a encontrar links úteis.</p>
      </article>
      <article class="step-card">
        <span class="step-num">3</span>
        <h3>Descarregue e organize</h3>
        <p>Os ficheiros ficam na sua biblioteca. Pode voltar a descarregar quando quiser, dentro dos seus créditos.</p>
      </article>
    </div>
  </section>

  <section class="section features-section">
    <h2>O que pode fazer</h2>
    <p class="sub">Ferramentas práticas para o dia a dia — sem precisar de instalar nada no computador.</p>
    <div class="features">
      <article class="feature-card">
        <h3>Sites favoritos</h3>
        <p>Guarde endereços e credenciais de forma segura no servidor.</p>
      </article>
      <article class="feature-card">
        <h3>Encontrar ficheiros</h3>
        <p>Peça ao painel para listar PDFs, vídeos, ZIPs e outros links numa página.</p>
      </article>
      <article class="feature-card">
        <h3>Biblioteca</h3>
        <p>Histórico do que já descarregou, com acesso rápido de novo.</p>
      </article>
      <article class="feature-card">
        <h3>PIX e planos</h3>
        <p>Recarregue créditos conforme o plano (quando o pagamento estiver activo).</p>
      </article>
    </div>
  </section>

  <section class="section" id="planos">
    <h2>Escolha o seu plano</h2>
    <p class="sub">Preços de referência — pode ajustar valores no seu negócio. Pagamento e créditos no painel após o registo.</p>
    <div class="plans">
      <?php foreach ($plans as $p):
          $code = (string) ($p['code'] ?? '');
          $planUrl = extractor_url('register.php') . '?plan=' . rawurlencode($code);
          ?>
        <article class="plan<?= $code === 'master' ? ' featured' : '' ?>">
          <h3><?= h((string) ($p['display_name'] ?? '')) ?></h3>
          <p class="plan-blurb"><?= h(extractor_plan_blurb($code)) ?></p>
          <div class="price"><?= h(extractor_money((float) ($p['price_monthly'] ?? 0))) ?><small>/mês</small></div>
          <ul>
            <li><?= (int) ($p['monthly_credits'] ?? 0) ?> créditos por mês</li>
            <li><?= (int) ($p['max_subusers'] ?? 0) > 0 ? 'Até ' . (int) $p['max_subusers'] . ' utilizadores na conta' : 'Uso individual' ?></li>
            <li><?= !empty($p['can_resell']) ? 'Pode revender acesso' : 'Uso directo' ?></li>
          </ul>
          <a class="btn btn-primary plan-cta" href="<?= h($planUrl) ?>">Quero este plano</a>
        </article>
      <?php endforeach; ?>

      <article class="plan plan-admin">
        <div class="plan-badge">ADMIN</div>
        <h3>Conta principal</h3>
        <p class="plan-blurb">Reservada à primeira instalação neste servidor — gestão completa.</p>
        <div class="price">Incluída</div>
        <ul>
          <li>Créditos ilimitados na app</li>
          <li>Vê todos os utilizadores e ficheiros</li>
          <li>Área de administração</li>
        </ul>
        <span class="btn btn-ghost plan-cta plan-cta-muted">Criada no 1.º registo</span>
      </article>
    </div>
  </section>

  <section class="section cta-band">
    <h2>Pronto para experimentar?</h2>
    <p class="sub">Crie a conta em menos de um minuto e explore o painel.</p>
    <div class="hero-cta" style="justify-content:center;">
      <a class="btn btn-primary" href="<?= h($reg) ?>">Criar conta</a>
      <a class="btn btn-ghost" href="<?= h($login) ?>">Entrar</a>
    </div>
  </section>

  <footer class="site-footer">
    <p>Use apenas conteúdos que lhe pertencem ou que tenha autorização para obter.</p>
    <p style="margin-top:0.5rem;"><a href="<?= h($login) ?>">Entrar</a> · <a href="<?= h($reg) ?>">Criar conta</a> · <a href="<?= h(extractor_url('health.php')) ?>">Diagnóstico</a></p>
    <p style="margin-top:0.35rem;font-size:0.72rem;opacity:0.65;">Versão <?= h(EXTRACTOR_BUILD_ID) ?></p>
  </footer>
</body>
</html>
