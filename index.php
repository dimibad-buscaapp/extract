<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
    header('Location: index.php');
    exit;
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
  <link rel="stylesheet" href="static/landing.css" />
</head>
<body class="page-auth">
  <div class="auth-bg"></div>
  <main class="auth-card-wrap">
    <div class="auth-card auth-wide">
      <h1>Configuração necessária</h1>
      <p class="lead">Copie <code>config.example.php</code> para <code>config.local.php</code> e defina <code>app_secret</code> (mínimo 16 caracteres).</p>
      <pre style="overflow:auto;font-size:0.8rem;background:rgba(0,0,0,.35);padding:0.75rem;border-radius:8px;">php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"</pre>
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
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Erro</title></head><body style="font-family:sans-serif;padding:2rem;">';
    echo h($e->getMessage());
    echo '</body></html>';
    exit;
}

if (extractor_logged_in()) {
    header('Location: panel.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';

$plans = [];
try {
    $pdo = extractor_pdo();
    $plans = extractor_plans_list($pdo);
    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable) {
    $userCount = 0;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="description" content="Painel profissional de extração e gestão de conteúdos com créditos e níveis de acesso." />
  <title>Extrator — Extração e gestão</title>
  <link rel="stylesheet" href="static/landing.css" />
</head>
<body>
  <div class="landing-bg" aria-hidden="true"></div>
  <div class="landing-grid" aria-hidden="true"></div>

  <header class="nav-shell">
    <div class="nav-inner">
      <a class="brand" href="index.php">Extrator</a>
      <nav class="nav-links" aria-label="Principal">
        <a class="btn btn-ghost" href="#planos">Planos</a>
        <a class="btn btn-ghost" href="login.php">Entrar</a>
        <a class="btn btn-primary" href="register.php">Criar conta</a>
      </nav>
    </div>
  </header>

  <section class="hero">
    <div>
      <h1><span class="gradient-text">Extração estruturada</span> com créditos, níveis e painel seguro</h1>
      <p class="lead">Automatize fluxos de descoberta e download respeitando limites da hospedagem. Cada conta tem o seu espaço e consumo de créditos configurável.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="register.php">Começar agora</a>
        <a class="btn btn-ghost" href="login.php">Já tenho conta</a>
      </div>
      <div class="stat-row" aria-label="Indicadores ilustrativos">
        <div class="stat"><b>API</b><span>JSON + sessão</span></div>
        <div class="stat"><b>SQLite</b><span>Multi-utilizador</span></div>
        <div class="stat"><b>Créditos</b><span>Por download</span></div>
      </div>
      <p class="lead" style="margin-top:1.25rem;font-size:0.82rem;"><?= $userCount < 1 ? 'O primeiro registo cria o perfil <strong>Super Master</strong> desta instalação.' : 'Novos utilizadores escolhem um plano no registo.' ?></p>
    </div>
    <div class="hero-visual">
      <div class="hero-visual-inner">
        <div class="pulse-ring" aria-hidden="true"></div>
        <h2 style="margin:0;font-size:1.1rem;">Fluxo em camadas</h2>
        <p style="margin:0.5rem 0 0;color:var(--muted);font-size:0.88rem;">Sites guardados, descoberta de links e biblioteca com permissões por conta.</p>
      </div>
    </div>
  </section>

  <section class="section" id="planos">
    <h2>Planos e hierarquia</h2>
    <p class="sub">Valores exibidos são referência para o seu negócio — ajuste preços e créditos na base de dados (<code>plans</code>). O nível <strong>Super Master</strong> não está disponível no registo público.</p>
    <div class="plans">
      <?php foreach ($plans as $p): ?>
        <article class="plan<?= ($p['code'] ?? '') === 'master' ? ' featured' : '' ?>">
          <h3><?= h((string) ($p['display_name'] ?? '')) ?></h3>
          <div class="role"><?= h((string) ($p['role'] ?? '')) ?></div>
          <div class="price"><?= number_format((float) ($p['price_monthly'] ?? 0), 2, ',', ' ') ?> €<small>/mês</small></div>
          <ul>
            <li><?= (int) ($p['monthly_credits'] ?? 0) ?> créditos / mês (referência)</li>
            <li><?= (int) ($p['max_subusers'] ?? 0) ?> sub-utilizadores máx. (referência)</li>
            <li><?= !empty($p['can_resell']) ? 'Revenda permitida (referência)' : 'Uso direto' ?></li>
          </ul>
          <a class="btn btn-primary" style="width:100%;margin-top:1rem;text-decoration:none;" href="register.php?plan=<?= h((string) ($p['code'] ?? '')) ?>">Escolher este plano</a>
        </article>
      <?php endforeach; ?>

      <article class="plan featured">
        <div class="plan-badge">TOPO</div>
        <h3>Super Master</h3>
        <div class="role">super_master</div>
        <div class="price">Sob consulta</div>
        <ul>
          <li>Créditos ilimitados na aplicação</li>
          <li>Visibilidade global de sites e ficheiros</li>
          <li>Defina políticas e integrações à medida</li>
        </ul>
        <span class="btn btn-ghost" style="width:100%;margin-top:1rem;cursor:default;opacity:0.85;">Atribuído ao 1.º registo</span>
      </article>
    </div>
  </section>

  <footer class="site-footer">
    <p>Uso legítimo apenas. Textos legais definitivos devem ser revistos por um advogado.</p>
    <p style="margin-top:0.5rem;"><a href="login.php">Entrar</a> · <a href="register.php">Registo</a></p>
  </footer>
</body>
</html>
