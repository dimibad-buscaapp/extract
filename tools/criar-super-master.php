<?php

declare(strict_types=1);

/**
 * Cria ou redefine o utilizador Super Master (linha de comandos no VPS).
 *
 * Uso:
 *   cd C:\Apps\Extrator
 *   C:\PHP\php.exe tools\criar-super-master.php
 *   C:\PHP\php.exe tools\criar-super-master.php admin@buscaapp.com AdminBusca2026! "Administrador"
 *
 * Depois entre em login.php e altere e-mail/senha em panel.php → Conta.
 */
$root = dirname(__DIR__);
if (!is_file($root . '/bootstrap.php')) {
    fwrite(STDERR, "ERRO: execute na pasta do projecto (php-hostinger / Extrator).\n");
    exit(1);
}

require $root . '/bootstrap.php';
require_once $root . '/includes/db.php';

$email = strtolower(trim($argv[1] ?? 'admin@buscaapp.com'));
$pass = (string) ($argv[2] ?? 'AdminBusca2026!');
$name = trim($argv[3] ?? 'Administrador');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ERRO: o login usa E-MAIL válido (ex.: admin@buscaapp.com), não só \"admin\".\n");
    exit(1);
}
if (strlen($pass) < 10) {
    fwrite(STDERR, "ERRO: a senha precisa de pelo menos 10 caracteres.\n");
    exit(1);
}

try {
    $pdo = extractor_pdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO base de dados: ' . $e->getMessage() . "\n");
    exit(1);
}

$hash = password_hash($pass, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "ERRO ao gerar hash da senha.\n");
    exit(1);
}

$now = time();
$credits = 999999999;

$st = $pdo->query("SELECT id, email FROM users WHERE role = 'super_master' ORDER BY id LIMIT 1");
$existing = $st ? $st->fetch() : false;

if ($existing) {
    $id = (int) $existing['id'];
    $pdo->prepare(
        'UPDATE users SET email = ?, password_hash = ?, full_name = ?, status = ?, credits = ?, plan_code = ? WHERE id = ?'
    )->execute([$email, $hash, $name, 'active', $credits, 'super_master', $id]);
    echo "Super Master actualizado (id={$id}).\n";
} else {
    $pdo->prepare(
        'INSERT INTO users (email, password_hash, full_name, role, credits, parent_user_id, created_at, terms_accepted_at, liability_accepted_at, signup_ip, signup_ua, status, plan_code)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $email,
        $hash,
        $name,
        'super_master',
        $credits,
        null,
        $now,
        $now,
        $now,
        '127.0.0.1',
        'cli-criar-super-master',
        'active',
        'super_master',
    ]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO credit_transactions (user_id, kind, delta, balance_after, description, created_at) VALUES (?,?,?,?,?,?)'
    )->execute([$id, 'bonus', $credits, $credits, 'Super Master (CLI)', $now]);
    echo "Super Master criado (id={$id}).\n";
}

echo "\n";
echo "Entrar no site:\n";
echo "  E-mail: {$email}\n";
echo "  Senha:  (a que definiu no comando)\n";
echo "\n";
echo "Painel utilizador: panel.php → secção Conta (alterar e-mail e senha).\n";
echo "Administração:     admin.php (após login).\n";
echo "\n";
echo "Em produção, remova ou esvazie seed_super_master_* em config.local.php.\n";
