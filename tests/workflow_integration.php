<?php
declare(strict_types=1);
require __DIR__ . '/../app/core.php';
require __DIR__ . '/../app/workflow.php';

class Redirected extends RuntimeException {}
function adc_input(string $key): string { return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : ''; }
function adc_flash(string $message): void {}
function adc_redirect(string $fragment = ''): never { throw new Redirected($fragment); }
function check(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
}
function run_action(PDO $db, array $user, string $action, array $data): void {
    $_POST = $data;
    try {
        $handled = adc_w_action($db, $user, $action);
        throw new RuntimeException('Action was not handled: ' . $action . ' ' . (int)$handled);
    } catch (Redirected $ok) {
        check(!$db->inTransaction(), 'Transaction committed: ' . $action);
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
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,'charge_id'=>(string)$cid,
    'category'=>'advertising','label'=>'Adjusted ads','amount'=>'1100.00','reason'=>'Change of scope',
]);
run_action($db,$employee,'save_charge',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'category'=>'development','label'=>'Landing page','amount'=>'400.00',
]);
run_action($db,$employee,'add_payment',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'event_type'=>'received','amount'=>'800.00','occurred_on'=>'2026-09-08',
]);
run_action($db,$employee,'add_payment',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'event_type'=>'received','amount'=>'200.00','occurred_on'=>'2026-09-09',
]);
run_action($db,$employee,'add_payment',[
    'client_id'=>(string)$realId,'period_id'=>(string)$p,
    'event_type'=>'refund','amount'=>'100.00','occurred_on'=>'2026-09-10',
]);
$balance = $db->query("SELECT
    (SELECT SUM(amount) FROM charge_lines WHERE client_id=$realId AND is_void=0) -
    (SELECT SUM(CASE WHEN event_type='received' THEN amount ELSE -amount END) FROM payment_events WHERE client_id=$realId)")->fetchColumn();
check((string)$balance==='600.00','1500 billed - 900 received net = 600 outstanding');

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

$demo = $default;
$demo['client_name'] = 'Fixture demo client';
$demo['is_demo']='1';
run_action($db,$employee,'save_client',$demo);
$demoId=(int)$db->query('SELECT MAX(id) FROM clients WHERE name="Fixture demo client"')->fetchColumn();
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
run_action($db,$director,'create_next_period',[
    'client_id'=>(string)$realId,'engagement_id'=>(string)$e
]);
$nextPeriod=$db->query("SELECT period_start,period_end FROM billing_periods WHERE engagement_id=$e ORDER BY id DESC LIMIT 1")->fetch();
check($nextPeriod['period_start']==='2026-10-07' && $nextPeriod['period_end']==='2026-11-07','Automatically generated next period');
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

run_action($db,$director,'archive_engagement',[
    'client_id'=>(string)$realId,'engagement_id'=>(string)$e,
    'ended_on'=>'2026-10-07','end_reason'=>'Fixture completion',
]);
check($db->query("SELECT status FROM engagements WHERE id=$e")->fetchColumn()==='archived','Archived');
$final=$db->query("SELECT period_end,is_final FROM billing_periods WHERE engagement_id=$e ORDER BY id DESC LIMIT 1")->fetch();
check($final['period_end']==='2026-10-08' && (int)$final['is_final']===1,'Final billing period shortened on archive');

run_action($db,$director,'start_engagement',[
    'client_id'=>(string)$realId,'started_on'=>'2026-10-14',
    'billing_day'=>'14','payment_mode'=>'postpayment',
    'currency'=>'BYN','quoted_amount'=>'',
]);
check((int)$db->query("SELECT COUNT(*) FROM engagements WHERE client_id=$realId")->fetchColumn()===2,'New collaboration retains history');
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

$db->prepare('DELETE FROM clients WHERE id=? AND is_demo=1')->execute([$demoId]);
check((int)$db->query("SELECT COUNT(*) FROM charge_lines WHERE client_id=$demoId")->fetchColumn()===0,'Demo charge cascades');
check((int)$db->query("SELECT COUNT(*) FROM payment_events WHERE client_id=$demoId")->fetchColumn()===0,'Demo payments cascade');
echo "Integrated client workflow passed: clients, finance, demo, archive, permissions, tasks\n";
