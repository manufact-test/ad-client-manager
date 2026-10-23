<?php
declare(strict_types=1);

/**
 * Read-only daily operating overview. Never generates billing periods,
 * charges, assignments or personal tasks merely because a page was opened.
 */
function adc_operations_snapshot(PDO $db,array $user,string $today): array {
    if (!adc_valid_date($today)) throw new InvalidArgumentException('Неверная дата обзора.');
    $future=(new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
    $active=(int)$db->query('SELECT COUNT(*) FROM clients c WHERE c.is_demo=0
        AND EXISTS (SELECT 1 FROM engagements e
            WHERE e.client_id=c.id AND e.status="active")')->fetchColumn();
    $tasks=$db->prepare('SELECT id,title,due_date,repeat_monthly FROM personal_tasks
        WHERE user_id=? AND is_done=0 ORDER BY due_date,id LIMIT 16');
    $tasks->execute([(int)$user['id']]);
    $own=$tasks->fetchAll(PDO::FETCH_ASSOC);
    $taskCounts=$db->prepare('SELECT
        COUNT(*) total,
        COALESCE(SUM(due_date<?),0) overdue,
        COALESCE(SUM(due_date=?),0) today,
        COALESCE(SUM(due_date>? AND due_date<=?),0) next_week
        FROM personal_tasks WHERE user_id=? AND is_done=0');
    $taskCounts->execute([$today,$today,$today,$future,(int)$user['id']]);
    $counts=$taskCounts->fetch(PDO::FETCH_ASSOC);
    $all=adc_client_reminders($db,$today);
    $reminders=[]; $dueCounts=['overdue'=>0,'today'=>0,'next_week'=>0,'all'=>0];
    foreach ($all as $item) {
        if ((int)$item['is_demo']!==0) continue;
        $dueCounts['all']++;
        if ($item['date']<$today) $dueCounts['overdue']++;
        elseif ($item['date']===$today) $dueCounts['today']++;
        elseif ($item['date']<=$future) $dueCounts['next_week']++;
        if (count($reminders)<16 && $item['date']<=$future) $reminders[]=$item;
    }
    $unpaidRows=$db->query('SELECT e.client_id,c.name,c.is_demo,e.currency,
        COUNT(*) items,SUM(e.amount) total,
        EXISTS (SELECT 1 FROM engagements g
          WHERE g.client_id=c.id AND g.status="active") is_active
        FROM client_entries e JOIN clients c ON c.id=e.client_id
        WHERE c.is_demo=0 AND e.is_void=0 AND e.amount IS NOT NULL
          AND e.sent_at IS NOT NULL AND e.received_at IS NULL
        GROUP BY e.client_id,c.name,c.is_demo,e.currency
        ORDER BY c.name,e.client_id,e.currency')->fetchAll(PDO::FETCH_ASSOC);
    $totals=[];$unpaidCount=0;$unpaidClients=[];$unpaid=[];
    foreach ($unpaidRows as $row) {
        $currency=(string)$row['currency'];
        $value=adc_fin_minor((string)$row['total']);
        $totals[$currency]=adc_fin_add($totals[$currency]??0,$value);
        $unpaidCount+=(int)$row['items'];
        $unpaidClients[(int)$row['client_id']]=true;
        if (count($unpaid)<16) $unpaid[]=$row;
    }
    ksort($totals);
    $team=[];
    if (($user['role']??'')==='director') {
        $statement=$db->prepare('SELECT u.id,u.display_name,u.role,u.is_active,
            COUNT(t.id) open_count,
            COALESCE(SUM(t.due_date<?),0) overdue_count
            FROM users u LEFT JOIN personal_tasks t
                ON t.user_id=u.id AND t.is_done=0
              AND u.is_active=1
            GROUP BY u.id,u.display_name,u.role,u.is_active
            ORDER BY u.display_name,u.id');
        $statement->execute([$today]);
        $team=$statement->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['active_clients'=>$active,'tasks'=>$own,'task_counts'=>$counts,
        'reminders'=>$reminders,'reminder_counts'=>$dueCounts,
        'unpaid'=>$unpaid,'unpaid_count'=>$unpaidCount,
        'unpaid_clients'=>count($unpaidClients),'unpaid_totals'=>$totals,
        'team'=>$team,'today'=>$today];
}

/**
 * One uncluttered overview. The detail/actions live in client cards.
 * Received amounts and amounts still awaiting payment are separate,
 * grouped by currency and based on actual records rather than contract prices.
 */
function adc_operations_html(PDO $db,array $user):string {
    $today=date('Y-m-d');
    $data=adc_operations_snapshot($db,$user,$today);
    $own=$data['task_counts'];
    $payments=[];
    $entries=$db->query('SELECT e.currency,
        SUM(CASE WHEN e.received_at IS NOT NULL THEN e.amount ELSE 0 END) paid,
        SUM(CASE WHEN e.sent_at IS NOT NULL AND e.received_at IS NULL THEN e.amount ELSE 0 END) unpaid
        FROM client_entries e JOIN clients c ON c.id=e.client_id
        WHERE c.is_demo=0 AND e.is_void=0 AND e.amount IS NOT NULL
        GROUP BY e.currency')->fetchAll(PDO::FETCH_ASSOC);
    foreach($entries as $entry) {
        $ccy=(string)$entry['currency'];
        $payments[$ccy]=[
            'paid'=>adc_fin_minor((string)$entry['paid']),
            'unpaid'=>adc_fin_minor((string)$entry['unpaid'])
        ];
    }
    // Preserve payments entered through the previously agreed period history.
    $historic=$db->query('SELECT p.currency,
        SUM(CASE WHEN p.event_type="received" THEN p.amount ELSE -p.amount END) paid
        FROM payment_events p JOIN clients c ON c.id=p.client_id
        WHERE c.is_demo=0 GROUP BY p.currency')->fetchAll(PDO::FETCH_ASSOC);
    foreach($historic as $entry){
        $ccy=(string)$entry['currency'];
        $payments[$ccy]['paid']=adc_fin_add($payments[$ccy]['paid']??0,
            adc_fin_minor((string)$entry['paid']));
        $payments[$ccy]['unpaid']=$payments[$ccy]['unpaid']??0;
    }
    ksort($payments);
    $html='<h1>Обзор</h1><section class="card"><h2>Сегодня · '.
        adc_h(date('d.m.Y',strtotime($today))).'</h2>'.
        '<div class="overview-stats">'.
        '<div class="overview-stat"><strong>'.(int)$data['active_clients'].'</strong><span>Клиентов в работе</span></div>'.
        '<div class="overview-stat"><strong>'.(int)$own['overdue'].'</strong><span>Моих просроченных задач</span></div>'.
        '<div class="overview-stat"><strong>'.(int)$own['today'].'</strong><span>Моих задач на сегодня</span></div>'.
        '</div><div class="overview-money">';
    if(!$payments){
        $html.='<div class="overview-money-item"><span>Оплачено</span><strong>0</strong></div>'.
               '<div class="overview-money-item"><span>Не оплачено</span><strong>0</strong></div>';
    }else {
        foreach($payments as $ccy=>$values) {
            $html.='<div class="overview-money-item"><span>Оплачено · '.adc_h($ccy).'</span><strong>'.
                adc_h(adc_fin_format($values['paid'],$ccy)).'</strong></div>'.
                '<div class="overview-money-item"><span>Не оплачено · '.adc_h($ccy).'</span><strong>'.
                adc_h(adc_fin_format($values['unpaid'],$ccy)).'</strong></div>';
        }
    }
    $html.='</div></section>'.
        '<section class="card"><h2>Мои ближайшие задачи</h2>';
    foreach($data['tasks'] as $task){
        $late=(string)$task['due_date']<$today;
        $html.='<div class="item"><strong>'.adc_h((string)$task['title']).'</strong>'.
            '<span'.($late?' class="overdue-text"':' class="muted"').'>'
            .adc_h(date('d.m.Y',strtotime((string)$task['due_date']))).
            ($late?' · Просрочено':'').'</span></div>';
    }
    if(!$data['tasks'])$html.='<p class="muted">Нет ближайших задач.</p>';
    $html.='<p><a class="btn secondary" href="/?view=tasks">Мои задачи</a></p></section>';
    if(($user['role']??'')==='director'){
        $html.='<section class="card"><h2>Команда · открытые задачи</h2>';
        foreach($data['team'] as $member){
            if((int)$member['is_active']!==1)continue;
            $html.='<div class="item"><strong>'.adc_h((string)$member['display_name']).'</strong>'.
                '<span>Открыто: '.(int)$member['open_count'].
                ((int)$member['overdue_count']>0?' · <span class="overdue-text">Просрочено: '.
                    (int)$member['overdue_count'].'</span>':'').'</span></div>';
        }
        if(!$data['team'])$html.='<p class="muted">Нет активных сотрудников.</p>';
        $html.='</section>';
    }
    return $html;
}
