<?php
declare(strict_types=1);

/**
 * Current, unpaid requests from the existing ledger. No estimates, inferred
 * payments or automatically generated monthly charges are inserted here.
 * Reuse the same historical billing-term precedence as ledger status.
 */
function adc_collections_open(PDO $db,string $today,bool $includeDemo=false): array {
    if (!adc_valid_date($today)) throw new InvalidArgumentException('Неверная дата.');
    $sql='SELECT e.id,e.client_id,e.document_id,e.entry_kind,e.amount,e.currency,
        e.category,e.note,e.sent_at,c.name client_name,c.is_demo,
        d.original_filename,
        EXISTS(SELECT 1 FROM engagements active
          WHERE active.client_id=c.id AND active.status="active") is_active,
        COALESCE(
          (SELECT t.billing_day FROM engagement_terms t
            JOIN engagements g ON g.id=t.engagement_id
            WHERE g.client_id=e.client_id AND t.effective_on<=DATE(e.sent_at)
            ORDER BY t.effective_on DESC,t.id DESC LIMIT 1),
          (SELECT g.billing_day FROM engagements g
            WHERE g.client_id=e.client_id AND g.started_on<=DATE(e.sent_at)
            ORDER BY g.started_on DESC,g.id DESC LIMIT 1)
        ) billing_day
        FROM client_entries e JOIN clients c ON c.id=e.client_id
        LEFT JOIN document_bundles d ON d.id=e.document_id
        WHERE e.is_void=0 AND e.amount IS NOT NULL
            AND e.sent_at IS NOT NULL AND e.received_at IS NULL
            AND c.is_demo=' . ($includeDemo?'1':'0') . '
        ORDER BY e.sent_at,e.id';
    $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row) {
        $anchor=$row['billing_day']===null?0:(int)$row['billing_day'];
        $due=$anchor>=1 && $anchor<=31
            ?adc_ledger_due_date((string)$row['sent_at'],$anchor):null;
        $grace=$due===null?null:(new DateTimeImmutable($due))->modify('+3 days')->format('Y-m-d');
        $state=$due===null?'unknown':($today>$grace?'overdue':
            ($today>$due?'grace':'upcoming'));
        $row['due_date']=$due;
        $row['grace_end']=$grace;
        $row['payment_state']=$state;
    }
    unset($row);
    usort($rows,static fn(array $a,array $b) =>
        [($a['due_date']??'9999-12-31'),(int)$a['id']] <=>
        [($b['due_date']??'9999-12-31'),(int)$b['id']]);
    return $rows;
}

/** Validated filters also drive links after receipt confirmation. */
function adc_collections_filters(array $query): array {
    $scopes=['all'=>'Все ожидающие','overdue'=>'Просроченные',
        'grace'=>'Срок прошёл (льготные 3 дня)',
        'upcoming'=>'Ещё не просрочены','unknown'=>'Срок не установлен'];
    $scope=is_string($query['scope']??null)?$query['scope']:'all';
    if(!isset($scopes[$scope]))$scope='all';
    $currency=is_string($query['currency']??null)?$query['currency']:'all';
    if(!preg_match('/\A(?:all|[A-Z]{3})\z/D',$currency))$currency='all';
    $search=is_string($query['q']??null)?trim($query['q']):'';
    if(mb_strlen($search)>80)$search=mb_substr($search,0,80);
    $requested=filter_var($query['page']??null,FILTER_VALIDATE_INT);
    $page=($requested!==false && $requested!==null && (int)$requested>0)
        ?min((int)$requested,1000000):1;
    return ['scope'=>$scope,'currency'=>$currency,'q'=>$search,
        'page'=>$page,'labels'=>$scopes];
}

function adc_collections_filter_rows(array $rows,array $filters): array {
    return array_values(array_filter($rows,static function(array $r) use($filters):bool{
        if($filters['scope']!=='all') {
            if($filters['scope']==='upcoming') {
                if(!in_array($r['payment_state'],['upcoming','grace'],true))return false;
            } elseif($r['payment_state']!==$filters['scope'])return false;
        }
        if($filters['currency']!=='all' && $r['currency']!==$filters['currency'])return false;
        return $filters['q']==='' ||
            mb_stripos((string)$r['client_name'],$filters['q'])!==false;
    }));
}

function adc_collections_sums(array $rows):array {
    $total=[];$overdue=[];$count=0;$lateCount=0;$clients=[];
    foreach($rows as $r) {
        $currency=(string)$r['currency'];
        $minor=adc_fin_minor((string)$r['amount']);
        $total[$currency]=adc_fin_add($total[$currency]??0,$minor);
        $count++;
        $clients[(int)$r['client_id']]=true;
        if($r['payment_state']==='overdue'){
            $lateCount++;
            $overdue[$currency]=adc_fin_add($overdue[$currency]??0,$minor);
        }
    }
    ksort($total);ksort($overdue);
    return ['total'=>$total,'overdue'=>$overdue,'count'=>$count,
        'late_count'=>$lateCount,'clients'=>count($clients)];
}

function adc_collections_html(PDO $db,array $user):string {
    $today=date('Y-m-d');
    $filters=adc_collections_filters($_GET);
    $all=adc_collections_open($db,$today);
    $matches=adc_collections_filter_rows($all,$filters);
    $summary=adc_collections_sums($matches);
    $allStats=adc_collections_sums($all);
    $perPage=50;$pages=max(1,(int)ceil(count($matches)/$perPage));
    $page=min($pages,$filters['page']);
    $visible=array_slice($matches,($page-1)*$perPage,$perPage);
    $url=static fn(int $pageNumber):string => '/?'.http_build_query([
        'view'=>'payments','scope'=>$filters['scope'],
        'currency'=>$filters['currency'],'q'=>$filters['q'],'page'=>$pageNumber
    ],'','&',PHP_QUERY_RFC3986);
    $html='<h1>Контроль оплат</h1><p class="muted">Единая очередь отправленных '.
        'запросов без подтверждения оплаты. Не создаёт начислений и не включает '.
        'демонстрационных клиентов. Старые начисления и платежи сохраняются в «Аналитике».</p>'.
        '<section class="card"><div class="grid">'.
        '<div><strong>'.(int)$allStats['count'].'</strong><p class="muted">Всего ожидают оплаты · '.
        (int)$allStats['clients'].' клиентов</p></div>'.
        '<div><strong>'.(int)$allStats['late_count'].'</strong>'.
        '<p class="muted">Просрочены с учётом 3 дней после расчётной даты</p></div>'.
        '</div><p class="muted">Суммы всех открытых запросов по валютам:</p><div class="actions">';
    if(!$allStats['total'])$html.='<span class="muted">Нет ожидающих оплат.</span>';
    foreach($allStats['total'] as $ccy=>$amount){
        $html.='<span class="badge">'.adc_h($ccy).' · '.
            adc_h(adc_fin_format($amount,$ccy)).'</span>';
    }
    $html.='</div><div class="actions"><a class="btn secondary" href="/?export=payments">'.
        'Скачать ожидающие оплаты в CSV</a>'.
        '<a class="btn secondary" href="/?view=analytics">Вся аналитика</a></div></section>'.
        '<section class="card"><h2>Поиск и фильтры</h2>'.
        '<form method="get"><input type="hidden" name="view" value="payments">'.
        '<div class="grid"><label class="field">Клиент<input name="q" maxlength="80" '.
        'value="'.adc_h($filters['q']).'" placeholder="Поиск по названию"></label>'.
        adc_w_select('Срок оплаты','scope',$filters['labels'],$filters['scope']);
    $currencies=['all'=>'Любая валюта'];
    foreach($allStats['total'] as $ccy=>$sum)$currencies[$ccy]=$ccy;
    $html.=adc_w_select('Валюта','currency',$currencies,$filters['currency']).
        '</div><button type="submit">Показать</button> '.
        '<a class="btn secondary" href="/?view=payments">Сбросить</a></form>'.
        '<p class="muted">В выборке: '.(int)$summary['count'].
        ' · просрочено: '.(int)$summary['late_count'].
        '. Все итоговые суммы — по отфильтрованному списку, а не только текущей странице.</p>';
    if($summary['total']){
        $html.='<p class="muted">Ожидается: ';
        foreach($summary['total'] as $ccy=>$value)
            $html.=adc_h(adc_fin_format($value,$ccy)).' · ';
        $html.='</p>';
    }
    $pagination='';
    if($pages>1){
        $pagination='<nav class="task-pagination" aria-label="Страницы ожидающих оплат">';
        if($page>1)$pagination.='<a class="btn secondary" href="'.
            adc_h($url($page-1)).'">← Ранее</a> ';
        $pagination.='<span class="muted">Страница '.$page.' из '.$pages.'</span>';
        if($page<$pages)$pagination.=' <a class="btn secondary" href="'.
            adc_h($url($page+1)).'">Далее →</a>';
        $pagination.='</nav>';
    }
    $html.=$pagination;
    foreach($visible as $row) {
        $date=$row['due_date']===null?'Не установлена':
            date('d.m.Y',strtotime((string)$row['due_date']));
        $label=match($row['payment_state']){
            'overdue'=>'Просрочено','grace'=>'В пределах 3 дней',
            'upcoming'=>'Ожидаем','unknown'=>'Срок неизвестен'
        };
        $clientView=(int)$row['is_active']===1?'clients':'archive';
        $description=$row['entry_kind']==='document' ?
            ($row['original_filename']?:'Документ'):'Оплата на карту';
        $html.='<div class="item"><div><strong>'.adc_h((string)$row['client_name']).'</strong>'.
            '<p class="muted">'.adc_h((string)$description).' · '.
            adc_h(adc_ledger_category((string)$row['category'])).' · '.
            adc_h(adc_fin_format(adc_fin_minor((string)$row['amount']),
                (string)$row['currency'])).'</p>'.
            '<p class="muted">Расчётный срок: '.adc_h($date).' · '.adc_h($label).
            ' · отправлено '.adc_h(substr((string)$row['sent_at'],0,10)).'</p></div>'.
            '<div class="actions"><a class="btn secondary" href="/?view='.$clientView.
                '&amp;client='.(int)$row['client_id'].'">Карточка</a>'.
            adc_w_form_start('receive_entry').
            adc_w_hidden('client_id',(int)$row['client_id']).
            adc_w_hidden('entry_id',(int)$row['id']).
            adc_w_hidden('return_view','payments').
            adc_w_hidden('return_scope',$filters['scope']).
            adc_w_hidden('return_currency',$filters['currency']).
            adc_w_hidden('return_query',$filters['q']).
            adc_w_hidden('return_page',$page).
            '<button type="submit" data-confirm="Подтвердить фактическое поступление этой суммы?">'.
            'Получили</button></form></div></div>';
    }
    if(!$visible)$html.='<p class="muted">Нет неоплаченных записей с этими условиями.</p>';
    return $html.$pagination.'</section>';
}
