<?php
declare(strict_types=1);

/**
 * Test-site-only MySQL schema check/migration for Ad Client Manager.
 * Do NOT use on production, another website, or before a verified SQL backup.
 *
 * Plan (read-only):
 *   php scripts/staging_migrate.php --plan
 *
 * After backing up the dedicated test database in hPanel:
 *   ADC_STAGING_MIGRATE=CONFIRM_TEST_SITE php scripts/staging_migrate.php --apply
 *
 * No secrets, database names or customer records are printed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$mode = $argc === 2 ? ($argv[1] ?? '') : '';
if (!in_array($mode, ['--plan', '--apply'], true)) {
    fwrite(STDERR, "Usage: php scripts/staging_migrate.php --plan|--apply\n");
    exit(2);
}
if ($mode === '--apply' && getenv('ADC_STAGING_MIGRATE') !== 'CONFIRM_TEST_SITE') {
    fwrite(STDERR, "BLOCKED: verified backup and explicit test-site confirmation required.\n");
    exit(2);
}

require __DIR__.'/../app/core.php';
$config = adc_config();
if ($config === null || !is_file(adc_config_path()) || is_link(adc_config_path())) {
    fwrite(STDERR, "BLOCKED: valid private configuration required.\n");
    exit(1);
}
$root = realpath(__DIR__.'/..');
$configPath = realpath(adc_config_path());
if ($root === false || $configPath === false ||
    $configPath === $root || str_starts_with($configPath, $root.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "BLOCKED: configuration must remain outside web root.\n");
    exit(1);
}
try {
    $db = adc_db($config);
    $db->query('SELECT 1');
    $required = [
        'users', 'login_attempts', 'audit_events', 'personal_tasks',
        'personal_task_completions', 'clients', 'engagements',
        'billing_periods', 'document_bundles', 'charge_lines', 'payment_events',
    ];
    $present = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($required, $present);
    if ($missing) {
        fwrite(STDERR, "BLOCKED: existing test-site database lacks required tables; do not migrate it.\n");
        exit(1);
    }
    $director = (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE role='director' AND is_active=1"
    )->fetchColumn();
    if ($director < 1) {
        fwrite(STDERR, "BLOCKED: existing test site has no active director account.\n");
        exit(1);
    }

    $items = [
        ['payment_events', 'submission_key', 'uq_payment_submission'],
        ['billing_periods', 'creation_key', 'uq_period_creation_key'],
    ];
    $inspect = static function () use ($db, $items): bool {
        $ready = true;
        foreach ($items as [$table, $column, $index]) {
            $col = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
            $idx = $db->query("SHOW INDEX FROM `$table` WHERE Key_name='$index'")->fetch();
            $ok = $col && $col['Null'] === 'YES' &&
                $idx && (int)$idx['Non_unique'] === 0 && $idx['Column_name'] === $column;
            echo ($ok ? '[PASS] ' : '[PENDING] ') . "$table: nullable unique $column\n";
            if (!$ok) $ready = false;
        }
        return $ready;
    };
    $newTables=['client_entries','engagement_terms','team_chat_messages','team_chat_reads','team_chat_mentions','team_chat_thread_reads',
        'team_chat_attachments','team_chat_pins','client_pauses'];
    $inspectTables=static function () use ($db,$newTables): bool {
        $ready=true;
        $present=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($newTables as $table) {
            $ok=in_array($table,$present,true);
            echo ($ok ? '[PASS] ' : '[PENDING] ')."$table: required new workflow table\n";
            if (!$ok) $ready=false;
        }
        if (in_array('team_chat_messages',$present,true)) {
            $reply=$db->query("SHOW COLUMNS FROM team_chat_messages LIKE 'reply_to_id'")->fetch();
            $okReply=$reply && strtolower((string)$reply['Type'])==='bigint unsigned';
            echo ($okReply?'[PASS] ':'[PENDING] ')."team_chat_messages: replies\n";
            if(!$okReply)$ready=false;
            $col=$db->query("SHOW COLUMNS FROM team_chat_messages LIKE 'thread_client_id'")->fetch();
            $ok=$col && strtolower((string)$col['Type'])==='bigint unsigned';
            echo ($ok?'[PASS] ':'[PENDING] ')."team_chat_messages: client threads\n";
            if (!$ok)$ready=false;
        }
        $clientContext=$db->query("SHOW COLUMNS FROM clients LIKE 'client_context_text'")->fetch();
        $contextOk=$clientContext && strtolower((string)$clientContext['Type'])==='varchar(500)'
            && $clientContext['Null']==='YES';
        echo ($contextOk ? '[PASS] ' : '[PENDING] ')."clients: optional client-list context text\n";
        if (!$contextOk) $ready=false;
        if (in_array('engagement_terms',$present,true)) {
            $price=$db->query("SHOW COLUMNS FROM engagement_terms LIKE 'quoted_amount'")->fetch();
            $ok=$price && strtolower((string)$price['Type'])==='decimal(16,2)'
                && $price['Null']==='YES';
            echo ($ok ? '[PASS] ' : '[PENDING] ')."engagement_terms: historical monthly fee\n";
            if (!$ok) $ready=false;
        }
        return $ready;
    };
    echo "Ad Client Manager test-site schema inspection (no private values):\n";
    $ready = $inspect();
    $newReady=$inspectTables();
    $ready=$ready && $newReady;
    if ($mode === '--plan') {
        echo $ready ? "PLAN: schema already compatible (read-only).\n" :
            "PLAN: schema update is required; make and verify SQL backup before --apply.\n";
        exit(0);
    }
    // Schema DDL can commit implicitly in MySQL. A complete SQL backup is
    // mandatory before this operation; there is no pretend-transaction rollback.
    adc_schema($db);
    $oldReady=$inspect();
    $newReady=$inspectTables();
    if (!$oldReady || !$newReady) {
        fwrite(STDERR, "FAILED: schema was not fully migrated. Restore test backup.\n");
        exit(1);
    }
    echo "MIGRATION PASSED. Existing director account retained. Run read-only preflight.\n";
} catch (Throwable $e) {
    // Never reveal SQL or credential details via shared terminal screenshots.
    fwrite(STDERR, "BLOCKED: database check/migration failed. Review private server logs.\n");
    exit(1);
}
