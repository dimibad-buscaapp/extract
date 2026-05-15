<?php

declare(strict_types=1);

require_once __DIR__ . '/billing.php';

function extractor_mp_api_base(bool $sandbox): string
{
    return 'https://api.mercadopago.com';
}

function extractor_mp_http(string $method, string $url, string $accessToken, ?array $body = null): array
{
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'X-Idempotency-Key: ' . bin2hex(random_bytes(16)),
    ];
    $payload = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;

    return extractor_http_json($method, $url, $headers, $payload, 45);
}

/**
 * Cria pagamento PIX no Mercado Pago e grava provider_payment_id + copia e cola.
 *
 * @return array{payment_id: string, pix_copy_paste: string, status: string}
 */
function extractor_mercadopago_create_pix(PDO $pdo, array $pcfg, int $localPaymentId): array
{
    $token = trim((string) ($pcfg['mercadopago_access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Mercado Pago não configurado (Access Token).');
    }

    $st = $pdo->prepare('SELECT id, user_id, plan_code, amount FROM payments WHERE id = ?');
    $st->execute([$localPaymentId]);
    $pay = $st->fetch();
    if (!$pay) {
        throw new RuntimeException('Pagamento local não encontrado.');
    }

    $userId = (int) $pay['user_id'];
    $stU = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stU->execute([$userId]);
    $email = (string) ($stU->fetchColumn() ?: 'cliente@example.com');

    $amount = round((float) $pay['amount'], 2);
    if ($amount < 0.01) {
        throw new RuntimeException('Valor do plano inválido para cobrança.');
    }

    $body = [
        'transaction_amount' => $amount,
        'description' => 'Plano ' . (string) $pay['plan_code'] . ' — Extrator',
        'payment_method_id' => 'pix',
        'payer' => ['email' => $email],
        'external_reference' => (string) $localPaymentId,
        'notification_url' => extractor_absolute_url('billing_webhook.php'),
    ];

    $url = extractor_mp_api_base((bool) ($pcfg['mercadopago_sandbox'] ?? true)) . '/v1/payments';
    $r = extractor_mp_http('POST', $url, $token, $body);
    if (!$r['ok']) {
        throw new RuntimeException('Mercado Pago: HTTP ' . $r['code'] . ' — ' . substr((string) $r['body'], 0, 300));
    }

    $data = json_decode((string) $r['body'], true);
    if (!is_array($data) || empty($data['id'])) {
        throw new RuntimeException('Mercado Pago: resposta inválida.');
    }

    $mpId = (string) $data['id'];
    $pix = '';
    if (isset($data['point_of_interaction']['transaction_data']['qr_code'])) {
        $pix = (string) $data['point_of_interaction']['transaction_data']['qr_code'];
    }
    $status = (string) ($data['status'] ?? 'pending');

    $pdo->prepare(
        'UPDATE payments SET provider = ?, provider_payment_id = ?, pix_copy_paste = ? WHERE id = ?'
    )->execute(['mercadopago', $mpId, $pix, $localPaymentId]);

    return ['payment_id' => $mpId, 'pix_copy_paste' => $pix, 'status' => $status];
}

function extractor_mercadopago_payment_get(array $pcfg, string $paymentId): array
{
    $token = trim((string) ($pcfg['mercadopago_access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Mercado Pago não configurado.');
    }
    $url = extractor_mp_api_base((bool) ($pcfg['mercadopago_sandbox'] ?? true)) . '/v1/payments/' . rawurlencode($paymentId);
    $r = extractor_mp_http('GET', $url, $token);
    if (!$r['ok']) {
        throw new RuntimeException('Mercado Pago (consultar): HTTP ' . $r['code']);
    }
    $data = json_decode((string) $r['body'], true);

    return is_array($data) ? $data : [];
}

function extractor_mercadopago_fulfil_if_approved(PDO $pdo, array $cfg, int $localPaymentId): bool
{
    require_once __DIR__ . '/billing.php';

    $pcfg = extractor_payment_config($cfg);
    $st = $pdo->prepare('SELECT id, user_id, plan_code, status, provider_payment_id FROM payments WHERE id = ?');
    $st->execute([$localPaymentId]);
    $pay = $st->fetch();
    if (!$pay || ($pay['status'] ?? '') === 'paid') {
        return ($pay['status'] ?? '') === 'paid';
    }
    $pid = (string) ($pay['provider_payment_id'] ?? '');
    if ($pid === '') {
        return false;
    }

    $remote = extractor_mercadopago_payment_get($pcfg, $pid);
    $stMp = strtolower((string) ($remote['status'] ?? ''));
    if (!in_array($stMp, ['approved', 'authorized'], true)) {
        return false;
    }

    $pdo->prepare('UPDATE payments SET status = ?, paid_at = ? WHERE id = ?')->execute(['paid', time(), $localPaymentId]);

    $planCode = (string) $pay['plan_code'];
    $userId = (int) $pay['user_id'];
    $pst = $pdo->prepare('SELECT monthly_credits FROM plans WHERE code = ?');
    $pst->execute([$planCode]);
    $prow = $pst->fetch();
    $credits = (int) ($prow['monthly_credits'] ?? 0);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET plan_code = ? WHERE id = ?')->execute([$planCode, $userId]);
        if ($credits > 0) {
            extractor_credit_grant($pdo, $userId, $credits, 'Compra PIX — plano ' . $planCode, true);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    extractor_audit_log($pdo, $userId, 'payment_fulfilled', 'payment=' . $localPaymentId . ';mp=' . $pid);

    return true;
}
