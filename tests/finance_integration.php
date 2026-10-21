<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/finance.php';
require __DIR__.'/../app/workflow.php';
function fin_assert(bool $ok,string $what):void {if (!$ok) throw new RuntimeException('Finance: '.$what);}
$cases=['0.10'=>10,'0.20'=>20,'0.30'=>30,'123456789012.99'=>12345678901299,'-0.01'=>-1,'0'=>0];
foreach ($cases as $k=>$v) fin_assert(adc_fin_minor((string)$k)===$v,'Minor units '.$k);
fin_assert(adc_fin_add(adc_fin_minor('0.10'),adc_fin_minor('0.20'))===30,'0.1+0.2 precise');
fin_assert(adc_fin_format(12345678901299,'BYN')==='123 456 789 012,99 BYN','Nonfloat formatting');
try {adc_fin_minor('0.001');throw new RuntimeException('Accepts excessive precision');}
catch (InvalidArgumentException $expected) {}
$db=adc_db(['db'=>[
 'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1','name'=>getenv('TEST_DB_NAME')?:'adclients_test',
 'user'=>getenv('TEST_DB_USER')?:'root','password'=>getenv('TEST_DB_PASSWORD')?:'',
]]);
adc_schema($db);
$db->beginTransaction();
try {
    $db->exec('INSERT INTO clients(name,is_demo) VALUES ("Finance real CHF",0)');
    $real=(int)$db->lastInsertId();
    $db->exec('INSERT INTO clients(name,is_demo) VALUES ("Finance demo CHF",1)');
    $demo=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
       VALUES (?,"2026-10-01",1,"postpayment","CHF")')->execute([$real]);
    $eng=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end)
       VALUES (?,"2026-10-01","2026-11-01")')->execute([$eng]);
    $period=(int)$db->lastInsertId();
    $charges=$db->prepare('INSERT INTO charge_lines(client_id,period_id,category,label,amount,currency)
       VALUES (?,? ,?,?,?,"CHF")');
    $charges->execute([$real,$period,'advertising','Fraction A','0.10']);
    $charges->execute([$real,$period,'advertising','Fraction B','0.20']);
    $charges->execute([$real,$period,'development','Landing','10.01']);
    $charges->execute([$real,$period,'other','Correction','-0.01']);
    $charges->execute([$demo,null,'advertising','Demo excluded','999999.00']);
    $pay=$db->prepare('INSERT INTO payment_events(client_id,period_id,event_type,amount,currency,occurred_on)
       VALUES (?,?,?,?,"CHF",?)');
    $pay->execute([$real,$period,'received','0.10','2026-10-10']);
    $pay->execute([$real,$period,'received','0.20','2026-10-11']);
    $pay->execute([$real,$period,'refund','0.01','2026-10-12']);
    $pay->execute([$demo,null,'received','999999.00','2026-10-12']);
    $balanceRows=adc_w_client_balances($db,$real);
    fin_assert(count($balanceRows)===1 && $balanceRows[0]['currency']==='CHF' &&
        $balanceRows[0]['billed']===1030 && $balanceRows[0]['net']===29 &&
        $balanceRows[0]['balance']===1001,'Client drawer reconciliation equals analytics');
    $demoRows=adc_w_client_balances($db,$demo);
    fin_assert(count($demoRows)===1 && $demoRows[0]['billed']===99999900,
        'Explicit demo drawer has its own exact balance');
    $g=adc_fin_groups($db);
    fin_assert($g['CHF']['advertising']===30,'Demo isolated, 30 cents advertising');
    fin_assert($g['CHF']['development']===1001,'Development 1001 cents');
    fin_assert($g['CHF']['other']===-1,'Signed correction');
    fin_assert($g['CHF']['received']===30 && $g['CHF']['refund']===1,'Refund separated');
    $m=adc_fin_months($db);
    fin_assert($m['CHF|2026-10']['billed']===1030,'Monthly billed 10.30');
    fin_assert($m['CHF|2026-10']['advertising']===30 && $m['CHF|2026-10']['development']===1001 && $m['CHF|2026-10']['other']===-1,'Monthly category split exact');
    fin_assert($m['CHF|2026-10']['paid']===29,'Monthly received net 0.29');
    $html=adc_fin_render($db);
    fin_assert(substr_count($html,'class="table-scroll"')===3,
        'All three mobile analytics tables are horizontally scrollable');
    fin_assert(substr_count($html,'tabindex="0"')===3,
        'Mobile finance tables keyboard scrollable');
    fin_assert(str_contains($html,'10,30 CHF') && str_contains($html,'0,29 CHF') &&
      str_contains($html,'10,01 CHF'),'Financial page renders exact cents');
    fin_assert(!str_contains($html,'Finance demo CHF'),'Demo not in LTV');
    fin_assert(str_contains($html,'Finance real CHF'),'Real client in LTV');
    // A Hostinger test account may contain ONLY a demo client. Rendering
    // analytics must not throw an SQL error or produce HTTP 500 in that case.
    $db->exec('SAVEPOINT analytics_demo_only');
    $db->prepare('UPDATE clients SET is_demo=1 WHERE id=?')->execute([$real]);
    $demoOnly=adc_fin_render($db);
    fin_assert(str_contains($demoOnly,'Аналитика') && str_contains($demoOnly,'Клиентская база'),
        'Demo-only analytics renders without SQL exceptions');
    $db->exec('ROLLBACK TO SAVEPOINT analytics_demo_only');
} finally {$db->rollBack();}
echo "Financial analytics passed: cents, refunds, grouped currencies, source client exclusion, LTV and months\n";
