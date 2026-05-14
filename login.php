<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

if (!extractor_config_exists()) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';

if (extractor_logged_in()) {
    header('Location: panel.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    extractor_verify_csrf();
    $email = (string) ($_POST['email'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');
    try {
        $pdo = extractor_pdo();
        $err = extractor_login_user($pdo, $email, $pass);
        if ($err === '') {
            header('Location: panel.php');
            exit;
        }
    } catch (Throwable $e) {
        $err = 'Erro ao iniciar sessão.';
    }
}

$csrf = extractor_csrf_token();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Entrar — Extrator</title>
  <link rel="stylesheet" href="static/landing.css" />
</head>
<body class="page-auth">
  <div class="auth-bg" aria-hidden="true"></div>
  <header class="auth-nav">
    <a class="brand" href="index.php">Extrator</a>
    <a class="link-ghost" href="register.php">Criar conta</a>
  </header>
  <main class="auth-card-wrap">
    <div class="auth-card">
      <h1>Entrar</h1>
      <p class="lead">Aceda ao painel com o seu e-mail e senha.</p>
      <?php if ($err !== ''): ?>
        <div class="alert alert-err" role="alert"><?= h($err) ?></div>
      <?php endif; ?>
      <form method="post" action="login.php" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" autocomplete="email" required value="<?= h((string) ($_POST['email'] ?? '')) ?>" />
        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required />
        <button type="submit" class="btn-primary">Continuar</button>
      </form>
      <p class="foot-note"><a href="index.php">Voltar ao início</a></p>
    </div>
  </main>
</body>
</html>
