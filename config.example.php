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
    /** Créditos por pedido de descoberta de links (0 = grátis). */
    'credits_per_discover' => 0,
    /** Créditos ao reservar cada varredura «Master Extrator» (omita para usar o mesmo valor que credits_per_discover). */
    'credits_per_master_scan' => 0,
    /** Créditos ao reservar «Descoberta Forçada» (omita para usar o mesmo valor que credits_per_master_scan). */
    'credits_per_force_scan' => 0,
    /** Pagamentos: mercadopago | asaas | demo (sem API) */
    'payment_provider' => 'mercadopago',

    /** Mercado Pago — Access Token (produção ou teste) em https://www.mercadopago.com.br/developers */
    'mercadopago_access_token' => '',
    'mercadopago_public_key' => '',
    /** true = credenciais de teste */
    'mercadopago_sandbox' => true,
    /** Opcional: validar notificações (x-signature) */
    'mercadopago_webhook_secret' => '',

    /** Asaas (alternativa PIX) */
    'asaas_api_key' => '',
    'asaas_sandbox' => true,
    'asaas_webhook_token' => '',

    /**
     * Opcional — primeiro Super Master automático quando a tabela users está vazia (testes / primeira instalação).
     * Em produção: altere a senha no painel (Conta) e remova ou esvazie estes campos no config.
     */
    /** Só na 1.ª base vazia; ou use: php tools/criar-super-master.php no VPS */
    'seed_super_master_email' => 'admin@buscaapp.com',
    'seed_super_master_password' => 'AdminBusca2026!',
    'seed_super_master_name' => 'Administrador',
];
