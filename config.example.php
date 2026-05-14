<?php

declare(strict_types=1);

/**
 * Copie para config.local.php e preencha.
 *
 * URL pública de referência: https://ext.buscaapp.com/
 *
 * app_secret (mín. 16 caracteres aleatórios):
 *   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 *
 * reCAPTCHA (opcional): https://www.google.com/recaptcha/admin — v2 checkbox
 * Deixe as chaves vazias para desativar (apenas para desenvolvimento).
 */
return [
    'app_secret' => 'COLOQUE_UM_SEGREDO_LONGO_ALEATORIO',
    'max_download_bytes' => 209715200,
    'http_timeout' => 120,
    /** Permitir página de registo público */
    'allow_registration' => true,
    'recaptcha_site_key' => '',
    'recaptcha_secret_key' => '',
    /** Créditos debitados por cada ficheiro descarregado via API (0 = grátis) */
    'credits_per_download' => 1,
    /** Créditos por pedido de descoberta de links (0 = grátis) */
    'credits_per_discover' => 0,

    /** Asaas (PIX): deixe asaas_api_key vazio para modo só registo local / instruções */
    'asaas_api_key' => '',
    /** true = https://sandbox.asaas.com — false = produção */
    'asaas_sandbox' => true,
    /** Opcional: mesmo valor que configurar no painel Asaas “token de autenticação” do webhook; vazio = não valida cabeçalho */
    'asaas_webhook_token' => '',

    /**
     * Opcional — primeiro Super Master automático quando a tabela users está vazia (testes / primeira instalação).
     * Em produção: altere a senha no painel (Conta) e remova ou esvazie estes campos no config.
     */
    'seed_super_master_email' => 'super@ext.buscaapp.com',
    'seed_super_master_password' => 'BuscaApp2026!Test',
    'seed_super_master_name' => 'Super Master',
];
