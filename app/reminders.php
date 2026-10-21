<?php
declare(strict_types=1);

/**
 * Derived client reminders: do not persist duplicate personal tasks or mutate
 * MySQL on page views. A prepared PDF hides the preparation reminder, while
 * an untransferred PDF creates an independent handover reminder.
 */
function adc_reminder_month_day(DateTimeImmutable $first, int $day): string {
    if ($day < 1 || $day > 31) {
        throw new InvalidArgumentException('Invalid billing anchor');
    }
    return $first->setDate((int)$first->format('Y'), (int)$first->format('m'),
        min($day, (int)$first->format('t')))->format('Y-m-d');
}

function adc_reminder_document_found(array $documents, string $flag, string $due, string $mode): bool {
    foreach ($documents as $doc) {
        if ((int)$doc['is_void'] !== 0 || $doc['storage_key'] === null || (int)$doc[$flag] !== 1) {
            continue;
        }
        if ($flag === 'needs_contract') return true;
        $start=$doc['period_start'];
        $end=$doc['period_end'];
        if ($start !== null && $end !== null) {
            if ($mode === 'postpayment' && $flag === 'needs_invoice') {
                if ($start < $due && $due <= $end) return true;
            } elseif ($start <= $due && $due < $end) {
                return true;
            }
        } else {
            // Legacy, unassigned PDF can satisfy only its own upload month,
            // never all future invoicing cycles.
            if (substr($doc['created_at'],0,7) === substr($due,0,7)) return true;
        }
    }
    return false;
}

/**
 * @return array<int,array{date:string,kind:string,client_id:int,client_name:string,is_demo:int,label:string}>
 */
function adc_client_reminders(PDO $db, string $today): array {
    if (!adc_valid_date($today)) {
        throw new InvalidArgumentException('Invalid reminder reference date');
    }
    $now=new DateTimeImmutable($today);
    $minDate=$now->modify('-35 days')->format('Y-m-d');
    $maxDate=$now->modify('+45 days')->format('Y-m-d');
    $first=(new DateTimeImmutable($minDate))->modify('first day of this month');
    $stmt=$db->query('SELECT c.id,c.name,c.is_demo,c.requires_specs,c.requires_acts,
        e.id engagement_id,e.started_on,e.billing_day,e.payment_mode
        FROM clients c JOIN engagements e ON e.client_id=c.id
        WHERE e.status="active" ORDER BY c.id');
    $events=[];
    $docsStmt=$db->prepare('SELECT d.is_void,d.storage_key,d.created_at,d.transfer_due_on,d.transferred_at,
        d.needs_contract,d.needs_spec,d.needs_invoice,d.needs_act,p.period_start,p.period_end
        FROM document_bundles d LEFT JOIN billing_periods p ON p.id=d.period_id
        WHERE d.client_id=? AND d.is_void=0');
    foreach ($stmt as $eng) {
        $id=(int)$eng['id'];
        $docsStmt->execute([$id]);
        $documents=$docsStmt->fetchAll();
        $push=static function (string $date,string $kind,string $label) use (&$events,$eng,$id):void {
            $events[]=[
                'date'=>$date,'kind'=>$kind,'client_id'=>$id,
                'client_name'=>(string)$eng['name'],'is_demo'=>(int)$eng['is_demo'],
                'label'=>$label
            ];
        };
        // Prepared and handover are distinct workflows; a ready but unshared
        // document generates only handover, not another prepare reminder.
        foreach ($documents as $doc) {
            if ($doc['storage_key'] !== null && $doc['transferred_at'] === null) {
                $date=$doc['transfer_due_on'] ?: $today;
                if ($date < $minDate) $date=$minDate; // keep old overdue visible
                if ($date <= $maxDate) $push($date,'handover','Передать подготовленный документ');
            }
        }
        if ($eng['started_on'] <= $maxDate &&
            !adc_reminder_document_found($documents,'needs_contract',$today,'prepayment')) {
            $date=max($eng['started_on'],$minDate);
            $push($date,'contract','Подготовить договор');
        }
        $billingDays=[];
        for ($i=0,$cursor=$first;$i<6;$i++,$cursor=$cursor->modify('+1 month')) {
            $billing=adc_reminder_month_day($cursor,(int)$eng['billing_day']);
            if ($billing >= $minDate && $billing <= $maxDate && $billing >= $eng['started_on']) {
                $billingDays[$billing]=true;
            }
            $last=$cursor->format('Y-m-t');
            if ((int)$eng['requires_acts']===1 &&
                $last >= $minDate && $last <= $maxDate && $last >= $eng['started_on'] &&
                !adc_reminder_document_found($documents,'needs_act',$last,'prepayment')) {
                $push($last,'act','Подготовить акт за календарный месяц');
            }
        }
        if ($eng['started_on'] >= $minDate && $eng['started_on'] <= $maxDate) {
            $billingDays[$eng['started_on']]=true;
        }
        ksort($billingDays);
        foreach (array_keys($billingDays) as $date) {
            if (!adc_reminder_document_found($documents,'needs_invoice',$date,(string)$eng['payment_mode'])) {
                $push($date,'invoice','Подготовить счёт ('.
                    ($eng['payment_mode']==='prepayment'?'предоплата':'постоплата').')');
            }
            if ((int)$eng['requires_specs']===1 &&
                !adc_reminder_document_found($documents,'needs_spec',$date,'prepayment')) {
                $push($date,'spec','Подготовить спецификацию');
            }
        }
    }
    usort($events,static fn(array $a,array $b) =>
        [$a['date'],$a['client_name'],$a['kind']] <=> [$b['date'],$b['client_name'],$b['kind']]);
    return $events;
}

function adc_reminders_html(PDO $db, string $today): string {
    $all=adc_client_reminders($db,$today);
    if (!$all) return '';
    $html='<details class="card"><summary>Рабочие напоминания по клиентам ('.count($all).')</summary>'.
        '<p class="muted">Напоминания рассчитываются из сроков сотрудничества и PDF. '.
        'Подготовка и передача — разные действия; в личные задачи сотрудников они не добавляются.</p>';
    foreach (array_slice($all,0,80) as $row) {
        $html.='<div class="item"><div><strong>'.adc_h($row['date']).' · '.
            adc_h($row['label']).'</strong><p class="muted">'.adc_h($row['client_name']).
            ($row['is_demo']===1?' · Демо':'').'</p></div><a class="btn secondary" href="/?view=clients&client='.
            $row['client_id'].'">Открыть</a></div>';
    }
    if (count($all)>80) {
        $html.='<p class="muted">Показаны первые 80 напоминаний. Для полного списка нужен фильтр.</p>';
    }
    return $html.'</details>';
}
