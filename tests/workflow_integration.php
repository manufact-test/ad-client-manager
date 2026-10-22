<?php
declare(strict_types=1);
require __DIR__ . '/../app/core.php';
require __DIR__ . '/../app/finance.php';
require __DIR__ . '/../app/workflow.php';
require __DIR__ . '/../app/documents.php';
require __DIR__ . '/../app/ledger.php';

class Redirected extends RuntimeException {}
function adc_input(string $key): string { return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : ''; }
function adc_flash(string $message): void {}
function adc_redirect(string $fragment = ''): never { throw new Redirected($fragment); }
function check(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
}
function run_action(PDO $db, array $user, string $action, array $data): string {
    // The regular web form supplies a new nonce for each distinct payment.
    if ($action==='add_payment' && !isset($data['submission_key'])) {
        $data['submission_key']=bin2hex(random_bytes(32));
    }
    // Simulate server-rendered snapshot fields for ordinary fixture actions.
    // Tests can supply an older snapshot explicitly to exercise stale tabs.
    if ($action==='save_client' && !empty($data['client_id']) && !isset($data['expected_revision'])) {
        $row=adc_w_client($db,(int)$data['client_id']);
        $data['expected_revision']=adc_w_client_revision($row);
    }
    if ($action==='save_engagement' && !empty($data['engagement_id']) && !isset($data['expected_revision'])) {
        $row=adc_w_engagement($db,(int)$data['client_id'],(int)$data['engagement_id']);
        $data['expected_revision']=adc_w_engagement_revision($row);
    }
    if (in_array($action,['save_charge','void_charge'],true) &&
        !isset($data['expected_revision']) && !empty($data['charge_id'])) {
        $snapshot=$db->prepare('SELECT * FROM charge_lines WHERE id=?');
        $snapshot->execute([(int)$data['charge_id']]);
        $current=$snapshot->fetch();
        if ($current) $data['expected_revision']=adc_w_charge_revision($current);
    }
    if ($action==='create_next_period') {
        $data['submission_key']??=bin2hex(random_bytes(32));
        if (!isset($data['expected_tail'])) {
            $last=$db->prepare('SELECT id,period_end FROM billing_periods
                WHERE engagement_id=? ORDER BY period_end DESC,id DESC LIMIT 1');
            $last->execute([(int)$data['engagement_id']]);
            $data['expected_tail']=adc_w_period_tail($last->fetch() ?: null);
        }
    }
    $_POST = $data;
    try {
        $handled = adc_w_action($db, $user, $action) || adc_doc_action($db, $user, $action);
        throw new RuntimeException('Action was not handled: ' . $action . ' ' . (int)$handled);
    } catch (Redirected $ok) {
        check(!$db->inTransaction(), 'Transaction committed: ' . $action);
        return $ok->getMessage();
    }
}
$config = ['db'=>[
    'host'=>getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'port'=>(int)(getenv('TEST_DB_PORT') ?: '3306'),
    'name'=>getenv('TEST_DB_NAME') ?: 'adclients_test',
    'user'=>getenv('TEST_DB_USER') ?: 'root',
    'password'=>getenv('TEST_DB_PASSWORD') ?: '',
]];
$db = adc_db($config);
adc_schema($db);
$unique = bin2hex(random_bytes(4));
$db->prepare('INSERT INTO users(email,display_name,password_hash,role) VALUES (?,?,"test","director")')
    ->execute(["director-$unique@example.test",'Fixture director']);
$director = ['id'=>(int)$db->lastInsertId(),'role'=>'director'];
$db->prepare('INSERT INTO users(email,display_name,password_hash,role) VALUES (?,?,"test","employee")')
    ->execute(["employee-$unique@example.test",'Fixture employee']);
$employee = ['id'=>(int)$db->lastInsertId(),'role'=>'employee'];

check(adc_w_amount('12,4') === '12.40','Decimal normalization');
check(adc_w_amount('-5.50', true) === '-5.50','Signed correction');
foreach (['0','1.234','12invalid'] as $invalid) {
    try { adc_w_amount($invalid); throw new RuntimeException('Accepted invalid amount'); }
    catch (InvalidArgumentException $pass) {}
}
$default = [
    'client_name'=>'Fixture real client', 'platform_text'=>'Yandex Direct',
    'source_kind'=>'advertising','source_note'=>'test','legal_type'=>'legal',
    'started_on'=>'2026-09-07','billing_day'=>'7','payment_mode'=>'prepayment',
    'currency'=>'BYN','quoted_amount'=>'1000.00','comment_text'=>'fictional',
];
run_action($db,$director,'save_client',$default);
$realId=(int)$db->lastInsertId(); // audit insertion follows client: retrieve by name instead
$realId=(int)$db->query('SELECT MAX(id) FROM clients WHERE name="Fixture real client"')->fetchColumn();
check($realId>0,'Real client created');
$e=(int)$db->query("SELECT id FROM engagements WHERE client_id=$realId")->fetchColumn();
check($e>0,'Engagement created');
$oldEngRevision=adc_w_engagement_revision(adc_w_engagement($db,$realId,$e));
run_action($db,$employee,'save_engagement',[
    'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
    'started_on'=>'2026-09-07','billing_day'=>'7','payment_mode'=>'postpayment',
    'currency'=>'BYN','quoted_amount'=>'1200.00',
]);
try {
    run_action($db,$director,'save_engagement',[
        'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
        'started_on'=>'2026-09-07','billing_day'=>'1','payment_mode'=>'prepayment',
        'currency'=>'BYN','quoted_amount'=>'900.00',
        'expected_revision'=>$oldEngRevision
    ]);
    throw new RuntimeException('Old tab overwrote engagement conditions');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'изменены в другой вкладке'),
        'Stale engagement terms cannot replace newer billing conditions');
    if ($db->inTransaction()) $db->rollBack();
}
$engCheck=$db->query("SELECT * FROM engagements WHERE id=$e")->fetch();
check($engCheck['payment_mode']==='postpayment' && $engCheck['quoted_amount']==='1200.00','Edit financial terms');
$quoteForm=adc_w_engagement_fields($engCheck);
check(str_contains($quoteForm,'Сумма по договору в месяц') &&
    str_contains($quoteForm,'name="quoted_amount" type="number" value="1200.00"'),
    'Saved contract price renders as the original editable quote');
check(str_contains(adc_w_engagement_fields(),'name="quoted_amount" type="number" value=""'),
    'New client initial quote is optional and left blank');

run_action($db,$director,'save_period',[
    'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
    'period_start'=>'2026-09-07','period_end'=>'2026-10-07','period_note'=>'September',
]);
$p=(int)$db->query("SELECT id FROM billing_periods WHERE engagement_id=$e")->fetchColumn();
check((bool)adc_w_period($db,$realId,$p),'Period belongs to real client');
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'category'=>'advertising','label'=>'Monthly ads','amount'=>'1000.00','reason'=>'',
]);
$cid=(int)$db->query("SELECT id FROM charge_lines WHERE client_id=$realId")->fetchColumn();
check($cid>0,'Charge created');
$oldCharge=$db->query("SELECT * FROM charge_lines WHERE id=$cid")->fetch();
$oldRevision=adc_w_charge_revision($oldCharge);
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,'charge_id'=>(string)$cid,
    'category'=>'advertising','label'=>'Adjusted ads','amount'=>'1100.00','reason'=>'Change of scope',
]);
$chargeEditAudits=(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='edit_charge' AND object_id=$cid")->fetchColumn();
foreach (['save_charge','void_charge'] as $staleAction) {
    try {
        run_action($db,$employee,$staleAction,[
            'client_id'=>(string)$realId,'period_id'=>(string)$p,'charge_id'=>(string)$cid,
            'category'=>'advertising','label'=>'Stale tab overwrite','amount'=>'2000.00',
            'reason'=>'stale','expected_revision'=>$oldRevision
        ]);
        throw new RuntimeException('Stale charge edit accepted');
    } catch (RuntimeException $expected) {
        check(str_contains($expected->getMessage(),'изменено в другой вкладке'),
            'Stale edited charge cannot be overwritten or voided using old version');
        if ($db->inTransaction()) $db->rollBack();
    }
}
check($db->query("SELECT amount FROM charge_lines WHERE id=$cid")->fetchColumn()==='1100.00' &&
    (int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='edit_charge' AND object_id=$cid")->fetchColumn()===$chargeEditAudits,
    'Stale charge requests never mutate amount or audit');
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'category'=>'development','label'=>'Landing page','amount'=>'400.00',
]);
$receiptToken=bin2hex(random_bytes(32));
$receiptFields=[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'event_type'=>'received','amount'=>'800.00','occurred_on'=>'2026-09-08',
    'submission_key'=>$receiptToken,
];
run_action($db,$employee,'add_payment',$receiptFields);
run_action($db,$employee,'add_payment',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'event_type'=>'received','amount'=>'200.00','occurred_on'=>'2026-09-09',
]);
$refundToken=bin2hex(random_bytes(32));
$refundFields=[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'event_type'=>'refund','amount'=>'100.00','occurred_on'=>'2026-09-10',
    'submission_key'=>$refundToken,
];
run_action($db,$employee,'add_payment',$refundFields);
$paymentCount=(int)$db->query("SELECT COUNT(*) FROM payment_events WHERE client_id=$realId")->fetchColumn();
$auditCount=(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='payment_event'")->fetchColumn();
run_action($db,$employee,'add_payment',$receiptFields);
run_action($db,$employee,'add_payment',$refundFields);
check((int)$db->query("SELECT COUNT(*) FROM payment_events WHERE client_id=$realId")->fetchColumn()===$paymentCount,
    'Replayed receipt and refund never duplicate financial entries');
check((int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='payment_event'")->fetchColumn()===$auditCount,
    'Replayed payments do not duplicate audit events');
try {
    run_action($db,$employee,'add_payment',array_merge($receiptFields,['amount'=>'800.01']));
    throw new RuntimeException('Reused nonce changed the recorded payment');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'уже использована'),'Nonce replay with altered amount rejected');
    if ($db->inTransaction()) $db->rollBack();
}
try {
    run_action($db,$employee,'add_payment',array_merge($receiptFields,['submission_key'=>'INVALID']));
    throw new RuntimeException('Invalid payment form nonce accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'устарела'),'Malformed payment form nonce rejected');
    if ($db->inTransaction()) $db->rollBack();
}
$balance = $db->query("SELECT
    (SELECT SUM(amount) FROM charge_lines WHERE client_id=$realId AND is_void=0) -
    (SELECT SUM(CASE WHEN event_type='received' THEN amount ELSE -amount END) FROM payment_events WHERE client_id=$realId)")->fetchColumn();
check((string)$balance==='600.00','1500 billed - 900 received net = 600 outstanding');
$clientTotals=adc_w_client_balances($db,$realId);
check(count($clientTotals)===1 && $clientTotals[0]['currency']==='BYN' &&
    $clientTotals[0]['billed']===150000 && $clientTotals[0]['net']===90000 &&
    $clientTotals[0]['balance']===60000,'Whole-client exact balance after partial payments and refund');
try {
    run_action($db,$employee,'save_engagement',[
        'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
        'started_on'=>'2026-09-07','billing_day'=>'7','payment_mode'=>'prepayment',
        'currency'=>'USD','quoted_amount'=>'1200.00',
    ]);
    throw new RuntimeException('Changed currency after payments');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'Нельзя менять валюту'),'Protect historical currency');
    if ($db->inTransaction()) $db->rollBack();
}


try {
    run_action($db,$employee,'add_payment',[
        'client_id'=>(string)$realId,'period_id'=>(string)$p,
        'event_type'=>'refund','amount'=>'1000.00','occurred_on'=>'2026-09-11',
    ]);
    throw new RuntimeException('Oversized refund should fail');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'Возврат превышает'),'Reject refund exceeding net payments');
    if ($db->inTransaction()) $db->rollBack();
}

$originalClient=adc_w_client($db,$realId);
$oldClientRev=adc_w_client_revision($originalClient);
run_action($db,$employee,'save_client',array_merge($default,[
    'client_id'=>(string)$realId,'client_name'=>'Fixture real client updated'
]));
try {
    run_action($db,$director,'save_client',array_merge($default,[
        'client_id'=>(string)$realId,
        'client_name'=>'Old client name from stale tab',
        'expected_revision'=>$oldClientRev
    ]));
    throw new RuntimeException('Old tab overwrote client name');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'изменена в другой вкладке'),
        'Stale client edit cannot overwrite changed name and source');
    if ($db->inTransaction()) $db->rollBack();
}
check(adc_w_client($db,$realId)['name']==='Fixture real client updated',
    'Latest client card survives stale edits');
$demo = $default;
$demo['client_name'] = 'Fixture demo client';
$demo['is_demo']='1';
run_action($db,$employee,'save_client',$demo);
$demoId=(int)$db->query('SELECT MAX(id) FROM clients WHERE name="Fixture demo client"')->fetchColumn();
try {
    run_action($db,$employee,'save_client',array_merge($default,[
        'client_id'=>(string)$realId,'previous_client_id'=>(string)$demoId
    ]));
    throw new RuntimeException('Accepted cross demo/real client lineage');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'демонстрационных'),'Do not mix real and demo repeat-client links');
    if ($db->inTransaction()) $db->rollBack();
}
try {
    run_action($db,$employee,'save_client',array_merge($default,[
        'client_id'=>(string)$realId,'source_note'=>str_repeat('z',241)
    ]));
    throw new RuntimeException('Accepted oversized acquisition note');
} catch (InvalidArgumentException $expected) {
    check(str_contains($expected->getMessage(),'длина'),'Check source note length independently');
    if ($db->inTransaction()) $db->rollBack();
}

$demoE=(int)$db->query("SELECT id FROM engagements WHERE client_id=$demoId")->fetchColumn();
run_action($db,$employee,'save_period',[
    'client_id'=>(string)$demoId,'engagement_id'=>(string)$demoE,
    'period_start'=>'2026-09-07','period_end'=>'2026-10-07',
]);
$demoP=(int)$db->query("SELECT id FROM billing_periods WHERE engagement_id=$demoE")->fetchColumn();
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$demoId,'period_id'=>(string)$demoP,
    'category'=>'advertising','label'=>'Demo ads','amount'=>'999999.00',
]);
run_action($db,$employee,'add_payment',[
    'client_id'=>(string)$demoId,'period_id'=>(string)$demoP,
    'event_type'=>'received','amount'=>'999999.00','occurred_on'=>'2026-09-09',
]);
$bill=(string)$db->query('SELECT SUM(ch.amount) FROM charge_lines ch JOIN clients c ON c.id=ch.client_id WHERE ch.is_void=0 AND '
    .adc_demo_analytics_predicate('c')." AND c.id IN ($realId,$demoId)")->fetchColumn();
check($bill==='1500.00','Demo excluded from real charges');
$pay=(string)$db->query('SELECT SUM(p.amount) FROM payment_events p JOIN clients c ON c.id=p.client_id WHERE p.event_type="received" AND '
    .adc_demo_analytics_predicate('c')." AND c.id IN ($realId,$demoId)")->fetchColumn();
check($pay==='1000.00','Demo excluded from received money');
try {
    run_action($db,$employee,'save_charge',[
        'client_id'=>(string)$realId,'period_id'=>(string)$demoP,
        'category'=>'other','label'=>'Cross-client attempt','amount'=>'500.00',
    ]);
    throw new RuntimeException('Cross-client period accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'не принадлежит'),'Reject cross-client financial write');
    if ($db->inTransaction()) $db->rollBack();
}

check(adc_w_next_cycle_end('2026-01-31',31)==='2026-02-28','January 31 clamp');
check(adc_w_next_cycle_end('2028-02-29',31)==='2028-03-31','Leap-year clamp and anchor');
check(adc_w_next_cycle_end('2026-10-02',7)==='2026-10-07','Before billing day');
$autoTail=adc_w_period_tail(['id'=>$p,'period_end'=>'2026-10-07']);
$autoKey=bin2hex(random_bytes(32));
$autoPayload=[
    'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
    'submission_key'=>$autoKey,'expected_tail'=>$autoTail
];
run_action($db,$director,'create_next_period',$autoPayload);
$periodCount=(int)$db->query("SELECT COUNT(*) FROM billing_periods WHERE engagement_id=$e")->fetchColumn();
$periodAuditCount=(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='create_next_period'")->fetchColumn();
run_action($db,$director,'create_next_period',$autoPayload);
check((int)$db->query("SELECT COUNT(*) FROM billing_periods WHERE engagement_id=$e")->fetchColumn()===$periodCount &&
    (int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='create_next_period'")->fetchColumn()===$periodAuditCount,
    'Repeated auto-period POST does not generate a second month or audit');
try {
    run_action($db,$director,'create_next_period',[
        'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
        'submission_key'=>bin2hex(random_bytes(32)),'expected_tail'=>$autoTail
    ]);
    throw new RuntimeException('Outdated second-tab period created an extra month');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'изменился в другой вкладке'),
        'Different open tab with old last-period boundary is rejected');
    if ($db->inTransaction()) $db->rollBack();
}
$nextPeriod=$db->query("SELECT period_start,period_end FROM billing_periods WHERE engagement_id=$e ORDER BY id DESC LIMIT 1")->fetch();
check($nextPeriod['period_start']==='2026-10-07' && $nextPeriod['period_end']==='2026-11-07','Automatically generated next period');
$autoPeriodId=(int)$db->query("SELECT MAX(id) FROM billing_periods WHERE engagement_id=$e")->fetchColumn();
$db->prepare('UPDATE billing_periods SET is_final=1 WHERE id=?')->execute([$autoPeriodId]);
try {
    run_action($db,$director,'create_next_period',[
        'client_id'=>(string)$realId,'engagement_id'=>(string)$e
    ]);
    throw new RuntimeException('Automatic period created after final period');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'Заключительный период'),
        'Auto-generation stops at an explicitly final period');
    if ($db->inTransaction()) $db->rollBack();
}
$db->prepare('UPDATE billing_periods SET is_final=0 WHERE id=?')->execute([$autoPeriodId]);
try {
    run_action($db,$director,'save_period',[
        'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
        'period_start'=>'2026-10-01','period_end'=>'2026-10-20'
    ]);
    throw new RuntimeException('Accepted overlapping period');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'пересекается'),'Reject overlapping periods');
    if ($db->inTransaction()) $db->rollBack();
}

// Archive must invalidate only PDF bundles whose period dates change.
$lastPeriodId=(int)$db->query("SELECT id FROM billing_periods WHERE engagement_id=$e ORDER BY period_end DESC LIMIT 1")->fetchColumn();
$db->prepare('INSERT INTO document_bundles(client_id,period_id,needs_invoice,transferred_at)
    VALUES (?,?,1,UTC_TIMESTAMP())')->execute([$realId,$p]);
$unchangedInvoice=(int)$db->lastInsertId();
$db->prepare('INSERT INTO document_bundles(client_id,period_id,needs_act,transferred_at)
    VALUES (?,?,1,UTC_TIMESTAMP())')->execute([$realId,$lastPeriodId]);
$shortenedAct=(int)$db->lastInsertId();
$archiveUrl=run_action($db,$director,'archive_engagement',[
    'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
    'ended_on'=>'2026-10-07','end_reason'=>'Fixture completion',
]);
check($archiveUrl==='?view=archive&client='.$realId,'Archive POST returns to archived client drawer');
check($db->query("SELECT status FROM engagements WHERE id=$e")->fetchColumn()==='archived','Archived');
$final=$db->query("SELECT period_end,is_final FROM billing_periods WHERE engagement_id=$e ORDER BY id DESC LIMIT 1")->fetch();
check($final['period_end']==='2026-10-08' && (int)$final['is_final']===1,'Final billing period shortened on archive');
check(adc_doc_requires_replacement($db,$shortenedAct),'Archive invalidates PDF for shortened period');
check((int)$db->query("SELECT transferred_at IS NULL FROM document_bundles WHERE id=$shortenedAct")->fetchColumn()===1,
    'Archive clears transferred state for shortened period');
check(!adc_doc_requires_replacement($db,$unchangedInvoice),'Archive does not invalidate unchanged period invoice');
try {
    run_action($db,$director,'save_period',[
        'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
        'period_start'=>'2026-10-08','period_end'=>'2026-11-08'
    ]);
    throw new RuntimeException('Archived invoice after closure accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'за даты сотрудничества'),
        'Archived period cannot exceed closure date');
    if ($db->inTransaction()) $db->rollBack();
}
try {
    run_action($db,$director,'start_engagement',[
        'client_id'=>(string)$realId,'started_on'=>'2026-10-07',
        'billing_day'=>'14','payment_mode'=>'postpayment',
        'currency'=>'BYN','quoted_amount'=>''
    ]);
    throw new RuntimeException('Overlapping archived engagement accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'после последней даты архива'),
        'New collaboration starts strictly after archived one');
    if ($db->inTransaction()) $db->rollBack();
}


$resumeUrl=run_action($db,$director,'start_engagement',[
    'client_id'=>(string)$realId,'started_on'=>'2026-10-14',
    'billing_day'=>'14','payment_mode'=>'postpayment',
    'currency'=>'BYN','quoted_amount'=>'',
]);
check($resumeUrl==='?view=clients&client='.$realId,'Resumed client returns to active client drawer');
check((int)$db->query("SELECT COUNT(*) FROM engagements WHERE client_id=$realId")->fetchColumn()===2,'New collaboration retains history');
$newEng=(int)$db->query("SELECT MAX(id) FROM engagements WHERE client_id=$realId")->fetchColumn();
try {
    run_action($db,$director,'save_period',[
        'client_id'=>(string)$realId,'engagement_id'=>(string)$newEng,
        'period_start'=>'2026-10-01','period_end'=>'2026-10-06'
    ]);
    throw new RuntimeException('Backdated period before new engagement accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'за даты сотрудничества'),
        'Period cannot start before collaboration');
    if ($db->inTransaction()) $db->rollBack();
}
try {
    run_action($db,$director,'save_engagement',[
        'client_id'=>(string)$realId,'engagement_id'=>(string)$newEng,
        'started_on'=>'2026-10-05','billing_day'=>'14',
        'payment_mode'=>'postpayment','currency'=>'BYN','quoted_amount'=>''
    ]);
    throw new RuntimeException('Backdating active contract into archive accepted');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'пересекается с архивом'),
        'Active collaboration cannot overlap archived one');
    if ($db->inTransaction()) $db->rollBack();
}

check((string)$db->query("SELECT SUM(amount) FROM charge_lines WHERE client_id=$realId AND is_void=0")->fetchColumn()==='1500.00','History preserved');
$db->prepare('INSERT INTO personal_tasks(user_id,title,due_date) VALUES (?,?,?)')
    ->execute([$director['id'],'Task A','2026-10-31']);
$task=(int)$db->lastInsertId();
try {
    run_action($db,$employee,'edit_task',[
        'task_id'=>(string)$task,'title'=>'Hijack','due_date'=>'2026-11-01'
    ]);
    throw new RuntimeException('Employee edited another user task');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'недоступна'),'Task ownership enforced');
}
run_action($db,$director,'edit_task',[
    'task_id'=>(string)$task,'title'=>'Task A revised',
    'due_date'=>'2026-11-30','repeat_monthly'=>'1',
]);
$taskRow=$db->query("SELECT * FROM personal_tasks WHERE id=$task")->fetch();
check($taskRow['title']==='Task A revised' && (int)$taskRow['anchor_day']===30,'Owner edit and monthly anchor');
$db->prepare('INSERT INTO personal_tasks(user_id,title,due_date,repeat_monthly,anchor_day) VALUES (?,?,?,?,?)')
    ->execute([$director['id'],'Task anchored 31','2026-02-28',1,31]);
$clampedTask=(int)$db->lastInsertId();
run_action($db,$director,'edit_task',[
    'task_id'=>(string)$clampedTask,'title'=>'Task renamed, still 31',
    'due_date'=>'2026-02-28','repeat_monthly'=>'1',
]);
check((int)$db->query("SELECT anchor_day FROM personal_tasks WHERE id=$clampedTask")->fetchColumn()===31,
    'Preserve original day 31 when editing February reminder');


$key=str_repeat('b',48).'.pdf';
// This MySQL-only integration creates a real private fixture file so that
// handover integrity checks exercise actual bytes. Leave no storage directory
// behind: the next HTTP fixture refuses an inherited private directory.
$fixtureCreatedDir=!file_exists(adc_doc_directory());
adc_doc_ensure_directory();
$fixtureBytes="%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
$fixturePath=adc_doc_path($key);
check(file_put_contents($fixturePath,$fixtureBytes)!==false,'Create fictional private fixture PDF');
$db->prepare('INSERT INTO document_bundles(client_id,period_id,storage_key,original_filename,
    content_sha256,needs_invoice,needs_act,transfer_due_on)
    VALUES (?,?,?,?,?,1,1,?)')->execute([
        $demoId,$demoP,$key,'fixture-invoice.pdf',hash('sha256',$fixtureBytes),'2026-10-31'
    ]);
$documentId=(int)$db->lastInsertId();
$form=adc_doc_upload_form($db,$demoId,$documentId);
check(str_contains($form,'value="'.$demoP.'" selected'),'Replacement preserves historical period selection');
check(str_contains($form,'name="needs_invoice" value="1" checked'),'Replacement preserves invoice type');
check(str_contains($form,'name="needs_act" value="1" checked'),'Replacement preserves act type');
check(str_contains($form,'value="2026-10-31"'),'Replacement preserves transfer due date');
$chargeBefore=(int)$db->query("SELECT id FROM charge_lines WHERE client_id=$demoId AND label='Demo ads'")->fetchColumn();
$auditBefore=(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='document_bundle' AND object_id=$documentId AND action='invalidate_document'")->fetchColumn();
$unchangedUrl=run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$demoId,'period_id'=>(string)$demoP,
    'charge_id'=>(string)$chargeBefore,'category'=>'advertising',
    'label'=>'Demo ads','amount'=>'999999.00','reason'=>''
]);
check($unchangedUrl==='?view=clients&client='.$demoId,'Unchanged charge returns to active drawer');
check((int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='document_bundle' AND object_id=$documentId AND action='invalidate_document'")->fetchColumn()===$auditBefore,
    'No-op charge does not invalidate unchanged invoice');
run_action($db,$employee,'transfer_document',[
    'client_id'=>(string)$demoId,'document_id'=>(string)$documentId
]);
check((bool)$db->query("SELECT transferred_at IS NOT NULL FROM document_bundles WHERE id=$documentId")->fetchColumn(),
    'Employee may mark a PDF transferred');
$transferCount=(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='document_bundle' AND object_id=$documentId AND action='transfer_document'")->fetchColumn();
run_action($db,$employee,'transfer_document',[
    'client_id'=>(string)$demoId,'document_id'=>(string)$documentId
]);
check((int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='document_bundle' AND object_id=$documentId AND action='transfer_document'")->fetchColumn()===$transferCount,
    'Replayed document handover is idempotent and audit-safe');
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$demoId,'period_id'=>(string)$demoP,
    'category'=>'other','label'=>'Amendment','amount'=>'1.00',
]);
check((int)$db->query("SELECT transferred_at IS NULL FROM document_bundles WHERE id=$documentId")->fetchColumn()===1,
    'Charge change invalidates previously transferred document');
check(adc_doc_requires_replacement($db,$documentId),
    'Financial modification invalidates original PDF, not merely transfer timestamp');
$onceInvalidated=(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='document_bundle' AND object_id=$documentId AND action='invalidate_document'")->fetchColumn();
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$demoId,'period_id'=>(string)$demoP,
    'category'=>'other','label'=>'Another amendment','amount'=>'2.00',
]);
check($onceInvalidated===1 && (int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='document_bundle' AND object_id=$documentId AND action='invalidate_document'")->fetchColumn()===1,
    'Further edits never create duplicate invalidation audit for a stale PDF');
try {
    run_action($db,$employee,'transfer_document',[
        'client_id'=>(string)$demoId,'document_id'=>(string)$documentId
    ]);
    throw new RuntimeException('Stale PDF was transferred');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'заменить PDF'),
        'Employee cannot confirm transfer of outdated document');
    if ($db->inTransaction()) $db->rollBack();
}
// A metadata-only edit cannot clear the invalidation. A real replacement
// does clear it, but any later charge modification invalidates it again.
adc_audit($db,(int)$director['id'],'edit_document','document_bundle',$documentId);
check(adc_doc_requires_replacement($db,$documentId),
    'Metadata-only edit cannot reactivate a stale invoice');
adc_audit($db,(int)$director['id'],'replace_document','document_bundle',$documentId);
check(!adc_doc_requires_replacement($db,$documentId),
    'Real replacement clears the preceding invalidation');
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$demoId,'period_id'=>(string)$demoP,
    'category'=>'other','label'=>'Post-replacement amendment','amount'=>'3.00'
]);
check(adc_doc_requires_replacement($db,$documentId),
    'New financial changes invalidate a replaced PDF again');
check((int)$db->query("SELECT COUNT(*) FROM audit_events WHERE object_type='document_bundle' AND object_id=$documentId AND action='invalidate_document'")->fetchColumn()===2,
    'Each distinct invalidation is logged once after actual PDF replacement');

// A fresh Edit/Void snapshot still allows a legitimate void exactly once.
$voidId=(int)$db->query("SELECT id FROM charge_lines WHERE client_id=$demoId AND label='Another amendment'")->fetchColumn();
$voidSnapshot=$db->query("SELECT * FROM charge_lines WHERE id=$voidId")->fetch();
$voidRevision=adc_w_charge_revision($voidSnapshot);
run_action($db,$employee,'void_charge',[
    'client_id'=>(string)$demoId,'charge_id'=>(string)$voidId,
    'expected_revision'=>$voidRevision
]);
check((int)$db->query("SELECT is_void FROM charge_lines WHERE id=$voidId")->fetchColumn()===1,
    'Current revision can be voided successfully');
$voidAudits=(int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='void_charge' AND object_id=$voidId")->fetchColumn();
try {
    run_action($db,$employee,'void_charge',[
        'client_id'=>(string)$demoId,'charge_id'=>(string)$voidId,
        'expected_revision'=>$voidRevision
    ]);
    throw new RuntimeException('Voided charge twice');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'не найдено'),'Voided charge cannot be voided again');
    if ($db->inTransaction()) $db->rollBack();
}
check($voidAudits===1 && (int)$db->query("SELECT COUNT(*) FROM audit_events WHERE action='void_charge' AND object_id=$voidId")->fetchColumn()===1,
    'Voiding a charge exactly once also keeps audit unique');

try {
    run_action($db,$employee,'delete_demo',['client_id'=>(string)$demoId]);
    throw new RuntimeException('Employee deleted demo files');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(),'Недостаточно прав'),'Demo deletion director-only');
}
run_action($db,$director,'delete_demo',['client_id'=>(string)$demoId]);
check((int)$db->query("SELECT COUNT(*) FROM charge_lines WHERE client_id=$demoId")->fetchColumn()===0,'Demo charge cascades');
check((int)$db->query("SELECT COUNT(*) FROM payment_events WHERE client_id=$demoId")->fetchColumn()===0,'Demo payments cascade');
check((int)$db->query("SELECT COUNT(*) FROM document_bundles WHERE client_id=$demoId")->fetchColumn()===0,'Demo PDF metadata cascades');
check(!file_exists($fixturePath),'Demo PDF fixture bytes removed from private storage');
if ($fixtureCreatedDir) {
    $remaining=scandir(adc_doc_directory());
    check($remaining!==false && count($remaining)===2,'No leaked private fixture files');
    check(rmdir(adc_doc_directory()),'Clean up CI-only storage directory');
}
echo "Integrated client workflow passed: clients, finance, demo, archive, permissions, tasks\n";
