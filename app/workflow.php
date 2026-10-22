<?php
declare(strict_types=1);

/**
 * Connected client/finance workspace. All writes are server-side and use the
 * existing MySQL schema. This module is intentionally not loaded on deploy
 * until the feature branch has passed integration review.
 */

function adc_w_date(string $value): string {
    if (!adc_valid_date($value)) {
        throw new InvalidArgumentException('Укажите корректную дату.');
    }
    return $value;
}

function adc_w_id(string $field): int {
    $id = filter_var(adc_input($field), FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Не выбрана запись: ' . $field);
    }
    return $id;
}

function adc_w_amount(string $value, bool $signed = false): string {
    $value = str_replace(',', '.', trim($value));
    if (!preg_match('/^-?(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Сумма должна иметь не более двух знаков после запятой.');
    }
    if (!$signed && str_starts_with($value, '-')) {
        throw new InvalidArgumentException('Сумма должна быть положительной.');
    }
    $negative = str_starts_with($value, '-');
    $digits = $negative ? substr($value, 1) : $value;
    [$major, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
    $normalized = ltrim($major, '0');
    $normalized = ($normalized === '' ? '0' : $normalized) . '.' . str_pad($fraction, 2, '0');
    if ($normalized === '0.00') {
        throw new InvalidArgumentException('Нулевая сумма не допускается.');
    }
    return $negative ? '-' . $normalized : $normalized;
}

function adc_w_currency(string $value): string {
    $currency = strtoupper(trim($value));
    if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
        throw new InvalidArgumentException('Валюта — трёхбуквенный код (BYN, RUB, USD и т. д.).');
    }
    return $currency;
}

function adc_w_next_cycle_end(string $start, int $billingDay): string {
    adc_w_date($start);
    if ($billingDay < 1 || $billingDay > 31) {
        throw new InvalidArgumentException('Неверный расчётный день.');
    }
    $date = new DateTimeImmutable($start);
    $monthStart = $date->modify('first day of this month');
    $boundary = $monthStart->setDate((int)$date->format('Y'), (int)$date->format('m'),
        min($billingDay,(int)$monthStart->format('t')));
    if ($boundary->format('Y-m-d') <= $start) {
        return adc_next_month($boundary->format('Y-m-d'), $billingDay);
    }
    return $boundary->format('Y-m-d');
}

function adc_w_pick(string $field, array $allowed): string {
    $value = adc_input($field);
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException('Недопустимое значение поля ' . $field);
    }
    return $value;
}

function adc_w_client(PDO $db, int $id, bool $lock = false): array {
    $stmt = $db->prepare('SELECT * FROM clients WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Клиент не найден.');
    }
    return $row;
}

function adc_w_engagement(PDO $db, int $clientId, int $engagementId): array {
    $stmt = $db->prepare('SELECT * FROM engagements WHERE id = ? AND client_id = ?');
    $stmt->execute([$engagementId, $clientId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Сотрудничество не принадлежит клиенту.');
    }
    return $row;
}

/** Resolve terms as of the requested date, not the latest scheduled change.
 * Adding a future condition keeps the historical snapshot in engagement_terms
 * even though engagements stores the latest settings for compatibility.
 */
function adc_w_effective_terms(PDO $db,array $engagement,string $today): array {
    $q=$db->prepare('SELECT effective_on,billing_day,payment_mode,currency,quoted_amount
        FROM engagement_terms WHERE engagement_id=? AND effective_on<=?
        ORDER BY effective_on DESC,id DESC LIMIT 1');
    $q->execute([(int)$engagement['id'],$today]);
    $term=$q->fetch();
    return $term?:[
        'effective_on'=>$engagement['started_on'],
        'billing_day'=>$engagement['billing_day'],
        'payment_mode'=>$engagement['payment_mode'],
        'currency'=>$engagement['currency'],
        'quoted_amount'=>$engagement['quoted_amount'] ?? null,
    ];
}


/** Resolve a cycle boundary using the terms valid at its start; stop at a
 * scheduled future contract change rather than silently rewriting history. */
function adc_w_next_cycle_end_effective(PDO $db,array $engagement,string $start): string {
    $terms=adc_w_effective_terms($db,$engagement,$start);
    $end=adc_w_next_cycle_end($start,(int)$terms['billing_day']);
    $q=$db->prepare('SELECT MIN(effective_on) FROM engagement_terms
        WHERE engagement_id=? AND effective_on>? AND effective_on<?');
    $q->execute([(int)$engagement['id'],$start,$end]);
    $next=$q->fetchColumn();
    return $next===false || $next===null ? $end : (string)$next;
}

/**
 * Optimistic concurrency token for the full contract timeline. A client
 * lock serializes writes; this token rejects a stale second browser tab.
 * Contract snapshots never contain secret material.
 */
function adc_w_terms_revision(PDO $db,array $engagement): string {
    $q=$db->prepare('SELECT id,effective_on,started_on,billing_day,
        payment_mode,currency,quoted_amount FROM engagement_terms
        WHERE engagement_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([(int)$engagement['id']]);
    $latest=$q->fetch(PDO::FETCH_ASSOC) ?: null;
    return hash('sha256',json_encode([
        adc_w_engagement_revision($engagement),$latest
    ],JSON_THROW_ON_ERROR));
}

function adc_w_period(PDO $db, int $clientId, int $periodId): array {
    $stmt = $db->prepare('SELECT p.*, e.currency, e.status FROM billing_periods p JOIN engagements e ON e.id = p.engagement_id
        WHERE p.id = ? AND e.client_id = ?');
    $stmt->execute([$periodId, $clientId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Расчётный период не принадлежит клиенту.');
    }
    return $row;
}

function adc_w_invalidate_period_documents(PDO $db,int $actor,int $periodId): void {
    $stmt=$db->prepare('SELECT id FROM document_bundles WHERE period_id=? AND is_void=0
        AND (needs_invoice=1 OR needs_spec=1 OR needs_act=1) FOR UPDATE');
    $stmt->execute([$periodId]);
    $documentIds=$stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$documentIds) return;
    // An already obsolete document is not invalidated or audited twice.
    // A new upload has its own ID, so replacement is still fully protected.
    $clear=$db->prepare('UPDATE document_bundles SET transferred_at=NULL WHERE id=?');
    foreach ($documentIds as $documentId) {
        if (adc_doc_requires_replacement($db,(int)$documentId)) continue;
        $clear->execute([$documentId]);
        adc_audit($db,$actor,'invalidate_document','document_bundle',(int)$documentId,
            ['reason'=>'financial_change','period_id'=>$periodId]);
    }
}

function adc_w_collaboration_fields(): array {
    $date = adc_w_date(adc_input('started_on'));
    // In the simplified form the billing day is derived from the start date.
    // Explicit legacy/CSV API submissions retain their historical billing day.
    $givenDay = adc_input('billing_day');
    $day = $givenDay === '' ? (int)substr($date,8,2) :
        filter_var($givenDay,FILTER_VALIDATE_INT);
    if (!$day || $day < 1 || $day > 31) {
        throw new InvalidArgumentException('Расчётный день — от 1 до 31.');
    }
    return [
        $date,
        $day,
        adc_w_pick('payment_mode', ['prepayment', 'postpayment']),
        adc_w_currency(adc_input('currency')),
        in_array(adc_input('quoted_amount'), ['', '0', '0.0', '0.00'], true) ? '0.00' : adc_w_amount(adc_input('quoted_amount'), false),
    ];
}

/** Stay in the correct client/archive tab after an operation. */
function adc_w_client_location(PDO $db, int $clientId): string {
    $stmt=$db->prepare('SELECT EXISTS(SELECT 1 FROM engagements
        WHERE client_id=? AND status="active")');
    $stmt->execute([$clientId]);
    return '?view='.((int)$stmt->fetchColumn()===1?'clients':'archive').'&client='.$clientId;
}

/** Whole-client money includes historical and unassigned rows, never quotes.
 * Demo rows are visible in their own cards, not global financial reports. */
function adc_w_client_balances(PDO $db, int $clientId): array {
    $stmt=$db->prepare('SELECT t.currency,
        COALESCE((SELECT SUM(ch.amount) FROM charge_lines ch
            WHERE ch.client_id=? AND ch.currency=t.currency AND ch.is_void=0),0) billed,
        COALESCE((SELECT SUM(CASE WHEN pe.event_type="received" THEN pe.amount ELSE -pe.amount END)
            FROM payment_events pe WHERE pe.client_id=? AND pe.currency=t.currency),0) received_net
        FROM (SELECT currency FROM charge_lines WHERE client_id=? AND is_void=0
              UNION SELECT currency FROM payment_events WHERE client_id=?) t
        ORDER BY t.currency');
    $stmt->execute([$clientId,$clientId,$clientId,$clientId]);
    $rows=[];
    foreach ($stmt as $entry) {
        $billed=adc_fin_minor((string)$entry['billed']);
        $net=adc_fin_minor((string)$entry['received_net']);
        $rows[]=['currency'=>(string)$entry['currency'],'billed'=>$billed,
            'net'=>$net,'balance'=>adc_fin_add($billed,-$net)];
    }
    return $rows;
}

/** Snapshot shared by Edit and Void so stale tabs cannot silently overwrite changes. */
function adc_w_charge_revision(array $row): string {
    return hash('sha256',json_encode([
        (string)$row['id'],(string)$row['period_id'],(string)$row['category'],
        (string)$row['label'],(string)$row['amount'],
        (string)($row['reason']??''),(string)$row['is_void']
    ],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** Boundary captured when the automatic period button was rendered. */
function adc_w_period_tail(?array $period): string {
    return $period ? (string)$period['id'].':'.(string)$period['period_end'] : 'none';
}

/** Snapshot only editable client fields; used to reject stale browser tabs. */
function adc_w_client_revision(array $row): string {
    return hash('sha256', json_encode([
        (string)$row['id'],(string)$row['name'],(string)($row['platform_text']??''),
        (string)$row['legal_type'],(string)($row['source_kind']??''),
        (string)($row['source_note']??''),(string)($row['previous_client_id']??''),
        (string)($row['previous_name_text']??''),(int)$row['requires_acts'],
        (int)$row['requires_specs'],(string)($row['comment_text']??'')
    ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

/** Don't silently overwrite financial conditions changed by another employee. */
function adc_w_engagement_revision(array $row): string {
    return hash('sha256',json_encode([
        (string)$row['id'],(string)$row['started_on'],(int)$row['billing_day'],
        (string)$row['payment_mode'],(string)$row['currency'],
        (string)$row['quoted_amount'],(string)$row['status']
    ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function adc_w_action(PDO $db, array $user, string $action): bool {
    $supported = [
        'add_engagement_terms', 'save_client', 'start_engagement', 'save_engagement', 'archive_engagement', 'save_period', 'create_next_period',
        'save_charge', 'void_charge', 'add_payment', 'edit_task',
    ];
    if (!in_array($action, $supported, true)) {
        return false;
    }
    $actor = (int)$user['id'];
    $feedback='Изменения сохранены.';
    if ($action === 'edit_task') {
        $taskId = adc_w_id('task_id');
        $title = adc_validate_task_title(adc_input('title'));
        $due = adc_w_date(adc_input('due_date'));
        $repeat = isset($_POST['repeat_monthly']) ? 1 : 0;
        $existingStmt=$db->prepare('SELECT due_date,repeat_monthly,anchor_day FROM personal_tasks
            WHERE id=? AND user_id=? AND is_done=0');
        $existingStmt->execute([$taskId,$actor]);
        $existing=$existingStmt->fetch();
        if (!$existing) {
            throw new RuntimeException('Задача не найдена или недоступна.');
        }
        // Editing only the title must not lose an original 29–31 anchor
        // when a task happens to be scheduled in a shorter month.
        $sameRecurring=(int)$existing['repeat_monthly']===1 && $existing['due_date']===$due;
        $anchor=$repeat ? ($sameRecurring ? (int)$existing['anchor_day'] : (int)substr($due,8,2)) : null;
        $stmt = $db->prepare('UPDATE personal_tasks SET title=?, due_date=?, repeat_monthly=?, anchor_day=?, is_done=0, completed_at=NULL
            WHERE id=? AND user_id=? AND is_done=0');
        $stmt->execute([$title, $due, $repeat, $anchor, $taskId, $actor]);
        if ($stmt->rowCount() < 1) {
            // MySQL may return zero for an identical update; check ownership.
            $check = $db->prepare('SELECT id FROM personal_tasks WHERE id=? AND user_id=? AND is_done=0');
            $check->execute([$taskId, $actor]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Задача не найдена или недоступна.');
            }
        }
        adc_audit($db, $actor, 'edit_task', 'personal_task', $taskId);
        adc_flash('Задача обновлена.');
        adc_redirect('?view=tasks');
    }

    if ($action === 'save_client') {
        $idText = adc_input('client_id');
        $id = $idText === '' ? null : adc_w_id('client_id');
        $name = adc_input('client_name');
        if ($name === '' || mb_strlen($name) > 240) {
            throw new InvalidArgumentException('Укажите название клиента (до 240 символов).');
        }
        $platform = adc_input('platform_text');
        $note = adc_input('source_note');
        $previousName = adc_input('previous_name_text');
        $comment = adc_input('comment_text');
        foreach ([[$platform, 400], [$note, 240], [$previousName, 240]] as [$v, $max]) {
            if (mb_strlen($v) > $max) {
                throw new InvalidArgumentException('Превышена допустимая длина поля.');
            }
        }
        $source = adc_w_pick('source_kind', ['advertising','referral','old_contact','inbound','other']);
        $legal = adc_w_pick('legal_type', ['legal', 'individual']);
        $previousChoice=adc_input('previous_client_id');
        $previous=$previousChoice==='' || $previousChoice==='__manual__' ? null : adc_w_id('previous_client_id');
        if ($previousChoice==='__manual__' && $previousName==='') {
            throw new InvalidArgumentException('Введите название прежнего клиента.');
        }
        if ($previousChoice!=='__manual__') $previousName='';
        $fields = $id === null ? adc_w_collaboration_fields() : null;
        $db->beginTransaction();
        if ($id !== null) {
            $existing = adc_w_client($db, $id, true);
            $expected=adc_input('expected_revision');
            if (!preg_match('/^[a-f0-9]{64}$/D',$expected) ||
                !hash_equals(adc_w_client_revision($existing),$expected)) {
                throw new RuntimeException('Карточка клиента изменена в другой вкладке. Обновите страницу перед сохранением.');
            }
            if ($previous === $id) {
                throw new InvalidArgumentException('Клиент не может ссылаться на себя.');
            }
        }
        if ($previous !== null) {
            $linked=adc_w_client($db, $previous);
            $targetDemo=$id === null ? (int)isset($_POST['is_demo']) : (int)$existing['is_demo'];
            if ((int)$linked['is_demo'] !== $targetDemo) {
                throw new RuntimeException('Нельзя связывать реальных и демонстрационных клиентов.');
            }
        }
        $params = [
            $name, $platform, $legal, $source, $note, $previous, $previousName,
            $id === null ? 0 : (int)$existing['requires_acts'],
            $id === null ? 0 : (int)$existing['requires_specs'],
            $comment
        ];
        if ($id === null) {
            $demo = isset($_POST['is_demo']) ? 1 : 0;
            $stmt = $db->prepare('INSERT INTO clients(name,platform_text,legal_type,source_kind,source_note,
                previous_client_id,previous_name_text,requires_acts,requires_specs,comment_text,is_demo,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([...$params, $demo, $actor]);
            $id = (int)$db->lastInsertId();
            $stmt = $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency,quoted_amount)
                VALUES (?,?,?,?,?,?)');
            $stmt->execute([$id, ...$fields]);
            adc_audit($db, $actor, 'create_client', 'client', $id, ['demo' => (bool)$demo]);
        } else {
            // Demo status is immutable. A user cannot launder demo activity into reporting.
            $stmt = $db->prepare('UPDATE clients SET name=?,platform_text=?,legal_type=?,source_kind=?,source_note=?,
                previous_client_id=?,previous_name_text=?,requires_acts=?,requires_specs=?,comment_text=? WHERE id=?');
            $stmt->execute([...$params, $id]);
            adc_audit($db, $actor, 'edit_client', 'client', $id);
        }
        $db->commit();
        adc_flash('Карточка клиента сохранена.');
        adc_redirect(adc_w_client_location($db,$id));
    }

    $clientId = adc_w_id('client_id');
    $db->beginTransaction();
    $client = adc_w_client($db, $clientId, true);

    if ($action === 'start_engagement') {
        $active = $db->prepare('SELECT id FROM engagements WHERE client_id=? AND status="active" FOR UPDATE');
        $active->execute([$clientId]);
        if ($active->fetch()) {
            throw new RuntimeException('Сначала завершите действующее сотрудничество.');
        }
        $fields = adc_w_collaboration_fields();
        $lastArchive=$db->prepare('SELECT MAX(ended_on) FROM engagements WHERE client_id=? AND status="archived"');
        $lastArchive->execute([$clientId]);
        $lastEnded=$lastArchive->fetchColumn();
        if ($lastEnded!==null && $fields[0]<=$lastEnded) {
            throw new RuntimeException('Новое сотрудничество должно начинаться после последней даты архива.');
        }
        $stmt = $db->prepare('INSERT INTO engagements(client_id,started_on,billing_day,payment_mode,currency,quoted_amount)
            VALUES (?,?,?,?,?,?)');
        $stmt->execute([$clientId, ...$fields]);
        adc_audit($db, $actor, 'resume_client', 'client', $clientId);
    } elseif ($action === 'add_engagement_terms') {
        $eid=adc_w_id('engagement_id');
        $old=adc_w_engagement($db,$clientId,$eid);
        if ($old['status']!=='active') throw new RuntimeException('Нельзя менять закрытое сотрудничество.');
        $expectedTermsRevision=adc_input('expected_terms_revision');
        if (!preg_match('/^[a-f0-9]{64}$/D',$expectedTermsRevision) ||
            !hash_equals(adc_w_terms_revision($db,$old),$expectedTermsRevision)) {
            throw new RuntimeException('История условий изменена в другой вкладке. Обновите карточку перед сохранением.');
        }
        $effective=adc_w_date(adc_input('effective_on'));
        if ($effective<=$old['started_on']) throw new RuntimeException('Новые условия должны вступать в силу позже первоначальной даты начала сотрудничества.');
        $day=(int)substr($effective,8,2);
        $mode=adc_w_pick('payment_mode',['prepayment','postpayment']);
        $ccy=adc_w_currency(adc_input('currency'));
        $rawQuote=adc_input('quoted_amount');
        // An omitted price is unknown, NOT a zero-priced contract.
        // engagements has a legacy NOT NULL field, but the dated version
        // records this distinction with the nullable quoted_amount column.
        $quote=$rawQuote==='' ? null :
            (in_array($rawQuote,['0','0.0','0.00'],true)?'0.00':adc_w_amount($rawQuote));
        $q=$db->prepare('SELECT MAX(effective_on) FROM engagement_terms WHERE engagement_id=?');
        $q->execute([$eid]);$last=$q->fetchColumn();
        if ($last!==false && $effective<=$last) throw new RuntimeException('Новая дата должна быть позже предыдущих условий.');
        $q=$db->prepare('SELECT COUNT(*) FROM engagement_terms WHERE engagement_id=?');
        $q->execute([$eid]);
        if ((int)$q->fetchColumn()===0) {
            $db->prepare('INSERT INTO engagement_terms
                (engagement_id,effective_on,started_on,billing_day,payment_mode,currency,quoted_amount)
                VALUES (?,?,?,?,?,?,?)')->execute([
                    $eid,$old['started_on'],$old['started_on'],$old['billing_day'],
                    $old['payment_mode'],$old['currency'],$old['quoted_amount']
                ]);
        }
        $db->prepare('INSERT INTO engagement_terms
            (engagement_id,effective_on,started_on,billing_day,payment_mode,currency,quoted_amount)
            VALUES (?,?,?,?,?,?,?)')->execute([$eid,$effective,$effective,$day,$mode,$ccy,$quote]);
        $db->prepare('UPDATE engagements SET billing_day=?,payment_mode=?,currency=?,quoted_amount=? WHERE id=?')
          ->execute([$day,$mode,$ccy,$quote??'0.00',$eid]);
        adc_audit($db,$actor,'add_engagement_terms','engagement',$eid,
            ['effective_on'=>$effective,'quoted_amount'=>$quote,'currency'=>$ccy]);
    } elseif ($action === 'save_engagement') {
        $eid=adc_w_id('engagement_id');
        $old=adc_w_engagement($db,$clientId,$eid);
        if ($old['status'] !== 'active') {
            throw new RuntimeException('Исторические условия доступны только для просмотра.');
        }
        $fields=adc_w_collaboration_fields();
        [$started,$day,$mode,$currency,$quote]=$fields;
        $expected=adc_input('expected_revision');
        if (!preg_match('/^[a-f0-9]{64}$/D',$expected) ||
            !hash_equals(adc_w_engagement_revision($old),$expected)) {
            throw new RuntimeException('Условия сотрудничества изменены в другой вкладке. Обновите страницу перед сохранением.');
        }
        $prevStmt=$db->prepare('SELECT MAX(ended_on) FROM engagements WHERE client_id=? AND status="archived"');
        $prevStmt->execute([$clientId]);
        $previousEnd=$prevStmt->fetchColumn();
        if ($previousEnd!==null && $started<=$previousEnd) {
            throw new RuntimeException('Начало действующего сотрудничества пересекается с архивом.');
        }
        $stmt=$db->prepare('SELECT MIN(period_start) FROM billing_periods WHERE engagement_id=?');
        $stmt->execute([$eid]);
        $first=$stmt->fetchColumn();
        if ($first !== null && $started > $first) {
            throw new RuntimeException('Начало сотрудничества не может быть позже существующего периода.');
        }
        $versionCount=$db->prepare('SELECT COUNT(*) FROM engagement_terms WHERE engagement_id=?');
        $versionCount->execute([$eid]);
        if ((int)$versionCount->fetchColumn()>0 &&
            ($old['started_on']!==$started || (int)$old['billing_day']!==$day ||
             $old['payment_mode']!==$mode || $old['currency']!==$currency ||
             adc_fin_minor((string)$old['quoted_amount'])!==adc_fin_minor($quote))) {
            throw new RuntimeException('Используйте «Добавить новые условия», чтобы сохранить историю изменения суммы и дат.');
        }
        if ($old['currency'] !== $currency) {
            $stmt=$db->prepare('SELECT
                (SELECT COUNT(*) FROM charge_lines ch JOIN billing_periods p ON p.id=ch.period_id
                    WHERE p.engagement_id=?)
              + (SELECT COUNT(*) FROM payment_events pe JOIN billing_periods p ON p.id=pe.period_id
                    WHERE p.engagement_id=?)
              + (SELECT COUNT(*) FROM document_bundles d JOIN billing_periods p ON p.id=d.period_id
                    WHERE p.engagement_id=? AND d.is_void=0)');
            $stmt->execute([$eid,$eid,$eid]);
            if ((int)$stmt->fetchColumn()>0) {
                throw new RuntimeException('Нельзя менять валюту после начислений или оплат.');
            }
        }
        $db->prepare('UPDATE engagements SET started_on=?,billing_day=?,payment_mode=?,currency=?,quoted_amount=?
            WHERE id=? AND client_id=? AND status="active"')
            ->execute([$started,$day,$mode,$currency,$quote,$eid,$clientId]);
        adc_audit($db,$actor,'edit_engagement','engagement',$eid,
            ['payment_mode'=>$mode,'billing_day'=>$day,'quoted_amount'=>$quote,'currency'=>$currency]);
    } elseif ($action === 'archive_engagement') {
        $eid = adc_w_id('engagement_id');
        $e = adc_w_engagement($db, $clientId, $eid);
        if ($e['status'] !== 'active') {
            throw new RuntimeException('Сотрудничество уже закрыто.');
        }
        $end = adc_w_date(adc_input('ended_on'));
        if ($end < $e['started_on']) {
            throw new RuntimeException('Дата завершения раньше начала.');
        }
        // Ending is inclusive; period_end is exclusive. Keep financial history,
        // but shorten the last cycle rather than charging for unused future days.
        $cutoff=(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d');
        $periodStmt=$db->prepare('SELECT id,period_start,period_end FROM billing_periods
            WHERE engagement_id=? ORDER BY period_start FOR UPDATE');
        $periodStmt->execute([$eid]);
        foreach ($periodStmt->fetchAll() as $periodRow) {
            if ($periodRow['period_start'] >= $cutoff) {
                throw new RuntimeException('Есть периоды после даты завершения. Исправьте их перед архивированием.');
            }
            if ($periodRow['period_end'] > $cutoff) {
                $db->prepare('UPDATE billing_periods SET period_end=?,is_final=1 WHERE id=?')
                    ->execute([$cutoff,$periodRow['id']]);
                // A shortened period changes the scope of its invoice/act/spec.
                // Keep the old PDF for the director, but require replacement
                // before the employee may download or transfer it again.
                adc_w_invalidate_period_documents($db,$actor,(int)$periodRow['id']);
            }
        }
        $stmt = $db->prepare('UPDATE engagements SET status="archived", ended_on=?, end_reason=? WHERE id=? AND client_id=? AND status="active"');
        $stmt->execute([$end, substr(adc_input('end_reason'), 0, 500), $eid, $clientId]);
        adc_audit($db, $actor, 'archive_engagement', 'engagement', $eid, ['date'=>$end]);
    } elseif ($action === 'create_next_period') {
        $eid = adc_w_id('engagement_id');
        $eng = adc_w_engagement($db,$clientId,$eid);
        $key=adc_input('submission_key');
        if (!preg_match('/^[a-f0-9]{64}$/D',$key)) {
            throw new RuntimeException('Форма периода устарела. Обновите карточку клиента.');
        }
        // Parent client FOR UPDATE serializes all writes to this customer.
        $already=$db->prepare('SELECT engagement_id FROM billing_periods WHERE creation_key=?');
        $already->execute([$key]);
        $savedEngagement=$already->fetchColumn();
        if ($savedEngagement!==false) {
            if ((int)$savedEngagement!==$eid) {
                throw new RuntimeException('Форма периода уже использована для другого сотрудничества.');
            }
            $db->commit();
            adc_flash('Период уже был создан. Повторная запись не добавлена.');
            adc_redirect(adc_w_client_location($db,$clientId));
        }
        if ($eng['status'] !== 'active') {
            throw new RuntimeException('Автоматические периоды создаются только для активного сотрудничества.');
        }
        $stmt=$db->prepare('SELECT id,period_end,is_final FROM billing_periods WHERE engagement_id=?
            ORDER BY period_end DESC,id DESC LIMIT 1 FOR UPDATE');
        $stmt->execute([$eid]);
        $latest=$stmt->fetch() ?: null;
        if (adc_input('expected_tail')!==adc_w_period_tail($latest)) {
            throw new RuntimeException('Список периодов изменился в другой вкладке. Обновите карточку клиента.');
        }
        if ($latest && (int)$latest['is_final']===1) {
            throw new RuntimeException('Заключительный период уже создан. Новый автоматический период невозможен.');
        }
        $start=$latest ? $latest['period_end'] : $eng['started_on'];
        $end=adc_w_next_cycle_end_effective($db,$eng,$start);
        $stmt=$db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end,creation_key) VALUES (?,?,?,?)');
        $stmt->execute([$eid,$start,$end,$key]);
        adc_audit($db,$actor,'create_next_period','billing_period',(int)$db->lastInsertId(),
            ['from'=>$start,'to'=>$end]);
    } elseif ($action === 'save_period') {
        $eid = adc_w_id('engagement_id');
        $eng=adc_w_engagement($db, $clientId, $eid);
        $start = adc_w_date(adc_input('period_start'));
        $end = adc_w_date(adc_input('period_end'));
        if ($start >= $end) {
            throw new RuntimeException('Окончание периода должно быть позже начала.');
        }
        if ($start<$eng['started_on'] ||
            ($eng['status']==='archived' && ($eng['ended_on']===null ||
             $end>(new DateTimeImmutable($eng['ended_on']))->modify('+1 day')->format('Y-m-d')))) {
            throw new RuntimeException('Расчётный период выходит за даты сотрудничества.');
        }
        $note = adc_input('period_note');
        if (mb_strlen($note) > 400) {
            throw new RuntimeException('Слишком длинное примечание.');
        }
        $check=$db->prepare('SELECT id FROM billing_periods WHERE engagement_id=?
            AND period_start < ? AND period_end > ? LIMIT 1 FOR UPDATE');
        $check->execute([$eid,$end,$start]);
        if ($check->fetch()) {
            throw new RuntimeException('Период пересекается с уже существующим.');
        }
        $stmt = $db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end,period_note,is_final)
            VALUES (?,?,?,?,?)');
        $stmt->execute([$eid, $start, $end, $note, isset($_POST['is_final']) ? 1 : 0]);
        adc_audit($db, $actor, 'add_period', 'billing_period', (int)$db->lastInsertId());
    } elseif ($action === 'save_charge') {
        $pid = adc_w_id('period_id');
        $period = adc_w_period($db, $clientId, $pid);
        $category = adc_w_pick('category', ['advertising','development','other']);
        $label = adc_input('label');
        $reason = adc_input('reason');
        if ($label === '' || mb_strlen($label) > 240 || mb_strlen($reason) > 500) {
            throw new RuntimeException('Проверьте название работы и комментарий.');
        }
        $amount = adc_w_amount(adc_input('amount'), true);
        $chargeText = adc_input('charge_id');
        if ($chargeText !== '') {
            $cid = adc_w_id('charge_id');
            $s = $db->prepare('SELECT id,period_id,category,label,amount,reason,is_void FROM charge_lines
                WHERE id=? AND client_id=? AND period_id=? AND is_void=0 FOR UPDATE');
            $s->execute([$cid, $clientId, $pid]);
            $oldCharge=$s->fetch();
            if (!$oldCharge) {
                throw new RuntimeException('Строка начисления не найдена.');
            }
            $revision=adc_input('expected_revision');
            if (!preg_match('/^[a-f0-9]{64}$/D',$revision) ||
                !hash_equals(adc_w_charge_revision($oldCharge),$revision)) {
                throw new RuntimeException('Начисление изменено в другой вкладке. Обновите карточку перед сохранением.');
            }
            $same=$oldCharge['category']===$category && $oldCharge['label']===$label &&
                (string)$oldCharge['amount']===$amount && (string)($oldCharge['reason']??'')===$reason;
            if ($same) {
                // A repeated identical save must not invalidate a signed invoice.
                $feedback='Начисление не изменилось.';
            } else {
                $db->prepare('UPDATE charge_lines SET category=?,label=?,amount=?,reason=? WHERE id=?')
                    ->execute([$category, $label, $amount, $reason, $cid]);
                adc_audit($db, $actor, 'edit_charge', 'charge_line', $cid, ['amount'=>$amount]);
                adc_w_invalidate_period_documents($db,$actor,$pid);
            }
        } else {
            $stmt = $db->prepare('INSERT INTO charge_lines(client_id,period_id,category,label,amount,currency,reason)
                VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$clientId, $pid, $category, $label, $amount, $period['currency'], $reason]);
            adc_audit($db, $actor, 'add_charge', 'charge_line', (int)$db->lastInsertId(), ['amount'=>$amount]);
            adc_w_invalidate_period_documents($db,$actor,$pid);
        }
    } elseif ($action === 'void_charge') {
        $cid = adc_w_id('charge_id');
        $s = $db->prepare('SELECT id,period_id,category,label,amount,reason,is_void
            FROM charge_lines WHERE id=? AND client_id=? AND is_void=0 FOR UPDATE');
        $s->execute([$cid,$clientId]);
        $previous=$s->fetch();
        if (!$previous) throw new RuntimeException('Начисление не найдено.');
        $revision=adc_input('expected_revision');
        if (!preg_match('/^[a-f0-9]{64}$/D',$revision) ||
            !hash_equals(adc_w_charge_revision($previous),$revision)) {
            throw new RuntimeException('Начисление изменено в другой вкладке. Обновите карточку перед аннулированием.');
        }
        $db->prepare('UPDATE charge_lines SET is_void=1 WHERE id=? AND client_id=? AND is_void=0')
            ->execute([$cid,$clientId]);
        adc_audit($db, $actor, 'void_charge', 'charge_line', $cid);
        $affected=(int)$previous['period_id'];
        if ($affected > 0) adc_w_invalidate_period_documents($db,$actor,$affected);
    } elseif ($action === 'add_payment') {
        $pid = adc_w_id('period_id');
        $period = adc_w_period($db, $clientId, $pid);
        $type = adc_w_pick('event_type', ['received','refund']);
        $amount = adc_w_amount(adc_input('amount'));
        $date = adc_w_date(adc_input('occurred_on'));
        $note = adc_input('note');
        if (mb_strlen($note) > 500) {
            throw new RuntimeException('Слишком длинное примечание.');
        }
        $submissionKey=adc_input('submission_key');
        if (!preg_match('/^[a-f0-9]{64}$/D',$submissionKey)) {
            throw new RuntimeException('Форма оплаты устарела. Обновите карточку клиента и повторите ввод.');
        }
        // The locked client row serializes payment POSTs for this customer.
        // A replay of the same form must not create a second payment/audit.
        $existingPayment=$db->prepare('SELECT client_id,period_id,event_type,amount,currency,occurred_on,note
            FROM payment_events WHERE submission_key=?');
        $existingPayment->execute([$submissionKey]);
        $saved=$existingPayment->fetch();
        if ($saved) {
            if ((int)$saved['client_id']!==$clientId ||
                (int)$saved['period_id']!==$pid ||
                $saved['event_type']!==$type || $saved['amount']!==$amount ||
                $saved['currency']!==$period['currency'] || $saved['occurred_on']!==$date ||
                (string)($saved['note']??'')!==$note) {
                throw new RuntimeException('Эта форма уже использована для другой операции. Обновите карточку.');
            }
            $db->commit();
            adc_flash('Операция уже сохранена. Повторное поступление или возврат не создан.');
            adc_redirect(adc_w_client_location($db,$clientId));
        }
        if ($type === 'refund') {
            $stmt = $db->prepare('SELECT COALESCE(SUM(CASE WHEN event_type="received" THEN amount ELSE -amount END),0)
                FROM payment_events WHERE client_id=? AND currency=?');
            $stmt->execute([$clientId, $period['currency']]);
            if (adc_fin_minor($amount) > adc_fin_minor((string)$stmt->fetchColumn())) {
                throw new RuntimeException('Возврат превышает сумму полученных оплат в данной валюте.');
            }
        }
        $stmt = $db->prepare('INSERT INTO payment_events(client_id,period_id,event_type,amount,currency,occurred_on,note,submission_key)
            VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$clientId, $pid, $type, $amount, $period['currency'], $date, $note, $submissionKey]);
        adc_audit($db, $actor, $type === 'refund' ? 'refund' : 'receive_payment', 'payment_event',
            (int)$db->lastInsertId(), ['amount'=>$amount,'currency'=>$period['currency']]);
    }
    $db->commit();
    adc_flash($feedback);
    adc_redirect(adc_w_client_location($db,$clientId));
}

function adc_w_form_start(string $action): string {
    return '<form method="post"><input type="hidden" name="csrf" value="' . adc_csrf() .
        '"><input type="hidden" name="action" value="' . adc_h($action) . '">';
}

function adc_w_hidden(string $key, string|int $value): string {
    return '<input type="hidden" name="' . adc_h($key) . '" value="' . adc_h((string)$value) . '">';
}

function adc_w_field(string $title, string $name, string $value = '', string $type = 'text', string $extra = ''): string {
    return '<label class="field">' . adc_h($title) . '<input name="' . adc_h($name) . '" type="' . adc_h($type) .
        '" value="' . adc_h($value) . '" ' . $extra . '></label>';
}

function adc_w_select(string $title, string $name, array $choices, string $selected = ''): string {
    $html = '<label class="field">' . adc_h($title) . '<select name="' . adc_h($name) . '">';
    foreach ($choices as $key => $label) {
        $html .= '<option value="' . adc_h((string)$key) . '"' . ((string)$key === $selected ? ' selected' : '') .
            '>' . adc_h((string)$label) . '</option>';
    }
    return $html . '</select></label>';
}

function adc_w_check(string $name, string $title, bool $checked): string {
    return '<label class="check"><input type="checkbox" name="' . adc_h($name) . '" value="1"' .
        ($checked ? ' checked' : '') . '> ' . adc_h($title) . '</label>';
}

function adc_w_money(string|float|int|null $amount, string $currency): string {
    return adc_fin_format(adc_fin_minor((string)($amount ?? '0.00')),$currency);
}

function adc_w_client_form(PDO $db, ?array $client): string {
    $edit = $client !== null;
    $html = '<details class="card customer-edit"><summary>' .
        ($edit ? 'Изменить данные клиента' : 'Добавить клиента') . '</summary>' .
        adc_w_form_start('save_client');
    if ($edit) {
        $html .= adc_w_hidden('client_id', (int)$client['id']) .
            adc_w_hidden('expected_revision',adc_w_client_revision($client));
    }
    $html .= '<div class="grid">' .
        adc_w_field('Название', 'client_name', (string)($client['name'] ?? ''), 'text', 'required maxlength="240"') .
        adc_w_field('Что делаем для клиента', 'platform_text', (string)($client['platform_text'] ?? ''), 'text', 'maxlength="400"') .
        adc_w_select('Тип клиента', 'legal_type', ['legal'=>'Юридическое лицо','individual'=>'Физическое лицо'], (string)($client['legal_type'] ?? 'legal')) .
        '<div class="source-choice">'.adc_w_select('Откуда пришёл клиент','source_kind',
         ['advertising'=>'Реклама','referral'=>'Рекомендация','old_contact'=>'Старые контакты',
          'inbound'=>'Сам обратился','other'=>'Другое'],(string)($client['source_kind'] ?? 'inbound')).
         '<div class="conditional-other">'.adc_w_field('Укажите источник','source_note',
         (string)($client['source_note'] ?? ''),'text','maxlength="240"').'</div></div>';
    $previous = [''=>'Выберите клиента'];
    foreach ($db->query('SELECT id,name FROM clients ORDER BY name LIMIT 500') as $row) {
        if (!$edit || (int)$row['id'] !== (int)$client['id']) {
            $previous[$row['id']] = $row['name'];
        }
    }
    $previous['__manual__']='Ввести название вручную';
    $hasPrevious=!empty($client['previous_client_id']) || trim((string)($client['previous_name_text'] ?? ''))!=='';
    $selectedPrevious=trim((string)($client['previous_name_text'] ?? ''))!=='' ? '__manual__' :
        (string)($client['previous_client_id'] ?? '');
    $html .= '</div><details class="repeat-choice"'.($hasPrevious?' open':'').'><summary>Повторное обращение</summary>'.
        adc_w_select('Прежний клиент','previous_client_id',$previous,$selectedPrevious).
        '<div class="manual-previous">'.adc_w_field('Название прежнего клиента','previous_name_text',
             (string)($client['previous_name_text'] ?? ''),'text','maxlength="240"').'</div></details>' .
        '<label class="field">Примечания<textarea name="comment_text" rows="2">' . adc_h((string)($client['comment_text'] ?? '')) . '</textarea></label>';
    if (!$edit) {
        $html .= adc_w_check('is_demo', 'Демонстрационный клиент (не учитывается в реальной аналитике)', false) .
            '<h3>Первое сотрудничество</h3>' . adc_w_engagement_fields();
    } else {
        $html .= '';
    }
    return $html . '<p><button type="submit">Сохранить клиента</button></p></form></details>';
}

function adc_w_engagement_fields(?array $current = null): string {
    // This optional monthly contract reference never creates a financial entry.
    $quote=(string)($current['quoted_amount'] ?? '');
    if ($quote==='0.00') $quote='';
    return '<div class="grid">' .
        adc_w_field('Дата начала','started_on',(string)($current['started_on'] ?? date('Y-m-d')),'date','required') .
        adc_w_select('Условия оплаты','payment_mode',['prepayment'=>'Предоплата','postpayment'=>'Постоплата'],(string)($current['payment_mode'] ?? 'prepayment')) .
        adc_w_select('Валюта','currency',['BYN'=>'BYN — белорусский рубль','RUB'=>'RUB — российский рубль',
         'EUR'=>'EUR — евро','USD'=>'USD — доллар США','KZT'=>'KZT — тенге'],(string)($current['currency'] ?? 'BYN')) .
        adc_w_field('Сумма по договору в месяц','quoted_amount',$quote,'number',
            'min="0.01" step="0.01" inputmode="decimal" placeholder="Не указана"') .
        '</div>';
}

/**
 * Real clients may be deleted only if the only relationship is their initial,
 * active engagement. Even voided operations or prior periods are historical
 * data and must never be removed through this convenience action.
 */
function adc_w_can_delete_empty_client(PDO $db, int $clientId): bool {
    $q=$db->prepare('SELECT id,status FROM engagements WHERE client_id=? ORDER BY id');
    $q->execute([$clientId]);
    $engagements=$q->fetchAll();
    if (count($engagements)!==1 || $engagements[0]['status']!=='active') return false;
    foreach ([
        'SELECT 1 FROM billing_periods p JOIN engagements e ON e.id=p.engagement_id WHERE e.client_id=? LIMIT 1',
        'SELECT 1 FROM engagement_terms t JOIN engagements e ON e.id=t.engagement_id WHERE e.client_id=? LIMIT 1',
        'SELECT 1 FROM document_bundles WHERE client_id=? LIMIT 1',
        'SELECT 1 FROM client_entries WHERE client_id=? LIMIT 1',
        'SELECT 1 FROM charge_lines WHERE client_id=? LIMIT 1',
        'SELECT 1 FROM payment_events WHERE client_id=? LIMIT 1',
        'SELECT 1 FROM clients WHERE previous_client_id=? LIMIT 1',
    ] as $sql) {
        $check=$db->prepare($sql);
        $check->execute([$clientId]);
        if ($check->fetchColumn()!==false) return false;
    }
    return true;
}

function adc_w_client_panel(PDO $db, array $user, int $id, string $view, string $returnUrl): string {
    $client = adc_w_client($db, $id);
    $qDate = static fn(?string $date): string => $date === null || $date === '' ? '' :
        date('d.m.Y', strtotime($date));
    $source = (string)$client['source_kind'] === 'other' && trim((string)$client['source_note']) !== ''
        ? (string)$client['source_note'] : adc_source_label((string)$client['source_kind']);
    $stmt = $db->prepare('SELECT * FROM engagements WHERE client_id=? ORDER BY
        (status="active") DESC, started_on DESC, id DESC');
    $stmt->execute([$id]);
    $engagements = $stmt->fetchAll();
    $active = array_values(array_filter($engagements, fn($e) => $e['status'] === 'active'));
    $current = $active[0] ?? ($engagements[0] ?? null);
    $html = '<aside class="drawer customer-profile" id="client" role="region" aria-labelledby="client-heading">'.
        '<div class="drawer-head"><h2 id="client-heading">'.adc_h((string)$client['name']).
        ((int)$client['is_demo'] === 1 ? ' <span class="badge demo">Демо</span>' : '').
        '</h2><a class="btn secondary" href="'.adc_h($returnUrl).'">Закрыть ✕</a></div>'.
        '<section class="card customer-identity"><div class="client-summary-header"><h3>О клиенте</h3><a class="btn secondary" href="/?export=client&amp;client_id='.$id.'">Выгрузить историю в CSV</a></div><div class="profile-facts">'.
        '<div><small>Услуги</small><strong>'.adc_h((string)$client['platform_text'] ?: 'Не указаны').'</strong></div>'.
        '<div><small>Тип</small><strong>'.((string)$client['legal_type']==='legal'?'Юридическое лицо':'Физическое лицо').'</strong></div>'.
        '<div><small>Откуда пришёл</small><strong>'.adc_h($source).'</strong></div>';
    if ($current) {
        $currentTerms=adc_w_effective_terms($db,$current,date('Y-m-d'));
        $activeFrom=$currentTerms['effective_on'];
        $html .= '<div><small>Дата начала текущих условий</small><strong>'.adc_h($qDate((string)$activeFrom)).'</strong></div>';
        $currentPrice=$currentTerms['quoted_amount']===null
            ? 'Не указана'
            : (adc_fin_minor((string)$currentTerms['quoted_amount'])===0
                ? 'Без суммы'
                : adc_w_money($currentTerms['quoted_amount'],(string)$currentTerms['currency']));
        $html.='<div><small>Сумма по договору в месяц</small><strong>'.
            adc_h($currentPrice).'</strong></div>';
        $html.='<div><small>Условия оплаты</small><strong>'.
            ($currentTerms['payment_mode']==='prepayment'?'Предоплата':'Постоплата').'</strong></div>';
    }
    if ($client['previous_client_id'] !== null || trim((string)$client['previous_name_text']) !== '') {
        $html .= '<div><small>Сотрудничали ранее</small><strong>'.
            adc_h(trim((string)$client['previous_name_text'])!=='' ?
                (string)$client['previous_name_text'] : 'Повторный клиент').'</strong></div>';
    }
    $html .= '</div>';
    if (trim((string)$client['comment_text'])!=='') {
        $html .= '<p class="client-note">'.nl2br(adc_h((string)$client['comment_text'])).'</p>';
    }
    $html .= '</section>';

    $html .= adc_ledger_panel($db,$user,$id);
    $historic=adc_w_client_balances($db,$id);
    if ($historic) {
        $html.='<details class="card"><summary>История прежнего учёта</summary>'.
            '<p class="muted">Ранее внесённые начисления и платежи сохранены и учитываются в аналитике.</p>';
        foreach ($historic as $balance) {
            $html.='<p>'.adc_h($balance['currency']).': выставлено '.
                adc_fin_format($balance['billed'],$balance['currency']).', получено '.
                adc_fin_format($balance['net'],$balance['currency']).'</p>';
        }
        $html.='</details>';
    }

    $html .= '<section class="card customer-more"><h3>Дополнительные действия</h3>'.
        adc_w_client_form($db,$client);
    foreach ($active as $eng) {
        $eid=(int)$eng['id'];
        $history=$db->prepare('SELECT * FROM engagement_terms WHERE engagement_id=? ORDER BY effective_on DESC,id DESC');
        $history->execute([$eid]);$terms=$history->fetchAll();
        $today=date('Y-m-d');
        $activeTerms=adc_w_effective_terms($db,$eng,$today);
        $latestTerms=$terms[0]??$activeTerms;
        $lastEffective=$terms ? (string)$terms[0]['effective_on'] : (string)$eng['started_on'];
        $newTermsDefaultDate=max($today,(new DateTimeImmutable($lastEffective))
            ->modify('+1 day')->format('Y-m-d'));
        $nextScheduled=null;
        foreach (array_reverse($terms) as $futureTerm) {
            if ((string)$futureTerm['effective_on']>$today) {
                $nextScheduled=$futureTerm;
                break;
            }
        }
        $html.='<div class="terms-list"><h4>Текущие условия</h4>'.
            '<p>'.adc_h($qDate((string)$activeTerms['effective_on'])).' · '.
            ((string)$activeTerms['payment_mode']==='prepayment'?'Предоплата':'Постоплата').
            ' · '.adc_h((string)$activeTerms['currency']).' · расчётный день '.(int)$activeTerms['billing_day'].'</p>';
        if ($nextScheduled!==null) {
            $upcomingRate=$nextScheduled['quoted_amount']===null ? 'сумма не указана' :
                (adc_fin_minor((string)$nextScheduled['quoted_amount'])===0 ? 'без суммы'
                : adc_w_money($nextScheduled['quoted_amount'],(string)$nextScheduled['currency']).' в месяц');
            $html.='<p class="muted"><strong>Запланированы новые условия с '.
                adc_h($qDate((string)$nextScheduled['effective_on'])).':</strong> '.
                ((string)$nextScheduled['payment_mode']==='prepayment'?'предоплата':'постоплата').
                ', '.adc_h((string)$nextScheduled['currency']).', день '.
                (int)$nextScheduled['billing_day'].', '.adc_h($upcomingRate).
                '. До этой даты действуют текущие условия.</p>';
        }
        if ($terms) {
            $html.='<details><summary>История условий · '.count($terms).'</summary>';
            foreach($terms as $term) {
                $price=$term['quoted_amount']===null ? 'Старая сумма неизвестна'
                    : (adc_fin_minor((string)$term['quoted_amount'])===0 ? 'Без суммы'
                    : adc_w_money($term['quoted_amount'],(string)$term['currency']).' в месяц');
                $html.='<p>'.adc_h($qDate((string)$term['effective_on'])).' · '.
                    ((string)$term['payment_mode']==='prepayment'?'Предоплата':'Постоплата').
                    ' · '.adc_h((string)$term['currency']).' · день '.(int)$term['billing_day'].
                    ' · '.adc_h($price).'</p>';
            }
            $html.='</details>';
        }
        $html.='</div><details class="sub-action"><summary>Добавить новые условия</summary>'.
            adc_w_form_start('add_engagement_terms').adc_w_hidden('client_id',$id).
            adc_w_hidden('engagement_id',$eid).
            adc_w_hidden('expected_terms_revision',adc_w_terms_revision($db,$eng)).
            adc_w_field('Действуют с','effective_on',$newTermsDefaultDate,'date','required').
            adc_w_select('Условия оплаты','payment_mode',['prepayment'=>'Предоплата','postpayment'=>'Постоплата'],(string)$latestTerms['payment_mode']).
            adc_w_select('Валюта','currency',['BYN'=>'BYN','RUB'=>'RUB','EUR'=>'EUR','USD'=>'USD','KZT'=>'KZT'],(string)$latestTerms['currency']).
            adc_w_field('Сумма по договору в месяц','quoted_amount',
                $latestTerms['quoted_amount']===null?'':(
                    adc_fin_minor((string)$latestTerms['quoted_amount'])===0?'':(string)$latestTerms['quoted_amount']),
                'number','min="0.01" step="0.01" inputmode="decimal" placeholder="Не указана"').
            '<p class="muted">Пустая сумма означает, что ставка неизвестна. Это не начисление и не поступление.</p>'.
            '<button type="submit">Сохранить новые условия</button></form></details>'.
            '<details class="sub-action"><summary>Завершить сотрудничество</summary>'.
            adc_w_form_start('archive_engagement').adc_w_hidden('client_id',$id).
            adc_w_hidden('engagement_id',$eid).
            adc_w_field('Дата завершения','ended_on',date('Y-m-d'),'date','required').
            adc_w_field('Причина','end_reason','','text','maxlength="500"').
            '<button type="submit">В архив</button></form></details>';
    }
    if (!$active) {
        $html .= '<details class="sub-action"><summary>Возобновить сотрудничество</summary>'.
            adc_w_form_start('start_engagement').adc_w_hidden('client_id',$id).
            adc_w_engagement_fields().'<button type="submit">Возобновить</button></form></details>';
    }
    if ((int)$client['is_demo']===0 && $user['role']==='director' &&
        adc_w_can_delete_empty_client($db,$id)) {
        $html .= '<details class="sub-action"><summary>Удалить клиента</summary>'.
            '<p class="muted">Только для случайно созданной пустой карточки. Удаление необратимо.</p>'.
            adc_w_form_start('delete_empty_client').adc_w_hidden('client_id',$id).
            adc_w_field('Для подтверждения введите УДАЛИТЬ','delete_confirmation','','text',
                'required autocomplete="off" placeholder="УДАЛИТЬ"').
            '<button type="submit" class="secondary">Удалить клиента</button></form></details>';
    }
    if ((int)$client['is_demo']===1 && $user['role']==='director') {
        $html .= '<details class="sub-action"><summary>Удалить демо-клиента</summary>'.
            '<p class="muted">Это действие удалит клиента, его операции и PDF без возможности отмены.</p>'.
            adc_w_form_start('delete_demo').adc_w_hidden('client_id',$id).
            '<button class="secondary" type="submit">Удалить демо-клиента</button></form></details>';
    }
    $html .= '</section></aside><a href="'.adc_h($returnUrl).'" class="drawer-shade" aria-label="Закрыть карточку"></a>';
    return $html;
}

function adc_w_charge_form(int $clientId, int $pid, ?array $charge = null): string {
    $html = adc_w_form_start('save_charge') . adc_w_hidden('client_id', $clientId) .
        adc_w_hidden('period_id', $pid);
    if ($charge) {
        $html .= adc_w_hidden('charge_id', (int)$charge['id']) .
            adc_w_hidden('expected_revision',adc_w_charge_revision($charge));
    }
    return $html .
        adc_w_select('Категория','category',['advertising'=>'Реклама','development'=>'Разработка','other'=>'Прочее'],(string)($charge['category'] ?? 'advertising')) .
        adc_w_field('Название работы','label',(string)($charge['label'] ?? ''),'text','maxlength="240" required') .
        adc_w_field('Сумма (отрицательная для корректировки)','amount',(string)($charge['amount'] ?? ''),'number','step="0.01" required') .
        adc_w_field('Причина изменения / примечание','reason',(string)($charge['reason'] ?? ''),'text','maxlength="500"') .
        '<button type="submit">Сохранить начисление</button></form>';
}

function adc_w_clients(PDO $db, array $user, string $view): string {
    $archive=$view==='archive';
    $query=is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    if (mb_strlen($query)>80) $query=mb_substr($query,0,80);
    $demo=in_array($_GET['demo'] ?? '', ['0','1'], true) ? (string)$_GET['demo'] : '';
    $returnUrl='/?'.http_build_query(['view'=>$view,'q'=>$query,'demo'=>$demo],'','&',PHP_QUERY_RFC3986);
    $sql='SELECT c.* FROM clients c WHERE '.
        ($archive
          ? 'NOT EXISTS (SELECT 1 FROM engagements e WHERE e.client_id=c.id AND e.status="active")'
          : 'EXISTS (SELECT 1 FROM engagements e WHERE e.client_id=c.id AND e.status="active")');
    $args=[];
    if ($demo!=='') {
        $sql.=' AND c.is_demo=?';
        $args[]=(int)$demo;
    }
    if ($query!=='') {
        // Literal substring search, unlike LIKE this does not treat "%" / "_"
        // in user text as wildcards.
        $sql.=' AND (LOCATE(LOWER(?),LOWER(c.name))>0 OR
            LOCATE(LOWER(?),LOWER(COALESCE(c.platform_text,"")))>0)';
        $args[]=$query;
        $args[]=$query;
    }
    $sql.=' ORDER BY c.id DESC LIMIT 300';
    $stmt=$db->prepare($sql);
    $stmt->execute($args);
    $clients=$stmt->fetchAll();
    $html=$archive
        ? '<h1>Архив</h1>'
        : '<h1>Клиенты</h1>'.
          adc_w_client_form($db,null);
    // Automatically computed events are shown only within a relevant client, not as tasks in the directory.
    $html.='<form class="card" method="get" role="search">'.
        adc_w_hidden('view',$view).
        '<div class="grid"><label class="field">Поиск клиентов и услуг'.
        '<input type="search" name="q" maxlength="80" placeholder="Название клиента или услуга" value="'.
        adc_h($query).'"></label><label class="field">Тип клиента<select name="demo">'.
        '<option value="">Все</option>'.
        '<option value="0"'.($demo==='0'?' selected':'').'>Реальные</option>'.
        '<option value="1"'.($demo==='1'?' selected':'').'>Демонстрационные</option>'.
        '</select></label></div><button type="submit">Найти</button> '.
        '<a class="btn secondary" href="/?view='.adc_h($view).'">Сбросить</a>'.
        '</form><section class="card"><p class="muted">Найдено в списке: '.count($clients).
        (count($clients)===300?' (показаны последние 300, уточните поиск)':'').'</p>'.
        '<div class="table-scroll client-table-scroll" tabindex="0" role="region" aria-label="Список клиентов: таблицу можно прокручивать по горизонтали"><table class="client-list-table"><thead><tr>'.
        '<th>Клиент</th><th>Услуги</th><th aria-label="Открыть"></th><th>Статус</th>'.
        '</tr></thead><tbody>';
    foreach ($clients as $client) {
        $html.='<tr><td><strong>'.adc_h($client['name']).'</strong>'.
            ((int)$client['is_demo']===1?' <span class="badge demo">Демо</span>':'').
            '</td><td>'.adc_h((string)$client['platform_text']).'</td><td><a class="btn secondary" href="'.
            adc_h($returnUrl.'&client='.(int)$client['id']).'">Открыть</a></td><td class="status-cell">'.
            '<span class="badge '.adc_ledger_client_status($db,(int)$client['id'],date('Y-m-d'))[1].'">'.
            adc_h(adc_ledger_client_status($db,(int)$client['id'],date('Y-m-d'))[0]).'</span></td></tr>';
    }
    if (!$clients) {
        $html.='<tr><td colspan="4" class="muted">Совпадений нет.</td></tr>';
    }
    $html.='</tbody></table></div></section>';
    $selected=filter_var($_GET['client'] ?? null,FILTER_VALIDATE_INT);
    if ($selected && $selected>0) {
        $exists=$db->prepare('SELECT id FROM clients WHERE id=?');
        $exists->execute([(int)$selected]);
        if ($exists->fetchColumn()) {
            $html.=adc_w_client_panel($db,$user,(int)$selected,$view,$returnUrl);
        } else {
            $html.='<div class="error" role="alert">Клиент больше не существует. Список остался доступен.</div>';
        }
    }
    return $html;
}


/**
 * Director-only shared task calendar. Ordinary employees keep private tasks;
 * the director can see all open tasks but cannot complete or edit someone
 * else's tasks from this read-only overview.
 */
function adc_w_team_schedule(PDO $db,array $user): string {
    if (($user['role']??'')!=='director') return '';
    $staff=$db->query('SELECT id,display_name,role,is_active FROM users ORDER BY display_name,id')
        ->fetchAll(PDO::FETCH_ASSOC);
    $staffIds=array_map(static fn($row)=>(int)$row['id'],$staff);
    $requested=filter_var($_GET['team_member']??null,FILTER_VALIDATE_INT);
    $selected=$requested!==false && in_array((int)$requested,$staffIds,true)?(int)$requested:0;
    $allowed=['current'=>'Актуальные','overdue'=>'Просрочено','today'=>'Сегодня',
        'upcoming'=>'Следующие 30 дней','all'=>'Все открытые'];
    $scope=(string)($_GET['team_scope']??'current');
    if (!isset($allowed[$scope])) $scope='current';
    $today=date('Y-m-d');
    $max=(new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
    $conditions=['t.is_done=0'];$params=[];
    if ($selected>0) {
        $conditions[]='t.user_id=?';$params[]=$selected;
    }
    if ($scope==='current') {
        $conditions[]='t.due_date<=?';$params[]=$max;
    } elseif ($scope==='overdue') {
        $conditions[]='t.due_date<?';$params[]=$today;
    } elseif ($scope==='today') {
        $conditions[]='t.due_date=?';$params[]=$today;
    } elseif ($scope==='upcoming') {
        $conditions[]='t.due_date>=? AND t.due_date<=?';$params[]=$today;$params[]=$max;
    }
    $sql=' FROM personal_tasks t JOIN users u ON u.id=t.user_id WHERE '.
        implode(' AND ',$conditions);
    $stmt=$db->prepare('SELECT t.id,t.title,t.due_date,t.repeat_monthly,
        u.display_name,u.role,u.is_active'.$sql.
        ' ORDER BY t.due_date,t.id LIMIT 301');
    $stmt->execute($params);
    $tasks=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $truncated=count($tasks)>300;
    if ($truncated) $tasks=array_slice($tasks,0,300);
    $html='<section class="card"><h2>Общий календарь команды</h2>'.
        '<p class="muted">Все открытые личные задачи по сотрудникам. '.
        'Это общий просмотр для директора: редактировать и завершать задачу может только её владелец.</p>'.
        '<form method="get"><input type="hidden" name="view" value="tasks"><div class="grid">'.
        '<label class="field">Сотрудник<select name="team_member">'.
        '<option value="0">Вся команда</option>';
    foreach ($staff as $member) {
        $uid=(int)$member['id'];
        $html.='<option value="'.$uid.'"'.($uid===$selected?' selected':'').'>'.
            adc_h((string)$member['display_name']).' ('.($member['role']==='director'?'директор':'сотрудник').
            ((int)$member['is_active']===1?'':', отключён').')</option>';
    }
    $html.='</select></label><label class="field">Срок<select name="team_scope">';
    foreach ($allowed as $key=>$label) {
        $html.='<option value="'.$key.'"'.($key===$scope?' selected':'').'>'.adc_h($label).'</option>';
    }
    $html.='</select></label></div><button type="submit">Показать</button></form>'.
        '<p class="muted">Задач в выборке: '.count($tasks).
        ($truncated?' (первые 300; уточните сотрудника или срок)':'').'</p>';
    if (!$tasks) return $html.'<p class="muted">Задач с такими условиями нет.</p></section>';
    $day='';
    foreach ($tasks as $task) {
        $date=(string)$task['due_date'];
        if ($date!==$day) {
            if ($day!=='') $html.='</div>';
            $day=$date;
            $html.='<div class="task-day"><h3>'.adc_h(date('d.m.Y',strtotime($date))).
                ($date<$today?' <span class="badge">Просрочено</span>':
                ($date===$today?' <span class="badge good">Сегодня</span>':'')).'</h3>';
        }
        $html.='<div class="task-row"><div class="task-title"><strong>'.
            adc_h((string)$task['title']).'</strong><div class="muted">'.
            adc_h((string)$task['display_name']).
            ($task['role']==='director'?' · директор':' · сотрудник').
            ((int)$task['is_active']===1?'':' · учётная запись отключена').
            ((int)$task['repeat_monthly']===1?' · ежемесячно':'').
            '</div></div></div>';
    }
    return $html.'</div></section>';
}

function adc_w_tasks(PDO $db, array $user): string {
    $stmt = $db->prepare('SELECT * FROM personal_tasks WHERE user_id=? AND is_done=0 ORDER BY due_date,id');
    $stmt->execute([(int)$user['id']]);
    $tasks = $stmt->fetchAll();
    $monthNames = [1=>'Январь',2=>'Февраль',3=>'Март',4=>'Апрель',5=>'Май',
        6=>'Июнь',7=>'Июль',8=>'Август',9=>'Сентябрь',10=>'Октябрь',
        11=>'Ноябрь',12=>'Декабрь'];
    $html = '<h1>Мои задачи</h1><section class="card task-create">'.
        adc_w_form_start('create_task').
        '<div class="grid">'.
        adc_w_field('Название','title','','text','maxlength="240" required').
        adc_w_field('Когда напомнить','due_date',date('Y-m-d'),'date','required').
        '</div>'.adc_w_check('repeat_monthly','Повторять каждый месяц',false).
        '<button type="submit">Добавить задачу</button></form></section>';
    $month = ''; $day = '';
    $today = date('Y-m-d');
    foreach ($tasks as $task) {
        $date = (string)$task['due_date'];
        $taskMonth = substr($date,0,7);
        if ($month!==$taskMonth) {
            if ($month!=='') $html.='</div></div></section>';
            $month=$taskMonth;$day='';
            $parts=explode('-',$date);
            $caption=$monthNames[(int)$parts[1]].' '.$parts[0];
            $html.='<section class="card task-month"><h2>'.adc_h($caption).'</h2><div class="task-month-body">';
        }
        if ($day!==$date) {
            if ($day!=='') $html.='</div>';
            $day=$date;
            $todayBadge = $date===$today ? ' <span class="badge good">Сегодня</span>'
                : ($date<$today ? ' <span class="badge">Просрочено</span>' : '');
            $html.='<div class="task-day"><h3>'.adc_h(date('d.m.Y',strtotime($date))).$todayBadge.'</h3>';
        }
        $id=(int)$task['id'];
        $html.='<div class="task-row"><div class="task-title"><strong>'.
            adc_h((string)$task['title']).'</strong>'.
            ((int)$task['repeat_monthly']===1?' <small>↻ Каждый месяц</small>':'').'</div>'.
            '<div class="task-actions"><button type="button" class="secondary" '.
            'aria-expanded="false" aria-controls="task-'.$id.'" data-task-toggle="task-'.$id.'">Изменить</button>'.
            adc_w_form_start('complete_task').adc_w_hidden('task_id',$id).
            '<button type="submit">Готово</button></form></div></div>';
        $html.='<div class="task-inline-edit" id="task-'.$id.'" hidden>'.
            '<h3>Изменить задачу</h3>'.
            adc_w_form_start('edit_task').adc_w_hidden('task_id',$id).
            '<div class="grid">'.
            adc_w_field('Название','title',(string)$task['title'],'text','maxlength="240" required').
            adc_w_field('Когда напомнить','due_date',$date,'date','required').
            '</div>'.adc_w_check('repeat_monthly','Повторять каждый месяц',(int)$task['repeat_monthly']===1).
            '<div class="task-edit-controls"><button type="submit">Сохранить</button>'.
            '<button class="secondary" type="button" data-task-close="task-'.$id.'">Отмена</button></div></form>'.
            '<div class="task-delete">'.adc_w_form_start('delete_task').adc_w_hidden('task_id',$id).
            '<button class="secondary" type="submit">Удалить задачу</button></form></div>'.
            '</div>';

    }
    if ($month!=='') $html.='</div></div></section>';
    if (!$tasks) $html.='<section class="card"><p class="muted">Пока нет задач.</p></section>';
    $html.=adc_w_team_schedule($db,$user);
    return $html;
}

function adc_w_analytics(PDO $db): string {
    return adc_fin_render($db);
}

function adc_w_team(PDO $db, array $user): string {
    $html='<h1>Настройки</h1>';
    if ($user['role']==='director') {
        $html.='<section class="card"><h2>Сотрудники</h2>';
        foreach ($db->query('SELECT id,display_name,role,is_active FROM users ORDER BY id') as $member) {
            $html.='<div class="item"><span><strong>'.adc_h($member['display_name']).'</strong> · '.
                ($member['role']==='director'?'Директор':'Сотрудник').
                ((int)$member['is_active']===1?'':' · Отключён').'</span>';
            if ($member['role']==='employee' && (int)$member['is_active']===0) {
                $html.=adc_w_form_start('activate_employee').
                    adc_w_hidden('employee_id',(int)$member['id']).
                    '<button type="submit" class="secondary">Включить</button></form>';
            }
            if ($member['role']==='employee' && (int)$member['is_active']===1) {
                $html.='<div class="actions">'.adc_w_form_start('deactivate_employee').
                    adc_w_hidden('employee_id',(int)$member['id']).
                    '<button type="submit" class="secondary">Отключить</button></form>'.
                    '<details><summary>Сменить временный пароль</summary>'.
                    adc_w_form_start('reset_employee_password').
                    adc_w_hidden('employee_id',(int)$member['id']).
                    adc_w_field('Новый временный пароль','employee_new_password','','password',
                    'required minlength="12" maxlength="1024" autocomplete="new-password"').
                    '<button type="submit">Сохранить пароль</button></form></details></div>';
            }
            $html.='</div>';
        }
        $html.='<details class="settings-disclosure"><summary>Добавить сотрудника</summary>'.
            '<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="'.adc_csrf().'">'.
            '<input type="hidden" name="action" value="create_employee">'.
            adc_w_field('Имя','employee_name','','text','required maxlength="120" autocomplete="off"').
            adc_w_field('Email сотрудника','employee_email','','email','required autocomplete="off"').
            adc_w_field('Временный пароль','employee_password','','password','required minlength="12" autocomplete="new-password"').
            '<button type="submit">Создать сотрудника</button></form></details></section>';
    }
    $html.='<details class="card settings-disclosure"><summary>Изменить пароль учётной записи</summary>'.
        adc_w_form_start('change_password').
        adc_w_field('Текущий пароль','old_password','','password','required autocomplete="current-password"').
        adc_w_field('Новый пароль','new_password','','password','minlength="12" required autocomplete="new-password"').
        '<button type="submit">Сохранить пароль</button></form></details>';
    $html.=adc_import_panel($user);
    $html.=adc_audit_panel($db,$user);
    return $html;
}

function adc_w_page(PDO $db, array $user, string $alert): never {
    $view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'clients';
    if (!in_array($view,['clients','control','tasks','archive','analytics','team'],true)) {
        $view='clients';
    }
    $body = $alert;
    $body .= match ($view) {
        'clients','archive' => adc_w_clients($db, $user, $view),
        'tasks' => adc_w_tasks($db, $user),
        'control' => adc_reminders_dashboard($db),
        'analytics' => adc_w_analytics($db),
        default => adc_w_team($db, $user),
    };
    adc_page('Ad Client Manager', $body, $user);
}
