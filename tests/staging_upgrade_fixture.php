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
if ((int)$db->query("SELECT COUNT(*) FROM users WHERE email='staging-director@example.test' AND role='director'")->fetchColumn()!==1 ||
    (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn()!==0 ||
    (int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='staging_schema_upgrade'")->fetchColumn()!==1) {
    throw new RuntimeException('Director was lost or migration audit is incorrect');
}
echo "Director-confirmed migration passed on disposable old schema\n";
