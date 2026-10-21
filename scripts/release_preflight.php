<?php
declare(strict_types=1);

/**
 * Read-only release preflight. Run from a trusted CLI on an isolated Hostinger
 * test copy first. Never creates tables, alters data or exposes credentials.
 *
 * php scripts/release_preflight.php [--verify-pdfs]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/documents.php';

$deep = $argc === 2 && ($argv[1] ?? '') === '--verify-pdfs';
if ($argc > 2 || ($argc === 2 && !$deep)) {
    fwrite(STDERR,"Usage: php scripts/release_preflight.php [--verify-pdfs]\n");
    exit(2);
}
$failed = false;
$check = static function (bool $ok, string $description) use (&$failed): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ').$description."\n";
    if (!$ok) $failed = true;
};
$check(PHP_VERSION_ID >= 80300, 'PHP version is 8.3 or newer');
$check(extension_loaded('pdo_mysql') && extension_loaded('fileinfo'),
    'Required PHP extensions are present');

$webRoot = realpath(__DIR__.'/..');
$configPath = realpath(adc_config_path());
$docRoot = adc_doc_directory();
$docPath = realpath($docRoot);
$isOutside = static fn(?string $path): bool =>
    is_string($path) && is_string($webRoot) &&
    $path !== $webRoot && !str_starts_with($path, $webRoot.DIRECTORY_SEPARATOR);
$check($isOutside($configPath) && is_file($configPath) &&
    !is_link(adc_config_path()), 'Private configuration is outside web root');
$check($isOutside($docPath) && is_dir($docRoot) && !is_link($docRoot) &&
    is_readable($docRoot) && is_writable($docRoot),
    'Private PDF directory exists and is accessible outside web root');

$config = adc_config();
$check($config !== null, 'Private configuration passes validation');
if (!$config || $failed) {
    fwrite(STDERR,"Preflight BLOCKED: fix environment before connecting to data.\n");
    exit(1);
}
try {
    $db = adc_db($config);
    $db->query('SELECT 1')->fetchColumn();
    $check(true,'Database is reachable (read-only query)');
} catch (Throwable $e) {
    $check(false,'Database connection (details intentionally hidden)');
    exit(1);
}
$expected = [
    'users','login_attempts','audit_events','personal_tasks',
    'personal_task_completions','clients','engagements','billing_periods',
    'document_bundles','charge_lines','payment_events',
    'client_entries','engagement_terms'
];
$actual = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff($expected,$actual);
$check(!$missing,'All 13 required tables exist');
if ($missing) exit(1);

foreach ([
    ['payment_events','submission_key','uq_payment_submission'],
    ['billing_periods','creation_key','uq_period_creation_key']
] as [$table,$column,$index]) {
    // Only fixed internal identifiers reach SQL; never interpolate user input.
    $cols=$db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
    $idx=$db->query("SHOW INDEX FROM `$table` WHERE Key_name='$index'")->fetch();
    $check($cols && $cols['Null']==='YES' && $idx && (int)$idx['Non_unique']===0 &&
        $idx['Column_name']===$column, "Required schema/index: $table");
}
// Verify that the new entry schema is genuinely compatible, not only table names.
$requiredColumns = [
    'client_entries'=>['client_id','document_id','entry_kind','amount','currency',
                       'category','sent_at','received_at','is_void'],
    'engagement_terms'=>['engagement_id','effective_on','started_on','billing_day',
                         'payment_mode','currency'],
];
foreach ($requiredColumns as $table=>$columns) {
    $actualColumns=$db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $check(!array_diff($columns,$actualColumns),"$table mandatory columns");
}
$hasDirector=$db->query('SELECT COUNT(*) FROM users WHERE role="director" AND is_active=1')
    ->fetchColumn();
$check((int)$hasDirector>0,'Active director account exists');

if ($deep) {
    $docs=$db->query('SELECT storage_key,content_sha256 FROM document_bundles
        WHERE is_void=0 AND storage_key IS NOT NULL');
    $total=0;
    $bad=0;
    foreach ($docs as $row) {
        $total++;
        if (adc_doc_verified_path($row) === null) $bad++;
    }
    $check($bad===0, 'Active private PDFs match recorded SHA-256 (checked: '.$total.')');
}
if ($failed) {
    fwrite(STDERR,"Preflight BLOCKED: no changes made.\n");
    exit(1);
}
echo "Preflight PASSED (read-only). This is not manual browser, backup/restore or release approval.\n";
