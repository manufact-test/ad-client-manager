<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/finance.php';
require __DIR__.'/../app/workflow.php';
require __DIR__.'/../app/export.php';

function ex_check(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException('Export: '.$message);
}
ex_check(adc_export_safe_cell('=HYPERLINK("https://evil.test")')[0]==="'",'Formula CSV blocked');
foreach (['+SUM(1,1)','-cmd /c','@SUM(1,2)'," \t=1+2", "\n=1+2"] as $attack) {
    ex_check(str_starts_with(adc_export_safe_cell($attack),"'"),'Spreadsheet formula blocked: '.$attack);
}
ex_check(adc_export_safe_cell('-0.01')==='-0.01','Legitimate numeric negative preserved');
ex_check(adc_export_decimal(-1)==='-0.01','Negative cent printed exactly');
ex_check(adc_export_decimal(10)==='0.10','Ten cents printed exactly');

$db=adc_db(['db'=>[
    'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1',
    'name'=>getenv('TEST_DB_NAME')?:'adclients_test',
    'user'=>getenv('TEST_DB_USER')?:'root',
    'password'=>getenv('TEST_DB_PASSWORD')?:'',
]]);
adc_schema($db);
$db->beginTransaction();
try {
    $db->exec("INSERT INTO clients(name,is_demo) VALUES ('=SUM(1,2) demo',1)");
    $demo=(int)$db->lastInsertId();
    $db->exec("INSERT INTO clients(name,is_demo) VALUES ('=HYPERLINK(1,2) real',0)");
    $real=(int)$db->lastInsertId();
    $charge=$db->prepare('INSERT INTO charge_lines(client_id,category,label,amount,currency) VALUES (?,"advertising",?,?,"CHF")');
    $charge->execute([$real,'=1+2','0.10']);
    $charge->execute([$real,'Additional','0.20']);
    $charge->execute([$demo,'Demo value','9999.00']);
    $pay=$db->prepare('INSERT INTO payment_events(client_id,event_type,amount,currency,occurred_on,note) VALUES (?,?,?, "CHF","2026-10-21",?)');
    $pay->execute([$real,'received','0.20','@cmd']);
    $pay->execute([$real,'refund','0.01','+formula']);
    $pay->execute([$demo,'received','9999.00','Fake']);
    $report=adc_export_balances($db);
    $rows=array_values(array_filter($report,fn($row)=>isset($row[0]) && (string)$row[0]===(string)$real));
    ex_check(count($rows)===1,'Real included once in client-currency output');
    $row=$rows[0];
    ex_check($row[3]==='0.30' && $row[7]==='0.20' && $row[8]==='0.01' &&
        $row[9]==='0.19' && $row[10]==='0.11','Exact monetary reconciliation (0.10 + 0.20 - 0.19)');
    ex_check(!array_filter($report,fn($row)=>isset($row[0]) && (string)$row[0]===(string)$demo),
        'Demo NEVER occurs in real-only report');
    $csv=adc_export_encode($report);
    ex_check(str_starts_with($csv,"\xEF\xBB\xBF"),'Excel UTF-8 BOM');
    ex_check(str_contains($csv,"'=HYPERLINK"),'Dangerous client name escaped in CSV');
    ex_check(!str_contains($csv,'9999.00'),'Demo never leaked into financial export');
    $ledger=adc_export_client_ledger($db,$real);
    ex_check(count($ledger)===5,'Two unassigned charges + two unassigned payments included');
    $text=adc_export_encode($ledger);
    ex_check(str_contains($text,"'=1+2"),'Dangerous charge label escaped');
    ex_check(str_contains($text,"'@cmd"),'Dangerous payment note escaped');
    ex_check(str_contains($text,'-0.01'),'Signed refund exported');
    ex_check(!str_contains($text,'9999.00'),'Real client ledger does not include unrelated demo');
    // Late-uploaded PDF retains the older linked billing period in CSV.
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-10-01",1,"prepayment","CHF")')->execute([$real]);
    $eid=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end)
        VALUES (?,"2026-10-01","2026-11-01")')->execute([$eid]);
    $pid=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO document_bundles(client_id,period_id,original_filename,needs_invoice)
        VALUES (?,?,"backdated-test.pdf",1)')->execute([$real,$pid]);
    $did=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO client_entries(client_id,document_id,entry_kind,amount,currency,
        category,created_at) VALUES (?,?,"document","42.00","CHF","advertising",
        "2026-12-12 09:00:00")')->execute([$real,$did]);
    $linkedRows=adc_export_client_ledger($db,$real);
    $pdfRows=array_values(array_filter($linkedRows,static fn($row)=>
        isset($row[8]) && str_contains((string)$row[8],"backdated-test.pdf")));
    ex_check(count($pdfRows)===1
        && $pdfRows[0][3]===$eid && $pdfRows[0][4]===$pid
        && $pdfRows[0][5]==="2026-10-01"
        && $pdfRows[0][7]==="2026-12-12 09:00:00",
        'Client CSV shows historic period and upload date independently');

    // Contract reference rates are not payments or automatic charges.
    $db->prepare('INSERT INTO engagement_terms
        (engagement_id,effective_on,started_on,billing_day,payment_mode,currency,quoted_amount)
        VALUES (?,"2026-10-01","2026-10-01",1,"prepayment","CHF",NULL)')
        ->execute([$eid]);
    $db->prepare('INSERT INTO engagement_terms
        (engagement_id,effective_on,started_on,billing_day,payment_mode,currency,quoted_amount)
        VALUES (?,"2026-11-20","2026-11-20",20,"postpayment","EUR","1200.00")')
        ->execute([$eid]);
    $termRows=array_values(array_filter(adc_export_client_ledger($db,$real),
        static fn($row)=>($row[8]??'')==='Условия договора'));
    ex_check(count($termRows)===2,'Both contract versions appear in CSV');
    ex_check($termRows[0][7]==='2026-10-01' &&
        str_contains((string)$termRows[0][10],'Старая сумма неизвестна') &&
        $termRows[0][11]==='','Unknown prior rate is not invented as billable charge');
    ex_check($termRows[1][7]==='2026-11-20' &&
        str_contains((string)$termRows[1][10],'1200.00') &&
        str_contains((string)$termRows[1][10],'Постоплата') &&
        $termRows[1][11]==='' && $termRows[1][12]==='EUR',
        'New contract rate exported as reference only');
    $demoLedger=adc_export_encode(adc_export_client_ledger($db,$demo));
    ex_check(str_contains($demoLedger,'9999.00') && str_contains($demoLedger,'Демо'),
        'Explicitly scoped demo ledger allowed, clearly labeled');
    try {adc_export_client_ledger($db,99999999); throw new RuntimeException('Unknown client allowed');}
    catch(RuntimeException $e){ex_check(str_contains($e->getMessage(),'Клиент не найден'),'Unknown client is rejected');}
} finally {
    $db->rollBack();
}
echo "Exports passed: real-only, demo isolation, unassigned historical entries, exact cents, CSV formula defenses\n";
