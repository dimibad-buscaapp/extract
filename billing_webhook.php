<?php

declare(strict_types=1);

/**
 * Webhook de pagamentos (Mercado Pago e Asaas). Sem sessão.
 * Configure a mesma URL nos dois provedores, se usar ambos.
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/payment_settings.php';
require_once __DIR__ . '/includes/mercadopago.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'billing_webhook', 'providers' => ['mercadopago', 'asaas']]);
    exit;
}

try {
    $cfg = extractor_config();
    $pcfg = extractor_payment_config($cfg);
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
    }

    $pdo = extractor_pdo();

    // —— Mercado Pago ——
    $mpPaymentId = null;
    if (isset($data['data']['id'])) {
        $mpPaymentId = (string) $data['data']['id'];
    } elseif (isset($data['id']) && !str_contains((string) $data['id'], 'pay_')) {
        $mpPaymentId = (string) $data['id'];
    }
    $mpTopic = strtolower((string) ($data['type'] ?? $data['topic'] ?? $data['action'] ?? ''));
    $looksMp = $mpPaymentId !== '' && ($mpTopic === '' || str_contains($mpTopic, 'payment'));

    if ($looksMp && trim((string) ($pcfg['mercadopago_access_token'] ?? '')) !== '') {
        $remote = extractor_mercadopago_payment_get($pcfg, $mpPaymentId);
        $ext = (string) ($remote['external_reference'] ?? '');
        if ($ext !== '' && ctype_digit($ext)) {
            $fulfilled = extractor_mercadopago_fulfil_if_approved($pdo, $cfg, (int) $ext);
            echo json_encode(['ok' => true, 'provider' => 'mercadopago', 'fulfilled' => $fulfilled]);
            exit;
        }
        $st = $pdo->prepare('SELECT id FROM payments WHERE provider_payment_id = ? LIMIT 1');
        $st->execute([$mpPaymentId]);
        $localId = (int) ($st->fetchColumn() ?: 0);
        if ($localId > 0) {
            $fulfilled = extractor_mercadopago_fulfil_if_approved($pdo, $cfg, $localId);
            echo json_encode(['ok' => true, 'provider' => 'mercadopago', 'fulfilled' => $fulfilled, 'via' => 'provider_id']);
            exit;
        }
        http_response_code(200);
        echo json_encode(['ok' => true, 'ignored' => true, 'provider' => 'mercadopago']);
        exit;
    }

    // —— Asaas ——
    $tokenCfg = (string) ($pcfg['asaas_webhook_token'] ?? '');
    if ($tokenCfg !== '') {
        $hdr = (string) ($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '');
        if ($hdr === '' || !hash_equals($tokenCfg, $hdr)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthorized']);
            exit;
        }
    }

    $paymentId = null;
    if (isset($data['payment']['id'])) {
        $paymentId = (string) $data['payment']['id'];
    } elseif (isset($data['payment']['object']) && is_array($data['payment']['object']) && isset($data['payment']['object']['id'])) {
        $paymentId = (string) $data['payment']['object']['id'];
    } elseif (isset($data['id']) && str_contains((string) $data['id'], 'pay_')) {
        $paymentId = (string) $data['id'];
    }

    if ($paymentId === null || $paymentId === '') {
        http_response_code(200);
        echo json_encode(['ok' => true, 'ignored' => true]);
        exit;
    }

    $remote = extractor_asaas_payment_get($pcfg, $paymentId);
    if ($remote === []) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'ignored' => true, 'reason' => 'fetch_failed']);
        exit;
    }

    $ext = (string) ($remote['externalReference'] ?? '');
    if ($ext === '' || !ctype_digit($ext)) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'ignored' => true, 'reason' => 'no_external_ref']);
        exit;
    }

    $localId = (int) $ext;
    $fulfilled = extractor_fulfil_payment_if_paid($pdo, $pcfg, $localId);
    echo json_encode(['ok' => true, 'provider' => 'asaas', 'fulfilled' => $fulfilled]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
