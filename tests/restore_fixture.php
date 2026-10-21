<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/documents.php';

if (getenv('ADC_RESTORE_TEST_FIXTURE')!=='isolated-github-actions') {
    throw new RuntimeException('Refusing restore fixture outside disposable CI.');
}
$mode=$argv[1]??'';
$key=str_repeat('d',48).'.pdf';
$dir=adc_doc_directory();
$path=adc_doc_path($key);
if ($mode==='cleanup') {
    if (is_file($path)) unlink($path);
    if (is_dir($dir)) @rmdir($dir);
    echo "Disposable PDF fixture cleaned\n";
    exit;
}
$db=adc_db(['db'=>[
 'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1',
 'port'=>(int)(getenv('TEST_DB_PORT')?:3306),
 'name'=>getenv('TEST_DB_NAME')?:'adclients_test',
 'user'=>getenv('TEST_DB_USER')?:'root',
 'password'=>getenv('TEST_DB_PASSWORD')?:'',
]]);
if ($mode==='seed') {
    if ((getenv('TEST_DB_NAME')?:'')!=='adclients_test') {
        throw new RuntimeException('Backup seed must use isolated CI database.');
    }
    adc_schema($db);
    adc_doc_ensure_directory();
    if (file_exists($path)) throw new RuntimeException('Unexpected existing PDF fixture');
    $pdf="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    if (file_put_contents($path,$pdf,LOCK_EX)===false) throw new RuntimeException('Private PDF cannot be seeded');
    chmod($path,0600);
    $db->exec('INSERT INTO clients(name,source_kind,is_demo) VALUES ("Restorable fictional client","referral",0)');
    $client=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
       VALUES (?,"2026-10-01",7,"prepayment","EUR")')->execute([$client]);
    $eng=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end)
       VALUES (?,"2026-10-01","2026-11-01")')->execute([$eng]);
    $period=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO charge_lines(client_id,period_id,category,label,amount,currency)
       VALUES (?,?,"advertising","Restore fixture charge","100.01","EUR")')->execute([$client,$period]);
    $db->prepare('INSERT INTO payment_events(client_id,period_id,event_type,amount,currency,occurred_on)
       VALUES (?,?,"received","80.01","EUR","2026-10-04")')->execute([$client,$period]);
    $db->prepare('INSERT INTO document_bundles(client_id,period_id,storage_key,original_filename,content_sha256,needs_invoice,transferred_at)
       VALUES (?,?,?,?,?,1,UTC_TIMESTAMP())')->execute([$client,$period,$key,'fictional.pdf',hash('sha256',$pdf)]);
    adc_audit($db,null,'fixture_restore','client',$client,['source'=>'synthetic']);
    echo "Disposable backup fixture seeded\n";
    exit;
}
if ($mode!=='verify' || getenv('TEST_DB_NAME')!=='adclients_restore_test') {
    throw new RuntimeException('Verification requires an isolated restored database.');
}
$restoredDir=getenv('ADC_RESTORE_PDF_DIR');
if (!is_string($restoredDir) || !is_dir($restoredDir) || str_contains($restoredDir,'..')) {
    throw new RuntimeException('Missing restored private document directory.');
}
$stmt=$db->query('SELECT c.id AS client_id, b.id period_id, d.storage_key,d.content_sha256,
  (SELECT SUM(ch.amount) FROM charge_lines ch WHERE ch.client_id=c.id AND ch.is_void=0) billed,
  (SELECT SUM(CASE WHEN p.event_type="received" THEN p.amount ELSE -p.amount END)
    FROM payment_events p WHERE p.client_id=c.id) paid,
  d.transferred_at
  FROM clients c JOIN engagements e ON e.client_id=c.id
  JOIN billing_periods b ON b.engagement_id=e.id
  JOIN document_bundles d ON d.period_id=b.id
  WHERE c.name="Restorable fictional client" AND c.is_demo=0');
$record=$stmt->fetch();
if (!$record || $record['billed']!=='100.01' || $record['paid']!=='80.01' ||
    $record['transferred_at']===null || $record['storage_key']!==$key) {
    throw new RuntimeException('Restored relational finance/document data mismatch.');
}
$file=$restoredDir.'/'.$key;
if (!is_file($file) || hash_file('sha256',$file)!==$record['content_sha256']) {
    throw new RuntimeException('Restored PDF hash differs from database metadata.');
}
$count=(int)$db->query('SELECT COUNT(*) FROM audit_events WHERE action="fixture_restore"')->fetchColumn();
if ($count!==1) throw new RuntimeException('Audit history lost during restore.');
echo "SQL+PDF restore passed: relationships, financial amounts, transfer status, audit and SHA256 match\n";
