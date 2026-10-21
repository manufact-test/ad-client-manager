<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/finance.php';
require __DIR__.'/../app/workflow.php';
require __DIR__.'/../app/import.php';
function imp_assert(bool $yes,string $label):void { if (!$yes) throw new RuntimeException('CSV import: '.$label); }
function imp_csv(array $records):string {
    $f=fopen('php://temp','w+');
    fputcsv($f,adc_import_headers(),',','"','');
    foreach ($records as $r) fputcsv($f,$r,',','"','');
    rewind($f);
    $data=stream_get_contents($f);
    fclose($f);
    return $data;
}
function imp_row(string $external,string $client,bool $demo,string $from,string $to,
    string $kind,string $label,string $billed,string $received,string $refunded):array {
    return [$external,$client,$demo?'1':'0','2026-10-01','31','prepayment','CHF',
        $from,$to,$kind,$label,$billed,$received,$refunded,
        $received!==''||$refunded!==''?'2026-10-10':'','','Fictional Ads','inbound'];
}
$name='Imported Fictional '.bin2hex(random_bytes(5));
$records=[
    imp_row('client-real',$name,false,'2026-10-01','2026-11-01','advertising','Ad one','0.10','0.10',''),
    imp_row('client-real',$name,false,'2026-10-01','2026-11-01','development','Landing','0.20','0.20','0.01'),
    imp_row('client-demo','Synthetic demo CSV',true,'2026-10-01','2026-11-01','advertising','Demo only','999999.00','','')
];
$path=tempnam(sys_get_temp_dir(),'adc-import-');
try {
    file_put_contents($path,imp_csv($records));
    $data=adc_import_parse($path);
    imp_assert($data['client_count']===2 && $data['period_count']===2 && $data['row_count']===3,
        'Preview recognizes client groups and shared periods');
    $db=adc_db(['db'=>[
        'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1',
        'name'=>getenv('TEST_DB_NAME')?:'adclients_test',
        'user'=>getenv('TEST_DB_USER')?:'root',
        'password'=>getenv('TEST_DB_PASSWORD')?:'',
    ]]);
    adc_schema($db);
    $email='fixture-import-'.bin2hex(random_bytes(5)).'@example.test';
    $db->prepare('INSERT INTO users(email,display_name,password_hash,role)
       VALUES (?,"Fixture importer","irrelevant","director")')->execute([$email]);
    $actor=(int)$db->lastInsertId();
    adc_import_apply($db,$data,$actor);
    imp_assert((int)$db->query('SELECT COUNT(*) FROM clients WHERE name='.$db->quote($name))->fetchColumn()===1,
        'Committed fictional real client exactly once');
    $stmt=$db->prepare('SELECT id FROM clients WHERE name=?');
    $stmt->execute([$name]);
    $cid=(int)$stmt->fetchColumn();
    $periods=$db->query("SELECT COUNT(*) FROM billing_periods b JOIN engagements e ON e.id=b.engagement_id WHERE e.client_id=$cid")->fetchColumn();
    imp_assert((int)$periods===1,'Two lines attach to one period');
    $charges=$db->query("SELECT SUM(amount) FROM charge_lines WHERE client_id=$cid")->fetchColumn();
    imp_assert((string)$charges==='0.30','Billed 0.10+0.20 accurate');
    $money=$db->query("SELECT SUM(CASE WHEN event_type='received' THEN amount ELSE -amount END) FROM payment_events WHERE client_id=$cid")->fetchColumn();
    imp_assert((string)$money==='0.29','Net actual payment 0.29');
    $html=adc_fin_render($db);
    imp_assert(!str_contains($html,'Synthetic demo CSV'),'Imported demo excluded from LTV');
    imp_assert(str_contains($html,$name),'Imported real client included in LTV');
    try {adc_import_apply($db,$data,$actor);throw new RuntimeException('Accepted duplicate hash');}
    catch (RuntimeException $e) {
        imp_assert(str_contains($e->getMessage(),'уже был импортирован'),'Idempotent duplicate blocked');
    }
    imp_assert((int)$db->query("SELECT COUNT(*) FROM clients WHERE name=".$db->quote($name))->fetchColumn()===1,
        'Duplicate does not add a second copy');
    $bad=$records;
    $bad[1][1]='Contradictory client identity';
    file_put_contents($path,imp_csv($bad));
    try {adc_import_parse($path);throw new RuntimeException('Accepted conflicting client rows');}
    catch (RuntimeException $e) {
        imp_assert(str_contains($e->getMessage(),'различаются'),'Conflicting same-client metadata rejected');
    }
    $bad=$records;
    $bad[1][7]='2026-10-20';
    $bad[1][8]='2026-11-21';
    file_put_contents($path,imp_csv($bad));
    try {adc_import_parse($path);throw new RuntimeException('Accepted overlap');}
    catch (RuntimeException $e) {
        imp_assert(str_contains($e->getMessage(),'пересекающиеся'),'Overlapping periods rejected');
    }
    $bad=$records;
    $bad[1][2]='9';
    file_put_contents($path,imp_csv($bad));
    try {adc_import_parse($path);throw new RuntimeException('Accepted illegal demo flag');}
    catch (RuntimeException $e) {
        imp_assert(str_contains($e->getMessage(),'данные клиента'),'Malformed fields blocked');
    }
} finally {unlink($path);}
echo "CSV historical import passed: grouped clients/periods, pennies, refunds, demo, idempotency, invalid inputs\n";
