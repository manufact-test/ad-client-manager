<?php
declare(strict_types=1);

/**
 * Verify, never restore. Run from a trusted CLI only after the operator
 * independently creates a SEPARATE restored DB and private PDF directory.
 *
 * Source uses the existing private app config. Destination credentials come
 * from process environment; no values are printed or written to disk.
 *
 * ADC_RESTORE_COMPARE=READ_ONLY_APPROVED php scripts/compare_restore.php --compare
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argc??0)!==2 || ($argv[1]??'')!=='--compare' ||
    getenv('ADC_RESTORE_COMPARE')!=='READ_ONLY_APPROVED'){
    fwrite(STDERR,"BLOCKED: CLI --compare and explicit read-only approval required.\n");
    exit(2);
}
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/documents.php';
require __DIR__.'/../app/restore_audit.php';

try{
    $test=getenv('ADC_RESTORE_COMPARE_CI')==='isolated-github-actions';
    if($test) {
        if(getenv('TEST_DB_NAME')!=='adclients_restore_source_test' ||
            getenv('TEST_DB_HOST')!=='127.0.0.1'){
            throw new RuntimeException('CI source must be a disposable test database.');
        }
        $sourceCfg=['db'=>[
            'host'=>getenv('TEST_DB_HOST'),
            'port'=>(int)(getenv('TEST_DB_PORT')?:3306),
            'name'=>getenv('TEST_DB_NAME'),
            'user'=>getenv('TEST_DB_USER'),
            'password'=>getenv('TEST_DB_PASSWORD')
        ]];
    }else{
        $sourceCfg=adc_config();
        $configPath=realpath(adc_config_path());
        $webRoot=realpath(__DIR__.'/..');
        if(!$sourceCfg || !$configPath || !$webRoot ||
            $configPath===$webRoot ||
            str_starts_with($configPath,$webRoot.DIRECTORY_SEPARATOR) ||
            is_link(adc_config_path())){
            throw new RuntimeException('Source private configuration is unavailable.');
        }
    }
    if(PHP_VERSION_ID<80300 || !extension_loaded('pdo_mysql')){
        throw new RuntimeException('PHP 8.3 with MySQL PDO is required.');
    }
    $required=['ADC_RESTORE_DB_HOST','ADC_RESTORE_DB_NAME',
        'ADC_RESTORE_DB_USER','ADC_RESTORE_DB_PASSWORD','ADC_RESTORE_PDF_DIR'];
    foreach($required as $key){
        if(!is_string(getenv($key)) || getenv($key)===''){
            throw new RuntimeException('Restored-copy configuration is incomplete.');
        }
    }
    $targetCfg=['db'=>[
        'host'=>getenv('ADC_RESTORE_DB_HOST'),
        'port'=>(int)(getenv('ADC_RESTORE_DB_PORT')?:3306),
        'name'=>getenv('ADC_RESTORE_DB_NAME'),
        'user'=>getenv('ADC_RESTORE_DB_USER'),
        'password'=>getenv('ADC_RESTORE_DB_PASSWORD')
    ]];
    if($sourceCfg['db']['name']===$targetCfg['db']['name']){
        throw new RuntimeException('Source and restored database names must differ.');
    }
    $originalDir=adc_doc_directory();
    $copyDir=getenv('ADC_RESTORE_PDF_DIR');
    $webRoot=realpath(__DIR__.'/..');
    $one=realpath($originalDir);
    $two=realpath($copyDir);
    $outside=static fn($p)=>is_string($p) && is_string($webRoot) &&
        $p!==$webRoot && !str_starts_with($p,$webRoot.DIRECTORY_SEPARATOR);
    if(is_link($originalDir) || is_link($copyDir) ||
       !$outside($one) || !$outside($two) || $one===$two) {
        throw new RuntimeException('Restore documents must be in a separate private directory.');
    }
    // The only database operations inside the following function are
    // SELECT/SHOW. This tool NEVER creates a backup or changes either DB.
    $source=adc_db($sourceCfg);
    $restored=adc_db($targetCfg);
    $report=adc_restore_compare($source,$restored,$one,$two);
    echo "[PASS] Isolated SQL+PDF copy read-only comparison\n";
    echo "[PASS] Schema and counts match (".$report['tables']." tables)\n";
    echo "[PASS] Financial totals and ALL database rows (13 tables) match by SHA-256\n";
    echo "[PASS] Document metadata and private PDF SHA-256 hashes match\n";
    echo "[PASS] Private PDFs checked: ".$report['pdfs']."\n";
    echo "CHECKED COPY ONLY: operator must still approve migration, UI tests,\n";
    echo "GitHub PRIVATE, and release. Original backup creation not attested.\n";
} catch(Throwable $error) {
    // Never expose PDO exceptions, storage keys or DB names in CLI output.
    $known=[
        'Source private configuration is unavailable.',
        'PHP 8.3 with MySQL PDO is required.',
        'Restored-copy configuration is incomplete.',
        'Source and restored database names must differ.',
        'Restore documents must be in a separate private directory.',
        'CI source must be a disposable test database.',
        'Required database schema is incomplete.',
        'Table row counts differ between databases.',
        'Full database row fingerprints differ.',
        'Unknown table in restore verifier.',
        'Database column name is unsupported.',
        'Primary key is required for safe streaming.',
        'Primary key definition does not match the table.',
        'Financial ledger aggregates differ.',
        'Private PDF metadata is invalid.',
        'Conflicting private PDF metadata.',
        'Private PDF database metadata differs.',
        'Private PDF directory is unavailable.',
        'One or more private PDFs are missing.',
        'One or more private PDFs are corrupted.',
        'Private PDF counts differ.'
    ];
    $message=in_array($error->getMessage(),$known,true)
        ?$error->getMessage():'Comparison could not complete (details withheld).';
    fwrite(STDERR,"[BLOCKED] ".$message."\nNo data was modified by this utility.\n");
    exit(1);
}
