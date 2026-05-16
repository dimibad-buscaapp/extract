<?php

declare(strict_types=1);

function extractor_db_column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->query('PRAGMA table_info(' . str_replace(['"', "'", ';'], '', $table) . ')');
    if ($st === false) {
        return false;
    }
    foreach ($st->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function extractor_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = EXTRACTOR_DATA . '/app.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ("user","reseller","master","super_master")),
            credits INTEGER NOT NULL DEFAULT 0,
            parent_user_id INTEGER,
            created_at INTEGER NOT NULL,
            terms_accepted_at INTEGER NOT NULL,
            liability_accepted_at INTEGER NOT NULL,
            signup_ip TEXT,
            signup_ua TEXT,
            status TEXT NOT NULL DEFAULT "active",
            plan_code TEXT NOT NULL DEFAULT "user",
            last_login_at INTEGER
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS plans (
            code TEXT PRIMARY KEY,
            display_name TEXT NOT NULL,
            role TEXT NOT NULL,
            monthly_credits INTEGER NOT NULL,
            price_monthly REAL NOT NULL,
            max_subusers INTEGER NOT NULL DEFAULT 0,
            can_resell INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS credit_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            delta INTEGER NOT NULL,
            balance_after INTEGER NOT NULL,
            description TEXT,
            created_at INTEGER NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            name TEXT NOT NULL,
            base_url TEXT NOT NULL,
            content_url TEXT,
            username TEXT,
            password_enc TEXT NOT NULL,
            cookie_enc TEXT,
            same_origin_only INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS m3u_playlists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            file_name TEXT NOT NULL,
            source_url TEXT,
            bytes INTEGER NOT NULL DEFAULT 0,
            entry_count INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            site_id INTEGER,
            source_url TEXT NOT NULL,
            local_path TEXT NOT NULL,
            bytes INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS master_scan_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            site_id INTEGER,
            seed_url TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            pages_crawled INTEGER NOT NULL DEFAULT 0,
            items_found INTEGER NOT NULL DEFAULT 0,
            error TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS master_scan_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            download_hint TEXT,
            display_name TEXT,
            size_bytes INTEGER NOT NULL DEFAULT 0,
            service TEXT NOT NULL DEFAULT \'\',
            type_label TEXT NOT NULL DEFAULT \'\',
            source_page TEXT NOT NULL DEFAULT \'\',
            FOREIGN KEY (run_id) REFERENCES master_scan_runs(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_master_scan_runs_user ON master_scan_runs(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_master_items_run ON master_scan_items(run_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_master_item_run_url ON master_scan_items(run_id, url)');

    /** Descoberta Forçada (varredura agressiva) — não partilha tabelas com o Master Extrator normal. */
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS master_force_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            site_id INTEGER NOT NULL,
            seed_url TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            pages_crawled INTEGER NOT NULL DEFAULT 0,
            items_found INTEGER NOT NULL DEFAULT 0,
            scan_status TEXT NOT NULL DEFAULT \'done\',
            progress_pct INTEGER NOT NULL DEFAULT 0,
            progress_msg TEXT,
            error TEXT,
            max_pages_ceiling INTEGER NOT NULL DEFAULT 0,
            max_depth_ceiling INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS master_force_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            dedupe_hash TEXT NOT NULL,
            url TEXT NOT NULL,
            kind TEXT NOT NULL,
            external_service TEXT,
            download_hint TEXT,
            post_data TEXT,
            js_context TEXT,
            needs_inspection INTEGER NOT NULL DEFAULT 0,
            source_page TEXT NOT NULL DEFAULT \'\',
            type_label TEXT NOT NULL DEFAULT \'\',
            FOREIGN KEY (run_id) REFERENCES master_force_runs(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS master_force_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            method TEXT NOT NULL,
            post_fields TEXT,
            response_code INTEGER NOT NULL DEFAULT 0,
            content_type TEXT,
            response_note TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (run_id) REFERENCES master_force_runs(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_force_runs_user ON master_force_runs(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_force_items_run ON master_force_items(run_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_force_items_run_kind ON master_force_items(run_id, kind)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_force_items_dedupe ON master_force_items(run_id, dedupe_hash)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_force_attempts_run ON master_force_attempts(run_id)');

    if (!extractor_db_column_exists($pdo, 'master_scan_runs', 'max_pages')) {
        $pdo->exec('ALTER TABLE master_scan_runs ADD COLUMN max_pages INTEGER NOT NULL DEFAULT 80');
    }
    if (!extractor_db_column_exists($pdo, 'master_scan_runs', 'max_depth')) {
        $pdo->exec('ALTER TABLE master_scan_runs ADD COLUMN max_depth INTEGER NOT NULL DEFAULT 2');
    }
    if (!extractor_db_column_exists($pdo, 'master_scan_runs', 'scan_status')) {
        $pdo->exec("ALTER TABLE master_scan_runs ADD COLUMN scan_status TEXT NOT NULL DEFAULT 'done'");
    }
    if (!extractor_db_column_exists($pdo, 'master_scan_runs', 'progress_pct')) {
        $pdo->exec('ALTER TABLE master_scan_runs ADD COLUMN progress_pct INTEGER NOT NULL DEFAULT 0');
    }
    if (!extractor_db_column_exists($pdo, 'master_scan_runs', 'progress_msg')) {
        $pdo->exec('ALTER TABLE master_scan_runs ADD COLUMN progress_msg TEXT');
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            detail TEXT,
            ip TEXT,
            created_at INTEGER NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            plan_code TEXT NOT NULL,
            amount REAL NOT NULL,
            currency TEXT NOT NULL DEFAULT \'BRL\',
            status TEXT NOT NULL DEFAULT \'pending\',
            provider TEXT NOT NULL DEFAULT \'asaas\',
            provider_payment_id TEXT,
            pix_copy_paste TEXT,
            created_at INTEGER NOT NULL,
            paid_at INTEGER
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS support_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            body TEXT NOT NULL,
            priority TEXT NOT NULL DEFAULT \'normal\',
            status TEXT NOT NULL DEFAULT \'open\',
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS support_ticket_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL,
            author_user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE
        )'
    );

    if (!extractor_db_column_exists($pdo, 'users', 'billing_customer_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN billing_customer_id TEXT');
    }
    if (!extractor_db_column_exists($pdo, 'sites', 'user_id')) {
        $pdo->exec('ALTER TABLE sites ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0');
    }
    if (!extractor_db_column_exists($pdo, 'files', 'user_id')) {
        $pdo->exec('ALTER TABLE files ADD COLUMN user_id INTEGER NOT NULL DEFAULT 0');
    }
    if (!extractor_db_column_exists($pdo, 'files', 'public_token')) {
        $pdo->exec('ALTER TABLE files ADD COLUMN public_token TEXT');
    }

    $pdo->exec('UPDATE sites SET same_origin_only = 0');

    $plans = [
        ['user', 'Utilizador', 'user', 100, 49.9, 0, 0],
        ['reseller', 'Revendedor', 'reseller', 500, 199.9, 50, 1],
        ['master', 'Master', 'master', 2000, 499.9, 500, 1],
        ['super_master', 'Super Master', 'super_master', 999999999, 0.0, 999999, 1],
    ];
    $ins = $pdo->prepare('INSERT OR IGNORE INTO plans (code, display_name, role, monthly_credits, price_monthly, max_subusers, can_resell) VALUES (?,?,?,?,?,?,?)');
    foreach ($plans as $p) {
        $ins->execute($p);
    }

    if (function_exists('extractor_config_try')) {
        $cfgTry = extractor_config_try();
        if (is_array($cfgTry)) {
            require_once __DIR__ . '/users.php';
            extractor_seed_super_master_if_configured($pdo, $cfgTry);
        }
    }

    return $pdo;
}
