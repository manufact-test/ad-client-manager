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
    $day = (int)substr($date, 8, 2); // next month follows the start date
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
        'save_client', 'start_engagement', 'save_engagement', 'archive_engagement', 'save_period', 'create_next_period',
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
        $previous = adc_input('previous_client_id') === '' ? null : adc_w_id('previous_client_id');
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
            isset($_POST['requires_acts']) ? 1 : 0,
            isset($_POST['requires_specs']) ? 1 : 0,
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
        $end=adc_w_next_cycle_end($start,(int)$eng['billing_day']);
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
    $previous = [''=>'Ранее не сотрудничали'];
    foreach ($db->query('SELECT id,name FROM clients ORDER BY name LIMIT 500') as $row) {
        if (!$edit || (int)$row['id'] !== (int)$client['id']) {
            $previous[$row['id']] = $row['name'];
        }
    }
    $hasPrevious=!empty($client['previous_client_id']) || trim((string)($client['previous_name_text'] ?? ''))!=='';
    $html .= '</div><details class="repeat-choice"'.($hasPrevious?' open':'').'><summary>Повторное обращение</summary>'.
        '<div class="grid">'.adc_w_select('Выбрать из списка','previous_client_id',$previous,(string)($client['previous_client_id'] ?? '')).
        adc_w_field('Или прежнее название вручную','previous_name_text',
            (string)($client['previous_name_text'] ?? ''),'text','maxlength="240"').'</div></details>' .
        adc_w_check('requires_acts','Нужны акты', (bool)($client['requires_acts'] ?? false)) .
        adc_w_check('requires_specs','Нужны спецификации', (bool)($client['requires_specs'] ?? false)) .
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
    return '<div class="grid">' .
        adc_w_field('Дата начала','started_on',(string)($current['started_on'] ?? date('Y-m-d')),'date','required') .
        '' .
        adc_w_select('Оплата','payment_mode',['prepayment'=>'Предоплата','postpayment'=>'Постоплата'],(string)($current['payment_mode'] ?? 'prepayment')) .
        adc_w_select('Валюта','currency',['BYN'=>'BYN — белорусский рубль','RUB'=>'RUB — российский рубль',
         'EUR'=>'EUR — евро','USD'=>'USD — доллар США','KZT'=>'KZT — тенге'],(string)($current['currency'] ?? 'BYN')) .
        adc_w_field('Стоимость услуг за месяц','quoted_amount',(string)($current['quoted_amount'] ?? ''),'number','min="0" step="0.01" placeholder="0.00"') .
        '</div>';
}

function adc_w_client_panel(PDO $db, array $user, int $id, string $view, string $returnUrl): string {
    $client = adc_w_client($db, $id);
    $html = '<aside class="drawer" id="client" role="region" aria-labelledby="client-heading"><div class="drawer-head"><h2 id="client-heading">' . adc_h($client['name']) .
        ((int)$client['is_demo'] === 1 ? ' <span class="badge demo">Демо</span>' : '') .
        '</h2><a class="btn secondary" href="' . adc_h($returnUrl) . '">Закрыть ✕</a></div>';
    $profileForm = adc_w_client_form($db, $client);
    $balances=adc_w_client_balances($db,$id);
    $html.='<section class="card"><h3>Финансы</h3><div class="summary-grid">';
    if (!$balances) $html.='<p class="muted">Финансовых операций пока нет.</p>';
    foreach ($balances as $row) {
        $ccy=$row['currency'];
        foreach (['Начислено'=>'billed','Оплачено'=>'net','Долг / аванс'=>'balance'] as $label=>$key) {
            $html.='<div class="summary-metric"><small>'.adc_h($label).' · '.adc_h($ccy).
                '</small><strong>'.adc_fin_format($row[$key],$ccy).'</strong></div>';
        }
    }
    $html.='</div></section>';
    $html .= '<p><a class="btn secondary" href="/?export=client&amp;client_id='.(int)$id.'">Выгрузить операции</a></p>';
    $html .= adc_doc_panel($db, $user, $id);
    $stmt = $db->prepare('SELECT * FROM engagements WHERE client_id=? ORDER BY id DESC');
    $stmt->execute([$id]);
    $engagements = $stmt->fetchAll();
    foreach ($engagements as $eng) {
        $eid = (int)$eng['id'];
        $html .= '<section class="card"><h3>Сотрудничество #' . $eid . ' · ' .
            ($eng['status'] === 'active' ? 'Активно' : 'Архив') . '</h3><p class="muted">' .
            adc_h($eng['started_on']) . ' — ' . adc_h((string)($eng['ended_on'] ?? '…')) . ' · ' .
            adc_h($eng['currency']) . ' · ' . ($eng['payment_mode'] === 'prepayment' ? 'Предоплата' : 'Постоплата') .
            ' · расчётный день ' . (int)$eng['billing_day'] . '</p>';
        if ($eng['status'] === 'active') {
            $html .= '<details><summary>✎ Изменить условия и ориентировочную стоимость</summary>' .
                adc_w_form_start('save_engagement') . adc_w_hidden('client_id',$id) .
                adc_w_hidden('engagement_id',$eid) .
                adc_w_hidden('expected_revision',adc_w_engagement_revision($eng)) .
                adc_w_engagement_fields($eng) .
                '<button type="submit">Сохранить условия</button></form></details>';
        }
        $stmt = $db->prepare('SELECT * FROM billing_periods WHERE engagement_id=? ORDER BY period_start DESC,id DESC');
        $stmt->execute([$eid]);
        $periods = $stmt->fetchAll();
        foreach ($periods as $p) {
            $pid = (int)$p['id'];
            $html .= '<details class="period"><summary><strong>' . adc_h($p['period_start']) . ' — ' .
                adc_h($p['period_end']) . '</strong> ' . adc_h((string)$p['period_note']) . '</summary>';
            $stmt = $db->prepare('SELECT * FROM charge_lines WHERE period_id=? AND client_id=? AND is_void=0 ORDER BY id');
            $stmt->execute([$pid, $id]);
            $charges = $stmt->fetchAll();
            $bill = 0;
            foreach ($charges as $row) {
                $bill = adc_fin_add($bill,adc_fin_minor((string)$row['amount']));
                $html .= '<div class="item"><span>' . adc_h($row['label']) . ' <small>(' .
                    adc_h($row['category']) . ')</small></span><strong>' .
                    adc_w_money($row['amount'], $eng['currency']) . '</strong></div>';
                $html .= '<details><summary>✎ Исправить / аннулировать</summary>' .
                    adc_w_charge_form($id, $pid, $row) .
                    adc_w_form_start('void_charge') . adc_w_hidden('client_id', $id) .
                    adc_w_hidden('charge_id', (int)$row['id']) .
                    adc_w_hidden('expected_revision',adc_w_charge_revision($row)) .
                    '<button class="secondary" type="submit">Аннулировать начисление</button></form></details>';
            }
            $html .= '<p><strong>Начислено: ' . adc_fin_format($bill, $eng['currency']) . '</strong></p>' .
                '<details><summary>＋ Строка начисления</summary>' . adc_w_charge_form($id, $pid) . '</details>';
            $stmt = $db->prepare('SELECT * FROM payment_events WHERE period_id=? AND client_id=? ORDER BY occurred_on DESC,id DESC');
            $stmt->execute([$pid, $id]);
            $paid = 0;
            foreach ($stmt->fetchAll() as $pay) {
                $paid = adc_fin_add($paid,($pay['event_type'] === 'received' ? 1 : -1) * adc_fin_minor((string)$pay['amount']));
                $html .= '<div class="item"><span>' . adc_h($pay['occurred_on']) . ' · ' .
                    ($pay['event_type'] === 'received' ? 'Оплата' : 'Возврат') .
                    ' · ' . adc_h((string)$pay['note']) . '</span><span>' .
                    adc_w_money($pay['amount'], $eng['currency']) . '</span></div>';
            }
            $html .= '<p>Получено за период (за вычетом возвратов): <strong>' .
                adc_fin_format($paid, $eng['currency']) . '</strong></p>' .
                '<details><summary>＋ Оплата / возврат</summary>' .
                adc_w_form_start('add_payment') . adc_w_hidden('client_id', $id) .
                adc_w_hidden('period_id', $pid) .
                adc_w_hidden('submission_key',bin2hex(random_bytes(32))) .
                adc_w_select('Операция','event_type',['received'=>'Поступление','refund'=>'Возврат']) .
                adc_w_field('Сумма','amount','','number','step="0.01" min="0.01" required') .
                adc_w_field('Дата','occurred_on',date('Y-m-d'),'date','required') .
                adc_w_field('Примечание','note','','text','maxlength="500"') .
                '<button type="submit">Записать операцию</button></form></details></details>';
        }
        if ($eng['status'] === 'active') {
            $html .= adc_w_form_start('create_next_period') . adc_w_hidden('client_id',$id) .
                adc_w_hidden('engagement_id',$eid) .
                adc_w_hidden('expected_tail',adc_w_period_tail($periods[0]??null)) .
                adc_w_hidden('submission_key',bin2hex(random_bytes(32))) .
                '<button type="submit" class="secondary">＋ Следующий период автоматически</button></form>';
        }
        $html .= '<details><summary>＋ Расчётный период вручную (включая историю)</summary>' .
            adc_w_form_start('save_period') . adc_w_hidden('client_id', $id) .
            adc_w_hidden('engagement_id', $eid) .
            adc_w_field('Начало','period_start',date('Y-m-01'),'date','required') .
            adc_w_field('Конец (не включая этот день)','period_end',date('Y-m-d',strtotime('first day of next month')),'date','required') .
            adc_w_field('Примечание','period_note','','text','maxlength="400"') .
            adc_w_check('is_final','Заключительный период',false) .
            '<button type="submit">Добавить период</button></form></details>';
        if ($eng['status'] === 'active') {
            $html .= '<details><summary>Завершить сотрудничество</summary>' .
                adc_w_form_start('archive_engagement') . adc_w_hidden('client_id', $id) .
                adc_w_hidden('engagement_id', $eid) .
                adc_w_field('Дата','ended_on',date('Y-m-d'),'date','required') .
                adc_w_field('Причина','end_reason','','text','maxlength="500"') .
                '<button type="submit">Перенести в архив</button></form></details>';
        }
        $html .= '</section>';
    }
    $active = array_filter($engagements, fn($e)=>$e['status'] === 'active');
    if (!$active) {
        $html .= '<section class="card"><h3>Возобновить сотрудничество</h3>' .
            adc_w_form_start('start_engagement') . adc_w_hidden('client_id', $id) .
            adc_w_engagement_fields() .
            '<button type="submit">Начать новое сотрудничество</button></form></section>';
    }
    if ((int)$client['is_demo'] === 1 && $user['role'] === 'director') {
        $html .= '<details class="card"><summary>Полностью удалить демо-клиента</summary>' .
            '<p>Удаляются начисления, оплаты, периоды и другие тестовые записи. Необратимо.</p>' .
            adc_w_form_start('delete_demo') . adc_w_hidden('client_id', $id) .
            '<button type="submit">Удалить демо-клиента</button></form></details>';
    }
    $html .= $profileForm;
    return $html . '</aside><a href="' . adc_h($returnUrl) . '" class="drawer-shade" aria-label="Закрыть карточку"></a>';
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
        '<div class="table-scroll" tabindex="0" role="region" aria-label="Список клиентов: таблицу можно прокручивать по горизонтали"><table><thead><tr>'.
        '<th>Клиент</th><th>Услуги</th><th>Источник</th><th>Карточка</th>'.
        '</tr></thead><tbody>';
    foreach ($clients as $client) {
        $html.='<tr><td><strong>'.adc_h($client['name']).'</strong>'.
            ((int)$client['is_demo']===1?' <span class="badge demo">Демо</span>':'').
            '</td><td>'.adc_h((string)$client['platform_text']).'</td><td>'.
            adc_h(adc_source_label((string)$client['source_kind'])).'</td><td><a href="'.
            adc_h($returnUrl.'&client='.(int)$client['id']).'">Открыть →</a></td></tr>';
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

function adc_w_tasks(PDO $db, array $user): string {
    $stmt = $db->prepare('SELECT * FROM personal_tasks WHERE user_id=? AND is_done=0 ORDER BY due_date,id');
    $stmt->execute([(int)$user['id']]);
    $tasks = $stmt->fetchAll();
    $html = '<h1>Мои задачи</h1><section class="card">' .
        adc_w_form_start('create_task') .
        '<div class="grid">' .
        adc_w_field('Название','title','','text','maxlength="240" required') .
        adc_w_field('Дата','due_date',date('Y-m-d'),'date','required') .
        '</div>' . adc_w_check('repeat_monthly','Каждый месяц',false) .
        '<button type="submit">Добавить задачу</button></form></section><section class="card">';
    $last = '';
    $today = date('Y-m-d');
    foreach ($tasks as $task) {
        $day = $task['due_date'];
        if ($day !== $last) {
            $html .= '<h3>' . adc_h($day) . ' ' . ($day < $today ? '<span class="badge">Просрочено</span>' :
                ($day === $today ? '<span class="badge good">Сегодня</span>' : '')) . '</h3>';
            $last = $day;
        }
        $html .= '<div class="item"><div><strong>' . adc_h($task['title']) . '</strong>' .
            ((int)$task['repeat_monthly'] === 1 ? ' <small>↻ Ежемесячно</small>' : '') . '</div><div class="actions">' .
            '<a class="btn secondary" aria-label="Редактировать задачу" href="/?view=tasks&task=' .
            (int)$task['id'] . '">✎</a>' .
            adc_w_form_start('complete_task') . adc_w_hidden('task_id', (int)$task['id']) .
            '<button type="submit">Готово</button></form></div></div>';
    }
    if (!$tasks) {
        $html .= '<p class="muted">Активных задач нет.</p>';
    }
    $html .= '</section>';
    $editId = filter_var($_GET['task'] ?? null, FILTER_VALIDATE_INT);
    if ($editId) {
        $stmt = $db->prepare('SELECT * FROM personal_tasks WHERE id=? AND user_id=? AND is_done=0');
        $stmt->execute([(int)$editId,(int)$user['id']]);
        $task = $stmt->fetch();
        if ($task) {
            $html .= '<section class="card"><h2>Редактировать задачу</h2>' .
                adc_w_form_start('edit_task') . adc_w_hidden('task_id', (int)$task['id']) .
                adc_w_field('Название','title',$task['title'],'text','maxlength="240" required') .
                adc_w_field('Дата','due_date',$task['due_date'],'date','required') .
                adc_w_check('repeat_monthly','Повторять каждый месяц',(int)$task['repeat_monthly'] === 1) .
                '<button type="submit">Сохранить</button></form>' .
                adc_w_form_start('delete_task') . adc_w_hidden('task_id',(int)$task['id']) .
                '<button class="secondary" type="submit">Удалить задачу</button></form></section>';
        }
    }
    return $html;
}

function adc_w_analytics(PDO $db): string {
    return adc_fin_render($db);
}

function adc_w_team(PDO $db, array $user): string {
    $html = '<h1>Настройки</h1><section class="card"><h2>Пароль учётной записи</h2>' .
        adc_w_form_start('change_password') .
        adc_w_field('Текущий пароль','old_password','','password','required') .
        adc_w_field('Новый пароль (12+ символов)','new_password','','password','minlength="12" required') .
        '<button type="submit">Изменить пароль</button></form></section>';
    if ($user['role'] === 'director') {
        $html .= '<section class="card"><h2>Сотрудники</h2>';
        foreach ($db->query('SELECT id,display_name,role,is_active FROM users ORDER BY id') as $member) {
            $html .= '<div class="item"><span>' . adc_h($member['display_name']) . ' · ' .
                adc_h($member['role']) . ((int)$member['is_active'] === 1 ? '' : ' · отключён') . '</span>';
            if ($member['role'] === 'employee' && (int)$member['is_active'] === 1) {
                $html .= adc_w_form_start('deactivate_employee') .
                    adc_w_hidden('employee_id',(int)$member['id']) .
                    '<button type="submit" class="secondary">Отключить</button></form>' .
                '<details><summary>Выдать новый временный пароль</summary>' .
                adc_w_form_start('reset_employee_password') .
                adc_w_hidden('employee_id',(int)$member['id']) .
                adc_w_field('Новый временный пароль','employee_new_password','','password',
                    'required minlength="12" maxlength="1024" autocomplete="new-password"') .
                '<button type="submit">Сбросить пароль сотрудника</button></form></details>';
            }
            $html .= '</div>';
        }
        $html .= '<details><summary>＋ Добавить сотрудника</summary>' . adc_w_form_start('create_employee') .
            adc_w_field('Имя','employee_name','','text','required maxlength="120"') .
            adc_w_field('Email','employee_email','','email','required') .
            adc_w_field('Временный пароль','employee_password','','password','required minlength="12"') .
            '<button type="submit">Создать сотрудника</button></form></details></section>';
    }
    $html .= adc_import_panel($user);
    $html .= adc_audit_panel($db,$user);
    return $html;
}

function adc_w_page(PDO $db, array $user, string $alert): never {
    $view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'clients';
    if (!in_array($view,['clients','tasks','archive','analytics','team'],true)) {
        $view='clients';
    }
    $body = $alert;
    $body .= match ($view) {
        'clients','archive' => adc_w_clients($db, $user, $view),
        'tasks' => adc_w_tasks($db, $user),
        'analytics' => adc_w_analytics($db),
        default => adc_w_team($db, $user),
    };
    adc_page('Ad Client Manager', $body, $user);
}
