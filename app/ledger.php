<?php
declare(strict_types=1);

/** One document / card request = one amount and one receipt toggle.
 * No monthly value is auto-posted; all money comes from explicit entries.
 */
function adc_ledger_entry(PDO $db,int $clientId,int $entryId,bool $lock=false): array {
    $q=$db->prepare('SELECT * FROM client_entries WHERE id=? AND client_id=? AND is_void=0'.($lock?' FOR UPDATE':''));
    $q->execute([$entryId,$clientId]);
    $row=$q->fetch();
    if (!$row) throw new RuntimeException('Запись не найдена. Обновите карточку клиента.');
    return $row;
}
function adc_ledger_money_from_post(): array {
    if (!isset($_POST['has_amount'])) return [null,null];
    return [adc_w_amount(adc_input('amount')),adc_w_currency(adc_input('currency'))];
}
function adc_ledger_action(PDO $db,array $user,string $action): bool {
    if (!in_array($action,['create_card_entry','edit_card_entry','delete_card_entry',
        'receive_entry','undo_entry_receipt','undo_entry_sent'],true)) return false;
    $actor=(int)$user['id'];
    $clientId=adc_w_id('client_id');
    $db->beginTransaction();
    adc_w_client($db,$clientId,true);
    if ($action==='create_card_entry') {
        adc_require_director($user);
        $amount=adc_w_amount(adc_input('amount'));
        $ccy=adc_w_currency(adc_input('currency'));
        $note=adc_input('note');
        if (mb_strlen($note)>500) throw new RuntimeException('Комментарий слишком длинный.');
        $category=adc_w_pick('category',['advertising','development','other']);
        $q=$db->prepare('INSERT INTO client_entries(client_id,entry_kind,amount,currency,category,note,sent_at)
            VALUES (?,"card",?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$clientId,$amount,$ccy,$category,$note]);
        adc_audit($db,$actor,'create_card_entry','client_entry',(int)$db->lastInsertId());
    } elseif (in_array($action,['edit_card_entry','delete_card_entry'],true)) {
        adc_require_director($user);
        $id=adc_w_id('entry_id');$r=adc_ledger_entry($db,$clientId,$id,true);
        if ($r['entry_kind']!=='card') throw new RuntimeException('Изменять документ нужно в его карточке.');
        if ($action==='delete_card_entry') {
            $db->prepare('UPDATE client_entries SET is_void=1 WHERE id=?')->execute([$id]);
        } else {
            $amount=adc_w_amount(adc_input('amount'));
            $ccy=adc_w_currency(adc_input('currency'));
            $note=adc_input('note');
            $category=adc_w_pick('category',['advertising','development','other']);
            if (mb_strlen($note)>500) throw new RuntimeException('Комментарий слишком длинный.');
            $db->prepare('UPDATE client_entries SET amount=?,currency=?,category=?,note=? WHERE id=?')
                ->execute([$amount,$ccy,$category,$note,$id]);
        }
        adc_audit($db,$actor,$action,'client_entry',$id);
    } else {
        $id=adc_w_id('entry_id');$r=adc_ledger_entry($db,$clientId,$id,true);
        if ($action==='receive_entry') {
            if ($r['amount']===null || $r['sent_at']===null || $r['received_at']!==null) {
                throw new RuntimeException('Сначала отправьте документ. Повторная оплата не записана.');
            }
            $db->prepare('UPDATE client_entries SET received_at=UTC_TIMESTAMP() WHERE id=? AND received_at IS NULL')->execute([$id]);
        } elseif ($action==='undo_entry_receipt') {
            if ($r['received_at']===null) throw new RuntimeException('Оплата уже отменена.');
            $db->prepare('UPDATE client_entries SET received_at=NULL WHERE id=?')->execute([$id]);
        } else {
            if ($r['entry_kind']!=='document' || $r['sent_at']===null || $r['received_at']!==null) {
                throw new RuntimeException('Сначала отмените получение оплаты, если оно отмечено.');
            }
            $db->prepare('UPDATE client_entries SET sent_at=NULL WHERE id=?')->execute([$id]);
            $db->prepare('UPDATE document_bundles SET transferred_at=NULL WHERE id=?')->execute([$r['document_id']]);
        }
        adc_audit($db,$actor,$action,'client_entry',$id);
    }
    $db->commit();
    adc_flash('Данные сохранены, статусы и аналитика обновлены.');
    adc_redirect(adc_w_client_location($db,$clientId));
}
function adc_ledger_category(string $cat): string {
    return ['advertising'=>'Реклама','development'=>'Разработка','other'=>'Прочее'][$cat]??'Прочее';
}
function adc_ledger_form_money(array $entry=[]): string {
    $ccy=$entry['currency']??'BYN';
    return adc_w_select('Категория','category',
        ['advertising'=>'Реклама','development'=>'Разработка','other'=>'Прочее'],
        (string)($entry['category']??'advertising')).
        adc_w_field('Сумма','amount',(string)($entry['amount']??''),'number','min="0.01" step="0.01" required').
        adc_w_select('Валюта','currency',
        ['BYN'=>'BYN','RUB'=>'RUB','EUR'=>'EUR','USD'=>'USD','KZT'=>'KZT'],(string)$ccy);
}
/**
 * Assign a sent request to its next contractual billing anchor.
 * Sending up to three days after the anchor still belongs to that cycle.
 * The anchor is a deadline, never an automatic money charge.
 */
function adc_ledger_due_date(string $sentAt,int $billingDay): string {
    if ($billingDay<1 || $billingDay>31 ||
        !preg_match('/^\d{4}-\d{2}-\d{2}/D',$sentAt)) {
        throw new InvalidArgumentException('Неверная договорная дата оплаты.');
    }
    $day=substr($sentAt,0,10);
    if (!adc_valid_date($day)) throw new InvalidArgumentException('Неверная дата отправки.');
    $sent=new DateTimeImmutable($day);
    $first=$sent->modify('first day of this month');
    $due=$first->setDate((int)$first->format('Y'),(int)$first->format('m'),
        min($billingDay,(int)$first->format('t')));
    if ($sent>$due->modify('+3 days')) {
        $next=$first->modify('first day of next month');
        $due=$next->setDate((int)$next->format('Y'),(int)$next->format('m'),
            min($billingDay,(int)$next->format('t')));
    }
    return $due->format('Y-m-d');
}

/** Show a reminder only in the seven calendar days around a billing
 * anchor; check neighboring months for dates like November 1. */
function adc_ledger_preparation_due(string $today,int $billingDay): ?string {
    if (!adc_valid_date($today) || $billingDay<1 || $billingDay>31) {
        throw new InvalidArgumentException('Некорректная дата подготовки документов.');
    }
    $now=new DateTimeImmutable($today);
    $month=$now->modify('first day of this month');
    foreach ([$month->modify('-1 month'),$month,$month->modify('+1 month')] as $anchor) {
        $due=$anchor->setDate((int)$anchor->format('Y'),(int)$anchor->format('m'),
            min($billingDay,(int)$anchor->format('t')));
        if ($now >= $due->modify('-3 days') && $now <= $due->modify('+3 days')) {
            return $due->format('Y-m-d');
        }
    }
    return null;
}

/** Count a sent request against its contractual month even if it was prepared
 * earlier than the three-day window. Preserve the terms active when sent. */
function adc_ledger_has_request_for_due(PDO $db,int $clientId,string $due,string $today): bool {
    $q=$db->prepare('SELECT e.sent_at,
        COALESCE(
          (SELECT t.billing_day FROM engagement_terms t
            JOIN engagements g ON g.id=t.engagement_id
            WHERE g.client_id=e.client_id AND t.effective_on<=DATE(e.sent_at)
            ORDER BY t.effective_on DESC,t.id DESC LIMIT 1),
          (SELECT g.billing_day FROM engagements g
            WHERE g.client_id=e.client_id AND g.started_on<=DATE(e.sent_at)
            ORDER BY g.started_on DESC,g.id DESC LIMIT 1)
        ) AS billing_day
        FROM client_entries e
        WHERE e.client_id=? AND e.is_void=0 AND e.sent_at IS NOT NULL
          AND DATE(e.sent_at)<=?');
    $q->execute([$clientId,$today]);
    foreach ($q as $entry) {
        if ($entry['billing_day']!==null &&
            adc_ledger_due_date((string)$entry['sent_at'],(int)$entry['billing_day'])===$due) {
            return true;
        }
    }
    return false;
}

function adc_ledger_has_overdue(PDO $db,int $clientId,string $today): bool {
    $q=$db->prepare('SELECT e.sent_at,
        COALESCE(
          (SELECT t.billing_day FROM engagement_terms t
            JOIN engagements g ON g.id=t.engagement_id
            WHERE g.client_id=e.client_id AND t.effective_on<=DATE(e.sent_at)
            ORDER BY t.effective_on DESC,t.id DESC LIMIT 1),
          (SELECT g.billing_day FROM engagements g
            WHERE g.client_id=e.client_id AND g.started_on<=DATE(e.sent_at)
            ORDER BY g.started_on DESC,g.id DESC LIMIT 1)
        ) AS billing_day
        FROM client_entries e
        WHERE e.client_id=? AND e.is_void=0 AND e.amount IS NOT NULL
            AND e.sent_at IS NOT NULL AND e.received_at IS NULL');
    $q->execute([$clientId]);
    foreach ($q as $entry) {
        if ($entry['billing_day']===null) continue;
        $due=adc_ledger_due_date((string)$entry['sent_at'],(int)$entry['billing_day']);
        $grace=(new DateTimeImmutable($due))->modify('+3 days')->format('Y-m-d');
        if ($today>$grace) return true;
    }
    return false;
}

function adc_ledger_client_status(PDO $db,int $clientId,string $today): array {
    if (!adc_valid_date($today)) throw new InvalidArgumentException('Неверная дата.');
    $q=$db->prepare('SELECT
        SUM(CASE WHEN sent_at IS NULL THEN 1 ELSE 0 END) unsent,
        SUM(CASE WHEN amount IS NOT NULL AND sent_at IS NOT NULL AND received_at IS NULL THEN 1 ELSE 0 END) unpaid,
        SUM(CASE WHEN received_at IS NOT NULL THEN 1 ELSE 0 END) paid
        FROM client_entries WHERE client_id=? AND is_void=0');
    $q->execute([$clientId]);$s=$q->fetch();
    if ((int)$s['unsent']>0) return ['Отправить документы · '.$s['unsent'],'warning'];
    // The monthly contract only reminds staff to prepare documentation; it
    // never creates a charge or a personal task for each employee.
    $e=$db->prepare('SELECT started_on,billing_day FROM engagements WHERE client_id=? AND status="active" ORDER BY id DESC LIMIT 1');
    $e->execute([$clientId]);$r=$e->fetch();
    $needsDocument=false;
    if($r && $r['started_on']<=$today) {
        $due=adc_ledger_preparation_due($today,(int)$r['billing_day']);
        if ($due!==null && $due >= $r['started_on']) {
            $needsDocument=!adc_ledger_has_request_for_due($db,$clientId,$due,$today);
        }
    }
    if($needsDocument) return ['Подготовить документы','warning'];
    if ((int)$s['unpaid']>0) {
        $late=adc_ledger_has_overdue($db,$clientId,$today);
        return [$late?'Ждём оплату · '.$s['unpaid'].' (просрочка)':'Ждём оплату · '.$s['unpaid'],
            $late?'danger':'warning'];
    }
    if ((int)$s['paid']>0) return ['Оплачено','good'];
    return ['В работе',''];
}

function adc_ledger_panel(PDO $db,array $user,int $clientId): string {
    $q=$db->prepare('SELECT e.*,d.original_filename,d.needs_invoice,d.needs_spec,d.needs_act,d.needs_contract
        FROM client_entries e LEFT JOIN document_bundles d ON d.id=e.document_id
        WHERE e.client_id=? AND e.is_void=0 ORDER BY e.received_at IS NOT NULL,e.id DESC');
    $q->execute([$clientId]);$rows=$q->fetchAll();
    $html='<section class="card ledger-panel"><h3>Документы и оплаты</h3>'.
      '<p class="muted">Каждый документ и каждая оплата на карту учитываются отдельно. Никаких автоматических начислений.</p>';
    $pending=[];$done=[];
    foreach ($rows as $r) {if ($r['received_at']===null) $pending[]=$r;else $done[]=$r;}
    $render=static function (array $r) use($db,$clientId,$user): string {
        $id=(int)$r['id'];$doc=$r['entry_kind']==='document';
        $title=$doc?(string)$r['original_filename']:'Оплата на карту';
        $money=$r['amount']===null?'Без суммы':adc_w_money($r['amount'],$r['currency']);
        $state=$r['received_at']!==null?'Получено':($r['sent_at']===null?'Отправить документ':'Ждём оплату');
        if ($r['amount']===null && $r['sent_at']!==null) $state='Документ отправлен';
        $h='<div class="ledger-row"><div class="ledger-main"><strong>'.adc_h($title).'</strong>'.
            '<div class="muted">'.adc_h($state).' · '.$money.' · '.adc_ledger_category((string)$r['category']).'</div>'.
            (($r['note']??'')!==''?'<small>'.adc_h((string)$r['note']).'</small>':'').'</div><div class="ledger-actions">';
        if ($doc) {
            $h.='<a class="btn secondary" href="/?download='.(int)$r['document_id'].'">Скачать</a>';
            if ($r['sent_at']===null) {
                $h.=adc_w_form_start('transfer_document').adc_w_hidden('client_id',$clientId).
                    adc_w_hidden('document_id',(int)$r['document_id']).'<button type="submit">Отправили</button></form>';
            } elseif ($r['received_at']===null) {
                $h.=adc_w_form_start('undo_entry_sent').adc_w_hidden('client_id',$clientId).
                    adc_w_hidden('entry_id',$id).'<button type="submit" class="secondary">Отменить отправку</button></form>';
            }
        }
        if ($r['amount']!==null && $r['sent_at']!==null) {
            $a=$r['received_at']===null?'receive_entry':'undo_entry_receipt';
            $h.=adc_w_form_start($a).adc_w_hidden('client_id',$clientId).
                adc_w_hidden('entry_id',$id).'<button type="submit"'.($a==='undo_entry_receipt'?' class="secondary"':'').
                '>'.($a==='receive_entry'?'Получили':'Отменить получение').'</button></form>';
        }
        if ($user['role']==='director') {
            if ($doc) {
                $h.='<details class="ledger-edit"><summary>Изменить</summary>'.
                    adc_doc_upload_form($db,$clientId,(int)$r['document_id']).
                    '</details>';
                $h.=adc_w_form_start('void_document').adc_w_hidden('client_id',$clientId).
                    adc_w_hidden('document_id',(int)$r['document_id']).
                    '<button class="secondary" type="submit" data-confirm="Удалить документ и всю связанную оплату?">Удалить</button></form>';
            } else {
                $h.='<details class="ledger-edit"><summary>Изменить</summary>'.
                    adc_w_form_start('edit_card_entry').adc_w_hidden('client_id',$clientId).
                    adc_w_hidden('entry_id',$id).adc_ledger_form_money($r).
                    adc_w_field('Комментарий','note',(string)($r['note']??''),'text','maxlength="500"').
                    '<button type="submit">Сохранить</button></form></details>';
                $h.=adc_w_form_start('delete_card_entry').adc_w_hidden('client_id',$clientId).
                    adc_w_hidden('entry_id',$id).
                    '<button class="secondary" type="submit" data-confirm="Удалить оплату на карту полностью?">Удалить</button></form>';
            }
        }
        return $h.'</div></div>';
    };
    if ($pending) foreach($pending as $r) $html.=$render($r);
    else $html.='<p class="muted">Нет ожидающих отправки или оплаты записей.</p>';
    if ($done) {
        $html.='<details class="ledger-paid"><summary>Оплаченные · '.count($done).'</summary>';
        foreach($done as $r) $html.=$render($r);
        $html.='</details>';
    }
    if ($user['role']==='director') {
        $html.='<details class="sub-action"><summary>Загрузить документ</summary>'.
            adc_doc_upload_form($db,$clientId).'</details>';
 
    }
    return $html.'</section>';
}
