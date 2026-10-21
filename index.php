<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
require __DIR__ . '/app/core.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
adc_session();

function adc_page(string $title, string $body, ?array $user = null): never {
    $name = $user ? adc_h((string) $user['display_name']) : '';
    $menu = $user
        ? '<nav><span>' . $name . ' · ' . ($user['role'] === 'director' ? 'Директор' : 'Сотрудник') . '</span>'
            . '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . adc_csrf() . '">'
            . '<input type="hidden" name="action" value="logout"><button type="submit" class="link">Выйти</button></form></nav>'
        : '';
    echo '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>' . adc_h($title) . ' · Ad Client Manager</title>'
       . '<style>
       *{box-sizing:border-box}body{font:15px/1.5 system-ui,-apple-system,Segoe UI,Arial,sans-serif;margin:0;background:#f6f7fa;color:#182130}
       header{padding:16px max(20px,calc((100vw - 980px)/2));background:#fff;border-bottom:1px solid #e8ebf1;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}
       main{max-width:980px;margin:28px auto;padding:0 16px 48px}.brand{font-weight:750;font-size:19px}nav{display:flex;gap:16px;align-items:center;font-size:13px;color:#526071}
       h1{font-size:27px;margin:4px 0 12px}h2{font-size:19px;margin:0 0 14px}p{margin:0 0 12px}small,.muted{color:#667285}
       .card{background:#fff;border:1px solid #e5e8ef;border-radius:14px;padding:22px;margin:0 0 16px}
       .narrow{max-width:475px;margin:55px auto}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:16px}.stack{display:grid;gap:10px}
       .field{display:grid;gap:5px;margin:0 0 13px}label{font-weight:600;font-size:13px}input,select{width:100%;padding:10px 11px;border:1px solid #cfd5df;border-radius:8px;font:inherit;background:white;color:inherit}
       input[type=checkbox]{width:auto;accent-color:#4d46cb}.check{display:flex;align-items:center;gap:8px;margin:8px 0;font-size:14px}
       button,.btn{font:inherit;border:1px solid #3b35a8;border-radius:8px;background:#493fbc;color:white;padding:9px 13px;cursor:pointer;text-decoration:none;display:inline-block}
       button.secondary,.btn.secondary{background:#fff;color:#2b286d;border-color:#c5c9dd}.link{border:none;color:#3b35a8;background:transparent;padding:0}
       .inline{display:inline-block}.actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.item{padding:11px 0;border-bottom:1px solid #edf0f3;display:flex;align-items:center;justify-content:space-between;gap:12px}
       .item:last-child{border-bottom:0}.item-title{font-weight:650}.badge{font-size:12px;font-weight:650;padding:2px 7px;background:#edf0f7;border-radius:30px;color:#48556b}
       .demo{background:#fff0c7;color:#735016}.good{background:#e4f6eb;color:#176739}.notice{border-left:3px solid #5147bc;background:#f0effe;padding:12px;border-radius:5px;margin:12px 0}
       .error{border-left:3px solid #c73333;background:#fff1f1;padding:11px;border-radius:5px;margin:12px 0}
       .months{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-bottom:16px}
       table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #edf0f3;padding:9px 4px;text-align:left;font-size:14px}
       @media(max-width:560px){header{padding:12px 16px}.item{align-items:flex-start;flex-direction:column}.card{padding:16px}h1{font-size:23px}}
       </style></head><body><header><div class="brand">Ad Client Manager</div>' . $menu . '</header><main>' . $body . '</main></body></html>';
    exit;
}

function adc_redirect(string $fragment = ''): never {
    header('Location: /' . $fragment, true, 303);
    exit;
}

function adc_input(string $name): string {
    return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
}

function adc_notice(): string {
    if (!isset($_SESSION['flash'])) {
        return '';
    }
    $value = (string) $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="notice">' . adc_h($value) . '</div>';
}

function adc_flash(string $message): void {
    $_SESSION['flash'] = $message;
}

$config = adc_config();
if ($config === null) {
    http_response_code(503);
    adc_page('Настройка сервера', '<section class="card narrow"><h1>Сервер готовится к запуску</h1><p>Конфигурация базы данных ещё не установлена.</p><small>Для безопасности сайт не принимает пользователей до завершения настройки.</small></section>');
}

try {
    $db = adc_db($config);
    $initialized = adc_has_users($db);
} catch (Throwable $error) {
    error_log('Ad Client Manager DB initialization error: ' . get_class($error));
    http_response_code(503);
    adc_page('Обслуживание', '<section class="card narrow"><h1>Временно недоступно</h1><p>Не удалось подключиться к базе данных. Обратитесь к администратору.</p></section>');
}

$action = adc_input('action');
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adc_verify_csrf();

        if (!$initialized) {
            if ($action !== 'bootstrap') {
                throw new RuntimeException('Сначала создайте учётную запись директора.');
            }
            $secret = adc_input('setup_secret');
            $email = mb_strtolower(adc_input('email'));
            $displayName = adc_input('display_name');
            $password = adc_input('password');
            if (!hash_equals($config['setup_secret'], $secret)) {
                throw new RuntimeException('Неверный секрет первичной настройки.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($displayName) < 2 ||
                mb_strlen($displayName) > 120 || strlen($password) < 12) {
                throw new RuntimeException('Проверьте email, имя и пароль длиной от 12 символов.');
            }
            adc_schema($db);
            // A database-level lock prevents concurrent first-user requests.
            $lock = (int) $db->query("SELECT GET_LOCK('adclients-director-bootstrap', 5)")->fetchColumn();
            if ($lock !== 1) {
                throw new RuntimeException('Попробуйте создать директора ещё раз.');
            }
            try {
                if (adc_has_users($db)) {
                    throw new RuntimeException('Учётная запись директора уже создана.');
                }
                $stmt = $db->prepare('INSERT INTO users(email, display_name, password_hash, role) VALUES (?, ?, ?, "director")');
                $stmt->execute([$email, $displayName, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int) $db->lastInsertId();
                adc_audit($db, $userId, 'bootstrap_director', 'user', $userId);
            } finally {
                $db->query("SELECT RELEASE_LOCK('adclients-director-bootstrap')");
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            adc_flash('Учётная запись директора создана.');
            adc_redirect();
        }

        if ($action === 'login') {
            $email = mb_strtolower(adc_input('email'));
            $password = adc_input('password');
            $key = adc_identity_hash($email);
            if (adc_login_blocked($db, $key)) {
                throw new RuntimeException('Слишком много попыток. Повторите через 15 минут.');
            }
            $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $candidate = $stmt->fetch();
            if (!$candidate || !password_verify($password, (string) $candidate['password_hash'])) {
                adc_login_failure($db, $key);
                throw new RuntimeException('Неверный email или пароль.');
            }
            $db->prepare('DELETE FROM login_attempts WHERE identity_hash = ?')->execute([$key]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $candidate['id'];
            adc_flash('Вход выполнен.');
            adc_redirect();
        }

        $current = adc_current_user($db);
        if (!$current) {
            throw new RuntimeException('Необходимо войти.');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            session_destroy();
            adc_redirect();
        }

        if ($action === 'change_password') {
            $old = adc_input('old_password');
            $new = adc_input('new_password');
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([(int)$current['id']]);
            if (!password_verify($old, (string)$stmt->fetchColumn()) || strlen($new) < 12) {
                throw new RuntimeException('Проверьте старый пароль и новый пароль (не менее 12 символов).');
            }
            $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
               ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$current['id']]);
            adc_audit($db, (int)$current['id'], 'change_password', 'user', (int)$current['id']);
            session_regenerate_id(true);
            adc_flash('Пароль изменён.');
            adc_redirect();
        }

        if ((int)$current['must_change_password'] === 1) {
            throw new RuntimeException('Сначала измените временный пароль.');
        }

        if ($action === 'create_task') {
            $title = adc_validate_task_title(adc_input('title'));
            $dueDate = adc_input('due_date');
            if (!adc_valid_date($dueDate)) {
                throw new RuntimeException('Выберите правильную дату.');
            }
            $monthly = isset($_POST['repeat_monthly']) ? 1 : 0;
            $anchor = $monthly ? (int)(new DateTimeImmutable($dueDate))->format('j') : null;
            $stmt = $db->prepare('INSERT INTO personal_tasks(user_id,title,due_date,repeat_monthly,anchor_day) VALUES(?,?,?,?,?)');
            $stmt->execute([(int)$current['id'], $title, $dueDate, $monthly, $anchor]);
            adc_flash('Задача добавлена.');
            adc_redirect('?month=' . substr($dueDate, 0, 7));
        }

        if ($action === 'complete_task' || $action === 'delete_task') {
            $taskId = filter_var(adc_input('task_id'), FILTER_VALIDATE_INT);
            if (!$taskId || $taskId < 1) {
                throw new RuntimeException('Неверная задача.');
            }
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT * FROM personal_tasks WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$taskId, (int)$current['id']]);
            $task = $stmt->fetch();
            if (!$task) {
                throw new RuntimeException('Задача не найдена.');
            }
            if ($action === 'delete_task') {
                $db->prepare('DELETE FROM personal_tasks WHERE id = ? AND user_id = ?')->execute([$taskId, (int)$current['id']]);
                adc_audit($db, (int)$current['id'], 'delete_task', 'personal_task', $taskId);
                $db->commit();
                adc_flash('Задача удалена.');
                adc_redirect();
            }
            if ((int)$task['is_done'] === 1) {
                throw new RuntimeException('Задача уже выполнена.');
            }
            $db->prepare('INSERT INTO personal_task_completions(task_id,user_id,due_date) VALUES (?,?,?)')
               ->execute([$taskId, (int)$current['id'], $task['due_date']]);
            if ((int)$task['repeat_monthly'] === 1) {
                $nextDate = adc_next_month((string)$task['due_date'], (int)$task['anchor_day']);
                $db->prepare('UPDATE personal_tasks SET due_date = ? WHERE id = ? AND user_id = ?')
                   ->execute([$nextDate, $taskId, (int)$current['id']]);
            } else {
                $db->prepare('UPDATE personal_tasks SET is_done = 1, completed_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?')
                   ->execute([$taskId, (int)$current['id']]);
            }
            $db->commit();
            adc_flash('Готово. Задача отмечена.');
            adc_redirect('?month=' . substr((string)$task['due_date'], 0, 7));
        }

        if ($action === 'create_demo') {
            $name = adc_input('client_name');
            if ($name === '' || mb_strlen($name) > 240) {
                throw new RuntimeException('Укажите название демо-клиента.');
            }
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO clients(name, is_demo, created_by) VALUES (?,1,?)');
            $stmt->execute([$name, (int)$current['id']]);
            $clientId = (int)$db->lastInsertId();
            $stmt = $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency,status) VALUES(?,CURDATE(),DAY(CURDATE()),"prepayment","BYN","active")');
            $stmt->execute([$clientId]);
            adc_audit($db, (int)$current['id'], 'create_demo_client', 'client', $clientId);
            $db->commit();
            adc_flash('Демонстрационный клиент создан. В реальную аналитику он не попадёт.');
            adc_redirect();
        }

        if ($action === 'delete_demo') {
            adc_require_director($current);
            $clientId = filter_var(adc_input('client_id'), FILTER_VALIDATE_INT);
            if (!$clientId) {
                throw new RuntimeException('Неверный клиент.');
            }
            $stmt = $db->prepare('DELETE FROM clients WHERE id = ? AND is_demo = 1');
            $stmt->execute([$clientId]);
            if (!$stmt->rowCount()) {
                throw new RuntimeException('Удалять можно только демонстрационных клиентов.');
            }
            adc_audit($db, (int)$current['id'], 'delete_demo_client', 'client', $clientId);
            adc_flash('Демонстрационный клиент полностью удалён.');
            adc_redirect();
        }

        if ($action === 'create_employee') {
            adc_require_director($current);
            $email = mb_strtolower(adc_input('employee_email'));
            $name = adc_input('employee_name');
            $password = adc_input('employee_password');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 2 || mb_strlen($name) > 120 || strlen($password) < 12) {
                throw new RuntimeException('Укажите имя, email и временный пароль от 12 символов.');
            }
            $stmt = $db->prepare('INSERT INTO users(email, display_name, password_hash, role, must_change_password) VALUES (?,?,?,"employee",1)');
            $stmt->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT)]);
            adc_audit($db, (int)$current['id'], 'create_employee', 'user', (int)$db->lastInsertId());
            adc_flash('Сотрудник добавлен. Передайте временный пароль безопасным способом.');
            adc_redirect();
        }

        if ($action === 'deactivate_employee') {
            adc_require_director($current);
            $employeeId = filter_var(adc_input('employee_id'), FILTER_VALIDATE_INT);
            if (!$employeeId) {
                throw new RuntimeException('Не выбран сотрудник.');
            }
            $stmt = $db->prepare('UPDATE users SET is_active = 0 WHERE id = ? AND role = "employee"');
            $stmt->execute([$employeeId]);
            adc_audit($db, (int)$current['id'], 'deactivate_employee', 'user', (int)$employeeId);
            adc_flash('Доступ сотрудника отключён.');
            adc_redirect();
        }

        throw new RuntimeException('Неизвестное действие.');
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($error instanceof PDOException) {
            error_log('Ad Client Manager DB action error: ' . $error->getCode());
            $errorMessage = 'Не удалось выполнить действие. Проверьте корректность данных.';
        } else {
            $errorMessage = $error->getMessage();
        }
    }
}

$alert = $errorMessage ? '<div class="error">' . adc_h($errorMessage) . '</div>' : adc_notice();
$csrf = adc_csrf();

if (!$initialized) {
    $html = '<section class="card narrow"><h1>Первичная настройка</h1>'
        . '<p>Создайте первого директора. Эта форма исчезнет после успешной настройки.</p>' . $alert
        . '<form method="post" class="stack"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="bootstrap">'
        . '<div class="field"><label>Секрет первичной настройки</label><input type="password" name="setup_secret" autocomplete="off" required></div>'
        . '<div class="field"><label>Имя директора</label><input name="display_name" maxlength="120" required></div>'
        . '<div class="field"><label>Email директора</label><input type="email" name="email" required></div>'
        . '<div class="field"><label>Пароль (от 12 символов)</label><input type="password" name="password" minlength="12" autocomplete="new-password" required></div>'
        . '<button type="submit">Создать директора</button></form><p class="muted"><small>Секрет указан в закрытом конфигурационном файле сервера. Не отправляйте его в переписке.</small></p></section>';
    adc_page('Первичная настройка', $html);
}

$user = adc_current_user($db);
if (!$user) {
    $html = '<section class="card narrow"><h1>Вход</h1><p class="muted">Доступ только для сотрудников компании.</p>' . $alert
        . '<form method="post" class="stack"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="login">'
        . '<div class="field"><label>Email</label><input type="email" name="email" autocomplete="username" required></div>'
        . '<div class="field"><label>Пароль</label><input type="password" name="password" autocomplete="current-password" required></div>'
        . '<button type="submit">Войти</button></form></section>';
    adc_page('Вход', $html);
}

if ((int)$user['must_change_password'] === 1) {
    $html = '<section class="card narrow"><h1>Измените временный пароль</h1>' . $alert
        . '<form method="post" class="stack"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="change_password">'
        . '<div class="field"><label>Временный пароль</label><input name="old_password" type="password" autocomplete="current-password" required></div>'
        . '<div class="field"><label>Новый пароль (от 12 символов)</label><input name="new_password" type="password" minlength="12" autocomplete="new-password" required></div>'
        . '<button type="submit">Сохранить новый пароль</button></form></section>';
    adc_page('Пароль', $html, $user);
}

$month = is_string($_GET['month'] ?? null) ? $_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
    $month = date('Y-m');
}
$start = $month . '-01';
$next = (new DateTimeImmutable($start))->modify('first day of next month')->format('Y-m-d');
$previousMonth = (new DateTimeImmutable($start))->modify('-1 month')->format('Y-m');
$nextMonth = substr($next, 0, 7);

$stmt = $db->prepare('SELECT * FROM personal_tasks WHERE user_id = ? AND due_date >= ? AND due_date < ? ORDER BY due_date, id');
$stmt->execute([(int)$user['id'], $start, $next]);
$tasks = $stmt->fetchAll();

$stmt = $db->prepare('SELECT c.due_date,c.completed_at,t.title FROM personal_task_completions c
JOIN personal_tasks t ON t.id = c.task_id WHERE c.user_id = ? AND c.due_date >= ? AND c.due_date < ?
ORDER BY c.due_date');
$stmt->execute([(int)$user['id'], $start, $next]);
$completions = $stmt->fetchAll();

$stmt = $db->query('SELECT id, name FROM clients WHERE is_demo = 1 ORDER BY id DESC LIMIT 100');
$demoClients = $stmt->fetchAll();

$realCount = (int)$db->query('SELECT COUNT(*) FROM clients c WHERE ' . adc_demo_analytics_predicate('c'))->fetchColumn();

$body = '<h1>Рабочий стол</h1>' . $alert
      . '<div class="notice">Фундамент системы: вход, роли, личные задачи и демо-клиенты. Реальные клиентские данные пока не вводим — функциональные разделы ещё разрабатываются.</div>'
      . '<div class="grid"><section class="card"><h2>Мои задачи</h2>'
      . '<div class="months"><a href="/?month=' . adc_h($previousMonth) . '" class="btn secondary">←</a><strong>' . adc_h($month) . '</strong><a href="/?month=' . adc_h($nextMonth) . '" class="btn secondary">→</a></div>';

if (!$tasks && !$completions) {
    $body .= '<p class="muted">На выбранный месяц задач пока нет.</p>';
}
foreach ($tasks as $task) {
    $done = (int)$task['is_done'] === 1;
    $body .= '<div class="item"><div><div class="item-title">' . adc_h($task['title']) . '</div>'
          . '<small>' . adc_h($task['due_date']) . ((int)$task['repeat_monthly'] === 1 ? ' · Каждый месяц' : ' · Один раз') . '</small></div>'
          . '<div class="actions">';
    if (!$done) {
        $body .= '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="complete_task">'
            . '<input type="hidden" name="task_id" value="' . (int)$task['id'] . '"><button type="submit">Готово</button></form>';
    }
    $body .= '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="delete_task">'
          . '<input type="hidden" name="task_id" value="' . (int)$task['id'] . '"><button class="secondary" type="submit">Удалить</button></form></div></div>';
}
foreach ($completions as $completion) {
    $body .= '<div class="item"><div><div class="item-title">' . adc_h($completion['title']) . '</div><small>'
          . adc_h($completion['due_date']) . '</small></div><span class="badge good">Выполнено</span></div>';
}

$body .= '<h2 style="margin-top:20px">Добавить задачу</h2><form method="post">'
      . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="create_task">'
      . '<div class="field"><label>Название</label><input name="title" maxlength="240" placeholder="Что нужно сделать?" required></div>'
      . '<div class="field"><label>Когда напомнить?</label><input type="date" name="due_date" value="' . date('Y-m-d') . '" required></div>'
      . '<label class="check"><input type="checkbox" name="repeat_monthly" value="1"> Повторять каждый месяц</label>'
      . '<button type="submit">Добавить</button></form></section>';

$body .= '<section class="card"><h2>Демо-клиенты</h2>'
      . '<p class="muted">Можно показывать сотрудникам и удалять. Эти записи всегда исключены из реальной аналитики.</p>';
foreach ($demoClients as $client) {
    $body .= '<div class="item"><div class="item-title">' . adc_h($client['name']) . ' <span class="badge demo">Демо</span></div>';
    if ($user['role'] === 'director') {
        $body .= '<form method="post" class="inline"><input type="hidden" name="csrf" value="' . $csrf . '">'
              . '<input type="hidden" name="action" value="delete_demo"><input type="hidden" name="client_id" value="' . (int)$client['id'] . '">'
              . '<button class="secondary" type="submit">Удалить</button></form>';
    }
    $body .= '</div>';
}
$body .= '<h2 style="margin-top:20px">Новый демо-клиент</h2><form method="post"><input type="hidden" name="csrf" value="' . $csrf . '">'
      . '<input type="hidden" name="action" value="create_demo"><div class="field"><label>Название</label>'
      . '<input name="client_name" placeholder="Например, Тестовый клиент" maxlength="240" required></div>'
      . '<button type="submit">Создать демо-клиента</button></form>'
      . '<div class="notice"><strong>Реальных клиентов в аналитике:</strong> ' . $realCount
      . '<p class="muted" style="margin:4px 0 0">Счётчик не включает демонстрационные записи.</p></div></section></div>';

if ($user['role'] === 'director') {
    $users = $db->query('SELECT id,email,display_name,role,is_active FROM users ORDER BY id')->fetchAll();
    $body .= '<section class="card"><h2>Пользователи</h2><table><thead><tr><th>Имя</th><th>Email</th><th>Роль</th><th></th></tr></thead><tbody>';
    foreach ($users as $member) {
        $body .= '<tr><td>' . adc_h($member['display_name']) . '</td><td>' . adc_h($member['email']) . '</td><td>'
            . ($member['role'] === 'director' ? 'Директор' : 'Сотрудник') . ((int)$member['is_active'] === 0 ? ' · Отключён' : '') . '</td><td>';
        if ($member['role'] === 'employee' && (int)$member['is_active'] === 1) {
            $body .= '<form method="post"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="deactivate_employee">'
                . '<input type="hidden" name="employee_id" value="' . (int)$member['id'] . '"><button type="submit" class="secondary">Отключить</button></form>';
        }
        $body .= '</td></tr>';
    }
    $body .= '</tbody></table><h2 style="margin-top:20px">Добавить сотрудника</h2>'
        . '<form method="post" class="grid"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="create_employee">'
        . '<div class="field"><label>Имя</label><input name="employee_name" required></div>'
        . '<div class="field"><label>Email</label><input type="email" name="employee_email" required></div>'
        . '<div class="field"><label>Временный пароль (от 12 символов)</label><input type="password" minlength="12" name="employee_password" autocomplete="new-password" required></div>'
        . '<div class="actions"><button type="submit">Добавить сотрудника</button></div></form></section>';
}

$body .= '<section class="card"><h2>Пароль учётной записи</h2><form method="post" class="grid">'
    . '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="change_password">'
    . '<div class="field"><label>Текущий пароль</label><input type="password" name="old_password" required></div>'
    . '<div class="field"><label>Новый пароль</label><input type="password" name="new_password" minlength="12" required></div>'
    . '<div class="actions"><button type="submit" class="secondary">Изменить пароль</button></div></form></section>';

adc_page('Рабочий стол', $body, $user);
