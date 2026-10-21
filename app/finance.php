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

function adc_fin_render(PDO $db): string {
    $html='<h1>Аналитика</h1><p class="muted">Только реальные клиенты. Начисления и деньги получены из разных операций; все суммы и балансы считаются до копейки без смешивания валют.</p>';
    $html.='<p><a class="btn secondary" href="/?export=balances">Скачать сверку реальных клиентов (CSV)</a></p>';
    $totals=adc_fin_groups($db);
    $html.='<section class="card"><h2>Начисления, поступления и задолженность</h2><div class="table-scroll" tabindex="0" role="region" aria-label="Финансовая сводка: горизонтальная прокрутка"><table><thead><tr><th>Валюта</th><th>Реклама</th><th>Разработка</th><th>Прочее</th><th>Начислено</th><th>Получено нетто</th><th>Баланс</th></tr></thead><tbody>';
    foreach ($totals as $ccy=>$t) {
        $billed=adc_fin_add(adc_fin_add($t['advertising'],$t['development']),$t['other']);
        $net=adc_fin_add($t['received'],-$t['refund']);
        $html.='<tr><td>'.adc_h($ccy).'</td><td>'.adc_fin_format($t['advertising'],$ccy).
            '</td><td>'.adc_fin_format($t['development'],$ccy).'</td><td>'.adc_fin_format($t['other'],$ccy).
            '</td><td>'.adc_fin_format($billed,$ccy).'</td><td>'.adc_fin_format($net,$ccy).
            '</td><td>'.adc_fin_format(adc_fin_add($billed,-$net),$ccy).'</td></tr>';
    }
    if (!$totals) $html.='<tr><td colspan="7">Пока нет финансовых операций.</td></tr>';
    $html.='</tbody></table></div><small>Положительный баланс — задолженность клиента, отрицательный — аванс. Корректировки учитываются со знаком.</small></section>';

    $html.='<section class="card"><h2>По месяцам</h2><p class="muted">Начисления относятся к месяцу начала расчётного периода, поступления — к месяцу фактической даты оплаты.</p><div class="table-scroll" tabindex="0" role="region" aria-label="Помесячная аналитика: горизонтальная прокрутка"><table><thead><tr><th>Месяц</th><th>Валюта</th><th>Реклама</th><th>Разработка</th><th>Прочее</th><th>Начислено</th><th>Получено нетто</th></tr></thead><tbody>';
    $months=adc_fin_months($db);
    foreach (array_slice($months,0,48) as $r) {
        $html.='<tr><td>'.adc_h($r['month']).'</td><td>'.adc_h($r['currency']).'</td><td>'.
            adc_fin_format($r['advertising'],$r['currency']).'</td><td>'.
            adc_fin_format($r['development'],$r['currency']).'</td><td>'.
            adc_fin_format($r['other'],$r['currency']).'</td><td>'.
            adc_fin_format($r['billed'],$r['currency']).'</td><td>'.
            adc_fin_format($r['paid'],$r['currency']).'</td></tr>';
    }
    if (!$months) $html.='<tr><td colspan="7">Нет операций по месяцам.</td></tr>';
    $html.='</tbody></table></div></section>';

    $counts=$db->query('SELECT COUNT(*) count_clients,
        COALESCE(SUM(EXISTS(SELECT 1 FROM engagements e WHERE e.client_id=c.id AND e.status="active")),0) active_clients,
        COALESCE(SUM(EXISTS(SELECT 1 FROM engagements e WHERE e.client_id=c.id AND e.status="archived")),0) ever_archived,
        COALESCE(SUM(EXISTS(SELECT 1 FROM engagements e WHERE e.client_id=c.id AND e.status="archived")
          AND NOT EXISTS(SELECT 1 FROM engagements x WHERE x.client_id=c.id AND x.status="active")),0) churned,
        COALESCE(SUM(EXISTS(SELECT 1 FROM engagements e WHERE e.client_id=c.id AND e.status="archived")
          AND EXISTS(SELECT 1 FROM engagements x WHERE x.client_id=c.id AND x.status="active")),0) resumed,
        COALESCE(SUM(c.previous_client_id IS NOT NULL OR (c.previous_name_text IS NOT NULL AND c.previous_name_text<>"")),0) returning
        FROM clients c WHERE '.adc_demo_analytics_predicate('c'))->fetch();
    $html.='<section class="card"><h2>Клиентская база</h2><p>Клиентов: <strong>'.(int)$counts['count_clients'].
        '</strong> · активных: <strong>'.(int)$counts['active_clients'].
        '</strong> · были в архиве: <strong>'.(int)$counts['ever_archived'].
        '</strong> · вернулись из архива: <strong>'.(int)$counts['resumed'].
        '</strong> · пришли по прежним контактам: <strong>'.(int)$counts['returning'].
        '</strong> · закрыты без возобновления: <strong>'.(int)$counts['churned'].'</strong></p>'.
        '<p class="muted">Отток: ранее архивированные без активного сотрудничества. Повторный приход по ссылке на прошлую запись считается отдельно.</p>';
    foreach ($db->query('SELECT COALESCE(NULLIF(source_kind,""),"unknown") kind,COUNT(*) qty
        FROM clients c WHERE '.adc_demo_analytics_predicate('c').'
        GROUP BY source_kind ORDER BY qty DESC') as $row) {
        $html.='<div class="item"><span>'.adc_h(adc_source_label((string)$row['kind'])).'</span><strong>'.(int)$row['qty'].'</strong></div>';
    }
    $html.='</section><section class="card"><h2>LTV и баланс по клиентам</h2><div class="table-scroll" tabindex="0" role="region" aria-label="LTV клиентов: горизонтальная прокрутка"><table><thead><tr><th>Клиент</th><th>Валюта</th><th>Начислено (LTV)</th><th>Получено нетто</th><th>Баланс</th></tr></thead><tbody>';
    $sql='SELECT c.name,t.currency,
        COALESCE((SELECT SUM(ch.amount) FROM charge_lines ch
          WHERE ch.client_id=c.id AND ch.currency=t.currency AND ch.is_void=0),0) billed,
        COALESCE((SELECT SUM(CASE WHEN p.event_type="received" THEN p.amount ELSE -p.amount END)
          FROM payment_events p WHERE p.client_id=c.id AND p.currency=t.currency),0) paid
        FROM clients c JOIN (
          SELECT client_id,currency FROM charge_lines WHERE is_void=0
          UNION SELECT client_id,currency FROM payment_events
        ) t ON t.client_id=c.id WHERE '.adc_demo_analytics_predicate('c').'
        ORDER BY c.name,t.currency LIMIT 500';
    foreach ($db->query($sql) as $r) {
        $b=adc_fin_minor((string)$r['billed']);
        $p=adc_fin_minor((string)$r['paid']);
        $html.='<tr><td>'.adc_h($r['name']).'</td><td>'.adc_h($r['currency']).'</td><td>'.
            adc_fin_format($b,$r['currency']).'</td><td>'.adc_fin_format($p,$r['currency']).
            '</td><td>'.adc_fin_format(adc_fin_add($b,-$p),$r['currency']).'</td></tr>';
    }
    return $html.'</tbody></table></div></section>';
}
