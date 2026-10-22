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


/** Scheduled billing dates and modes must come from dated contract terms,
 * not the future latest value mirrored in engagements. */
function adc_reminder_billing_days(PDO $db,array $eng,DateTimeImmutable $first,
    string $minDate,string $maxDate): array {
    $q=$db->prepare('SELECT effective_on,billing_day,payment_mode
        FROM engagement_terms WHERE engagement_id=? ORDER BY effective_on,id');
    $q->execute([(int)$eng['engagement_id']]);
    $versions=$q->fetchAll(PDO::FETCH_ASSOC);
    $anchors=$versions ? array_unique(array_map('intval',array_column($versions,'billing_day')))
        : [(int)$eng['billing_day']];
    $at=static function(string $date) use ($versions,$eng): array {
        $current=$versions[0]??$eng;
        foreach($versions as $v){
            if($v['effective_on']>$date) break;
            $current=$v;
        }
        return $current;
    };
    $days=[];
    for($i=0,$cursor=$first;$i<18 && $cursor->format('Y-m-d')<=$maxDate;
        $i++,$cursor=$cursor->modify('+1 month')){
        foreach($anchors as $anchor){
            $date=adc_reminder_month_day($cursor,(int)$anchor);
            if($date<$minDate || $date>$maxDate || $date<$eng['started_on']) continue;
            $effective=$at($date);
            if((int)$effective['billing_day']===(int)$anchor){
                $days[$date]=(string)$effective['payment_mode'];
            }
        }
    }
    // The effective date itself starts a new billing cycle.
    foreach($versions as $v){
        $date=(string)$v['effective_on'];
        if($date>=$minDate && $date<=$maxDate && $date>=$eng['started_on']){
            $days[$date]=(string)$v['payment_mode'];
        }
    }
    if($eng['started_on']>=$minDate && $eng['started_on']<=$maxDate){
        $initial=$at($eng['started_on']);
        $days[$eng['started_on']]=(string)$initial['payment_mode'];
    }
    ksort($days);
    return $days;
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
    // Keep up to six months of missed paperwork visible in the control
    // board. Older unresolved PDF handovers/contracts remain visible too.
    $minDate=$now->modify('-183 days')->format('Y-m-d');
    $maxDate=$now->modify('+45 days')->format('Y-m-d');
    $first=(new DateTimeImmutable($minDate))->modify('first day of this month');
    $stmt=$db->query('SELECT c.id,c.name,c.is_demo,c.requires_specs,c.requires_acts,
        e.id engagement_id,e.started_on,e.billing_day,e.payment_mode
        FROM clients c JOIN engagements e ON e.client_id=c.id
        WHERE e.status="active" ORDER BY c.id');
    $events=[];
    $docsStmt=$db->prepare('SELECT d.id,d.is_void,d.storage_key,d.created_at,d.transfer_due_on,d.transferred_at,
        COALESCE((SELECT a.action FROM audit_events a
          WHERE a.object_type="document_bundle" AND a.object_id=d.id
            AND a.action IN ("invalidate_document","replace_document")
          ORDER BY a.id DESC LIMIT 1),"")="invalidate_document" AS needs_replacement,
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
            if ((int)$doc['needs_replacement'] === 1) {
                $push($today,'replace','Заменить устаревший PDF после изменения суммы');
                continue;
            }
            if ($doc['storage_key'] !== null && $doc['transferred_at'] === null) {
                $date=$doc['transfer_due_on'] ?: $today;
                // Retain the contractual/original deadline, even when it is
                // older than the generated monthly reminder lookback window.
                if ($date <= $maxDate) $push($date,'handover','Передать подготовленный документ');
            }
        }
        if ($eng['started_on'] <= $maxDate &&
            !adc_reminder_document_found($documents,'needs_contract',$today,'prepayment')) {
            $push((string)$eng['started_on'],'contract','Подготовить договор');
        }
        $billingDays=adc_reminder_billing_days($db,$eng,$first,$minDate,$maxDate);
        for ($i=0,$cursor=$first;$i<18 && $cursor->format('Y-m-d')<=$maxDate;
            $i++,$cursor=$cursor->modify('+1 month')) {
            $last=$cursor->format('Y-m-t');
            if ((int)$eng['requires_acts']===1 &&
                $last >= $minDate && $last <= $maxDate && $last >= $eng['started_on'] &&
                !adc_reminder_document_found($documents,'needs_act',$last,'prepayment')) {
                $push($last,'act','Подготовить акт за календарный месяц');
            }
        }
        foreach ($billingDays as $date=>$mode) {
            if (!adc_reminder_document_found($documents,'needs_invoice',$date,$mode)) {
                $push($date,'invoice','Подготовить счёт ('.
                    ($mode==='prepayment'?'предоплата':'постоплата').')');
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

/** Shared read-only client workload; it never adds personal tasks or payments. */
/** Shared client-control board with visible delegation status and safe bulk dispatch. */
function adc_reminders_dashboard(PDO $db,?array $user=null): string {
    $isDirector=(($user['role']??'')==='director');
    $staff=$isDirector?adc_team_task_staff($db):[];
    $assigned=adc_team_control_assignments($db);
    $today=date('Y-m-d');
    $kindOptions=['all'=>'Все действия','invoice'=>'Счета','act'=>'Акты',
        'spec'=>'Спецификации','contract'=>'Договоры',
        'handover'=>'Передача PDF','replace'=>'Замена PDF'];
    $scopeOptions=['all'=>'Все сроки','overdue'=>'Просроченные',
        'today'=>'Сегодня','upcoming'=>'Предстоящие'];
    $demoOptions=['0'=>'Рабочие клиенты','1'=>'Демо-клиенты','all'=>'Все клиенты'];
    $assignmentOptions=['all'=>'Любые','free'=>'Без исполнителя',
        'assigned'=>'Уже поручено'];
    $kind=(string)($_GET['kind']??'all');
    if (!isset($kindOptions[$kind])) $kind='all';
    $scope=(string)($_GET['scope']??'all');
    if (!isset($scopeOptions[$scope])) $scope='all';
    $demo=(string)($_GET['demo']??'0');
    if (!isset($demoOptions[$demo])) $demo='0';
    $assignment=(string)($_GET['assignment']??'all');
    if (!isset($assignmentOptions[$assignment])) $assignment='all';
    $all=adc_client_reminders($db,$today);
    $rows=array_values(array_filter($all,static function(array $event) use
        ($kind,$scope,$demo,$assignment,$assigned,$today):bool {
        if ($demo!=='all' && (int)$event['is_demo']!==(int)$demo) return false;
        if ($kind!=='all' && $event['kind']!==$kind) return false;
        $key=adc_team_control_key((int)$event['client_id'],
            (string)$event['kind'],(string)$event['date']);
        if ($assignment==='free' && isset($assigned[$key])) return false;
        if ($assignment==='assigned' && !isset($assigned[$key])) return false;
        return match($scope){
            'overdue'=>$event['date']<$today,
            'today'=>$event['date']===$today,
            'upcoming'=>$event['date']>$today,
            default=>true
        };
    }));
    $perPage=60;
    $total=count($rows);
    $pages=max(1,(int)ceil($total/$perPage));
    $requestedPage=filter_var($_GET['page']??null,FILTER_VALIDATE_INT);
    $page=($requestedPage!==false && $requestedPage!==null)
        ?min(max(1,(int)$requestedPage),$pages):1;
    $shown=array_slice($rows,($page-1)*$perPage,$perPage);
    $pageLink=static fn(int $destination):string => '/?'.http_build_query([
        'view'=>'control','demo'=>$demo,'kind'=>$kind,'scope'=>$scope,
        'assignment'=>$assignment,'page'=>$destination
    ],'','&',PHP_QUERY_RFC3986);
    $navigation='';
    if ($pages>1) {
        $navigation='<nav class="task-pagination" aria-label="Страницы контрольных напоминаний">';
        if ($page>1) $navigation.='<a class="btn secondary" href="'.
            adc_h($pageLink($page-1)).'">← Ранее</a> ';
        $navigation.='<span class="muted">Страница '.$page.' из '.$pages.'</span>';
        if ($page<$pages) $navigation.=' <a class="btn secondary" href="'.
            adc_h($pageLink($page+1)).'">Далее →</a>';
        $navigation.='</nav>';
    }
    $html='<h1>Контроль клиентов</h1><section class="card">'.
        '<p class="muted">Общий план документов и передачи PDF для сотрудников. '.
        'Расчётные события: последние 183 дня и следующие 45 дней; '.
        'непереданные PDF и договоры с более ранними сроками также показаны. '.
        'План вычисляется по условиям договоров и не создаёт личных задач или начислений.</p>'.
        '<form method="get"><input type="hidden" name="view" value="control"><div class="grid">'.
        '<label class="field">Клиенты<select name="demo">';
    foreach($demoOptions as $val=>$label){
        $html.='<option value="'.$val.'"'.($val===$demo?' selected':'').'>'.adc_h($label).'</option>';
    }
    $html.='</select></label><label class="field">Действие<select name="kind">';
    foreach($kindOptions as $val=>$label){
        $html.='<option value="'.$val.'"'.($val===$kind?' selected':'').'>'.adc_h($label).'</option>';
    }
    $html.='</select></label><label class="field">Срок<select name="scope">';
    foreach($scopeOptions as $val=>$label){
        $html.='<option value="'.$val.'"'.($val===$scope?' selected':'').'>'.adc_h($label).'</option>';
    }
    $html.='</select></label><label class="field">Поручения<select name="assignment">';
    foreach($assignmentOptions as $val=>$label){
        $html.='<option value="'.$val.'"'.($val===$assignment?' selected':'').'>'.adc_h($label).'</option>';
    }
    $html.='</select></label></div><button type="submit">Показать</button></form>'.
        '<p class="muted">Напоминаний: '.$total.'. Поручение не закрывает документ автоматически: '.
        'после выполнения проверьте сам документ в карточке клиента.</p>'.$navigation;
    if (!$shown) return $html.'<p class="muted">Подходящих напоминаний нет.</p></section>';
    $available=0;
    $unique=[];
    foreach ($shown as $event) {
        $key=adc_team_control_key((int)$event['client_id'],
            (string)$event['kind'],(string)$event['date']);
        if (!isset($assigned[$key]) && !isset($unique[$key])) {
            $unique[$key]=true;
            $available++;
        }
    }
    if ($staff && $available>0) {
        $html.='<form id="control-bulk-assign" method="post" class="card">'.
            adc_w_hidden('csrf',adc_csrf()).
            adc_w_hidden('action','assign_control_bulk').
            '<p><strong>Распределить несколько дел сразу</strong> — отметьте действия '.
            'ниже (до 60 на странице), выберите исполнителя и назначьте за один раз.</p>'.
            adc_w_select('Исполнитель','assignee_user_id',adc_team_task_options($staff)).
            '<button type="submit">Назначить выбранные поручения</button></form>';
    }
    $lastDate='';
    $alreadyListed=[];
    foreach($shown as $item){
        $date=(string)$item['date'];
        if($date!==$lastDate){
            if($lastDate!=='')$html.='</div>';
            $lastDate=$date;
            $html.='<div class="task-day"><h3>'.adc_h(date('d.m.Y',strtotime($date))).
                ($date<$today?' <span class="badge">Просрочено</span>':
                ($date===$today?' <span class="badge good">Сегодня</span>':'')).'</h3>';
        }
        $key=adc_team_control_key((int)$item['client_id'],
            (string)$item['kind'],(string)$item['date']);
        $delegation=$assigned[$key]??null;
        $selection='';
        if ($staff && !$delegation && !isset($alreadyListed[$key])) {
            $selection='<label class="check"><input form="control-bulk-assign" type="checkbox" '.
                'name="selected_reminders[]" value="'.adc_h($key).'"> В общий список</label>';
        }
        $alreadyListed[$key]=true;
        $status=$delegation
            ?'<p class="muted">В работе: '.adc_h($delegation['display_name']).
                ($isDirector?' · <a href="/?view=tasks&amp;team_scope=all&amp;team_member='.
                (int)$delegation['assignee_user_id'].'">Общий календарь</a>':'').'</p>'
            :'';
        $html.='<div class="item"><div><strong>'.adc_h((string)$item['label']).'</strong>'.
            '<p class="muted">'.adc_h((string)$item['client_name']).
            ((int)$item['is_demo']===1?' · Демо':'').'</p>'.$status.$selection.'</div>'.
            '<a class="btn secondary" href="/?view=clients&amp;client='.
            (int)$item['client_id'].'">Открыть клиента</a>'.
            ($staff && !$delegation?adc_team_task_control_form($staff,$item):'').'</div>';
    }
    return $html.'</div>'.$navigation.'</section>';
}
