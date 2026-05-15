<?php

declare(strict_types=1);

function extractor_payment_settings_path(): string
{
    return EXTRACTOR_DATA . '/payment_settings.json';
}

/**
 * @return array{
 *   payment_provider: string,
 *   mercadopago_access_token: string,
 *   mercadopago_public_key: string,
 *   mercadopago_sandbox: bool,
 *   mercadopago_webhook_secret: string,
 *   asaas_api_key: string,
 *   asaas_sandbox: bool,
 *   asaas_webhook_token: string
 * }
 */
function extractor_payment_settings_raw(): array
{
    $path = extractor_payment_settings_path();
    if (!is_file($path)) {
        return [];
    }
    $j = json_decode((string) file_get_contents($path), true);

    return is_array($j) ? $j : [];
}

/**
 * Config de pagamento: config.local.php + payment_settings.json (JSON sobrepõe).
 *
 * @param array<string, mixed> $cfg
 * @return array<string, mixed>
 */
function extractor_payment_config(array $cfg): array
{
    $over = extractor_payment_settings_raw();
    $merged = array_merge($cfg, $over);

    $provider = trim((string) ($merged['payment_provider'] ?? 'mercadopago'));
    if (!in_array($provider, ['mercadopago', 'asaas', 'demo'], true)) {
        $provider = 'mercadopago';
    }
    $merged['payment_provider'] = $provider;
    $merged['mercadopago_access_token'] = trim((string) ($merged['mercadopago_access_token'] ?? ''));
    $merged['mercadopago_public_key'] = trim((string) ($merged['mercadopago_public_key'] ?? ''));
    $merged['mercadopago_sandbox'] = (bool) ($merged['mercadopago_sandbox'] ?? true);
    $merged['mercadopago_webhook_secret'] = trim((string) ($merged['mercadopago_webhook_secret'] ?? ''));

    return $merged;
}

/**
 * @param array<string, mixed> $patch
 */
function extractor_payment_settings_save(array $patch): void
{
    $allowed = [
        'payment_provider',
        'mercadopago_access_token',
        'mercadopago_public_key',
        'mercadopago_sandbox',
        'mercadopago_webhook_secret',
        'asaas_api_key',
        'asaas_sandbox',
        'asaas_webhook_token',
    ];
    $current = extractor_payment_settings_raw();
    foreach ($allowed as $k) {
        if (array_key_exists($k, $patch)) {
            $current[$k] = $patch[$k];
        }
    }
    if (!is_dir(EXTRACTOR_DATA)) {
        mkdir(EXTRACTOR_DATA, 0770, true);
    }
    file_put_contents(
        extractor_payment_settings_path(),
        json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
        LOCK_EX
    );
}

function extractor_payment_provider_configured(array $pcfg): bool
{
    $p = (string) ($pcfg['payment_provider'] ?? 'demo');
    if ($p === 'mercadopago') {
        return trim((string) ($pcfg['mercadopago_access_token'] ?? '')) !== '';
    }
    if ($p === 'asaas') {
        return trim((string) ($pcfg['asaas_api_key'] ?? '')) !== '';
    }

    return false;
}
