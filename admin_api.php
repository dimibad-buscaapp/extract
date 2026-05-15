<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/payment_settings.php';

header('Content-Type: application/json; charset=utf-8');

if (!extractor_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'nao_autenticado']);
    exit;
}

if (!extractor_is_admin_panel_user()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'sem_permissao']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
}

$csrf = (string) ($input['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$action = (string) ($input['action'] ?? '');

/**
 * @return array<string, mixed>|null
 */
function admin_user_by_id(PDO $pdo, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, email, full_name, role, credits, status, plan_code, parent_user_id, created_at, last_login_at FROM users WHERE id = ?'
    );
    $st->execute([$id]);
    $r = $st->fetch();

    return $r ?: null;
}

/**
 * @return list<int>|null null = super (sem filtro por utilizador)
 */
function admin_report_scope_user_ids(PDO $pdo, string $actorRole, int $actorId): ?array
{
    if ($actorRole === 'super_master') {
        return null;
    }
    $st = $pdo->prepare('SELECT id FROM users WHERE id = ? OR parent_user_id = ?');
    $st->execute([$actorId, $actorId]);

    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

try {
    $pdo = extractor_pdo();
    extractor_user_refresh_session($pdo);
    if (!extractor_logged_in()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'nao_autenticado']);
        exit;
    }

    $actorId = extractor_user_id();
    $actorRole = (string) ($_SESSION['user_role'] ?? '');
    $isSuper = $actorRole === 'super_master';
    $cfg = extractor_config();

    if ($action === 'admin_stats') {
        $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $byRole = $pdo->query('SELECT role, COUNT(*) AS c FROM users GROUP BY role ORDER BY role')->fetchAll();
        $sumCredits = (int) $pdo->query('SELECT COALESCE(SUM(credits), 0) FROM users')->fetchColumn();
        $totalTx = (int) $pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn();
        $totalSites = (int) $pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn();
        $totalFiles = (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
        echo json_encode([
            'ok' => true,
            'stats' => [
                'total_users' => $totalUsers,
                'users_by_role' => $byRole,
                'sum_credits' => $sumCredits,
                'total_credit_transactions' => $totalTx,
                'total_sites' => $totalSites,
                'total_files' => $totalFiles,
            ],
            'role' => $actorRole,
        ]);
        exit;
    }

    if ($action === 'admin_users_list') {
        if ($isSuper) {
            $rows = $pdo->query(
                'SELECT id, email, full_name, role, credits, status, plan_code, parent_user_id, created_at, last_login_at FROM users WHERE id > 0 ORDER BY id ASC'
            )->fetchAll();
        } else {
            $st = $pdo->prepare(
                'SELECT id, email, full_name, role, credits, status, plan_code, parent_user_id, created_at, last_login_at FROM users WHERE role IN (\'user\',\'reseller\') AND parent_user_id = ? ORDER BY id ASC'
            );
            $st->execute([$actorId]);
            $rows = $st->fetchAll();
        }
        echo json_encode(['ok' => true, 'users' => $rows, 'role' => $actorRole]);
        exit;
    }

    if ($action === 'admin_user_set_status') {
        $id = (int) ($input['id'] ?? 0);
        $status = (string) ($input['status'] ?? '');
        if ($id < 1 || !in_array($status, ['active', 'suspended'], true)) {
            throw new RuntimeException('Dados inválidos.');
        }
        $target = admin_user_by_id($pdo, $id);
        if (!$target) {
            throw new RuntimeException('Utilizador não encontrado.');
        }
        if (!extractor_admin_can_manage_target($actorRole, $actorId, $target)) {
            throw new RuntimeException('Sem permissão para esta conta.');
        }
        if ($id === $actorId && $status === 'suspended') {
            throw new RuntimeException('Não pode suspender a própria sessão.');
        }
        $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $id]);
        extractor_audit_log($pdo, $actorId, 'user_set_status', 'user=' . $id . ';status=' . $status);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'admin_user_grant_credits') {
        $id = (int) ($input['id'] ?? 0);
        $amount = (int) ($input['amount'] ?? 0);
        $desc = trim((string) ($input['description'] ?? 'Bónus (painel admin)'));
        if ($id < 1 || $amount < 1) {
            throw new RuntimeException('ID ou quantidade inválidos.');
        }
        if ($desc === '') {
            $desc = 'Bónus (painel admin)';
        }
        $target = admin_user_by_id($pdo, $id);
        if (!$target) {
            throw new RuntimeException('Utilizador não encontrado.');
        }
        if (!extractor_admin_can_manage_target($actorRole, $actorId, $target)) {
            throw new RuntimeException('Sem permissão para esta conta.');
        }
        extractor_credit_grant($pdo, $id, $amount, $desc);
        extractor_audit_log($pdo, $actorId, 'grant_credits', 'user=' . $id . ';amount=' . $amount);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'admin_user_delete') {
        if (!$isSuper) {
            throw new RuntimeException('Apenas Super Master pode apagar utilizadores.');
        }
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('ID inválido.');
        }
        if ($id === $actorId) {
            throw new RuntimeException('Não pode apagar a própria conta.');
        }
        $target = admin_user_by_id($pdo, $id);
        if (!$target) {
            throw new RuntimeException('Utilizador não encontrado.');
        }
        if (($target['role'] ?? '') === 'super_master') {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = \'super_master\' AND status = \'active\'')->fetchColumn();
            if ($n < 2) {
                throw new RuntimeException('Não pode remover o último Super Master activo.');
            }
        }
        $stc = $pdo->prepare('SELECT COUNT(*) FROM users WHERE parent_user_id = ?');
        $stc->execute([$id]);
        if ((int) $stc->fetchColumn() > 0) {
            throw new RuntimeException('Existem contas filhas; reatribua ou apague-as primeiro.');
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM support_ticket_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE user_id = ?)')->execute([$id]);
            $pdo->prepare('DELETE FROM support_tickets WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM payments WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM files WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM sites WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM credit_transactions WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        extractor_audit_log($pdo, $actorId, 'user_delete', 'user=' . $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'admin_user_create') {
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $roleIn = trim((string) ($input['role'] ?? 'user'));
        $credits = max(0, (int) ($input['credits'] ?? 0));
        $parentIn = isset($input['parent_user_id']) ? (int) $input['parent_user_id'] : null;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('E-mail inválido.');
        }
        if ($fullName === '' || strlen($fullName) > 200) {
            throw new RuntimeException('Nome inválido.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('A senha deve ter pelo menos 10 caracteres.');
        }

        $role = 'user';
        $parentId = null;
        if ($actorRole === 'master') {
            if (!in_array($roleIn, ['user', 'reseller'], true)) {
                throw new RuntimeException('Papel inválido para Master.');
            }
            $role = $roleIn;
            $parentId = $actorId;
        } elseif ($isSuper) {
            if (!in_array($roleIn, ['user', 'reseller', 'master'], true)) {
                throw new RuntimeException('Papel inválido.');
            }
            $role = $roleIn;
            if ($parentIn !== null && $parentIn > 0) {
                $p = admin_user_by_id($pdo, $parentIn);
                if (!$p) {
                    throw new RuntimeException('Conta pai inválida.');
                }
                $parentId = $parentIn;
            }
        } else {
            throw new RuntimeException('Sem permissão.');
        }

        $planCode = $role;
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new RuntimeException('Erro ao gerar hash de senha.');
        }
        $now = time();
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        try {
            $pdo->prepare(
                'INSERT INTO users (email, password_hash, full_name, role, credits, parent_user_id, created_at, terms_accepted_at, liability_accepted_at, signup_ip, signup_ua, status, plan_code)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $email,
                $hash,
                $fullName,
                $role,
                $credits,
                $parentId,
                $now,
                $now,
                $now,
                $ip,
                $ua,
                'active',
                $planCode,
            ]);
            $newId = (int) $pdo->lastInsertId();
            if ($credits > 0) {
                $pdo->prepare(
                    'INSERT INTO credit_transactions (user_id, kind, delta, balance_after, description, created_at) VALUES (?,?,?,?,?,?)'
                )->execute([$newId, 'bonus', $credits, $credits, 'Créditos iniciais (painel admin)', $now]);
            }
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new RuntimeException('Este e-mail já está registado.');
            }
            throw new RuntimeException('Erro ao criar utilizador.');
        }
        extractor_audit_log($pdo, $actorId, 'user_create', 'id=' . $newId . ';email=' . $email . ';role=' . $role);
        echo json_encode(['ok' => true, 'id' => $newId]);
        exit;
    }

    if ($action === 'admin_transactions_list') {
        $limit = min(500, max(1, (int) ($input['limit'] ?? 100)));
        if ($isSuper) {
            $st = $pdo->prepare(
                'SELECT t.id, t.user_id, t.kind, t.delta, t.balance_after, t.description, t.created_at, u.email AS user_email
                 FROM credit_transactions t LEFT JOIN users u ON u.id = t.user_id
                 ORDER BY t.id DESC LIMIT ' . (int) $limit
            );
            $st->execute();
            $rows = $st->fetchAll();
        } else {
            $st = $pdo->prepare(
                'SELECT t.id, t.user_id, t.kind, t.delta, t.balance_after, t.description, t.created_at, u.email AS user_email
                 FROM credit_transactions t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE u.role IN (\'user\',\'reseller\') AND u.parent_user_id = ?
                 ORDER BY t.id DESC LIMIT ' . (int) $limit
            );
            $st->execute([$actorId]);
            $rows = $st->fetchAll();
        }
        echo json_encode(['ok' => true, 'transactions' => $rows]);
        exit;
    }

    if ($action === 'admin_plans_list') {
        if (!$isSuper) {
            throw new RuntimeException('Apenas Super Master pode consultar planos aqui.');
        }
        $plans = $pdo->query(
            'SELECT code, display_name, role, monthly_credits, price_monthly, max_subusers, can_resell FROM plans ORDER BY monthly_credits ASC'
        )->fetchAll();
        echo json_encode(['ok' => true, 'plans' => $plans]);
        exit;
    }

    if ($action === 'admin_plan_save') {
        if (!$isSuper) {
            throw new RuntimeException('Apenas Super Master.');
        }
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        $isNew = !empty($input['is_new']);
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $code)) {
            throw new RuntimeException('Código inválido (a-z, 0-9, _, mín. 2 caracteres).');
        }
        if ($code === 'super_master') {
            throw new RuntimeException('O plano super_master não pode ser criado manualmente.');
        }
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $role = trim((string) ($input['role'] ?? 'user'));
        $monthlyCredits = max(0, (int) ($input['monthly_credits'] ?? 0));
        $priceMonthly = round((float) ($input['price_monthly'] ?? 0), 2);
        $maxSubusers = max(0, (int) ($input['max_subusers'] ?? 0));
        $canResell = !empty($input['can_resell']) ? 1 : 0;
        if ($displayName === '' || strlen($displayName) > 120) {
            throw new RuntimeException('Nome de exibição inválido.');
        }
        if (!in_array($role, ['user', 'reseller', 'master', 'super_master'], true)) {
            throw new RuntimeException('Papel inválido.');
        }
        $exists = $pdo->prepare('SELECT 1 FROM plans WHERE code = ?');
        $exists->execute([$code]);
        $has = (bool) $exists->fetchColumn();
        if ($isNew && $has) {
            throw new RuntimeException('Já existe um plano com este código.');
        }
        if (!$isNew && !$has) {
            throw new RuntimeException('Plano não encontrado.');
        }
        if ($isNew) {
            $pdo->prepare(
                'INSERT INTO plans (code, display_name, role, monthly_credits, price_monthly, max_subusers, can_resell) VALUES (?,?,?,?,?,?,?)'
            )->execute([$code, $displayName, $role, $monthlyCredits, $priceMonthly, $maxSubusers, $canResell]);
            extractor_audit_log($pdo, $actorId, 'plan_create', 'code=' . $code);
        } else {
            $pdo->prepare(
                'UPDATE plans SET display_name=?, role=?, monthly_credits=?, price_monthly=?, max_subusers=?, can_resell=? WHERE code=?'
            )->execute([$displayName, $role, $monthlyCredits, $priceMonthly, $maxSubusers, $canResell, $code]);
            extractor_audit_log($pdo, $actorId, 'plan_save', 'code=' . $code);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'admin_plan_delete') {
        if (!$isSuper) {
            throw new RuntimeException('Apenas Super Master.');
        }
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        if ($code === '' || $code === 'super_master') {
            throw new RuntimeException('Não pode apagar este plano.');
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE plan_code = ?');
        $st->execute([$code]);
        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException('Existem utilizadores neste plano; migre-os antes de apagar.');
        }
        $pdo->prepare('DELETE FROM plans WHERE code = ?')->execute([$code]);
        extractor_audit_log($pdo, $actorId, 'plan_delete', 'code=' . $code);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'admin_payment_settings_get') {
        if (!$isSuper) {
            throw new RuntimeException('Apenas Super Master.');
        }
        $pcfg = extractor_payment_config($cfg);
        $mpTok = trim((string) ($pcfg['mercadopago_access_token'] ?? ''));
        $asaasKey = trim((string) ($pcfg['asaas_api_key'] ?? ''));
        $mask = static function (string $s): string {
            if ($s === '') {
                return '';
            }
            if (strlen($s) <= 8) {
                return '••••••••';
            }

            return substr($s, 0, 6) . '…' . substr($s, -4);
        };
        echo json_encode([
            'ok' => true,
            'settings' => [
                'payment_provider' => (string) ($pcfg['payment_provider'] ?? 'mercadopago'),
                'mercadopago_access_token_masked' => $mask($mpTok),
                'mercadopago_access_token_set' => $mpTok !== '',
                'mercadopago_public_key' => (string) ($pcfg['mercadopago_public_key'] ?? ''),
                'mercadopago_sandbox' => (bool) ($pcfg['mercadopago_sandbox'] ?? true),
                'mercadopago_webhook_secret' => (string) ($pcfg['mercadopago_webhook_secret'] ?? ''),
                'asaas_api_key_masked' => $mask($asaasKey),
                'asaas_api_key_set' => $asaasKey !== '',
                'asaas_sandbox' => (bool) ($pcfg['asaas_sandbox'] ?? true),
                'asaas_webhook_token' => (string) ($pcfg['asaas_webhook_token'] ?? ''),
                'configured' => extractor_payment_provider_configured($pcfg),
            ],
            'webhook_url' => extractor_absolute_url('billing_webhook.php'),
        ]);
        exit;
    }

    if ($action === 'admin_payment_settings_save') {
        if (!$isSuper) {
            throw new RuntimeException('Apenas Super Master.');
        }
        $provider = trim((string) ($input['payment_provider'] ?? 'mercadopago'));
        if (!in_array($provider, ['mercadopago', 'asaas', 'demo'], true)) {
            throw new RuntimeException('Provedor inválido.');
        }
        $patch = [
            'payment_provider' => $provider,
            'mercadopago_public_key' => trim((string) ($input['mercadopago_public_key'] ?? '')),
            'mercadopago_sandbox' => !empty($input['mercadopago_sandbox']),
            'mercadopago_webhook_secret' => trim((string) ($input['mercadopago_webhook_secret'] ?? '')),
            'asaas_sandbox' => !empty($input['asaas_sandbox']),
            'asaas_webhook_token' => trim((string) ($input['asaas_webhook_token'] ?? '')),
        ];
        $mpNew = trim((string) ($input['mercadopago_access_token'] ?? ''));
        if ($mpNew !== '') {
            $patch['mercadopago_access_token'] = $mpNew;
        }
        $asaasNew = trim((string) ($input['asaas_api_key'] ?? ''));
        if ($asaasNew !== '') {
            $patch['asaas_api_key'] = $asaasNew;
        }
        extractor_payment_settings_save($patch);
        extractor_audit_log($pdo, $actorId, 'payment_settings_save', 'provider=' . $provider);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'admin_audit_list') {
        if (!$isSuper) {
            throw new RuntimeException('Apenas Super Master pode ver auditoria.');
        }
        $limit = min(500, max(1, (int) ($input['limit'] ?? 150)));
        $st = $pdo->prepare(
            'SELECT a.id, a.actor_user_id, a.action, a.detail, a.ip, a.created_at, u.email AS actor_email
             FROM audit_log a LEFT JOIN users u ON u.id = a.actor_user_id
             ORDER BY a.id DESC LIMIT ' . (int) $limit
        );
        $st->execute();
        echo json_encode(['ok' => true, 'audit' => $st->fetchAll()]);
        exit;
    }

    if ($action === 'admin_config_snapshot') {
        if (!$isSuper) {
            throw new RuntimeException('Apenas Super Master.');
        }
        $secret = (string) $cfg['app_secret'];
        $masked = strlen($secret) > 8 ? (substr($secret, 0, 4) . '…' . substr($secret, -4)) : '(definido)';
        echo json_encode([
            'ok' => true,
            'config' => [
                'allow_registration' => (bool) $cfg['allow_registration'],
                'credits_per_download' => (int) $cfg['credits_per_download'],
                'credits_per_discover' => (int) $cfg['credits_per_discover'],
                'max_download_bytes' => (int) $cfg['max_download_bytes'],
                'http_timeout' => (int) $cfg['http_timeout'],
                'recaptcha_configured' => ($cfg['recaptcha_site_key'] ?? '') !== '' && ($cfg['recaptcha_secret_key'] ?? '') !== '',
                'app_secret_masked' => $masked,
                'payment_provider' => (string) (extractor_payment_config($cfg)['payment_provider'] ?? ''),
                'payment_configured' => extractor_payment_provider_configured(extractor_payment_config($cfg)),
                'asaas_configured' => trim((string) (extractor_payment_config($cfg)['asaas_api_key'] ?? '')) !== '',
                'mercadopago_configured' => trim((string) (extractor_payment_config($cfg)['mercadopago_access_token'] ?? '')) !== '',
            ],
        ]);
        exit;
    }

    if ($action === 'admin_reports') {
        $days = min(365, max(7, (int) ($input['days'] ?? 30)));
        $now = time();
        $start = $now - $days * 86400;
        $ids = admin_report_scope_user_ids($pdo, $actorRole, $actorId);

        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $labels[] = gmdate('Y-m-d', $now - $i * 86400);
        }
        $zeroSeries = static function (array $labels): array {
            $o = [];
            foreach ($labels as $d) {
                $o[$d] = 0;
            }

            return $o;
        };
        $signByDay = $zeroSeries($labels);
        $filesByDay = $zeroSeries($labels);
        $useByDay = $zeroSeries($labels);

        $sgRows = [];
        if ($ids === null) {
            $sg = $pdo->prepare(
                'SELECT strftime(\'%Y-%m-%d\', created_at, \'unixepoch\') AS d, COUNT(*) AS c FROM users WHERE created_at >= ? GROUP BY d'
            );
            $sg->execute([$start]);
            $sgRows = $sg->fetchAll();
        } elseif ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sg = $pdo->prepare(
                'SELECT strftime(\'%Y-%m-%d\', created_at, \'unixepoch\') AS d, COUNT(*) AS c FROM users WHERE created_at >= ? AND id IN (' . $ph . ') GROUP BY d'
            );
            $sg->execute(array_merge([$start], $ids));
            $sgRows = $sg->fetchAll();
        }
        foreach ($sgRows as $r) {
            $d = (string) ($r['d'] ?? '');
            if (isset($signByDay[$d])) {
                $signByDay[$d] = (int) ($r['c'] ?? 0);
            }
        }

        $fgRows = [];
        if ($ids === null) {
            $fg = $pdo->prepare(
                'SELECT strftime(\'%Y-%m-%d\', created_at, \'unixepoch\') AS d, COUNT(*) AS c FROM files WHERE created_at >= ? GROUP BY d'
            );
            $fg->execute([$start]);
            $fgRows = $fg->fetchAll();
        } elseif ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $fg = $pdo->prepare(
                'SELECT strftime(\'%Y-%m-%d\', created_at, \'unixepoch\') AS d, COUNT(*) AS c FROM files WHERE created_at >= ? AND user_id IN (' . $ph . ') GROUP BY d'
            );
            $fg->execute(array_merge([$start], $ids));
            $fgRows = $fg->fetchAll();
        }
        foreach ($fgRows as $r) {
            $d = (string) ($r['d'] ?? '');
            if (isset($filesByDay[$d])) {
                $filesByDay[$d] = (int) ($r['c'] ?? 0);
            }
        }

        $ugRows = [];
        if ($ids === null) {
            $ug = $pdo->prepare(
                'SELECT strftime(\'%Y-%m-%d\', t.created_at, \'unixepoch\') AS d, SUM(ABS(t.delta)) AS s
                 FROM credit_transactions t WHERE t.created_at >= ? AND t.kind = \'use\' GROUP BY d'
            );
            $ug->execute([$start]);
            $ugRows = $ug->fetchAll();
        } elseif ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $ug = $pdo->prepare(
                'SELECT strftime(\'%Y-%m-%d\', t.created_at, \'unixepoch\') AS d, SUM(ABS(t.delta)) AS s
                 FROM credit_transactions t WHERE t.created_at >= ? AND t.kind = \'use\' AND t.user_id IN (' . $ph . ') GROUP BY d'
            );
            $ug->execute(array_merge([$start], $ids));
            $ugRows = $ug->fetchAll();
        }
        foreach ($ugRows as $r) {
            $d = (string) ($r['d'] ?? '');
            if (isset($useByDay[$d])) {
                $useByDay[$d] = (int) ($r['s'] ?? 0);
            }
        }

        $creditsByRole = [];
        if ($ids === null) {
            $cr = $pdo->prepare(
                'SELECT u.role AS role, SUM(ABS(t.delta)) AS s FROM credit_transactions t
                 JOIN users u ON u.id = t.user_id WHERE t.created_at >= ? AND t.kind = \'use\' GROUP BY u.role'
            );
            $cr->execute([$start]);
            $creditsByRole = $cr->fetchAll();
        } elseif ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $cr = $pdo->prepare(
                'SELECT u.role AS role, SUM(ABS(t.delta)) AS s FROM credit_transactions t
                 JOIN users u ON u.id = t.user_id WHERE t.created_at >= ? AND t.kind = \'use\' AND t.user_id IN (' . $ph . ') GROUP BY u.role'
            );
            $cr->execute(array_merge([$start], $ids));
            $creditsByRole = $cr->fetchAll();
        }

        $topFiles = [];
        if ($ids === null) {
            $tf = $pdo->prepare(
                'SELECT f.user_id, u.full_name, u.email, COUNT(*) AS c FROM files f
                 LEFT JOIN users u ON u.id = f.user_id WHERE f.created_at >= ? GROUP BY f.user_id ORDER BY c DESC LIMIT 10'
            );
            $tf->execute([$start]);
            $topFiles = $tf->fetchAll();
        } elseif ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $tf = $pdo->prepare(
                'SELECT f.user_id, u.full_name, u.email, COUNT(*) AS c FROM files f
                 LEFT JOIN users u ON u.id = f.user_id WHERE f.created_at >= ? AND f.user_id IN (' . $ph . ') GROUP BY f.user_id ORDER BY c DESC LIMIT 10'
            );
            $tf->execute(array_merge([$start], $ids));
            $topFiles = $tf->fetchAll();
        }

        $revenueMonths = [];
        for ($i = 5; $i >= 0; $i--) {
            $ym = gmdate('Y-m', strtotime('-' . $i . ' months', $now));
            $label = gmdate('M/Y', strtotime($ym . '-01 UTC'));
            if ($ids === null) {
                $rs = $pdo->prepare(
                    'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = \'paid\' AND paid_at IS NOT NULL
                     AND strftime(\'%Y-%m\', paid_at, \'unixepoch\') = ?'
                );
                $rs->execute([$ym]);
            } elseif ($ids === []) {
                $rsVal = 0.0;
                $revenueMonths[] = ['month' => $label, 'ym' => $ym, 'total' => $rsVal];
                continue;
            } else {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $rs = $pdo->prepare(
                    'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = \'paid\' AND paid_at IS NOT NULL
                     AND strftime(\'%Y-%m\', paid_at, \'unixepoch\') = ? AND user_id IN (' . $ph . ')'
                );
                $rs->execute(array_merge([$ym], $ids));
            }
            $revenueMonths[] = ['month' => $label, 'ym' => $ym, 'total' => (float) $rs->fetchColumn()];
        }

        $dailyOut = [];
        foreach ($labels as $d) {
            $dailyOut[] = [
                'date' => $d,
                'signups' => $signByDay[$d] ?? 0,
                'files' => $filesByDay[$d] ?? 0,
                'credits_used' => $useByDay[$d] ?? 0,
            ];
        }

        echo json_encode([
            'ok' => true,
            'days' => $days,
            'daily' => $dailyOut,
            'credits_by_role' => $creditsByRole,
            'top_files' => $topFiles,
            'revenue_months' => $revenueMonths,
            'totals' => [
                'signups' => array_sum($signByDay),
                'files' => array_sum($filesByDay),
                'credits_used' => array_sum($useByDay),
            ],
        ]);
        exit;
    }

    if ($action === 'admin_payments_list') {
        $limit = min(200, max(1, (int) ($input['limit'] ?? 80)));
        $scoped = admin_report_scope_user_ids($pdo, $actorRole, $actorId);
        if ($scoped !== null) {
            if ($scoped === []) {
                echo json_encode(['ok' => true, 'payments' => []]);
                exit;
            }
            $ph = implode(',', array_fill(0, count($scoped), '?'));
            $st = $pdo->prepare(
                'SELECT p.*, u.email AS user_email FROM payments p
                 LEFT JOIN users u ON u.id = p.user_id WHERE p.user_id IN (' . $ph . ') ORDER BY p.id DESC LIMIT ' . (int) $limit
            );
            $st->execute($scoped);
        } else {
            $st = $pdo->prepare(
                'SELECT p.*, u.email AS user_email FROM payments p
                 LEFT JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT ' . (int) $limit
            );
            $st->execute();
        }
        echo json_encode(['ok' => true, 'payments' => $st->fetchAll()]);
        exit;
    }

    if ($action === 'admin_tickets_list') {
        $status = trim((string) ($input['status'] ?? 'all'));
        $allowed = ['all', 'open', 'in_progress', 'answered', 'closed'];
        if (!in_array($status, $allowed, true)) {
            $status = 'all';
        }
        $lim = min(200, max(1, (int) ($input['limit'] ?? 100)));
        $sql = 'SELECT t.*, u.email AS user_email, u.full_name,
                (SELECT COUNT(*) FROM support_ticket_messages m WHERE m.ticket_id = t.id) AS msg_count
                FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE 1=1';
        $par = [];
        if ($actorRole === 'master') {
            $sql .= ' AND (t.user_id = ? OR u.parent_user_id = ?)';
            $par[] = $actorId;
            $par[] = $actorId;
        }
        if ($status !== 'all') {
            $sql .= ' AND t.status = ?';
            $par[] = $status;
        }
        $sql .= ' ORDER BY t.id DESC LIMIT ' . (int) $lim;
        $st = $pdo->prepare($sql);
        $st->execute($par);
        echo json_encode(['ok' => true, 'tickets' => $st->fetchAll()]);
        exit;
    }

    if ($action === 'admin_ticket_get') {
        $tid = (int) ($input['id'] ?? 0);
        if ($tid < 1) {
            throw new RuntimeException('ID inválido.');
        }
        $st = $pdo->prepare('SELECT t.*, u.email AS user_email FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?');
        $st->execute([$tid]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('Ticket não encontrado.');
        }
        if (!extractor_ticket_admin_can_view($pdo, $actorRole, $actorId, (int) $t['user_id'])) {
            throw new RuntimeException('Sem permissão.');
        }
        $ms = $pdo->prepare(
            'SELECT m.*, u.email AS author_email FROM support_ticket_messages m
             LEFT JOIN users u ON u.id = m.author_user_id WHERE m.ticket_id = ? ORDER BY m.id ASC'
        );
        $ms->execute([$tid]);
        echo json_encode(['ok' => true, 'ticket' => $t, 'messages' => $ms->fetchAll()]);
        exit;
    }

    if ($action === 'admin_ticket_reply') {
        $tid = (int) ($input['id'] ?? 0);
        $body = trim((string) ($input['body'] ?? ''));
        if ($tid < 1 || $body === '' || strlen($body) > 8000) {
            throw new RuntimeException('Dados inválidos.');
        }
        $st = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?');
        $st->execute([$tid]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('Ticket não encontrado.');
        }
        if (!extractor_ticket_admin_can_view($pdo, $actorRole, $actorId, (int) $t['user_id'])) {
            throw new RuntimeException('Sem permissão.');
        }
        if (($t['status'] ?? '') === 'closed') {
            throw new RuntimeException('Ticket fechado.');
        }
        $now = time();
        $pdo->prepare(
            'INSERT INTO support_ticket_messages (ticket_id, author_user_id, body, created_at) VALUES (?,?,?,?)'
        )->execute([$tid, $actorId, $body, $now]);
        $pdo->prepare('UPDATE support_tickets SET status = ?, updated_at = ? WHERE id = ?')->execute(['answered', $now, $tid]);
        extractor_audit_log($pdo, $actorId, 'ticket_reply', 'ticket=' . $tid);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'admin_ticket_set_status') {
        $tid = (int) ($input['id'] ?? 0);
        $stt = (string) ($input['status'] ?? '');
        if ($tid < 1 || !in_array($stt, ['open', 'in_progress', 'answered', 'closed'], true)) {
            throw new RuntimeException('Dados inválidos.');
        }
        $st = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?');
        $st->execute([$tid]);
        $t = $st->fetch();
        if (!$t) {
            throw new RuntimeException('Ticket não encontrado.');
        }
        if (!extractor_ticket_admin_can_view($pdo, $actorRole, $actorId, (int) $t['user_id'])) {
            throw new RuntimeException('Sem permissão.');
        }
        $now = time();
        $pdo->prepare('UPDATE support_tickets SET status = ?, updated_at = ? WHERE id = ?')->execute([$stt, $now, $tid]);
        extractor_audit_log($pdo, $actorId, 'ticket_status', 'ticket=' . $tid . ';status=' . $stt);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'acao_desconhecida']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
