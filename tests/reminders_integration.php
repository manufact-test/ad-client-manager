<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/workflow.php';
require __DIR__.'/../app/reminders.php';

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

    $db->prepare('UPDATE document_bundles SET is_void=1 WHERE id=?')->execute([$docId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(count(array_filter($events,static fn($row)=>$row['kind']==='invoice' &&
        $row['date']==='2026-10-31'))===1,'Voided PDF reactivates preparation reminder');

    $db->prepare('UPDATE engagements SET status="archived" WHERE id=?')->execute([$engagementId]);
    $events=array_values(array_filter(adc_client_reminders($db,'2026-10-21'),
        static fn($row)=>$row['client_id']===$clientId));
    require_reminder(!$events,'Archived clients have no active schedule reminders');
} finally {
    $db->rollBack();
}
echo "Document schedule reminders passed: month clamp, multi-type PDF, handover, void, archive, no task duplication\n";
