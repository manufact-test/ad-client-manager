<?php
declare(strict_types=1);
/**
 * Fully isolated CI fixture. Refuses any non-CI database and every non-CI
 * operation. The separate database contains ONLY synthetic information.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('ADC_STAGING_UPGRADE_FIXTURE') !== 'isolated-github-actions' ||
    getenv('TEST_DB_NAME') !== 'adclients_test' ||
    getenv('TEST_DB_HOST') !== '127.0.0.1') {
    throw new RuntimeException('Only the disposable GitHub Actions fixture is allowed.');
}
require __DIR__.'/../app/core.php';
$base = ['host'=>'127.0.0.1','name'=>'adclients_test',
    'user'=>getenv('TEST_DB_USER') ?: 'root',
    'password'=>getenv('TEST_DB_PASSWORD') ?: '',
];
$admin = adc_db(['db'=>$base]);
$dbName = 'adclients_migration_test';
$configPath = adc_config_path();
$mode = $argv[1] ?? '';
if ($mode === 'cleanup') {
    if (is_file($configPath)) unlink($configPath);
    $admin->exec('DROP DATABASE IF EXISTS adclients_migration_test');
    echo "Staging schema acceptance fixture cleaned up\n";
    exit;
}
if ($mode === 'seed') {
    if (file_exists($configPath)) throw new RuntimeException('Unexpected private config exists');
    $present=(int)$admin->query(
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='adclients_migration_test'"
    )->fetchColumn();
    if ($present !== 0) throw new RuntimeException('Nonempty fixture DB already exists');
    $admin->exec('CREATE DATABASE adclients_migration_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $base['name']=$dbName;
    $db=adc_db(['db'=>$base]);
    adc_schema($db);
    $db->prepare('INSERT INTO users(email,display_name,password_hash,role)
        VALUES (?,?,?,"director")')->execute([
            'staging-director@example.test', 'Fictional CI director',
            password_hash('ci-fixture-upgrade-123456',PASSWORD_DEFAULT),
        ]);
    // Mimic the actual Hostinger situation: the director already created
    // an employee, real+demo test clients, finance, tasks and a document row.
    $db->prepare('INSERT INTO users(email,display_name,password_hash,role)
        VALUES (?,?,?,"employee")')->execute([
            'staging-employee@example.test','Fictional CI employee',
            password_hash('ci-employee-password-123456', PASSWORD_DEFAULT),
        ]);
    $employee=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO personal_tasks(user_id,title,due_date)
        VALUES (?,?,"2026-11-10")')->execute([$employee,'Fictional staging task']);
    $db->exec('INSERT INTO clients(name,is_demo) VALUES ("Fictional current client",0)');
    $realClient=(int)$db->lastInsertId();
    $db->exec('INSERT INTO clients(name,is_demo) VALUES ("Fictional demo client",1)');
    $demoClient=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-10-01",7,"prepayment","BYN")')->execute([$realClient]);
    $engagement=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end)
        VALUES (?,"2026-10-01","2026-11-01")')->execute([$engagement]);
    $period=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO charge_lines(client_id,period_id,category,label,amount,currency)
        VALUES (?,?,"advertising","Fictional service","1250.00","BYN")')
        ->execute([$realClient,$period]);
    $db->prepare('INSERT INTO payment_events(client_id,period_id,event_type,amount,currency,occurred_on)
        VALUES (?,?,"received","400.00","BYN","2026-10-15")')
        ->execute([$realClient,$period]);
    $db->prepare('INSERT INTO payment_events(client_id,period_id,event_type,amount,currency,occurred_on)
        VALUES (?,?,"refund","100.00","BYN","2026-10-16")')
        ->execute([$realClient,$period]);
    $db->prepare('INSERT INTO document_bundles(client_id,period_id,original_filename,needs_invoice)
        VALUES (?,?,"fictional-existing-document.pdf",1)')
        ->execute([$realClient,$period]);
    adc_audit($db,null,'fixture_existing','client',$realClient);
    $db->exec('DROP TABLE IF EXISTS team_chat_reads');
    $db->exec('DROP TABLE IF EXISTS team_chat_messages');
    // Simulate the previous deployed schema: no monthly-price snapshots.
    $db->exec('ALTER TABLE engagement_terms DROP COLUMN quoted_amount');
    $db->exec('ALTER TABLE payment_events DROP INDEX uq_payment_submission, DROP COLUMN submission_key');
    $db->exec('ALTER TABLE billing_periods DROP INDEX uq_period_creation_key, DROP COLUMN creation_key');
    $conf='<?php return '.var_export([
        'db'=>$base,'setup_secret'=>str_repeat('S',32),
    ],true).';';
    if (file_put_contents($configPath,$conf,LOCK_EX)===false) {
        throw new RuntimeException('Cannot create private fixture config');
    }
    chmod($configPath,0600);
    echo "Legacy schema fixture ready (single fictional director, no business data)\n";
    exit;
}
if ($mode !== 'verify') throw new RuntimeException('Use seed, verify, or cleanup');
$base['name']=$dbName;
$db=adc_db(['db'=>$base]);
foreach ([
    ['payment_events','submission_key','uq_payment_submission'],
    ['billing_periods','creation_key','uq_period_creation_key'],
] as [$table,$column,$index]) {
    $col=$db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
    $idx=$db->query("SHOW INDEX FROM `$table` WHERE Key_name='$index'")->fetch();
    if (!$col || $col['Null']!=='YES' || !$idx || (int)$idx['Non_unique']!==0) {
        throw new RuntimeException('Required schema upgrade did not complete');
    }
}
$priceCol=$db->query("SHOW COLUMNS FROM engagement_terms LIKE 'quoted_amount'")->fetch();
if (!$priceCol || strtolower((string)$priceCol['Type'])!=='decimal(16,2)' ||
    $priceCol['Null']!=='YES') throw new RuntimeException('Monthly contract price migration failed');
// Every row from the pre-migration fictional business dataset must remain.
$expectedCounts = [
    'users'=>2, 'clients'=>2, 'engagements'=>1, 'billing_periods'=>1,
    'charge_lines'=>1, 'payment_events'=>2, 'personal_tasks'=>1,
    'document_bundles'=>1, 'audit_events'=>2,
];
foreach ($expectedCounts as $table=>$count) {
    if ((int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== $count) {
        throw new RuntimeException("Historical data lost during migration: $table");
    }
}
if ((int)$db->query("SELECT COUNT(*) FROM users WHERE email='staging-director@example.test' AND role='director' AND is_active=1")->fetchColumn()!==1 ||
    (int)$db->query("SELECT COUNT(*) FROM users WHERE email='staging-employee@example.test' AND role='employee'")->fetchColumn()!==1 ||
    (int)$db->query("SELECT COUNT(*) FROM clients WHERE is_demo=1")->fetchColumn()!==1 ||
    (int)$db->query("SELECT COUNT(*) FROM payment_events WHERE submission_key IS NULL")->fetchColumn()!==2 ||
    (int)$db->query("SELECT COUNT(*) FROM billing_periods WHERE creation_key IS NULL")->fetchColumn()!==1 ||
    (int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='staging_schema_upgrade'")->fetchColumn()!==1) {
    throw new RuntimeException('Existing director/employee/demo/financial history not preserved');
}
$money = $db->query('SELECT c.amount AS billed, p.amount AS received
   FROM charge_lines c JOIN payment_events p ON p.client_id=c.client_id
   WHERE p.event_type="received" LIMIT 1')->fetch();
if (!$money || $money['billed']!=='1250.00' || $money['received']!=='400.00') {
    throw new RuntimeException('Exact historical financial amounts changed');
}
echo "Director-confirmed migration preserved existing employees, real/demo clients, billing, payments, tasks, PDF metadata and audit\n";
