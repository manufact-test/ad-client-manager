<?php
declare(strict_types=1);
/** Disposable CI-only validation that read-only preflight detects PDF corruption.
 * Refuses to operate on any DB other than adclients_test with explicit fixture flag.
 */
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/documents.php';

if (PHP_SAPI!=='cli' || getenv('ADC_PREFLIGHT_TEST_FIXTURE')!=='isolated-github-actions' ||
    getenv('TEST_DB_NAME')!=='adclients_test') {
    throw new RuntimeException('Preflight fixture is restricted to disposable CI');
}
$mode=$argv[1]??'';
if (!in_array($mode,['seed','corrupt','cleanup'],true)) {
    throw new InvalidArgumentException('Expected seed, corrupt or cleanup');
}
$config=adc_config();
if (!$config || $config['db']['name']!=='adclients_test') {
    throw new RuntimeException('Only isolated configured fixture database is permitted');
}
$db=adc_db($config);
$key=str_repeat('f',48).'.pdf';
$path=adc_doc_path($key);
$name='Synthetic CI preflight PDF fixture';
$bytes="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
if ($mode==='seed') {
    if (is_file($path) || is_link($path) || is_link(adc_doc_directory())) {
        throw new RuntimeException('Private fixture already exists');
    }
    if (file_put_contents($path,$bytes,LOCK_EX)===false) {
        throw new RuntimeException('Could not create disposable PDF');
    }
    chmod($path,0600);
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO clients(name,is_demo) VALUES (?,1)')->execute([$name]);
        $client=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO document_bundles(client_id,storage_key,content_sha256,needs_contract,original_filename)
            VALUES (?,?,?,1,?)')->execute([$client,$key,hash('sha256',$bytes),'synthetic.pdf']);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        unlink($path);
        throw $e;
    }
    echo "Synthetic active preflight PDF seeded\n";
} elseif ($mode==='corrupt') {
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Synthetic PDF unavailable to corrupt');
    }
    file_put_contents($path,"not a PDF\n",LOCK_EX);
    echo "Synthetic PDF corrupted intentionally for expected preflight failure\n";
} else {
    $db->prepare('DELETE FROM clients WHERE name=? AND is_demo=1')->execute([$name]);
    if (is_file($path) && !is_link($path)) unlink($path);
    echo "Synthetic preflight fixture cleaned\n";
}
