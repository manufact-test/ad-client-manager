<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/finance.php';
require __DIR__.'/../app/workflow.php';
require __DIR__.'/../app/quick_import.php';
require __DIR__.'/../app/export.php';

function quick_assert(bool $condition,string $label):void{
    if(!$condition)throw new RuntimeException('Quick client import: '.$label);
}
function quick_csv(array $rows):string{
    $f=fopen('php://temp','w+');
    foreach($rows as $row)fputcsv($f,$row,',','"','');
    rewind($f);$s=stream_get_contents($f);fclose($f);return $s;
}
$filename=tempnam(sys_get_temp_dir(),'adclients-quick-');
$token=bin2hex(random_bytes(5));
try {
    $sample=adc_quick_import_template();
    quick_assert(count($sample[0])===14,'Template has exactly 14 fields');
    file_put_contents($filename,quick_csv($sample));
    $parsed=adc_quick_import_parse($filename);
    quick_assert($parsed['count']===2 && $parsed['real']===1 && $parsed['demo']===1,
        'Template can be parsed without private input');
    $base=$sample;
    $base[1][0]='Fictional new client '.$token;
    $base[2][0]='Fictional demo '.$token;
    $base[1][5]='1234.56';
    $base[1][10]='Previous fictional client';
    $base[1][11]='1';$base[1][12]='1';
    file_put_contents($filename,quick_csv($base));
    $parsed=adc_quick_import_parse($filename);
    $db=adc_db(['db'=>[
        'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1',
        'name'=>getenv('TEST_DB_NAME')?:'adclients_test',
        'user'=>getenv('TEST_DB_USER')?:'root',
        'password'=>getenv('TEST_DB_PASSWORD')?:''
    ]]);
    adc_schema($db);
    $email='quick-import-'.$token.'@example.test';
    $db->prepare('INSERT INTO users(email,display_name,password_hash,role)
        VALUES (?,"Synthetic Director","unused","director")')->execute([$email]);
    $actor=(int)$db->lastInsertId();
    adc_quick_import_assert_new($db,$parsed['rows']);
    adc_quick_import_apply($db,$parsed,$actor);
    $stmt=$db->prepare('SELECT c.id,c.is_demo,c.requires_acts,c.requires_specs,
        c.previous_name_text,e.billing_day,e.currency,e.quoted_amount,e.started_on,e.status
        FROM clients c JOIN engagements e ON e.client_id=c.id
        WHERE c.name=?');
    $stmt->execute([$base[1][0]]);
    $saved=$stmt->fetch();
    quick_assert($saved && (int)$saved['is_demo']===0 &&
        (int)$saved['requires_acts']===1 &&
        (int)$saved['requires_specs']===1 &&
        $saved['previous_name_text']==='Previous fictional client' &&
        $saved['started_on']==='2026-10-01' &&
        (int)$saved['billing_day']===1 &&
        $saved['currency']==='BYN' &&
        $saved['quoted_amount']==='1234.56' &&
        $saved['status']==='active',
        'Real client fields and initial active engagement persisted accurately');
    $realId=(int)$saved['id'];
    quick_assert((int)$db->query('SELECT COUNT(*) FROM charge_lines WHERE client_id='.$realId)->fetchColumn()===0 &&
        (int)$db->query('SELECT COUNT(*) FROM payment_events WHERE client_id='.$realId)->fetchColumn()===0 &&
        (int)$db->query('SELECT COUNT(*) FROM client_entries WHERE client_id='.$realId)->fetchColumn()===0,
        'Monthly quote never creates a charge, payment or document');
    $stmt->execute([$base[2][0]]);
    $demo=$stmt->fetch();
    quick_assert($demo && (int)$demo['is_demo']===1 &&
        $demo['quoted_amount']==='0.00','Demo flag and unknown quote remain separate');
    $audit=$db->prepare('SELECT COUNT(*) FROM audit_events WHERE action="import_quick_clients"
        AND JSON_UNQUOTE(JSON_EXTRACT(details_json,"$.sha256"))=?');
    $audit->execute([$parsed['sha256']]);
    quick_assert((int)$audit->fetchColumn()===1,'One full-batch audit event recorded');
    try{adc_quick_import_apply($db,$parsed,$actor);throw new RuntimeException('Duplicate imported');}
    catch(RuntimeException $ex){quick_assert(str_contains($ex->getMessage(),'уже импортирован'),'Same CSV hash blocked');}
    // A modified file with one existing and one new client must reject
    // everything, including the supposedly fresh row.
    $bad=$base;
    $bad[2][0]='Fresh but must roll back '.$token;
    file_put_contents($filename,quick_csv($bad));
    $modified=adc_quick_import_parse($filename);
    try{adc_quick_import_apply($db,$modified,$actor);throw new RuntimeException('Mixed duplicate imported');}
    catch(RuntimeException $ex){quick_assert(str_contains($ex->getMessage(),'уже есть'),'Existing name rejected');}
    quick_assert((int)$db->query('SELECT COUNT(*) FROM clients WHERE name='.$db->quote($bad[2][0]))->fetchColumn()===0,
        'Batch is atomic on duplicate existing name');
    $duplicate=$base;
    $duplicate[2][0]=$duplicate[1][0];
    file_put_contents($filename,quick_csv($duplicate));
    // In distinct demo categories the same name is allowed. Make both real to
    // prove name-based CSV duplicates are rejected inside one cohort.
    $duplicate[2][1]='0';
    file_put_contents($filename,quick_csv($duplicate));
    try{adc_quick_import_parse($filename);throw new RuntimeException('CSV internal duplicate imported');}
    catch(RuntimeException $ex){quick_assert(str_contains($ex->getMessage(),'Повторяющийся'),'Duplicate row caught');}
    $invalid=$base;
    $invalid[2][11]='2';
    file_put_contents($filename,quick_csv($invalid));
    try{adc_quick_import_parse($filename);throw new RuntimeException('Invalid booleans imported');}
    catch(RuntimeException $ex){quick_assert(str_contains($ex->getMessage(),'должно быть 0 или 1'),'Invalid flags caught');}
    $invalid=$base;
    $invalid[2][2]='2026-02-30';
    file_put_contents($filename,quick_csv($invalid));
    try{adc_quick_import_parse($filename);throw new RuntimeException('Impossible date accepted');}
    catch(Throwable $ex){quick_assert($ex instanceof InvalidArgumentException ||
        $ex instanceof RuntimeException,'Impossible date refused');}
    $invalid=$base;$invalid[2][5]='-17.00';
    file_put_contents($filename,quick_csv($invalid));
    try{adc_quick_import_parse($filename);throw new RuntimeException('Negative reference amount allowed');}
    catch(Throwable $ex){quick_assert($ex instanceof InvalidArgumentException ||
        $ex instanceof RuntimeException,'Negative monthly quote refused');}
    quick_assert(count(adc_quick_import_headers())===count(adc_quick_import_template()[0]),
        'Exported template columns are consistent');
    quick_assert(str_contains(adc_quick_import_panel(['role'=>'director']),
        'preview_quick_clients') &&
        !str_contains(adc_quick_import_panel(['role'=>'employee']),
        'preview_quick_clients'),'Director-only UI');
    $_SESSION['quick_clients_preview']=['sha256'=>$parsed['sha256'],
        'count'=>2,'real'=>1,'demo'=>1,'names'=>['Fixture One','Fixture Two']];
    quick_assert(str_contains(adc_quick_import_panel(['role'=>'director']),
        'id="quick-import" open') &&
        str_contains(adc_quick_import_panel(['role'=>'director']),
            'commit_quick_clients'),'After preview, next confirmation is expanded');
    unset($_SESSION['quick_clients_preview']);
    echo "Quick onboarding passed: 2-step CSV, full client cards, no fake payments, demo, atomic duplicates and audit\n";
} finally {
    if(is_file($filename))unlink($filename);
}
