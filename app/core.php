<?php
declare(strict_types=1);

function adc_config_path(): string {
    return dirname(__DIR__, 2) . '/adclients-config.php';
}

function adc_config(): ?array {
    $path = adc_config_path();
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $config = require $path;
    if (!is_array($config) || !isset($config['db'], $config['setup_secret']) ||
        !is_array($config['db']) || !is_string($config['setup_secret']) ||
        strlen($config['setup_secret']) < 24) {
        return null;
    }
    foreach (['host', 'name', 'user', 'password'] as $key) {
        if (!isset($config['db'][$key]) || !is_string($config['db'][$key]) || $config['db'][$key] === '') {
            return null;
        }
    }
    return $config;
}

function adc_db(array $config): PDO {
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'],
        (int) ($db['port'] ?? 3306),
        $db['name']
    );
    return new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function adc_schema(PDO $db): void {
    $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $statements = [
        'CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            display_name VARCHAR(120) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM("director", "employee") NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            must_change_password TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )',
        'CREATE TABLE IF NOT EXISTS login_attempts (
            identity_hash CHAR(64) PRIMARY KEY,
            failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_failure DATETIME NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS audit_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            object_type VARCHAR(80) NOT NULL,
            object_id BIGINT UNSIGNED NULL,
            details_json TEXT NULL,
            occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_actor_time(actor_user_id, occurred_at)
        )',
        'CREATE TABLE IF NOT EXISTS personal_tasks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(240) NOT NULL,
            due_date DATE NOT NULL,
            repeat_monthly TINYINT(1) NOT NULL DEFAULT 0,
            anchor_day TINYINT UNSIGNED NULL,
            is_done TINYINT(1) NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_due(user_id, due_date),
            CONSTRAINT fk_personal_task_user FOREIGN KEY(user_id) REFERENCES users(id)
        )',
        'CREATE TABLE IF NOT EXISTS clients (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(240) NOT NULL,
            platform_text VARCHAR(400) NULL,
            legal_type ENUM("legal", "individual") NOT NULL DEFAULT "legal",
            source_kind VARCHAR(30) NULL,
            source_note VARCHAR(240) NULL,
            previous_client_id BIGINT UNSIGNED NULL,
            previous_name_text VARCHAR(240) NULL,
            requires_acts TINYINT(1) NOT NULL DEFAULT 0,
            requires_specs TINYINT(1) NOT NULL DEFAULT 0,
            is_demo TINYINT(1) NOT NULL DEFAULT 0,
            comment_text TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_client_name(name),
            INDEX idx_client_previous(previous_client_id),
            INDEX idx_demo_client(is_demo)
        )',
        'CREATE TABLE IF NOT EXISTS engagements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            started_on DATE NOT NULL,
            ended_on DATE NULL,
            end_reason VARCHAR(500) NULL,
            billing_day TINYINT UNSIGNED NOT NULL,
            payment_mode ENUM("prepayment", "postpayment") NOT NULL,
            currency CHAR(3) NOT NULL,
            quoted_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
            status ENUM("active", "archived") NOT NULL DEFAULT "active",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_client_engagement(client_id, status),
            CONSTRAINT fk_engagement_client FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE CASCADE
        )',
        'CREATE TABLE IF NOT EXISTS personal_task_completions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            due_date DATE NOT NULL,
            completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_task_completion_user(user_id, completed_at),
            CONSTRAINT fk_completion_task FOREIGN KEY(task_id) REFERENCES personal_tasks(id) ON DELETE CASCADE
        )',
        'CREATE TABLE IF NOT EXISTS billing_periods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            engagement_id BIGINT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            period_note VARCHAR(400) NULL,
            is_final TINYINT(1) NOT NULL DEFAULT 0,
            creation_key CHAR(64) NULL,
            UNIQUE KEY uq_period_creation_key(creation_key),
            UNIQUE KEY uq_engagement_period(engagement_id, period_start, period_end),
            INDEX idx_period_start(period_start),
            CONSTRAINT fk_period_engagement FOREIGN KEY(engagement_id) REFERENCES engagements(id) ON DELETE CASCADE
        )',
        'CREATE TABLE IF NOT EXISTS document_bundles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            period_id BIGINT UNSIGNED NULL,
            storage_key VARCHAR(160) NULL,
            original_filename VARCHAR(250) NULL,
            content_sha256 CHAR(64) NULL,
            needs_contract TINYINT(1) NOT NULL DEFAULT 0,
            needs_spec TINYINT(1) NOT NULL DEFAULT 0,
            needs_invoice TINYINT(1) NOT NULL DEFAULT 0,
            needs_act TINYINT(1) NOT NULL DEFAULT 0,
            transfer_due_on DATE NULL,
            transferred_at DATETIME NULL,
            uploaded_by BIGINT UNSIGNED NULL,
            is_void TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bundle_transfer(transfer_due_on, transferred_at),
            CONSTRAINT fk_bundle_client FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_bundle_period FOREIGN KEY(period_id) REFERENCES billing_periods(id) ON DELETE SET NULL
        )',
        'CREATE TABLE IF NOT EXISTS charge_lines (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            period_id BIGINT UNSIGNED NULL,
            bundle_id BIGINT UNSIGNED NULL,
            category ENUM("advertising","development","other") NOT NULL,
            label VARCHAR(240) NOT NULL,
            amount DECIMAL(16,2) NOT NULL,
            currency CHAR(3) NOT NULL,
            reason VARCHAR(500) NULL,
            is_void TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_charge_client(client_id, category),
            CONSTRAINT fk_charge_client FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_charge_period FOREIGN KEY(period_id) REFERENCES billing_periods(id) ON DELETE SET NULL,
            CONSTRAINT fk_charge_bundle FOREIGN KEY(bundle_id) REFERENCES document_bundles(id) ON DELETE SET NULL
        )',
        'CREATE TABLE IF NOT EXISTS payment_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            period_id BIGINT UNSIGNED NULL,
            event_type ENUM("received","refund") NOT NULL,
            amount DECIMAL(16,2) NOT NULL,
            currency CHAR(3) NOT NULL,
            occurred_on DATE NOT NULL,
            note VARCHAR(500) NULL,
            submission_key CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_payment_submission(submission_key),
            INDEX idx_payment_client(client_id, occurred_on),
            CONSTRAINT fk_payment_client FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_payment_period FOREIGN KEY(period_id) REFERENCES billing_periods(id) ON DELETE SET NULL
        )'
        ,'CREATE TABLE IF NOT EXISTS client_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            document_id BIGINT UNSIGNED NULL,
            entry_kind ENUM("document","card") NOT NULL,
            amount DECIMAL(16,2) NULL,
            currency CHAR(3) NULL,
            category ENUM("advertising","development","other") NOT NULL DEFAULT "advertising",
            note VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            received_at DATETIME NULL,
            is_void TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_entry_document(document_id),
            INDEX idx_entry_client(client_id,is_void),
            CONSTRAINT fk_entry_client FOREIGN KEY(client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_entry_document FOREIGN KEY(document_id) REFERENCES document_bundles(id) ON DELETE SET NULL
        )'
        ,'CREATE TABLE IF NOT EXISTS engagement_terms (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            engagement_id BIGINT UNSIGNED NOT NULL,
            effective_on DATE NOT NULL,
            started_on DATE NOT NULL,
            billing_day TINYINT UNSIGNED NOT NULL,
            payment_mode ENUM("prepayment","postpayment") NOT NULL,
            currency CHAR(3) NOT NULL,
            quoted_amount DECIMAL(16,2) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_terms_effective(engagement_id,effective_on),
            CONSTRAINT fk_terms_engagement FOREIGN KEY(engagement_id) REFERENCES engagements(id) ON DELETE CASCADE
        )'
    ];
    foreach ($statements as $sql) {
        $db->exec($sql . $options);
    }
    // Earlier releases had no price in historic terms. Unknown rates remain
    // SQL NULL rather than being filled with invented historic amounts.
    if (!$db->query("SHOW COLUMNS FROM engagement_terms LIKE 'quoted_amount'")->fetch()) {
        $db->exec('ALTER TABLE engagement_terms
            ADD COLUMN quoted_amount DECIMAL(16,2) NULL DEFAULT NULL');
    }
    // Migrate existing documents into the unified list without fabricating money.
    // Backfill is idempotent: one linked entry per existing PDF.
    $db->exec('INSERT INTO client_entries(client_id,document_id,entry_kind,sent_at,created_at)
        SELECT d.client_id,d.id,"document",d.transferred_at,d.created_at
        FROM document_bundles d LEFT JOIN client_entries e ON e.document_id=d.id
        WHERE d.is_void=0 AND e.id IS NULL');
    // Legacy test installations can have payment_events without the nullable
    // idempotency key. Never rewrite existing payments or generate keys for
    // historic imports: MySQL UNIQUE permits multiple NULL legacy rows.
    $hasKey=$db->query("SHOW COLUMNS FROM payment_events LIKE 'submission_key'")->fetch();
    if (!$hasKey) {
        $db->exec('ALTER TABLE payment_events
            ADD COLUMN submission_key CHAR(64) NULL,
            ADD UNIQUE KEY uq_payment_submission(submission_key)');
    } else {
        $idx=$db->query("SHOW INDEX FROM payment_events WHERE Key_name='uq_payment_submission'")->fetch();
        if (!$idx) $db->exec('ALTER TABLE payment_events ADD UNIQUE KEY uq_payment_submission(submission_key)');
    }
    // Existing periods and historical imports retain NULL. A nullable UNIQUE
    // key safely recognizes repeated automatic creation requests.
    $hasPeriodKey=$db->query("SHOW COLUMNS FROM billing_periods LIKE 'creation_key'")->fetch();
    if (!$hasPeriodKey) {
        $db->exec('ALTER TABLE billing_periods
            ADD COLUMN creation_key CHAR(64) NULL,
            ADD UNIQUE KEY uq_period_creation_key(creation_key)');
    } else {
        $periodIndex=$db->query("SHOW INDEX FROM billing_periods WHERE Key_name='uq_period_creation_key'")->fetch();
        if (!$periodIndex) $db->exec('ALTER TABLE billing_periods ADD UNIQUE KEY uq_period_creation_key(creation_key)');
    }
}

function adc_has_users(PDO $db): bool {
    try {
        return (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (PDOException $e) {
        // No schema exists before the first, secret-gated setup.
        if (in_array($e->getCode(), ['42S02', '42P01'], true)) {
            return false;
        }
        throw $e;
    }
}

function adc_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name('ADCLIENTSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function adc_csrf(): string {
    if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function adc_verify_csrf(): void {
    $submitted = $_POST['csrf'] ?? '';
    if (!is_string($submitted) || !hash_equals(adc_csrf(), $submitted)) {
        http_response_code(403);
        throw new RuntimeException('Проверка формы не пройдена. Обновите страницу.');
    }
}

function adc_valid_date(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function adc_next_month(string $date, int $anchorDay): string {
    if (!adc_valid_date($date) || $anchorDay < 1 || $anchorDay > 31) {
        throw new InvalidArgumentException('Неверная дата повторения');
    }
    $next = (new DateTimeImmutable($date))->modify('first day of next month');
    $day = min($anchorDay, (int) $next->format('t'));
    return $next->setDate((int) $next->format('Y'), (int) $next->format('m'), $day)->format('Y-m-d');
}

function adc_h(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adc_audit(PDO $db, ?int $actorId, string $action, string $type, ?int $objectId, array $details = []): void {
    $stmt = $db->prepare('INSERT INTO audit_events (actor_user_id, action, object_type, object_id, details_json) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$actorId, $action, $type, $objectId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}

function adc_identity_hash(string $email): string {
    return hash('sha256', strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function adc_login_blocked(PDO $db, string $key): bool {
    $stmt = $db->prepare('SELECT failure_count, last_failure FROM login_attempts WHERE identity_hash = ?');
    $stmt->execute([$key]);
    $record = $stmt->fetch();
    return $record && (int)$record['failure_count'] >= 5 &&
        strtotime((string)$record['last_failure']) > time() - 900;
}

function adc_login_failure(PDO $db, string $key): void {
    $stmt = $db->prepare('INSERT INTO login_attempts(identity_hash, failure_count, last_failure) VALUES (?, 1, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
          failure_count = IF(last_failure < UTC_TIMESTAMP() - INTERVAL 15 MINUTE, 1, failure_count + 1),
          last_failure = UTC_TIMESTAMP()');
    $stmt->execute([$key]);
}

/**
 * A session is valid only for the exact password hash used when it was issued.
 * Password changes revoke all other sessions without adding a new database
 * migration. Older sessions without a binding intentionally require re-login.
 */
function adc_auth_binding(string $passwordHash): string {
    return hash('sha256', 'ad-client-manager-auth-v1:' . $passwordHash);
}

function adc_auth_session_issue(int $userId, string $passwordHash): void {
    // Never carry pending import approvals or old CSRF tokens across logins.
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['user_id']=$userId;
    $_SESSION['auth_binding']=adc_auth_binding($passwordHash);
    $_SESSION['auth_issued_at']=time();
    $_SESSION['auth_last_seen_at']=time();
}

function adc_auth_session_clear(): void {
    unset($_SESSION['user_id'],$_SESSION['auth_binding'],
        $_SESSION['auth_issued_at'],$_SESSION['auth_last_seen_at'],
        $_SESSION['history_import_preview']);
}

function adc_auth_session_timely(array $session, int $now): bool {
    $issued=$session['auth_issued_at'] ?? null;
    $last=$session['auth_last_seen_at'] ?? null;
    return is_int($issued) && is_int($last) && $issued>0 && $last>0 &&
        $issued<=$now && $last<=$now && $last>=$issued &&
        ($now-$issued)<=12*3600 && ($now-$last)<=30*60;
}

function adc_current_user(PDO $db): ?array {
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) || $id < 1 || !adc_auth_session_timely($_SESSION,time())) {
        adc_auth_session_clear();
        return null;
    }
    $stmt=$db->prepare('SELECT id,email,display_name,role,is_active,must_change_password,password_hash
        FROM users WHERE id=?');
    $stmt->execute([$id]);
    $row=$stmt->fetch();
    $provided=$_SESSION['auth_binding'] ?? null;
    if (!$row || (int)$row['is_active']!==1 ||
        !is_string($provided) || !hash_equals(adc_auth_binding((string)$row['password_hash']),$provided)) {
        adc_auth_session_clear();
        return null;
    }
    $_SESSION['auth_last_seen_at']=time();
    unset($row['password_hash']);
    return $row;
}

function adc_require_director(array $user): void {
    if ($user['role'] !== 'director') {
        http_response_code(403);
        throw new RuntimeException('Недостаточно прав.');
    }
}

function adc_demo_analytics_predicate(string $clientAlias = 'c'): string {
    if (!preg_match('/^[a-z][a-z0-9_]*$/iD', $clientAlias)) {
        throw new InvalidArgumentException('Invalid SQL alias');
    }
    return $clientAlias . '.is_demo = 0';
}

function adc_validate_task_title(string $title): string {
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 240) {
        throw new InvalidArgumentException('Укажите название задачи (до 240 символов).');
    }
    return $title;
}
