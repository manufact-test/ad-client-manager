<?php
declare(strict_types=1);

/**
 * DECIMAL(16,2) is kept as integer kopecks/cents in PHP: never cast SQL
 * decimal aggregates to a float before doing financial arithmetic.
 * The largest single value allowed by the database fits signed 64-bit cents.
 */
function adc_source_label(string $source): string {
    return [
        'advertising'=>'Реклама',
        'referral'=>'Рекомендация',
        'old_contact'=>'Старые контакты',
        'inbound'=>'Сам обратился',
        'other'=>'Другое',
        'unknown'=>'Не указано'
    ][$source] ?? $source;
}

function adc_fin_minor(string $amount): int {
    if (!preg_match('/^-?[0-9]{1,16}(?:\.[0-9]{1,2})?$/D', $amount)) {
        throw new InvalidArgumentException('Некорректная денежная сумма.');
    }
    $negative=str_starts_with($amount,'-');
    $number=ltrim($negative ? substr($amount,1) : $amount,'0');
    if ($number==='') $number='0';
    [$whole,$fraction]=array_pad(explode('.',$number,2),2,'');
    $whole=(int)$whole;
    if ($whole>intdiv(PHP_INT_MAX-99,100)) {
        throw new OverflowException('Сумма отчёта слишком велика.');
    }
    $minor=$whole*100+(int)str_pad($fraction,2,'0');
    return $negative ? -$minor : $minor;
}

function adc_fin_add(int $a,int $b): int {
    if (($b>0 && $a>PHP_INT_MAX-$b) || ($b<0 && $a<PHP_INT_MIN-$b)) {
        throw new OverflowException('Сумма отчёта превышает допустимый диапазон.');
    }
    return $a+$b;
}

function adc_fin_format(int $minor,string $currency): string {
    $sign=$minor<0?'-':'';
    $m=$minor<0?-$minor:$minor;
    return $sign.number_format(intdiv($m,100),0,',',' ').','.str_pad((string)($m%100),2,'0',STR_PAD_LEFT).
        ' '.adc_h($currency);
}

function adc_fin_groups(PDO $db): array {
    $totals=[];
    $init=static fn() => ['advertising'=>0,'development'=>0,'other'=>0,'received'=>0,'refund'=>0];
    $sql='SELECT ch.currency,ch.category,SUM(ch.amount) total
        FROM charge_lines ch JOIN clients c ON c.id=ch.client_id
        WHERE ch.is_void=0 AND '.adc_demo_analytics_predicate('c').'
        GROUP BY ch.currency,ch.category ORDER BY ch.currency,ch.category';
    foreach ($db->query($sql) as $row) {
        $ccy=$row['currency'];
        $totals[$ccy] ??= $init();
        $totals[$ccy][$row['category']]=adc_fin_minor((string)$row['total']);
    }
    $sql='SELECT p.currency,p.event_type,SUM(p.amount) total
        FROM payment_events p JOIN clients c ON c.id=p.client_id
        WHERE '.adc_demo_analytics_predicate('c').'
        GROUP BY p.currency,p.event_type ORDER BY p.currency,p.event_type';
    foreach ($db->query($sql) as $row) {
        $ccy=$row['currency'];
        $totals[$ccy] ??= $init();
        $totals[$ccy][$row['event_type']]=adc_fin_minor((string)$row['total']);
    }
    ksort($totals);
    return $totals;
}

function adc_fin_months(PDO $db): array {
    $out=[];
    $a=$db->query('SELECT ch.currency,SUBSTR(bp.period_start,1,7) mon,ch.category,SUM(ch.amount) total
        FROM charge_lines ch JOIN clients c ON c.id=ch.client_id
        JOIN billing_periods bp ON bp.id=ch.period_id
        WHERE ch.is_void=0 AND '.adc_demo_analytics_predicate('c').'
        GROUP BY ch.currency,mon,ch.category ORDER BY mon DESC');
    foreach ($a as $r) {
        $k=$r['currency'].'|'.$r['mon'];
        $out[$k] ??= ['currency'=>$r['currency'],'month'=>$r['mon'],'advertising'=>0,'development'=>0,'other'=>0,'billed'=>0,'paid'=>0];
        $v=adc_fin_minor((string)$r['total']);
        $out[$k][$r['category']]=adc_fin_add($out[$k][$r['category']],$v);
        $out[$k]['billed']=adc_fin_add($out[$k]['billed'],$v);
    }
    $p=$db->query('SELECT p.currency,SUBSTR(p.occurred_on,1,7) mon,p.event_type,SUM(p.amount) total
        FROM payment_events p JOIN clients c ON c.id=p.client_id
        WHERE '.adc_demo_analytics_predicate('c').'
        GROUP BY p.currency,mon,p.event_type ORDER BY mon DESC');
    foreach ($p as $r) {
        $k=$r['currency'].'|'.$r['mon'];
        $out[$k] ??= ['currency'=>$r['currency'],'month'=>$r['mon'],'advertising'=>0,'development'=>0,'other'=>0,'billed'=>0,'paid'=>0];
        $v=adc_fin_minor((string)$r['total']);
        $out[$k]['paid']=adc_fin_add($out[$k]['paid'],$r['event_type']==='received'?$v:-$v);
    }
    uasort($out,static fn($a,$b)=>[$b['month'],$a['currency']] <=> [$a['month'],$b['currency']]);
    return $out;
}

/**
 * Director and employees share one read-only reporting dashboard.
 * "Received" is actual net cash; accrued charges are never mislabeled as LTV.
 * Cross-currency amounts are never added together.
 */
function adc_fin_render(PDO $db): string {
    $allowedCurrencies=['BYN','RUB','EUR','USD','KZT'];
    $requested=is_string($_GET['currency']??null)?strtoupper($_GET['currency']):'BYN';
    $currency=in_array($requested,$allowedCurrencies,true)?$requested:'BYN';
    $selected=is_string($_GET['period']??null)?$_GET['period']:'all';
    if ($selected!=='all' && !preg_match('/^20[0-9]{2}(?:-(?:0[1-9]|1[0-2]))?$/D',$selected)) {
        $selected='all';
    }
    $totals=adc_fin_groups($db);
    $months=adc_fin_months($db);
    $history=[];
    $filtered=['advertising'=>0,'development'=>0,'other'=>0,'billed'=>0,'paid'=>0];
    $years=[];
    foreach ($months as $r) {
        $years[substr((string)$r['month'],0,4)]=true;
        if ($r['currency']!==$currency) continue;
        $history[(string)$r['month']]=$r;
        if ($selected!=='all' && !str_starts_with((string)$r['month'],$selected)) continue;
        foreach (array_keys($filtered) as $key) {
            $filtered[$key]=adc_fin_add($filtered[$key],(int)$r[$key]);
        }
    }
    ksort($history);
    $all=$totals[$currency]??['advertising'=>0,'development'=>0,'other'=>0,'received'=>0,'refund'=>0];
    $allBilled=adc_fin_add(adc_fin_add($all['advertising'],$all['development']),$all['other']);
    $allPaid=adc_fin_add($all['received'],-$all['refund']);
    $balance=adc_fin_add($allBilled,-$allPaid);

    // Explicit previous-client links are the only safe way to merge LTV.
    // A manually entered old name is labeled, never guessed by spelling.
    $clients=[];
    $rows=$db->query('SELECT c.id,c.name,c.previous_client_id,c.previous_name_text,c.created_at,
        EXISTS(SELECT 1 FROM engagements e WHERE e.client_id=c.id AND e.status="active") AS active
        FROM clients c WHERE '.adc_demo_analytics_predicate('c').' ORDER BY c.id');
    foreach ($rows as $row) $clients[(int)$row['id']]=$row;
    $rootOf=[];
    foreach ($clients as $id=>$row) {
        $root=$id;$seen=[];
        while (isset($clients[$root])) {
            if (isset($seen[$root])) { $root=$id; break; }
            $seen[$root]=true;
            $parent=(int)($clients[$root]['previous_client_id']??0);
            if (!$parent || !isset($clients[$parent])) break;
            $root=$parent;
        }
        $rootOf[$id]=$root;
    }
    $groups=[];
    $active=0; $repeat=0; $closed=0;
    foreach ($clients as $id=>$c) {
        if ((int)$c['active']===1) $active++;
        else $closed++;
        if ($c['previous_client_id']!==null || trim((string)$c['previous_name_text'])!=='') $repeat++;
        $root=$rootOf[$id];
        $groups[$root]??=['name'=>(string)$clients[$root]['name'],'ids'=>[],
            'billed'=>[],'paid'=>[],'returning'=>false,'active'=>false];
        $groups[$root]['ids'][]=$id;
        if ($id!==$root || trim((string)$c['previous_name_text'])!=='')$groups[$root]['returning']=true;
        if ((int)$c['active']===1)$groups[$root]['active']=true;
    }
    $moneyQuery='SELECT c.id, x.currency,
        COALESCE((SELECT SUM(ch.amount) FROM charge_lines ch WHERE ch.client_id=c.id
          AND ch.currency=x.currency AND ch.is_void=0),0) billed,
        COALESCE((SELECT SUM(CASE WHEN p.event_type="received" THEN p.amount ELSE -p.amount END)
          FROM payment_events p WHERE p.client_id=c.id AND p.currency=x.currency),0) paid
        FROM clients c JOIN (
          SELECT client_id,currency FROM charge_lines WHERE is_void=0
          UNION SELECT client_id,currency FROM payment_events
        ) x ON x.client_id=c.id WHERE '.adc_demo_analytics_predicate('c').'
        ORDER BY c.id,x.currency LIMIT 3000';
    foreach ($db->query($moneyQuery) as $r) {
        $id=(int)$r['id'];$root=$rootOf[$id]??$id;
        if (!isset($groups[$root]))continue;
        $ccy=(string)$r['currency'];
        $groups[$root]['billed'][$ccy]=adc_fin_add($groups[$root]['billed'][$ccy]??0,
            adc_fin_minor((string)$r['billed']));
        $groups[$root]['paid'][$ccy]=adc_fin_add($groups[$root]['paid'][$ccy]??0,
            adc_fin_minor((string)$r['paid']));
    }
    $payerCount=0;
    foreach ($groups as $g)if(($g['paid'][$currency]??0)>0)$payerCount++;
    $average=$payerCount>0?intdiv($allPaid,$payerCount):0;
    $html='<h1>Аналитика</h1><form class="analytics-filter" method="get">'.
        '<input type="hidden" name="view" value="analytics">'.
        '<label>Период<select name="period"><option value="all"'.
          ($selected==='all'?' selected':'').'>За всё время</option>';
    $yearList=array_keys($years);
    $yearList[]=date('Y');
    $yearList=array_unique($yearList);
    rsort($yearList);
    foreach ($yearList as $year) {
        $html.='<option value="'.adc_h($year).'"'.($selected===$year?' selected':'').'>'.
            adc_h($year).' год</option>';
    }
    $monthList=array_keys($history);
    rsort($monthList);
    foreach ($monthList as $month) {
        $html.='<option value="'.adc_h($month).'"'.($selected===$month?' selected':'').'>'.
            adc_h(date('m.Y',strtotime($month.'-01'))).'</option>';
    }
    $html.='</select></label><label>Валюта<select name="currency">';
    foreach ($allowedCurrencies as $ccy) {
        $html.='<option value="'.$ccy.'"'.($currency===$ccy?' selected':'').'>'.$ccy.'</option>';
    }
    $html.='</select></label><button type="submit">Показать</button></form>';
    $periodLabel=$selected==='all'?'За всё время':($selected===substr($selected,0,4)?
        'За '.$selected.' год':'За '.date('m.Y',strtotime($selected.'-01')));
    $cards=[
        ['Начислено за период',$filtered['billed'],'Реклама, разработка и прочие работы'],
        ['Получено за период',$filtered['paid'],'Поступления за вычетом возвратов'],
        ['Начислено за рекламу',$filtered['advertising'],'Отдельно от разработки'],
        ['Остаток за всё время',$balance,'Начисления минус полученные деньги'],
    ];
    $html.='<section class="card analytics-overview"><div class="analytics-overview-title">'.
        '<h2>Деньги</h2><small>'.adc_h($periodLabel).' · '.$currency.'</small></div>'.
        '<div class="analytics-kpis">';
    foreach ($cards as [$label,$amount,$caption]) {
        $html.='<div class="analytics-kpi"><small>'.adc_h($label).'</small>'.
            '<strong>'.adc_fin_format($amount,$currency).'</strong>'.
            '<span>'.adc_h($caption).'</span></div>';
    }
    $html.='</div></section>';

    $html.='<section class="card"><h2>По месяцам</h2>'.
        '<p class="muted">Фиолетовый — начислено, голубой — получено. Наведите на строку, чтобы увидеть суммы.</p>';
    $chart=[];
    foreach ($history as $month=>$r) {
        if ($selected!=='all' && !str_starts_with($month,$selected))continue;
        $chart[$month]=$r;
    }
    $chart=array_slice($chart,-12,12,true);
    $max=1;
    foreach ($chart as $r)$max=max($max,abs($r['billed']),abs($r['paid']));
    if (!$chart) $html.='<p class="muted">За выбранный период операций пока нет.</p>';
    foreach ($chart as $month=>$r) {
        $w1=(int)round(abs($r['billed'])/$max*100);
        $w2=(int)round(abs($r['paid'])/$max*100);
        $title='Начислено: '.adc_fin_format($r['billed'],$currency).
            '; получено: '.adc_fin_format($r['paid'],$currency);
        $html.='<div class="analytics-chart-row" title="'.adc_h($title).'">'.
            '<span class="analytics-chart-month">'.adc_h(date('m.Y',strtotime($month.'-01'))).'</span>'.
            '<div class="analytics-chart-track"><div class="analytics-chart-bar billed" style="width:'.$w1.'%"></div>'.
            '<div class="analytics-chart-bar received" style="width:'.$w2.'%"></div></div>'.
            '<span class="analytics-chart-label">'.adc_fin_format($r['paid'],$currency).'</span></div>';
    }
    $html.='</section>';

    $html.='<section class="card"><h2>Клиенты</h2><div class="analytics-kpis">'.
        '<div class="analytics-kpi"><small>Всего клиентов</small><strong>'.count($groups).'</strong><span>Уникальные истории сотрудничества</span></div>'.
        '<div class="analytics-kpi"><small>Активных записей</small><strong>'.$active.'</strong><span>Сейчас ведём</span></div>'.
        '<div class="analytics-kpi"><small>Повторных обращений</small><strong>'.$repeat.'</strong><span>Связанных или отмеченных вручную</span></div>'.
        '<div class="analytics-kpi"><small>Завершённых записей</small><strong>'.$closed.'</strong><span>Нет активного сотрудничества</span></div>'.
        '</div></section>';
    $html.='<section class="card"><h2>LTV — ценность клиента за всё время</h2>'.
        '<p class="muted">По фактически полученным деньгам с учётом возвратов. Повторные обращения объединяются только при подтверждённой связи с прежней записью.</p>'.
        '<div class="analytics-kpis">'.
        '<div class="analytics-kpi"><small>Получено за всё время</small><strong>'.adc_fin_format($allPaid,$currency).'</strong></div>'.
        '<div class="analytics-kpi"><small>Средний LTV</small><strong>'.adc_fin_format($average,$currency).
        '</strong><span>Среди '.$payerCount.' клиентов с оплатами</span></div>'.
        '</div><h3>Финансы по клиентам</h3><div class="table-scroll"><table><thead><tr>'.
        '<th>Клиент</th><th>Начислено</th><th>Получено (LTV)</th><th>Баланс</th><th>Карточка</th>'.
        '</tr></thead><tbody>';
    uasort($groups,static fn($a,$b)=>(($b['paid'][$currency]??0)<=>($a['paid'][$currency]??0)));
    foreach (array_slice($groups,0,200,true) as $root=>$g) {
        $b=(int)($g['billed'][$currency]??0);$p=(int)($g['paid'][$currency]??0);
        $html.='<tr><td><strong>'.adc_h($g['name']).'</strong>'.
            ($g['returning']?' <span class="badge">Повторный</span>':'').
            (count($g['ids'])>1?' <small>· '.count($g['ids']).' записи</small>':'').
            '</td><td>'.adc_fin_format($b,$currency).'</td><td>'.adc_fin_format($p,$currency).
            '</td><td>'.adc_fin_format(adc_fin_add($b,-$p),$currency).
            '</td><td><a class="btn secondary" href="/?view=clients&amp;client='.$root.'">Открыть</a></td></tr>';
    }
    if (!$groups)$html.='<tr><td colspan="5">Клиентов пока нет.</td></tr>';
    $html.='</tbody></table></div></section>';
    $html.='<p class="analytics-export"><a class="btn secondary" href="/?export=balances">Экспорт CSV</a></p>';
    return $html;
}
