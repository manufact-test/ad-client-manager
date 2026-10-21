<?php
declare(strict_types=1);
require __DIR__ . '/../app/core.php';

$connection = [
    'db' => [
        'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('TEST_DB_PORT') ?: 3306),
        'name' => getenv('TEST_DB_NAME') ?: 'adclients_test',
        'user' => getenv('TEST_DB_USER') ?: 'root',
        'password' => getenv('TEST_DB_PASSWORD') ?: '',
    ],
];
$db = adc_db($connection);
adc_schema($db);
adc_schema($db); // Re-running schema must be safe.
// Simulate an old installation without the idempotency column in disposable CI.
// Migration must preserve old financial rows and be rerunnable.
$db->exec('ALTER TABLE payment_events DROP INDEX uq_payment_submission, DROP COLUMN submission_key');
adc_schema($db);
adc_schema($db);
$submissionColumn=$db->query("SHOW COLUMNS FROM payment_events LIKE 'submission_key'")->fetch();
$submissionIndex=$db->query("SHOW INDEX FROM payment_events WHERE Key_name='uq_payment_submission'")->fetch();
if (!$submissionColumn || $submissionColumn['Null']!=='YES' || !$submissionIndex ||
    (int)$submissionIndex['Non_unique']!==0) {
    throw new RuntimeException('Payment submission idempotency migration is missing');
}

function expect_equal(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ": got " . var_export($actual, true) . ", expected " . var_export($expected, true));
    }
}
$tables = [
    'users', 'login_attempts', 'audit_events', 'personal_tasks',
    'personal_task_completions', 'clients', 'engagements', 'billing_periods',
    'document_bundles', 'charge_lines', 'payment_events'
];
foreach ($tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '" . $table . "'")->fetchColumn();
    expect_equal($table, $result, "Table $table exists");
}

$db->exec('INSERT INTO users(email,display_name,password_hash,role) VALUES ("test@example.test","Test Director","not-a-real-password-hash","director")');
$userId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO personal_tasks(user_id,title,due_date,repeat_monthly,anchor_day) VALUES (?,?,?,?,?)')
    ->execute([$userId, 'Test monthly reminder', '2026-10-31', 1, 31]);
expect_equal('2026-11-30', adc_next_month('2026-10-31', 31), 'Recurring task month clamping');

$db->exec('INSERT INTO clients(name,is_demo) VALUES ("Actual test fixture",0)');
$realId = (int)$db->lastInsertId();
$db->exec('INSERT INTO clients(name,is_demo) VALUES ("Demo test fixture",1)');
$demoId = (int)$db->lastInsertId();

$engagement = $db->prepare('INSERT INTO engagements(client_id, started_on, billing_day, payment_mode, currency) VALUES (?, "2026-10-07", 7, "prepayment", "BYN")');
$engagement->execute([$demoId]);
$demoEngagementId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end) VALUES(?, "2026-10-07", "2026-11-07")')->execute([$demoEngagementId]);
$demoPeriodId = (int)$db->lastInsertId();

$charge = $db->prepare('INSERT INTO charge_lines(client_id,period_id,category,label,amount,currency) VALUES(?,?,"advertising",?,?,"BYN")');
$charge->execute([$realId, null, 'Advertising', 150]);
$charge->execute([$demoId, $demoPeriodId, 'Demo advertising', 999999]);

$payment = $db->prepare('INSERT INTO payment_events(client_id,period_id,event_type,amount,currency,occurred_on) VALUES(?,?,"received",?,"BYN","2026-10-21")');
$payment->execute([$realId, null, 150]);
$payment->execute([$demoId, $demoPeriodId, 999999]);

$realCount = $db->query('SELECT COUNT(*) FROM clients c WHERE ' . adc_demo_analytics_predicate('c'))->fetchColumn();
expect_equal('1', (string)$realCount, 'Demo excluded from client counts');

$realCharges = $db->query('SELECT SUM(ch.amount) FROM charge_lines ch JOIN clients c ON c.id = ch.client_id WHERE ch.is_void = 0 AND ' . adc_demo_analytics_predicate('c'))->fetchColumn();
expect_equal('150.00', (string)$realCharges, 'Demo excluded from billed revenue');

$received = $db->query('SELECT SUM(p.amount) FROM payment_events p JOIN clients c ON c.id = p.client_id WHERE p.event_type = "received" AND ' . adc_demo_analytics_predicate('c'))->fetchColumn();
expect_equal('150.00', (string)$received, 'Demo excluded from payments');

$db->prepare('DELETE FROM clients WHERE id = ? AND is_demo = 1')->execute([$demoId]);
expect_equal('0', (string)$db->query('SELECT COUNT(*) FROM charge_lines WHERE client_id = ' . $demoId)->fetchColumn(), 'Demo charges deleted');
expect_equal('0', (string)$db->query('SELECT COUNT(*) FROM payment_events WHERE client_id = ' . $demoId)->fetchColumn(), 'Demo payments deleted');
expect_equal('0', (string)$db->query('SELECT COUNT(*) FROM engagements WHERE client_id = ' . $demoId)->fetchColumn(), 'Demo engagements deleted');

echo "MySQL integration tests passed\n";
