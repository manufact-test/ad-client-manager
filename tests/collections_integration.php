<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/finance.php';
require __DIR__.'/../app/workflow.php';
require __DIR__.'/../app/ledger.php';
require __DIR__.'/../app/collections.php';
require __DIR__.'/../app/export.php';

function payments_check(bool $ok,string $description):void {
    if(!$ok)throw new RuntimeException('Payments desk: '.$description);
}
$db=adc_db(['db'=>[
    'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1',
    'name'=>getenv('TEST_DB_NAME')?:'adclients_test',
    'user'=>getenv('TEST_DB_USER')?:'root',
    'password'=>getenv('TEST_DB_PASSWORD')?:''
]]);
adc_schema($db);
$db->beginTransaction();
try {
    $tag='Synthetic collection '.bin2hex(random_bytes(5));
    $clients=$db->prepare('INSERT INTO clients(name,is_demo) VALUES (?,?)');
    $clients->execute([$tag.' real',0]);$real=(int)$db->lastInsertId();
    $clients->execute([$tag.' demo',1]);$demo=(int)$db->lastInsertId();
    $eng=$db->prepare('INSERT INTO engagements
        (client_id,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-08-01",25,"prepayment","CHF")');
    $eng->execute([$real]);$engId=(int)$db->lastInsertId();
    $eng->execute([$demo]);
    $entry=$db->prepare('INSERT INTO client_entries(client_id,entry_kind,
        amount,currency,category,sent_at,note)
        VALUES (?,"card",?,"CHF","advertising",?,?)');
    $entry->execute([$real,'100.10','2026-09-15 10:00:00','=SUM(1,2)']);
    $pastId=(int)$db->lastInsertId();
    $entry->execute([$real,'50.05','2026-10-29 09:00:00','future']);
    $futureId=(int)$db->lastInsertId();
    $entry->execute([$demo,'99999.00','2026-09-15 09:00:00','demo']);
    $items=adc_collections_open($db,'2026-10-29');
    $mine=array_values(array_filter($items,static fn($x)=>(int)$x['client_id']===$real));
    payments_check(count($mine)===2,'All real open entries in same client');
    payments_check(count(array_filter($items,static fn($x)=>(int)$x['client_id']===$demo))===0,
        'Demo is never mixed into production payment queue');
    $past=array_values(array_filter($mine,static fn($x)=>(int)$x['id']===$pastId))[0];
    $future=array_values(array_filter($mine,static fn($x)=>(int)$x['id']===$futureId))[0];
    payments_check($past['due_date']==='2026-09-25' && $past['payment_state']==='overdue',
        'Past billing anchor is overdue after three day grace');
    payments_check($future['due_date']==='2026-11-25' &&
        $future['payment_state']==='upcoming',
        'Entry sent outside October grace window belongs to next anchor');
    payments_check($past['grace_end']==='2026-09-28','Grace window is explicit');
    $filter=adc_collections_filters(['scope'=>'overdue','currency'=>'CHF','q'=>$tag]);
    $late=adc_collections_filter_rows($items,$filter);
    payments_check(count($late)===1 && (int)$late[0]['id']===$pastId,
        'Combined search, currency and overdue filters work');
    $sum=adc_collections_sums($mine);
    payments_check($sum['total']['CHF']===15015 && $sum['overdue']['CHF']===10010,
        'Exact cents, not binary floats, and overdue subtotal');
    // A terms version changes subsequent deadlines without rewriting old ones.
    $db->prepare('INSERT INTO engagement_terms
        (engagement_id,effective_on,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-10-20","2026-10-20",10,"prepayment","CHF")')->execute([$engId]);
    $versioned=adc_collections_open($db,'2026-10-29');
    $after=array_values(array_filter($versioned,
        static fn($x)=>(int)$x['id']===$futureId))[0];
    $before=array_values(array_filter($versioned,
        static fn($x)=>(int)$x['id']===$pastId))[0];
    payments_check($after['due_date']==='2026-11-10' && $before['due_date']==='2026-09-25',
        'Dated billing terms only apply to entries sent after transition');
    $csv=adc_export_encode(adc_export_payments($db,'2026-10-29'));
    payments_check(str_contains($csv,$tag.' real') && !str_contains($csv,$tag.' demo'),
        'Payments export contains real clients only');
    payments_check(str_contains($csv,"'=SUM(1,2)"),
        'CSV comment with spreadsheet formula is escaped');
    $db->prepare('UPDATE client_entries SET received_at="2026-10-29 16:00:00"
        WHERE id=?')->execute([$pastId]);
    $left=adc_collections_open($db,'2026-10-29');
    payments_check(!count(array_filter($left,
        static fn($x)=>(int)$x['id']===$pastId)),
        'Confirmed receipt drops out of collection work queue immediately');
    payments_check(adc_collections_filters(['scope'=>'invalid',
        'currency'=>'not-real','page'=>'-99'])['page']===1,
        'Invalid page is ignored');
} finally {
    $db->rollBack();
}
echo "Payments desk passed: real-only, due dates, effective terms, currency,
    exact cents, overdue filters, receipt lifecycle and CSV safety\n";
