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
            GROUP BY u.id,u.display_name,u.role,u.is_active
            ORDER BY u.is_active DESC,u.display_name,u.id');
        $statement->execute([$today]);
        $team=$statement->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['active_clients'=>$active,'tasks'=>$own,'task_counts'=>$counts,
        'reminders'=>$reminders,'reminder_counts'=>$dueCounts,
        'unpaid'=>$unpaid,'unpaid_count'=>$unpaidCount,
        'unpaid_clients'=>count($unpaidClients),'unpaid_totals'=>$totals,
        'team'=>$team,'today'=>$today];
}

function adc_operations_html(PDO $db,array $user): string {
    $today=date('Y-m-d');
    $data=adc_operations_snapshot($db,$user,$today);
    $counts=$data['reminder_counts'];
    $own=$data['task_counts'];
    $html='<h1>Обзор работы</h1>'.
        '<p class="muted">Актуальные задачи, документы и ожидания оплаты по рабочим клиентам. '.
        'Только просмотр — никакого автоматического начисления или отправки документов.</p>'.
        '<section class="card"><h2>Сегодня · '.adc_h(date('d.m.Y',strtotime($today))).'</h2>'.
        '<div class="grid">'.
        '<div><strong>'.(int)$data['active_clients'].'</strong>'.
            '<p class="muted">Активных рабочих клиентов</p><a href="/?view=clients&amp;demo=0">Открыть клиентов</a></div>'.
        '<div><strong>'.(int)$counts['overdue'].'</strong>'.
            '<p class="muted">Просроченных действий по документам</p>'.
            '<a href="/?view=control&amp;scope=overdue">Открыть контроль</a></div>'.
        '<div><strong>'.((int)$counts['today']+(int)$counts['next_week']).'</strong>'.
            '<p class="muted">Документных действий сегодня и за следующие 7 дней</p>'.
            '<a href="/?view=control&amp;scope=upcoming">Все предстоящие</a></div>'.
        '<div><strong>'.(int)$own['overdue'].'</strong>'.
            '<p class="muted">Моих просроченных задач</p><a href="/?view=tasks">Мои задачи</a></div>'.
        '<div><strong>'.(int)$data['unpaid_count'].'</strong>'.
            '<p class="muted">Отправленных запросов без подтверждения оплаты · '.
            (int)$data['unpaid_clients'].' клиентов</p>'.
            '<a href="/?view=payments">Перейти к оплатам</a></div>'.
        '</div></section>';
    $html.='<section class="card"><h2>Ожидают оплаты</h2>'.
        '<p class="muted">Суммы только по отправленным неоплаченным записям нового учёта. '.
        'Не полная дебиторская задолженность по старой истории. Демо исключены. '.
        'Валюты не смешиваются.</p>';
    if (!$data['unpaid_totals']) {
        $html.='<p class="muted">Открытых отправленных запросов нет.</p>';
    } else {
        $html.='<div class="actions">';
        foreach($data['unpaid_totals'] as $currency=>$minor) {
            $html.='<span class="badge">'.adc_h($currency).': '.
                adc_h(adc_fin_format($minor,$currency)).'</span>';
        }
        $html.='</div>';
        foreach($data['unpaid'] as $item){
            $view=(int)$item['is_active']===1?'clients':'archive';
            $html.='<div class="item"><div><strong>'.adc_h((string)$item['name']).'</strong>'.
                '<p class="muted">'.(int)$item['items'].' запрос(ов) · '.
                adc_h(adc_fin_format(adc_fin_minor((string)$item['total']),
                    (string)$item['currency'])).'</p></div>'.
                '<a class="btn secondary" href="/?view='.$view.'&amp;client='.
                (int)$item['client_id'].'">Открыть клиента</a></div>';
        }
        if($data['unpaid_clients']>16)$html.='<p class="muted">Показана часть клиентов. '.
            'Все суммы по валютам учтены выше; подробности доступны в аналитике.</p>';
    }
    $html.='<p><a class="btn secondary" href="/?view=payments">Вся очередь оплат и просрочек</a></p>'.
        '</section><section class="card"><div class="actions"><h2>Ближайшая работа с документами</h2>'.
        '<a class="btn secondary" href="/?export=control">Скачать план CSV</a>'.
        '<a class="btn secondary" href="/?view=control">Весь контроль</a></div>'.
        '<p class="muted">Просрочено: '.(int)$counts['overdue'].
            ' · сегодня: '.(int)$counts['today'].
            ' · следующие 7 дней: '.(int)$counts['next_week'].
            '. CSV содержит все вычисленные действия только рабочих клиентов.</p>';
    $assigned=adc_team_control_assignments($db);
    foreach($data['reminders'] as $item){
        $key=adc_team_control_key((int)$item['client_id'],
            (string)$item['kind'],(string)$item['date']);
        $executor=isset($assigned[$key])?' · в работе: '.
            adc_h((string)$assigned[$key]['display_name']):'';
        $html.='<div class="item"><div><strong>'.adc_h((string)$item['label']).'</strong>'.
            '<p class="muted">'.adc_h((string)$item['client_name']).' · '.
            adc_h(date('d.m.Y',strtotime((string)$item['date']))).$executor.
            '</p></div><a class="btn secondary" href="/?view=clients&amp;client='.
            (int)$item['client_id'].'">Открыть</a></div>';
    }
    if (!$data['reminders'])$html.='<p class="muted">Срочных действий не обнаружено.</p>';
    if ((int)$counts['all']>count($data['reminders'])) $html.=
        '<p class="muted">Показаны первые 16 срочных событий. Полный список с фильтрами — в «Контроле».</p>';
    $html.='</section><section class="card"><h2>Мои ближайшие задачи</h2>'.
        '<p class="muted">Открытых: '.(int)$own['total'].
        ' · сегодня: '.(int)$own['today'].' · следующие 7 дней: '.
        (int)$own['next_week'].'.</p>';
    foreach ($data['tasks'] as $task) {
        $html.='<div class="item"><strong>'.adc_h((string)$task['title']).'</strong>'.
            '<span class="muted">'.adc_h(date('d.m.Y',strtotime((string)$task['due_date']))).
            ((int)$task['repeat_monthly']===1?' · ежемесячно':'').'</span></div>';
    }
    if(!$data['tasks']) $html.='<p class="muted">Открытых задач нет.</p>';
    $html.='<p><a class="btn secondary" href="/?view=tasks">Открыть «Мои задачи»</a></p></section>';
    if (($user['role']??'')==='director') {
        $html.='<section class="card"><h2>Команда · открытые задачи</h2>'.
            '<p class="muted">Директор видит общую загрузку команды, включая личные задачи; '.
            'редактировать чужие личные дела здесь нельзя.</p>';
        foreach($data['team'] as $member) {
            $html.='<div class="item"><div><strong>'.adc_h((string)$member['display_name']).'</strong>'.
                ((int)$member['is_active']===1?'':' <span class="badge">Отключён</span>').
                '<p class="muted">Открыто: '.(int)$member['open_count'].
                ' · просрочено: '.(int)$member['overdue_count'].'</p></div>'.
                '<a class="btn secondary" href="/?view=tasks&amp;team_scope=all&amp;team_member='.
                (int)$member['id'].'">Календарь</a></div>';
        }
        $html.='</section>';
    }
    return $html;
}
