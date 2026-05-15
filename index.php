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
    $allPlans = extractor_plans_list($pdo);
    $plans = extractor_visible_plans_for_landing($allPlans);
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable) {
    $plans = [];
}

$b = extractor_branding();
$ix = (array) ($b['index'] ?? []);
$featured = (string) ($b['featured_plan_code'] ?? 'master');
$showAdminCard = !empty($b['show_admin_plan_card']);
$steps = (array) ($ix['steps'] ?? []);
$features = (array) ($ix['features'] ?? []);

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
  <meta name="description" content="<?= h((string) ($b['meta_description'] ?? '')) ?>" />
  <title><?= h((string) ($ix['page_title'] ?? extractor_site_name())) ?></title>
  <?= extractor_favicon_link_tags() ?>
  <link rel="stylesheet" href="<?= h($css) ?>" />
</head>
<body>
  <div class="landing-bg" aria-hidden="true"></div>
  <div class="landing-grid" aria-hidden="true"></div>

  <header class="nav-shell">
    <div class="nav-inner">
      <?= extractor_brand_html(['href' => $home, 'class' => 'brand']) ?>
      <nav class="nav-links" aria-label="Principal">
        <a class="btn btn-ghost" href="#como-funciona"><?= h((string) ($ix['nav_how'] ?? 'Como funciona')) ?></a>
        <a class="btn btn-ghost" href="#planos"><?= h((string) ($ix['nav_plans'] ?? 'Planos')) ?></a>
        <a class="btn btn-ghost" href="<?= h($login) ?>"><?= h((string) ($ix['nav_login'] ?? 'Entrar')) ?></a>
        <a class="btn btn-primary" href="<?= h($reg) ?>"><?= h((string) ($ix['nav_register'] ?? 'Criar conta')) ?></a>
      </nav>
    </div>
  </header>

  <section class="hero">
    <div>
      <p class="eyebrow"><?= h((string) ($ix['hero_eyebrow'] ?? '')) ?></p>
      <h1><?= h((string) ($ix['hero_title_before'] ?? '')) ?><span class="gradient-text"><?= h((string) ($ix['hero_title_highlight'] ?? '')) ?></span><?= h((string) ($ix['hero_title_after'] ?? '')) ?></h1>
      <p class="lead"><?= h((string) ($ix['hero_lead'] ?? '')) ?></p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="<?= h($reg) ?>"><?= h((string) ($ix['hero_cta_primary'] ?? 'Começar agora')) ?></a>
        <a class="btn btn-ghost" href="<?= h($login) ?>"><?= h((string) ($ix['hero_cta_secondary'] ?? 'Já tenho conta')) ?></a>
      </div>
      <p class="trust-line"><?= h($userCount < 1 ? (string) ($ix['hero_trust_empty'] ?? '') : (string) ($ix['hero_trust_users'] ?? '')) ?></p>
    </div>
    <div class="hero-visual">
      <div class="hero-visual-inner">
        <div class="pulse-ring" aria-hidden="true"></div>
        <h2 style="margin:0;font-size:1.15rem;"><?= h((string) ($ix['hero_visual_title'] ?? '')) ?></h2>
        <p style="margin:0.5rem 0 0;color:var(--muted);font-size:0.9rem;"><?= h((string) ($ix['hero_visual_text'] ?? '')) ?></p>
      </div>
    </div>
  </section>

  <section class="section" id="como-funciona">
    <h2><?= h((string) ($ix['how_title'] ?? 'Como funciona')) ?></h2>
    <p class="sub"><?= h((string) ($ix['how_sub'] ?? '')) ?></p>
    <div class="steps">
      <?php foreach ($steps as $i => $step):
          if (!is_array($step)) {
              continue;
          }
          ?>
      <article class="step-card">
        <span class="step-num"><?= (int) $i + 1 ?></span>
        <h3><?= h((string) ($step['title'] ?? '')) ?></h3>
        <p><?= h((string) ($step['text'] ?? '')) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="section features-section">
    <h2><?= h((string) ($ix['features_title'] ?? '')) ?></h2>
    <p class="sub"><?= h((string) ($ix['features_sub'] ?? '')) ?></p>
    <div class="features">
      <?php foreach ($features as $feat):
          if (!is_array($feat)) {
              continue;
          }
          ?>
      <article class="feature-card">
        <h3><?= h((string) ($feat['title'] ?? '')) ?></h3>
        <p><?= h((string) ($feat['text'] ?? '')) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="section" id="planos">
    <h2><?= h((string) ($ix['plans_title'] ?? '')) ?></h2>
    <p class="sub"><?= h((string) ($ix['plans_sub'] ?? '')) ?></p>
    <div class="plans">
      <?php foreach ($plans as $p):
          $code = (string) ($p['code'] ?? '');
          $planUrl = extractor_url('register.php') . '?plan=' . rawurlencode($code);
          ?>
        <article class="plan<?= $code === $featured ? ' featured' : '' ?>">
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

      <?php if ($showAdminCard): ?>
      <article class="plan plan-admin">
        <div class="plan-badge">ADMIN</div>
        <h3><?= h((string) ($ix['admin_plan_title'] ?? 'Conta principal')) ?></h3>
        <p class="plan-blurb"><?= h((string) ($ix['admin_plan_blurb'] ?? '')) ?></p>
        <div class="price"><?= h((string) ($ix['admin_plan_price'] ?? 'Incluída')) ?></div>
        <ul>
          <li>Créditos ilimitados na app</li>
          <li>Vê todos os utilizadores e ficheiros</li>
          <li>Área de administração</li>
        </ul>
        <span class="btn btn-ghost plan-cta plan-cta-muted"><?= h((string) ($ix['admin_plan_cta'] ?? 'Criada no 1.º registo')) ?></span>
      </article>
      <?php endif; ?>
    </div>
  </section>

  <section class="section cta-band">
    <h2><?= h((string) ($ix['cta_title'] ?? '')) ?></h2>
    <p class="sub"><?= h((string) ($ix['cta_sub'] ?? '')) ?></p>
    <div class="hero-cta" style="justify-content:center;">
      <a class="btn btn-primary" href="<?= h($reg) ?>"><?= h((string) ($ix['hero_cta_primary'] ?? 'Criar conta')) ?></a>
      <a class="btn btn-ghost" href="<?= h($login) ?>"><?= h((string) ($ix['hero_cta_secondary'] ?? 'Entrar')) ?></a>
    </div>
  </section>

  <footer class="site-footer">
    <p><?= h((string) ($ix['footer_legal'] ?? '')) ?></p>
    <p style="margin-top:0.5rem;"><a href="<?= h($login) ?>">Entrar</a> · <a href="<?= h($reg) ?>">Criar conta</a> · <a href="<?= h(extractor_url('health.php')) ?>">Diagnóstico</a></p>
    <p style="margin-top:0.35rem;font-size:0.72rem;opacity:0.65;">Versão <?= h(EXTRACTOR_BUILD_ID) ?></p>
  </footer>
</body>
</html>
