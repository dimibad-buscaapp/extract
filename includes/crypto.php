<?php

declare(strict_types=1);

function extractor_crypto_key(): string
{
    $secret = extractor_config()['app_secret'];
    return hash('sha256', 'enc|' . $secret, true);
}

function extractor_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $key = extractor_crypto_key();
    $iv = random_bytes(16);
    $raw = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($raw === false) {
        throw new RuntimeException('Falha ao cifrar');
    }
    return base64_encode($iv . $raw);
}

function extractor_decrypt(string $token): string
{
    if ($token === '') {
        return '';
    }
    $bin = base64_decode($token, true);
    if ($bin === false || strlen($bin) < 17) {
        throw new RuntimeException('Token inválido');
    }
    $iv = substr($bin, 0, 16);
    $raw = substr($bin, 16);
    $key = extractor_crypto_key();
    $out = openssl_decrypt($raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($out === false) {
        throw new RuntimeException('Falha ao decifrar (app_secret mudou?)');
    }
    return $out;
}
