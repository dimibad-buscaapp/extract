<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

if (!extractor_config_exists()) {
    extractor_redirect('index.php');
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';

if (extractor_logged_in()) {
    extractor_redirect('panel.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        extractor_verify_csrf();
        $email = (string) ($_POST['email'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        $pdo = extractor_pdo();
        $err = extractor_login_user($pdo, $email, $pass);
        if ($err === '') {
            extractor_redirect('panel.php');
        }
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[Extrator login] ' . $e->getMessage());
        $err = 'Não foi possível entrar. Verifique os dados ou tente mais tarde.';
    }
}

$csrf = extractor_csrf_token();
header('Content-Type: text/html; charset=utf-8');
$css = extractor_url('static/landing.css');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Entrar — <?= h(extractor_site_name()) ?></title>
  <?= extractor_favicon_link_tags() ?>
  <link rel="stylesheet" href="<?= h($css) ?>" />
</head>
<body class="page-auth">
  <div class="auth-bg" aria-hidden="true"></div>
  <header class="auth-nav">
    <?= extractor_brand_html(['href' => extractor_url('index.php'), 'class' => 'brand']) ?>
    <a class="link-ghost" href="<?= h(extractor_url('register.php')) ?>">Criar conta</a>
  </header>
  <main class="auth-card-wrap">
    <div class="auth-card">
      <h1>Entrar</h1>
      <p class="lead">Use o e-mail e a senha que definiu no registo.</p>
      <?php if ($err !== ''): ?>
        <div class="alert alert-err" role="alert"><?= h($err) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= h(extractor_url('login.php')) ?>" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="<?= h((string) ($_POST['email'] ?? '')) ?>" />
        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required />
        <button type="submit" class="btn-primary">Continuar</button>
      </form>
      <p class="foot-note"><a href="<?= h(extractor_url('index.php')) ?>">Voltar ao início</a></p>
    </div>
  </main>
</body>
</html>
