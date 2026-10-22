<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/workflow.php';
require __DIR__.'/../app/reminders.php';
require __DIR__.'/../app/team_tasks.php';

function require_reminder(bool $result,string $label): void {
    if (!$result) throw new RuntimeException('Reminder regression: '.$label);
}
$info=['db'=>[
    'host'=>getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'name'=>getenv('TEST_DB_NAME') ?: 'adclients_test',
    'user'=>getenv('TEST_DB_USER') ?: 'root',
    'password'=>getenv('TEST_DB_PASSWORD') ?: '',
]];
$db=adc_db($info);
adc_schema($db);
$db->beginTransaction();
try {
    $stmt=$db->prepare('INSERT INTO clients(name,requires_acts,requires_specs,is_demo)
        VALUES ("Synthetic reminder fixture",1,1,1)');
    $stmt->execute();
    $clientId=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
        VALUES (?, "2026-09-29",31,"prepayment","BYN")')->execute([$clientId]);
    $engagementId=(int)$db->lastInsertId();
    $before=(int)$db->query('SELECT COUNT(*) FROM personal_tasks')->fetchColumn();
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    $byDate=static fn($date,$kind) => count(array_filter($events,
        static fn($row)=>$row['date']===$date && $row['kind']===$kind))===1;
    require_reminder($byDate('2026-10-31','invoice'),'Invoice 31 October');
    require_reminder($byDate('2026-10-31','spec'),'Specification 31 October');
    require_reminder($byDate('2026-10-31','act'),'Act last calendar day 31 October');
    require_reminder($byDate('2026-11-30','invoice'),'Anchor 31 clamps to 30 November');
    require_reminder($byDate('2026-11-30','act'),'Act last calendar day 30 November');
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='contract'))===1,
        'Single missing contract reminder');
    require_reminder((int)$db->query('SELECT COUNT(*) FROM personal_tasks')->fetchColumn()===$before,
        'No personal tasks created by a read');

    $db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end)
        VALUES (?,"2026-10-31","2026-11-30")')->execute([$engagementId]);
    $periodId=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO document_bundles
        (client_id,period_id,storage_key,original_filename,needs_invoice,needs_spec,needs_act,transfer_due_on)
        VALUES (?,?,?,?,1,1,1,"2026-10-25")')
        ->execute([$clientId,$periodId,str_repeat('a',48).'.pdf','synthetic.pdf']);
    $docId=(int)$db->lastInsertId();

    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(count(array_filter($events,static fn($row)=>$row['date']==='2026-10-31' &&
        in_array($row['kind'],['invoice','spec','act'],true)))===0,
        'Multi-type PDF suppresses preparation reminders');
    require_reminder(count(array_filter($events,static fn($row)=>$row['date']==='2026-10-25'
        && $row['kind']==='handover'))===1,
        'Prepared PDF requires separate transfer');
    $db->prepare('UPDATE document_bundles SET transferred_at=UTC_TIMESTAMP() WHERE id=?')->execute([$docId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='handover'))===0,
        'Transferred documents no longer require handover');
    $db->prepare('INSERT INTO audit_events(action,object_type,object_id)
        VALUES ("invalidate_document","document_bundle",?)')->execute([$docId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='replace'))===1,
        'Finance edit makes an explicit replacement reminder');
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='handover'))===0,
        'Stale PDF must not produce a transferable reminder');
    // A real PDF replacement ends the previous invalidation. The control
    // board must not keep a permanent false red reminder forever.
    $db->prepare('INSERT INTO audit_events(action,object_type,object_id)
        VALUES ("replace_document","document_bundle",?)')->execute([$docId]);
    // A real PDF replacement also resets the handover timestamp.
    $db->prepare('UPDATE document_bundles SET transferred_at=NULL WHERE id=?')
        ->execute([$docId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='replace'))===0,
        'Latest replacement event clears obsolete-PDF reminder');
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='handover'))===1,
        'Replaced PDF once again requires normal handover');
    $db->prepare('INSERT INTO audit_events(action,object_type,object_id)
        VALUES ("invalidate_document","document_bundle",?)')->execute([$docId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='replace'))===1,
        'A later genuine invalidation reopens replacement reminder');
    $db->prepare('INSERT INTO audit_events(action,object_type,object_id)
        VALUES ("replace_document","document_bundle",?)')->execute([$docId]);
    // Even if the transfer deadline predates the calendar lookback,
    // the original due date remains visible as an old overdue item.
    $db->prepare('UPDATE document_bundles SET transfer_due_on="2025-12-01",
        transferred_at=NULL WHERE id=?')->execute([$docId]);
    $oldEvents=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId && $row['kind']==='handover'));
    require_reminder(count(array_filter($oldEvents,
        static fn($row)=>$row['date']==='2025-12-01'))===1,
        'Very old open PDF handover retains its actual overdue date');


    $db->prepare('UPDATE document_bundles SET is_void=1 WHERE id=?')->execute([$docId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='invoice' &&
        $row['date']==='2026-10-31'))===1,'Voided PDF reactivates preparation reminder');


    // Future terms must not overwrite the schedule before taking effect.
    $db->prepare('INSERT INTO engagement_terms
        (engagement_id,effective_on,started_on,billing_day,payment_mode,currency,quoted_amount)
        VALUES (?,"2026-09-29","2026-09-29",31,"prepayment","BYN","900.00")')
        ->execute([$engagementId]);
    $db->prepare('INSERT INTO engagement_terms
        (engagement_id,effective_on,started_on,billing_day,payment_mode,currency,quoted_amount)
        VALUES (?,"2026-11-20","2026-11-20",20,"postpayment","EUR","1200.00")')
        ->execute([$engagementId]);
    $db->prepare('UPDATE engagements SET billing_day=20,payment_mode="postpayment",
        currency="EUR",quoted_amount="1200.00" WHERE id=?')->execute([$engagementId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='invoice'
        && $row['date']==='2026-10-31' && str_contains($row['label'],'предоплата')))===1,
        'October keeps old billing day and prepayment');
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='invoice'
        && $row['date']==='2026-11-20' && str_contains($row['label'],'постоплата')))===1,
        'November change starts new billing cycle');
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='invoice'
        && $row['date']==='2026-11-30'))===0,
        'Superseded old anchor must not generate a later invoice');
    $eng=$db->query('SELECT * FROM engagements WHERE id='.(int)$engagementId)->fetch();
    require_reminder(adc_w_next_cycle_end_effective($db,$eng,'2026-10-31')==='2026-11-20',
        'Old billing cycle ends at contract transition');
    require_reminder(adc_w_next_cycle_end_effective($db,$eng,'2026-11-20')==='2026-12-20',
        'New cycle uses the new billing anchor');

    // Enough open fictional contracts to cover two pages without a new DDL.
    // Avoid one long flat list hiding newer obligations after entry 60.
    $prefix='Synthetic calendar paging '.bin2hex(random_bytes(5)).' ';
    $bulk=$db->prepare('INSERT INTO clients(name,is_demo) VALUES (?,1)');
    for($i=0;$i<65;$i++) $bulk->execute([$prefix.$i]);
    $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency)
        SELECT id,CURRENT_DATE(),DAY(CURRENT_DATE()),"prepayment","BYN"
        FROM clients WHERE name LIKE ?')->execute([$prefix.'%']);
    $_GET=['demo'=>'1','kind'=>'contract','scope'=>'all','page'=>'1'];
    $pageOne=adc_reminders_dashboard($db);
    require_reminder(str_contains($pageOne,'Страница 1 из') &&
        str_contains($pageOne,'Далее →'),'Control list has a next page when crowded');
    $_GET['page']='2';
    $pageTwo=adc_reminders_dashboard($db);
    require_reminder(str_contains($pageTwo,'Страница 2 из') &&
        str_contains($pageTwo,'← Ранее'),
        'Director may inspect remaining reminders on later pages');
    $_GET=[];

    $db->prepare('UPDATE engagements SET status="archived" WHERE id=?')->execute([$engagementId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(!$events,'Archived clients have no active schedule reminders');
} finally {
    $db->rollBack();
}
echo "Document schedule reminders passed: month clamp, multi-type PDF, handover, void, archive, no task duplication\n";
