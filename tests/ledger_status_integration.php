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
    status_check(str_contains($early[0],'Ждём оплату')&&!str_contains($early[0],'просрочка'),
        'October 20 is not overdue for October 25 even though sent October 15');
    $preparationWindow=adc_ledger_client_status($db,$client,'2026-10-22');
    status_check(str_contains($preparationWindow[0],'Ждём оплату'),
        'Document sent ten days early must suppress duplicate preparation reminder');
    $withinGrace=adc_ledger_client_status($db,$client,'2026-10-28');
    status_check(str_contains($withinGrace[0],'Ждём оплату')&&!str_contains($withinGrace[0],'просрочка'),
        'October 28 is within agreed three-day tolerance');
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
    status_check(str_contains($priority[0],'Отправить документы'),
        'Unsent second month document outranks past-due payment');

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
} finally {
    if($db->inTransaction())$db->rollBack();
}
echo "Contractual payment-day ledger tests passed\n";
