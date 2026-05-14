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

$pdo = extractor_pdo();
$plans = extractor_plans_list($pdo);
$pref = preg_replace('/[^a-z0-9_]/i', '', (string) ($_GET['plan'] ?? ''));
$errors = [];
$recSite = trim((string) (extractor_config()['recaptcha_site_key'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    extractor_verify_csrf();
    try {
        extractor_verify_recaptcha_if_configured();
        $errors = extractor_register_user($pdo, $_POST);
        if ($errors === []) {
            extractor_login_user($pdo, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            header('Location: panel.php');
            exit;
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable) {
        $errors[] = 'Erro ao processar o registo.';
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
  <title>Criar conta — Extrator</title>
  <link rel="stylesheet" href="static/landing.css" />
  <?php if ($recSite !== ''): ?>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php endif; ?>
</head>
<body class="page-auth">
  <div class="auth-bg" aria-hidden="true"></div>
  <header class="auth-nav">
    <a class="brand" href="index.php">Extrator</a>
    <a class="link-ghost" href="login.php">Entrar</a>
  </header>
  <main class="auth-card-wrap auth-wide">
    <div class="auth-card">
      <h1>Criar conta</h1>
      <p class="lead">O primeiro registo torna-se automaticamente <strong>Super Master</strong> (instalação). Os seguintes escolhem um plano.</p>
      <?php if ($errors !== []): ?>
        <div class="alert alert-err" role="alert">
          <ul class="err-list">
            <?php foreach ($errors as $e): ?>
              <li><?= h($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <form method="post" action="register.php" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="hp" aria-hidden="true" />
        <label for="full_name">Nome completo</label>
        <input id="full_name" name="full_name" required maxlength="200" value="<?= h((string) ($_POST['full_name'] ?? '')) ?>" />
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required value="<?= h((string) ($_POST['email'] ?? '')) ?>" />
        <label for="password">Senha (mín. 10 caracteres)</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" />
        <label for="password_confirm">Confirmar senha</label>
        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required minlength="10" />
        <label for="plan_code">Plano</label>
        <select id="plan_code" name="plan_code" required>
          <?php
            $codes = array_column($plans, 'code');
            $defaultCode = in_array($pref, $codes, true) ? $pref : ($plans[0]['code'] ?? 'user');
            foreach ($plans as $p):
                $c = (string) ($p['code'] ?? '');
                $sel = $c === $defaultCode ? ' selected' : '';
            ?>
            <option value="<?= h($c) ?>"<?= $sel ?>>
              <?= h((string) $p['display_name']) ?> — <?= number_format((float) $p['price_monthly'], 2, ',', ' ') ?> €/mês (<?= (int) $p['monthly_credits'] ?> créditos)
            </option>
          <?php endforeach; ?>
        </select>
        <div class="legal-box">
          <label class="check">
            <input type="checkbox" name="accept_terms" value="1" required />
            <span>Li e aceito os <a href="#termos" class="inline-link">Termos de utilização</a>. Utilizarei a ferramenta apenas para conteúdos que possuo ou tenho autorização legal para obter.</span>
          </label>
          <label class="check">
            <input type="checkbox" name="accept_liability" value="1" required />
            <span>Assumo a responsabilidade pelo uso que faço do serviço e declaro que não o utilizarei para violar leis, direitos de terceiros ou termos de plataformas.</span>
          </label>
        </div>
        <?php if ($recSite !== ''): ?>
          <div class="recaptcha-wrap">
            <div class="g-recaptcha" data-sitekey="<?= h($recSite) ?>"></div>
          </div>
        <?php endif; ?>
        <button type="submit" class="btn-primary">Criar conta</button>
      </form>
      <div id="termos" class="terms-scroll">
        <h2>Termos (resumo)</h2>
        <p>Esta aplicação destina-se a uso legítimo. O operador do servidor pode registar endereço IP e agente do navegador no registo. Os créditos são consumidos conforme a configuração do sistema. Consulte um advogado para textos legais definitivos.</p>
      </div>
      <p class="foot-note"><a href="index.php">Voltar ao início</a></p>
    </div>
  </main>
</body>
</html>
