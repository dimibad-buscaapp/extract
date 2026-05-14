<?php

declare(strict_types=1);

/**
 * Webhook Asaas (sem sessão). Configure no painel Asaas a URL deste ficheiro.
 * Valida opcionalmente o cabeçalho `asaas-access-token` com asaas_webhook_token.
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/billing.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'billing_webhook']);
    exit;
}

try {
    $cfg = extractor_config();
    $tokenCfg = (string) ($cfg['asaas_webhook_token'] ?? '');
    if ($tokenCfg !== '') {
        $hdr = (string) ($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '');
        if ($hdr === '' || !hash_equals($tokenCfg, $hdr)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'unauthorized']);
            exit;
        }
    }

    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_json']);
        exit;
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

    $remote = extractor_asaas_payment_get($cfg, $paymentId);
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
    $pdo = extractor_pdo();
    $fulfilled = extractor_fulfil_payment_if_paid($pdo, $cfg, $localId);
    echo json_encode(['ok' => true, 'fulfilled' => $fulfilled]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
