<?php

declare(strict_types=1);

/**
 * HTTP JSON (POST/PATCH/GET) para integração Asaas (PIX).
 *
 * @return array{ok: bool, code: int, body: string}
 */
function extractor_http_json(string $method, string $url, array $headers, ?string $body, int $timeoutSec): array
{
    $lines = array_merge(['User-Agent: ExtratorPainelPHP/' . EXTRACTOR_PHP_VERSION], $headers);
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'timeout' => $timeoutSec,
            'header' => implode("\r\n", $lines) . "\r\n",
            'content' => $body ?? '',
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    if ($raw === false) {
        return ['ok' => false, 'code' => $code, 'body' => ''];
    }

    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => (string) $raw];
}

function extractor_asaas_base_url(array $cfg): string
{
    return !empty($cfg['asaas_sandbox'])
        ? 'https://sandbox.asaas.com/api/v3'
        : 'https://api.asaas.com/api/v3';
}

/**
 * @return array<string, mixed>
 */
function extractor_asaas_payment_get(array $cfg, string $paymentId): array
{
    $key = trim((string) ($cfg['asaas_api_key'] ?? ''));
    if ($key === '') {
        return [];
    }
    $url = extractor_asaas_base_url($cfg) . '/payments/' . rawurlencode($paymentId);
    $r = extractor_http_json('GET', $url, [
        'Content-Type: application/json',
        'access_token: ' . $key,
    ], null, (int) $cfg['http_timeout']);
    if (!$r['ok']) {
        return [];
    }
    $j = json_decode($r['body'], true);

    return is_array($j) ? $j : [];
}

/**
 * Garante customer no Asaas; grava billing_customer_id no utilizador.
 */
function extractor_asaas_ensure_customer(PDO $pdo, array $cfg, int $userId): string
{
    $key = trim((string) ($cfg['asaas_api_key'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('Asaas não configurado (asaas_api_key).');
    }
    $st = $pdo->prepare('SELECT id, email, full_name, billing_customer_id FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) {
        throw new RuntimeException('Utilizador não encontrado.');
    }
    $cid = trim((string) ($u['billing_customer_id'] ?? ''));
    if ($cid !== '') {
        return $cid;
    }
    $base = extractor_asaas_base_url($cfg);
    $payload = json_encode([
        'name' => (string) $u['full_name'],
        'email' => (string) $u['email'],
        'notificationDisabled' => true,
    ], JSON_THROW_ON_ERROR);
    $r = extractor_http_json('POST', $base . '/customers', [
        'Content-Type: application/json',
        'access_token: ' . $key,
    ], $payload, (int) $cfg['http_timeout']);
    if (!$r['ok']) {
        throw new RuntimeException('Asaas (criar cliente): HTTP ' . $r['code']);
    }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['id'])) {
        throw new RuntimeException('Asaas: resposta inválida ao criar cliente.');
    }
    $newId = (string) $j['id'];
    $pdo->prepare('UPDATE users SET billing_customer_id = ? WHERE id = ?')->execute([$newId, $userId]);

    return $newId;
}

/**
 * Cria cobrança PIX no Asaas e actualiza linha local payments.
 *
 * @return array{pix_copy_paste: string, provider_payment_id: string}
 */
function extractor_asaas_create_pix_for_payment(PDO $pdo, array $cfg, int $localPaymentId): array
{
    $key = trim((string) ($cfg['asaas_api_key'] ?? ''));
    if ($key === '') {
        throw new RuntimeException('Asaas não configurado.');
    }
    $st = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$localPaymentId]);
    $pay = $st->fetch();
    if (!$pay) {
        throw new RuntimeException('Pagamento local não encontrado.');
    }
    $userId = (int) $pay['user_id'];
    $amount = (float) $pay['amount'];
    if ($amount <= 0) {
        throw new RuntimeException('Valor inválido.');
    }
    $customerId = extractor_asaas_ensure_customer($pdo, $cfg, $userId);
    $base = extractor_asaas_base_url($cfg);
    $due = date('Y-m-d', strtotime('+3 days'));
    $payload = json_encode([
        'customer' => $customerId,
        'billingType' => 'PIX',
        'value' => round($amount, 2),
        'dueDate' => $due,
        'description' => 'Plano ' . (string) $pay['plan_code'] . ' — Extrator',
        'externalReference' => (string) $localPaymentId,
    ], JSON_THROW_ON_ERROR);
    $r = extractor_http_json('POST', $base . '/payments', [
        'Content-Type: application/json',
        'access_token: ' . $key,
    ], $payload, (int) $cfg['http_timeout']);
    if (!$r['ok']) {
        throw new RuntimeException('Asaas (criar pagamento): HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 200));
    }
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['id'])) {
        throw new RuntimeException('Asaas: resposta inválida ao criar pagamento.');
    }
    $pid = (string) $j['id'];
    $qr = extractor_http_json('GET', $base . '/payments/' . rawurlencode($pid) . '/pixQrCode', [
        'Content-Type: application/json',
        'access_token: ' . $key,
    ], null, (int) $cfg['http_timeout']);
    $pixPayload = '';
    if ($qr['ok']) {
        $qj = json_decode($qr['body'], true);
        if (is_array($qj) && !empty($qj['payload'])) {
            $pixPayload = (string) $qj['payload'];
        }
    }
    $pdo->prepare('UPDATE payments SET provider_payment_id = ?, pix_copy_paste = ? WHERE id = ?')->execute([
        $pid,
        $pixPayload !== '' ? $pixPayload : null,
        $localPaymentId,
    ]);

    return ['pix_copy_paste' => $pixPayload, 'provider_payment_id' => $pid];
}

/**
 * Confirma pagamento no Asaas e, se pago, credita utilizador (idempotente).
 */
function extractor_fulfil_payment_if_paid(PDO $pdo, array $cfg, int $localPaymentId): bool
{
    $st = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$localPaymentId]);
    $pay = $st->fetch();
    if (!$pay || ($pay['status'] ?? '') === 'paid') {
        return false;
    }
    $pid = trim((string) ($pay['provider_payment_id'] ?? ''));
    if ($pid === '') {
        return false;
    }
    $remote = extractor_asaas_payment_get($cfg, $pid);
    if ($remote === []) {
        return false;
    }
    $stRemote = strtoupper((string) ($remote['status'] ?? ''));
    if (!in_array($stRemote, ['CONFIRMED', 'RECEIVED'], true)) {
        return false;
    }
    $userId = (int) $pay['user_id'];
    $planCode = (string) $pay['plan_code'];
    $pst = $pdo->prepare('SELECT monthly_credits FROM plans WHERE code = ?');
    $pst->execute([$planCode]);
    $prow = $pst->fetch();
    if (!$prow) {
        return false;
    }
    $credits = (int) $prow['monthly_credits'];
    $now = time();
    $pdo->beginTransaction();
    try {
        $st2 = $pdo->prepare('UPDATE payments SET status = ?, paid_at = ? WHERE id = ? AND status = ?');
        $st2->execute(['paid', $now, $localPaymentId, 'pending']);
        if ($st2->rowCount() < 1) {
            $pdo->rollBack();

            return false;
        }
        $pdo->prepare('UPDATE users SET plan_code = ? WHERE id = ?')->execute([$planCode, $userId]);
        if ($credits > 0) {
            extractor_credit_grant($pdo, $userId, $credits, 'Compra PIX — plano ' . $planCode, true);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    extractor_audit_log($pdo, $userId, 'payment_fulfilled', 'payment=' . $localPaymentId . ';plan=' . $planCode);

    return true;
}
