<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/finance.php';
require __DIR__.'/../app/workflow.php';
require __DIR__.'/../app/ledger.php';

function status_check(bool $ok,string $message):void {
    if(!$ok) throw new RuntimeException('Ledger due dates: '.$message);
}
status_check(adc_ledger_due_date('2026-10-15 10:00:00',25)==='2026-10-25',
    'Early invoice belongs to Oct 25, not three days after sending');
status_check(adc_ledger_due_date('2026-10-27 10:00:00',25)==='2026-10-25',
    'Invoice sent two days after billing anchor stays in the same month');
status_check(adc_ledger_due_date('2026-10-29 10:00:00',25)==='2026-11-25',
    'Invoice sent outside grace window belongs to next cycle');
status_check(adc_ledger_due_date('2026-02-28 10:00:00',31)==='2026-02-28',
    '31st clamps to last day of February');
status_check(adc_ledger_due_date('2028-02-29 10:00:00',31)==='2028-02-29',
    'Leap year retains Feb 29 anchor');
status_check(adc_ledger_preparation_due('2026-10-30',1)==='2026-11-01',
    'Next-month anchor is found from October');
status_check(adc_ledger_preparation_due('2026-10-20',1)===null,
    'Old month does not cause perpetual preparation reminder');
status_check(adc_ledger_preparation_due('2026-11-27',31)==='2026-11-30',
    'End-of-month anchor clamps to November 30');

$db=adc_db(['db'=>[
  'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1',
  'name'=>getenv('TEST_DB_NAME')?:'adclients_test',
  'user'=>getenv('TEST_DB_USER')?:'root',
  'password'=>getenv('TEST_DB_PASSWORD')?:'',
]]);
adc_schema($db);
$db->beginTransaction();
try {
    $db->exec('INSERT INTO clients(name,is_demo) VALUES ("CI contractual payment status",1)');
    $client=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-09-25",25,"prepayment","BYN")')->execute([$client]);
    $eng=(int)$db->lastInsertId();
    $stmt=$db->prepare('INSERT INTO client_entries(client_id,entry_kind,amount,currency,category,
        sent_at,created_at) VALUES (?,"card","100.00","BYN","advertising",?,?)');
    $stmt->execute([$client,'2026-10-15 10:00:00','2026-10-15 12:00:00']);
    $entry=(int)$db->lastInsertId();
    $early=adc_ledger_client_status($db,$client,'2026-10-20');
    status_check(str_contains($early[0],'Ждём оплату')&&!str_contains($early[0],'просрочка')&&$early[1]==='danger',
        'Waiting payment is red before the due date too');
    $preparationWindow=adc_ledger_client_status($db,$client,'2026-10-22');
    status_check(str_contains($preparationWindow[0],'Ждём оплату')&&$preparationWindow[1]==='danger',
        'Document sent ten days early suppresses duplicate preparation reminder while payment stays red');
    $withinGrace=adc_ledger_client_status($db,$client,'2026-10-28');
    status_check(str_contains($withinGrace[0],'Ждём оплату')&&!str_contains($withinGrace[0],'просрочка')&&$withinGrace[1]==='danger',
        'Payment remains red during the three-day grace period');
    $late=adc_ledger_client_status($db,$client,'2026-10-29');
    status_check(str_contains($late[0],'просрочка')&&$late[1]==='danger',
        'October 29 is overdue');
    $db->prepare('UPDATE client_entries SET received_at="2026-10-29 12:00:00" WHERE id=?')->execute([$entry]);
    $paid=adc_ledger_client_status($db,$client,'2026-10-29');
    status_check($paid[0]==='Оплачено','Full receipt hides overdue status');
    $db->prepare('UPDATE client_entries SET received_at=NULL WHERE id=?')->execute([$entry]);
    $again=adc_ledger_client_status($db,$client,'2026-10-29');
    status_check(str_contains($again[0],'просрочка'),'Undo receipt restores overdue');
    $db->prepare('INSERT INTO client_entries(client_id,entry_kind,amount,currency,category,
        created_at) VALUES (?,"document","200.00","BYN","advertising","2026-10-29 08:00:00")')->execute([$client]);
    $priority=adc_ledger_client_status($db,$client,'2026-10-29');
    status_check(str_contains($priority[0],'Отправить документы')&&$priority[1]==='warning',
        'Document preparation remains gray and outranks past-due payment');

    // Scheduled handovers are read-only and activate exactly on their date.
    $db->exec('INSERT INTO clients(name,is_demo) VALUES ("CI scheduled handover",1)');
    $scheduledClient=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO document_bundles(client_id,original_filename,storage_key,transfer_due_on)
        VALUES (?,"future.pdf",?,"2026-11-02")')
        ->execute([$scheduledClient,str_repeat('f',48).'.pdf']);
    $scheduledDoc=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO client_entries(client_id,document_id,entry_kind,category)
        VALUES (?,?,"document","advertising")')->execute([$scheduledClient,$scheduledDoc]);
    $directorStatus=adc_ledger_client_status($db,$scheduledClient,'2026-10-27',['role'=>'director']);
    $employeeEarly=adc_ledger_client_status($db,$scheduledClient,'2026-10-27',['role'=>'employee']);
    $employeeBefore=adc_ledger_client_status($db,$scheduledClient,'2026-11-01',['role'=>'employee']);
    $employeeDue=adc_ledger_client_status($db,$scheduledClient,'2026-11-02',['role'=>'employee']);
    $employeeLate=adc_ledger_client_status($db,$scheduledClient,'2026-11-03',['role'=>'employee']);
    status_check(str_contains($directorStatus[0],'Отправить документы') &&
        $directorStatus[1]==='warning','Director can see unsent future PDF');
    status_check($employeeEarly[0]==='Выполнено' && $employeeBefore[0]==='Выполнено',
        'Staff do not see queued handover until due date');
    status_check(str_contains($employeeDue[0],'Отправить документы') &&
        str_contains($employeeLate[0],'Отправить документы') &&
        $employeeDue[1]==='warning','Staff see the send badge on due date and afterwards');
    status_check(!adc_doc_visible_to_staff(['transfer_due_on'=>'2026-11-02','transferred_at'=>null],
        '2026-11-01') && adc_doc_visible_to_staff(
        ['transfer_due_on'=>'2026-11-02','transferred_at'=>null],'2026-11-02') &&
        adc_doc_visible_to_staff(['transfer_due_on'=>null,'transferred_at'=>null],'2026-10-27'),
        'The PDF activation policy matches the send status, unscheduled PDFs remain immediate');
    $db->prepare('UPDATE document_bundles SET transfer_due_on=NULL WHERE id=?')->execute([$scheduledDoc]);
    status_check(str_contains(adc_ledger_client_status($db,$scheduledClient,'2026-10-27',
        ['role'=>'employee'])[0],'Отправить документы'),'Clearing date releases PDF immediately');

    $db->prepare('INSERT INTO engagement_terms(engagement_id,effective_on,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-11-01","2026-11-01",5,"prepayment","BYN")')->execute([$eng]);
    $stmt->execute([$client,'2026-11-01 10:00:00','2026-11-05 12:00:00']);
    $db->prepare('UPDATE client_entries SET is_void=1 WHERE sent_at="2026-10-15 10:00:00" OR sent_at IS NULL')->execute();
    $newGrace=adc_ledger_client_status($db,$client,'2026-11-08');
    status_check(!str_contains($newGrace[0],'просрочка'),'Latest terms retain grace around new 5th');
    $newOverdue=adc_ledger_client_status($db,$client,'2026-11-09');
    status_check(str_contains($newOverdue[0],'просрочка'),
        'New contractual billing day applies only to the newer request');
    $db->exec('INSERT INTO clients(name,is_demo) VALUES ("CI preparation date window",1)');
    $newClient=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-10-01",1,"prepayment","BYN")')->execute([$newClient]);
    status_check(adc_ledger_client_status($db,$newClient,'2026-10-01')[0]==='Выполнено',
        'Newly activated collaboration is neutral on its first contractual billing date');
    status_check(adc_ledger_client_status($db,$newClient,'2026-10-30')[0]==='Подготовить документы',
        'November 1 billing anchor creates preparation reminder from October');
    status_check(adc_ledger_client_status($db,$newClient,'2026-10-20')[0]==='Выполнено',
        'Preparation reminder does not persist all month');
    // A future effective change cannot move an earlier document deadline.
    $futureEng=(int)$db->query("SELECT id FROM engagements WHERE client_id=$newClient")->fetchColumn();
    $db->prepare('INSERT INTO engagement_terms(engagement_id,effective_on,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-10-01","2026-10-01",1,"prepayment","BYN")')->execute([$futureEng]);
    $db->prepare('INSERT INTO engagement_terms(engagement_id,effective_on,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-12-10","2026-12-10",10,"postpayment","EUR")')->execute([$futureEng]);
    $db->prepare('UPDATE engagements SET billing_day=10,payment_mode="postpayment",currency="EUR" WHERE id=?')
        ->execute([$futureEng]);
    $termBefore=adc_w_effective_terms($db,["id"=>$futureEng,"started_on"=>"2026-10-01",
        "billing_day"=>10,"payment_mode"=>"postpayment","currency"=>"EUR"],"2026-11-29");
    status_check($termBefore['billing_day']==1 && $termBefore['currency']==='BYN',
        'Scheduled new terms do not affect earlier October-November billing');
    status_check(adc_ledger_client_status($db,$newClient,'2026-11-29')[0]==='Подготовить документы',
        'November 29 still prepares for original December 1 billing date');
    status_check(adc_ledger_client_status($db,$newClient,'2026-12-07')[0]==='Выполнено',
        'New billing day is not effective before December 10');
    $termAfter=adc_w_effective_terms($db,["id"=>$futureEng,"started_on"=>"2026-10-01",
        "billing_day"=>10,"payment_mode"=>"postpayment","currency"=>"EUR"],"2026-12-10");
    status_check($termAfter['billing_day']==10 && $termAfter['payment_mode']==='postpayment',
        'New conditions become active on their actual effective date');
    $db->exec('INSERT INTO clients(name,is_demo) VALUES ("CI initial billing window",1)');
    $laterClient=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
        VALUES (?,"2026-10-20",21,"prepayment","BYN")')->execute([$laterClient]);
    status_check(adc_ledger_client_status($db,$laterClient,'2026-10-22')[0]==='Выполнено',
        'Starting the day before billing date does not demand new paperwork');
    status_check(adc_ledger_client_status($db,$laterClient,'2026-11-18')[0]==='Подготовить документы',
        'First reminder is for the next monthly billing cycle');
} finally {
    if($db->inTransaction())$db->rollBack();
}
echo "Contractual payment-day ledger tests passed\n";
