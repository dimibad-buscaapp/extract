<?php

declare(strict_types=1);

const EXTRACTOR_PHP_VERSION = '1.1.0';

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

define('EXTRACTOR_ROOT', __DIR__);
define('EXTRACTOR_DATA', EXTRACTOR_ROOT . '/data');

if (!is_dir(EXTRACTOR_DATA)) {
    mkdir(EXTRACTOR_DATA, 0700, true);
}

function extractor_config_path(): string
{
    return EXTRACTOR_ROOT . '/config.local.php';
}

function extractor_config_exists(): bool
{
    return is_file(extractor_config_path());
}

/**
 * @return array{
 *   app_secret: string,
 *   max_download_bytes: int,
 *   http_timeout: int,
 *   allow_registration: bool,
 *   recaptcha_site_key: string,
 *   recaptcha_secret_key: string,
 *   credits_per_download: int,
 *   credits_per_discover: int,
 *   asaas_api_key: string,
 *   asaas_sandbox: bool,
 *   asaas_webhook_token: string,
 *   seed_super_master_email: string,
 *   seed_super_master_password: string,
 *   seed_super_master_name: string
 * }|null
 */
function extractor_config_try(): ?array
{
    try {
        return extractor_config();
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return array{
 *   app_secret: string,
 *   max_download_bytes: int,
 *   http_timeout: int,
 *   allow_registration: bool,
 *   recaptcha_site_key: string,
 *   recaptcha_secret_key: string,
 *   credits_per_download: int,
 *   credits_per_discover: int,
 *   asaas_api_key: string,
 *   asaas_sandbox: bool,
 *   asaas_webhook_token: string,
 *   seed_super_master_email: string,
 *   seed_super_master_password: string,
 *   seed_super_master_name: string
 * }
 */
function extractor_config(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (!extractor_config_exists()) {
        throw new RuntimeException('config.local.php ausente');
    }
    /** @var array<string, mixed> $loaded */
    $loaded = require extractor_config_path();
    $tmp = [
        'app_secret' => (string) ($loaded['app_secret'] ?? ''),
        'max_download_bytes' => (int) ($loaded['max_download_bytes'] ?? 209715200),
        'http_timeout' => (int) ($loaded['http_timeout'] ?? 120),
        'allow_registration' => (bool) ($loaded['allow_registration'] ?? true),
        'recaptcha_site_key' => trim((string) ($loaded['recaptcha_site_key'] ?? '')),
        'recaptcha_secret_key' => trim((string) ($loaded['recaptcha_secret_key'] ?? '')),
        'credits_per_download' => max(0, (int) ($loaded['credits_per_download'] ?? 1)),
        'credits_per_discover' => max(0, (int) ($loaded['credits_per_discover'] ?? 0)),
        'asaas_api_key' => trim((string) ($loaded['asaas_api_key'] ?? '')),
        'asaas_sandbox' => (bool) ($loaded['asaas_sandbox'] ?? true),
        'asaas_webhook_token' => trim((string) ($loaded['asaas_webhook_token'] ?? '')),
        'seed_super_master_email' => trim((string) ($loaded['seed_super_master_email'] ?? '')),
        'seed_super_master_password' => (string) ($loaded['seed_super_master_password'] ?? ''),
        'seed_super_master_name' => trim((string) ($loaded['seed_super_master_name'] ?? 'Super Master')),
    ];
    if (strlen($tmp['app_secret']) < 16 || $tmp['app_secret'] === 'COLOQUE_UM_SEGREDO_LONGO_ALEATORIO') {
        throw new RuntimeException('Defina app_secret (>=16 chars) em config.local.php.');
    }
    $cached = $tmp;
    return $cached;
}

function extractor_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function extractor_logged_in(): bool
{
    return extractor_user_id() > 0;
}

function extractor_require_login(): void
{
    if (!extractor_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function extractor_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function extractor_verify_csrf(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('CSRF inválido. Atualize a página e tente de novo.');
    }
}

function extractor_safe_filename(string $name): string
{
    $name = basename($name);
    $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? 'arquivo';
    return $name !== '' ? $name : 'arquivo';
}

function extractor_http_get(string $url, array $extraHeaders = []): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'URL inválida', 'body' => null, 'code' => 0];
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'Somente http/https', 'body' => null, 'code' => 0];
    }

    $cfg = extractor_config();
    $lines = ['User-Agent: ExtratorPainelPHP/' . EXTRACTOR_PHP_VERSION];
    foreach ($extraHeaders as $h) {
        $h = trim($h);
        if ($h !== '') {
            $lines[] = $h;
        }
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $cfg['http_timeout'],
            'header' => implode("\r\n", $lines) . "\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['ok' => false, 'error' => 'Falha ao baixar URL', 'body' => null, 'code' => 0];
    }
    $code = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    return ['ok' => $code < 400, 'error' => $code >= 400 ? "HTTP {$code}" : '', 'body' => $body, 'code' => $code];
}

/**
 * @return array{ok: bool, error: string, bytes: int}
 */
function extractor_stream_url_to_file(string $url, string $destPath, array $extraHeaders = []): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'URL inválida', 'bytes' => 0];
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'Somente http/https', 'bytes' => 0];
    }

    $cfg = extractor_config();
    $lines = ['User-Agent: ExtratorPainelPHP/' . EXTRACTOR_PHP_VERSION];
    foreach ($extraHeaders as $h) {
        $h = trim($h);
        if ($h !== '') {
            $lines[] = $h;
        }
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $cfg['http_timeout'],
            'header' => implode("\r\n", $lines) . "\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $in = @fopen($url, 'rb', false, $ctx);
    if ($in === false) {
        return ['ok' => false, 'error' => 'Não foi possível abrir a URL', 'bytes' => 0];
    }
    $out = @fopen($destPath, 'wb');
    if ($out === false) {
        fclose($in);
        return ['ok' => false, 'error' => 'Não foi possível gravar em disco', 'bytes' => 0];
    }

    $max = $cfg['max_download_bytes'];
    $total = 0;
    while (!feof($in)) {
        $chunk = fread($in, 8192);
        if ($chunk === false) {
            break;
        }
        $len = strlen($chunk);
        $total += $len;
        if ($total > $max) {
            fclose($in);
            fclose($out);
            @unlink($destPath);
            return ['ok' => false, 'error' => 'Arquivo excede max_download_bytes', 'bytes' => 0];
        }
        fwrite($out, $chunk);
    }
    fclose($in);
    fclose($out);
    return ['ok' => true, 'error' => '', 'bytes' => $total];
}
