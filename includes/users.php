<?php

declare(strict_types=1);

/**
 * Utilizadores, sessão e créditos (SQLite).
 */

/** @return array<string, mixed>|null */
function extractor_user_row(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, email, full_name, role, credits, status, plan_code FROM users WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

function extractor_user_refresh_session(PDO $pdo): void
{
    $id = extractor_user_id();
    if ($id < 1) {
        return;
    }
    $u = extractor_user_row($pdo, $id);
    if (!$u || ($u['status'] ?? '') !== 'active') {
        $_SESSION = [];
        return;
    }
    $_SESSION['user_email'] = (string) $u['email'];
    $_SESSION['user_name'] = (string) $u['full_name'];
    $_SESSION['user_role'] = (string) $u['role'];
    $_SESSION['user_credits'] = (int) $u['credits'];
}

function extractor_is_super_master(): bool
{
    return ($_SESSION['user_role'] ?? '') === 'super_master';
}

function extractor_user_can_unlimited(): bool
{
    return extractor_is_super_master();
}

/**
 * @return list<array<string, mixed>>
 */
function extractor_plans_list(PDO $pdo): array
{
    return $pdo->query(
        "SELECT code, display_name, role, monthly_credits, price_monthly, max_subusers, can_resell FROM plans WHERE code != 'super_master' ORDER BY monthly_credits ASC"
    )->fetchAll();
}

function extractor_verify_recaptcha_if_configured(): void
{
    $secret = trim((string) (extractor_config()['recaptcha_secret_key'] ?? ''));
    if ($secret === '') {
        return;
    }
    $token = (string) ($_POST['g-recaptcha-response'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Complete a verificação reCAPTCHA.');
    }
    $payload = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 15,
        ],
    ]);
    $raw = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('Falha ao validar reCAPTCHA.');
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['success'])) {
        throw new RuntimeException('reCAPTCHA inválido.');
    }
}

/**
 * @param list<string> $errors
 */
function extractor_register_user(PDO $pdo, array $input): array
{
    $errors = [];
    $name = trim((string) ($input['full_name'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $pass = (string) ($input['password'] ?? '');
    $pass2 = (string) ($input['password_confirm'] ?? '');
    $plan = trim((string) ($input['plan_code'] ?? 'user'));
    $terms = !empty($input['accept_terms']);
    $liab = !empty($input['accept_liability']);
    $honeypot = trim((string) ($input['website'] ?? ''));

    if ($honeypot !== '') {
        $errors[] = 'Falha na validação.';
        return $errors;
    }
    if ($name === '' || strlen($name) > 200) {
        $errors[] = 'Nome inválido.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-mail inválido.';
    }
    if (strlen($pass) < 10) {
        $errors[] = 'A senha deve ter pelo menos 10 caracteres.';
    }
    if ($pass !== $pass2) {
        $errors[] = 'As senhas não coincidem.';
    }
    if (!$terms) {
        $errors[] = 'Aceite os termos de utilização.';
    }
    if (!$liab) {
        $errors[] = 'Aceite a declaração de responsabilidade.';
    }
    if (!extractor_config()['allow_registration']) {
        $errors[] = 'Novos registos estão desativados.';
        return $errors;
    }

    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $planRow = null;
    if ($cnt > 0) {
        $stPlan = $pdo->prepare('SELECT code, role, monthly_credits FROM plans WHERE code = ?');
        $stPlan->execute([$plan]);
        $planRow = $stPlan->fetch();
        if (!$planRow || ($planRow['code'] ?? '') === 'super_master') {
            $errors[] = 'Plano inválido.';
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    $now = time();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    if ($cnt === 0) {
        $role = 'super_master';
        $credits = 999999999;
        $planCode = 'super_master';
    } else {
        $role = (string) $planRow['role'];
        $credits = (int) $planRow['monthly_credits'];
        $planCode = (string) $planRow['code'];
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($hash === false) {
        $errors[] = 'Erro ao gerar hash de senha.';
        return $errors;
    }

    try {
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, credits, parent_user_id, created_at, terms_accepted_at, liability_accepted_at, signup_ip, signup_ua, status, plan_code)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $email,
            $hash,
            $name,
            $role,
            $credits,
            null,
            $now,
            $now,
            $now,
            $ip,
            $ua,
            'active',
            $planCode,
        ]);
        $uid = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO credit_transactions (user_id, kind, delta, balance_after, description, created_at) VALUES (?,?,?,?,?,?)'
        )->execute([$uid, 'bonus', $credits, $credits, 'Créditos iniciais do plano', $now]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            $errors[] = 'Este e-mail já está registado.';
        } else {
            $errors[] = 'Erro ao criar conta.';
        }
    }

    return $errors;
}

function extractor_login_user(PDO $pdo, string $email, string $password): string
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return 'Preencha e-mail e senha.';
    }
    $st = $pdo->prepare('SELECT id, email, full_name, role, credits, password_hash, status FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, (string) $u['password_hash'])) {
        return 'E-mail ou senha incorretos.';
    }
    if (($u['status'] ?? '') !== 'active') {
        return 'Conta inativa ou suspensa.';
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    $_SESSION['user_email'] = (string) $u['email'];
    $_SESSION['user_name'] = (string) $u['full_name'];
    $_SESSION['user_role'] = (string) $u['role'];
    $_SESSION['user_credits'] = (int) $u['credits'];
    $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([time(), (int) $u['id']]);

    return '';
}

/**
 * Debita créditos; super_master não paga. Retorna true se autorizado (mesmo com custo 0).
 */
function extractor_credit_try_debit(PDO $pdo, int $userId, int $cost, string $description): bool
{
    if ($cost < 1) {
        return true;
    }
    $st = $pdo->prepare('SELECT role, credits FROM users WHERE id = ? AND status = ?');
    $st->execute([$userId, 'active']);
    $u = $st->fetch();
    if (!$u) {
        return false;
    }
    if (($u['role'] ?? '') === 'super_master') {
        return true;
    }
    $balance = (int) $u['credits'];
    if ($balance < $cost) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $st2 = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $st2->execute([$userId]);
        $row = $st2->fetch();
        if (!$row) {
            $pdo->rollBack();

            return false;
        }
        $b0 = (int) $row['credits'];
        if ($b0 < $cost) {
            $pdo->rollBack();

            return false;
        }
        $b1 = $b0 - $cost;
        $pdo->prepare('UPDATE users SET credits = ? WHERE id = ?')->execute([$b1, $userId]);
        $pdo->prepare(
            'INSERT INTO credit_transactions (user_id, kind, delta, balance_after, description, created_at) VALUES (?,?,?,?,?,?)'
        )->execute([$userId, 'use', -$cost, $b1, $description, time()]);
        $pdo->commit();
        if ($userId === extractor_user_id()) {
            $_SESSION['user_credits'] = $b1;
        }

        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function extractor_is_admin_panel_user(): bool
{
    $r = (string) ($_SESSION['user_role'] ?? '');

    return $r === 'super_master' || $r === 'master';
}

/**
 * Super gere qualquer conta (id > 0). Master só user/reseller com parent_user_id = actor.
 */
function extractor_admin_can_manage_target(string $actorRole, int $actorId, array $target): bool
{
    $tid = (int) ($target['id'] ?? 0);
    $trole = (string) ($target['role'] ?? '');
    if ($actorRole === 'super_master') {
        return $tid > 0;
    }
    if ($actorRole === 'master') {
        if ($tid <= 0 || !in_array($trole, ['user', 'reseller'], true)) {
            return false;
        }

        return (int) ($target['parent_user_id'] ?? 0) === $actorId;
    }

    return false;
}

/** Master vê tickets do próprio ID ou de contas com parent_user_id = master. Super vê todos. */
function extractor_ticket_admin_can_view(PDO $pdo, string $actorRole, int $actorId, int $ticketOwnerUserId): bool
{
    if ($actorRole === 'super_master') {
        return true;
    }
    if ($actorRole === 'master') {
        if ($ticketOwnerUserId === $actorId) {
            return true;
        }
        $st = $pdo->prepare('SELECT id FROM users WHERE id = ? AND parent_user_id = ?');
        $st->execute([$ticketOwnerUserId, $actorId]);

        return (bool) $st->fetch();
    }

    return false;
}

function extractor_audit_log(PDO $pdo, int $actorUserId, string $action, string $detail = ''): void
{
    $pdo->prepare('INSERT INTO audit_log (actor_user_id, action, detail, ip, created_at) VALUES (?,?,?,?,?)')->execute([
        $actorUserId,
        $action,
        $detail !== '' ? $detail : null,
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        time(),
    ]);
}

/** Créditos oferecidos pelo painel admin; actualiza sessão se o destinatário for o utilizador actual. */
function extractor_credit_grant(PDO $pdo, int $userId, int $amount, string $description, bool $inOuterTransaction = false): void
{
    if ($amount < 1) {
        return;
    }
    $apply = static function () use ($pdo, $userId, $amount, $description): int {
        $st = $pdo->prepare('SELECT credits FROM users WHERE id = ?');
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('Utilizador não encontrado.');
        }
        $b1 = (int) $row['credits'] + $amount;
        $pdo->prepare('UPDATE users SET credits = ? WHERE id = ?')->execute([$b1, $userId]);
        $pdo->prepare(
            'INSERT INTO credit_transactions (user_id, kind, delta, balance_after, description, created_at) VALUES (?,?,?,?,?,?)'
        )->execute([$userId, 'bonus', $amount, $b1, $description, time()]);

        return $b1;
    };
    if ($inOuterTransaction) {
        $b1 = $apply();
        if ($userId === extractor_user_id()) {
            $_SESSION['user_credits'] = $b1;
        }

        return;
    }
    $pdo->beginTransaction();
    try {
        $b1 = $apply();
        $pdo->commit();
        if ($userId === extractor_user_id()) {
            $_SESSION['user_credits'] = $b1;
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Se a base estiver vazia e existirem seed_super_master_* em config, cria o primeiro Super Master (ambiente de teste).
 */
function extractor_seed_super_master_if_configured(PDO $pdo, array $cfg): void
{
    $email = strtolower(trim((string) ($cfg['seed_super_master_email'] ?? '')));
    $pass = (string) ($cfg['seed_super_master_password'] ?? '');
    $name = trim((string) ($cfg['seed_super_master_name'] ?? 'Super Master'));
    if ($email === '' || $pass === '' || strlen($pass) < 10) {
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    try {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($n > 0) {
            return;
        }
    } catch (Throwable) {
        return;
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    if ($hash === false) {
        return;
    }
    $now = time();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $credits = 999999999;
    try {
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, full_name, role, credits, parent_user_id, created_at, terms_accepted_at, liability_accepted_at, signup_ip, signup_ua, status, plan_code)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $email,
            $hash,
            $name !== '' ? $name : 'Super Master',
            'super_master',
            $credits,
            null,
            $now,
            $now,
            $now,
            $ip,
            $ua,
            'active',
            'super_master',
        ]);
        $uid = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO credit_transactions (user_id, kind, delta, balance_after, description, created_at) VALUES (?,?,?,?,?,?)'
        )->execute([$uid, 'bonus', $credits, $credits, 'Conta seed (config) — testes', $now]);
    } catch (PDOException) {
        // já existe ou corrida
    }
}
