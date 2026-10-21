<?php
declare(strict_types=1);
// Disposable CI-only fixture. Never run against a live/customer database.
require __DIR__ . '/../app/core.php';
require __DIR__ . '/../app/documents.php';
if (getenv('ADC_HTTP_TEST_FIXTURE') !== 'isolated-github-actions') {
    throw new RuntimeException('Refusing non-CI fixture operation.');
}
$dir=dirname(__DIR__,2);
$configFile=$dir . '/adclients-config.php';
$documentDir=$dir . '/adclients-documents';
$mode=$argv[1] ?? '';
if ($mode === 'cleanup') {
    if (is_dir($documentDir)) {
        foreach (glob($documentDir.'/*.pdf') ?: [] as $p) unlink($p);
        rmdir($documentDir);
    }
    if (is_file($configFile)) unlink($configFile);
    echo "HTTP fixture private config and PDFs removed\n";
    exit;
}
if ($mode !== 'init' || file_exists($configFile) || file_exists($documentDir)) {
    throw new RuntimeException('Expected empty, isolated CI config and storage directories.');
}
$dbInfo=[
    'host'=>getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'port'=>(int)(getenv('TEST_DB_PORT') ?: '3306'),
    'name'=>getenv('TEST_DB_NAME') ?: 'adclients_test',
    'user'=>getenv('TEST_DB_USER') ?: 'root',
    'password'=>getenv('TEST_DB_PASSWORD') ?: '',
];
if ($dbInfo['name'] !== 'adclients_test') throw new RuntimeException('Fixture DB name must be adclients_test');
$db=adc_db(['db'=>$dbInfo]);
adc_schema($db);
$password='CI-only-fictional-password-12345';
foreach (['director','employee'] as $role) {
    $stmt=$db->prepare('INSERT INTO users(email,display_name,password_hash,role) VALUES (?,?,?,?)');
    $stmt->execute(["web-$role@example.test",ucfirst($role).' Fixture',password_hash($password,PASSWORD_DEFAULT),$role]);
}
$config='<?php return '.var_export(['db'=>$dbInfo,'setup_secret'=>str_repeat('z',32)],true).';';
if (file_put_contents($configFile,$config,LOCK_EX)===false) throw new RuntimeException('Cannot create private CI config');
chmod($configFile,0600);
echo "Disposable CI users and out-of-root config prepared\n";
