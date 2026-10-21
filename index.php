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
       .tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 23px}.tabs a{padding:9px 13px;color:#48465c;text-decoration:none;border-radius:8px}.tabs a.active{background:#eae8ff;color:#3025a7;font-weight:700}
       textarea{width:100%;padding:10px;border:1px solid #cfd5df;border-radius:8px;font:inherit;resize:vertical}.field{min-width:0}
       details{margin:10px 0}summary{cursor:pointer;color:#4239a9;font-weight:650;padding:8px 0}.period{border:1px solid #e6e9f1;border-radius:10px;padding:12px;margin:10px 0}.period>summary{color:#232c44}
       h3{font-size:16px;margin:14px 0 10px}.drawer{position:fixed;right:0;top:0;bottom:0;width:min(740px,100vw);background:#f7f8fb;padding:18px;overflow:auto;z-index:20;box-shadow:-20px 0 70px #1921392e}.drawer-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.drawer-head h2{margin:0}.drawer-shade{position:fixed;inset:0;background:#0b133c38;z-index:19}.drawer .card{margin-top:14px}
       
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

require __DIR__ . '/app/workflow.php';
require __DIR__ . '/app/documents.php';
require __DIR__ . '/app/reminders.php';

$config = adc_config();
if ($config === null) {
    http_response_code(503);
    adc_page('Настройка сервера', '<section class="card narrow"><h1>Сервер готовится к запуску</h1><p>Конфигурация базы данных ещё не установлена.</p><small>Для безопасности сайт не принимает пользователей до завершения настройки.</small></section>');
}

try {
    $db = adc_db($config);
    $initialized = adc_has_users($db);
} catch (Throwable $error) {
    // Public diagnostic code contains only a broad category: never expose SQL messages,
    // hostnames, credentials, filenames or stack traces.
    $code = 'ADC-DB-OTHER';
    if (!extension_loaded('pdo_mysql')) {
        $code = 'ADC-DB-DRIVER';
    } elseif ($error instanceof PDOException) {
        $mysqlCode = (int) ($error->errorInfo[1] ?? 0);
        if (in_array($mysqlCode, [1044, 1045, 1698], true)) {
            $code = 'ADC-DB-AUTH';
        } elseif (in_array($mysqlCode, [1049, 1046], true)) {
            $code = 'ADC-DB-NAME';
        } elseif (in_array($mysqlCode, [2002, 2003, 2005, 2013], true)) {
            $code = 'ADC-DB-HOST';
        } elseif ((string) $error->getCode() === '42S02') {
            $code = 'ADC-DB-SCHEMA';
        }
    }
    error_log('Ad Client Manager DB initialization diagnostic: ' . $code);
    http_response_code(503);
    adc_page('Обслуживание', '<section class="card narrow"><h1>Временно недоступно</h1>'
        . '<p>Не удалось подключиться к базе данных.</p>'
        . '<p class="muted">Код диагностики: <strong>' . adc_h($code) . '</strong></p>'
        . '<small>Сообщите только код. Пароль, секрет и файл конфигурации отправлять не нужно.</small></section>');
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

        // Connected workflow actions share the established authentication and CSRF gates.
        if (adc_w_action($db, $current, $action)) {
            adc_redirect();
        }
        if (adc_doc_action($db, $current, $action)) {
            adc_redirect();
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
            adc_redirect('?view=tasks');
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
            adc_redirect('?view=tasks');
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
    // Brave/Chromium password managers may inspect adjacent registration fields.
    // Keep regular inputs before secrets; opt out of autofill on one-time setup.
    $html = '<section class="card narrow"><h1>Первичная настройка</h1>'
        . '<p>Создайте первого директора. Эта форма исчезнет после успешной настройки.</p>' . $alert
        . '<form method="post" class="stack" autocomplete="off"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="bootstrap">'
        . '<div class="field"><label>Имя директора</label><input type="text" name="display_name" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="120" required></div>'
        . '<div class="field"><label>Email директора</label><input type="email" name="email" autocomplete="off" autocapitalize="off" spellcheck="false" required></div>'
        . '<div class="field"><label>Пароль (от 12 символов)</label><input type="password" name="password" minlength="12" autocomplete="new-password" required></div>'
        . '<div class="field"><label>Секрет первичной настройки</label><input type="password" name="setup_secret" autocomplete="off" required></div>'
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

// Only authenticated users who have changed a temporary password can retrieve PDFs.
if (isset($_GET['download'])) {
    adc_doc_download($db, $user);
}

// The old demo-only and month-switching dashboard has been superseded.
adc_w_page($db, $user, $alert);
