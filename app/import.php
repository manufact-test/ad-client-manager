<?php
declare(strict_types=1);

/**
 * Explicit canonical CSV import, not a guessing importer for arbitrary Excel
 * sheets. Read-only preview then verified two-step confirm with same bytes.
 * Never save source CSV in public_html or the git repository.
 */
function adc_import_headers(): array {
    return ['external_client_id','client_name','is_demo','started_on','billing_day',
        'payment_mode','currency','period_start','period_end','category','work_name',
        'billed','received','refunded','payment_date','archived_on','platform_text','source_kind'];
}

function adc_import_date(string $v,bool $required): ?string {
    if ($v === '' && !$required) return null;
    return adc_w_date($v);
}

function adc_import_money(string $v,bool $signed=false): ?string {
    if ($v==='' || preg_match('/^0(?:[,.]0{1,2})?$/D',$v)) return null;
    return adc_w_amount($v,$signed);
}

function adc_import_parse(string $path): array {
    if (!is_file($path) || !is_readable($path) || filesize($path)>2*1024*1024 || filesize($path)<30) {
        throw new RuntimeException('CSV должен быть не пустым и не больше 2 МБ.');
    }
    $raw=file_get_contents($path);
    if (!is_string($raw) || !mb_check_encoding($raw,'UTF-8') || str_contains($raw,"\0")) {
        throw new RuntimeException('CSV должен быть в UTF-8 без бинарных данных.');
    }
    $file=fopen($path,'rb');
    if (!$file) throw new RuntimeException('Не удалось прочитать CSV.');
    $lines=[];
    $clients=[];
    $periods=[];
    try {
        $headers=fgetcsv($file,0,',','"','');
        if (!is_array($headers)) throw new RuntimeException('У CSV нет заголовка.');
        $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
        if ($headers!==adc_import_headers()) {
            throw new RuntimeException('Колонки CSV должны точно соответствовать шаблону и порядку полей.');
        }
        $line=1;
        while (($record=fgetcsv($file,0,',','"',''))!==false) {
            $line++;
            if ($record===[null]) continue;
            if ($line>2001) throw new RuntimeException('В одной загрузке максимум 2000 строк.');
            if (count($record)!==count($headers)) {
                throw new RuntimeException('Неверное число полей CSV в строке '.$line);
            }
            $row=array_combine($headers,array_map('trim',$record));
            $id=$row['external_client_id'];
            $name=$row['client_name'];
            if ($id==='' || mb_strlen($id)>80 || $name==='' || mb_strlen($name)>240 ||
                !in_array($row['is_demo'],['0','1'],true) ||
                mb_strlen($row['platform_text'])>400 ||
                !in_array($row['source_kind'],['advertising','referral','old_contact','inbound','other'],true)) {
                throw new RuntimeException('Некорректные данные клиента в строке '.$line);
            }
            $started=adc_import_date($row['started_on'],true);
            $pstart=adc_import_date($row['period_start'],true);
            $pend=adc_import_date($row['period_end'],true);
            $closed=adc_import_date($row['archived_on'],false);
            $paymentDate=adc_import_date($row['payment_date'],false);
            $day=filter_var($row['billing_day'],FILTER_VALIDATE_INT);
            if (!$day || $day<1 || $day>31 || !in_array($row['payment_mode'],['prepayment','postpayment'],true) ||
                $pstart>=$pend || $pstart<$started || ($closed!==null && ($closed<$started || $pend>(new DateTimeImmutable($closed))->modify('+1 day')->format('Y-m-d')))) {
                throw new RuntimeException('Неверные периоды или условия в строке '.$line);
            }
            $ccy=adc_w_currency($row['currency']);
            $category=$row['category'];
            $label=$row['work_name'];
            $billed=adc_import_money($row['billed'],true);
            $received=adc_import_money($row['received']);
            $refunded=adc_import_money($row['refunded']);
            if (($billed!==null && (!in_array($category,['advertising','development','other'],true) ||
                  $label==='' || mb_strlen($label)>240)) ||
                ($billed===null && ($category!=='' || $label!=='')) ||
                (($received!==null || $refunded!==null) && $paymentDate===null) ||
                ($received===null && $refunded===null && $paymentDate!==null)) {
                throw new RuntimeException('Некорректные работы или оплаты в строке '.$line);
            }
            $client=[
                'name'=>$name,'demo'=>(int)$row['is_demo'],'started'=>$started,
                'billing_day'=>(int)$day,'mode'=>$row['payment_mode'],
                'currency'=>$ccy,'archived'=>$closed,
                'platform'=>$row['platform_text'],'source'=>$row['source_kind']
            ];
            if (isset($clients[$id]) && $clients[$id]!==$client) {
                throw new RuntimeException('Поля одного external_client_id различаются в строке '.$line);
            }
            $clients[$id]=$client;
            $periodKey=$id."\0".$pstart."\0".$pend;
            $periods[$periodKey]=['id'=>$id,'start'=>$pstart,'end'=>$pend];
            $lines[]=[
                'id'=>$id,'period_key'=>$periodKey,'category'=>$category,
                'label'=>$label,'billed'=>$billed,'received'=>$received,
                'refunded'=>$refunded,'date'=>$paymentDate
            ];
        }
    } finally {
        fclose($file);
    }
    if (!$lines) throw new RuntimeException('Нет строк для импорта.');
    $balances=[];
    foreach ($lines as $item) {
        $external=$item['id'];
        $balances[$external] ??= 0;
        if ($item['received']!==null) {
            $balances[$external]=adc_fin_add($balances[$external],adc_fin_minor($item['received']));
        }
        if ($item['refunded']!==null) {
            $balances[$external]=adc_fin_add($balances[$external],-adc_fin_minor($item['refunded']));
        }
    }
    foreach ($balances as $balance) {
        if ($balance<0) throw new RuntimeException('Суммарные возвраты превышают поступления у одного из клиентов CSV.');
    }
    $ranges=[];
    foreach ($periods as $period) $ranges[$period['id']][]=$period;
    foreach ($ranges as $periodList) {
        usort($periodList,static fn($a,$b)=>strcmp($a['start'],$b['start']));
        for ($i=1;$i<count($periodList);$i++) {
            if ($periodList[$i]['start'] < $periodList[$i-1]['end']) {
                throw new RuntimeException('Для одного клиента найдены пересекающиеся периоды.');
            }
        }
    }
    return [
        'sha256'=>hash('sha256',$raw),'clients'=>$clients,'periods'=>$periods,
        'lines'=>$lines,'client_count'=>count($clients),
        'period_count'=>count($periods),'row_count'=>count($lines)
    ];
}

function adc_import_apply(PDO $db,array $data,int $actor):void {
    $lock=(int)$db->query("SELECT GET_LOCK('adclients-history-import',10)")->fetchColumn();
    if ($lock!==1) throw new RuntimeException('Другой импорт выполняется. Попробуйте позже.');
    try {
        $db->beginTransaction();
        $check=$db->prepare('SELECT id FROM audit_events WHERE action="import_history_csv"
            AND JSON_UNQUOTE(JSON_EXTRACT(details_json,"$.sha256"))=? LIMIT 1');
        $check->execute([$data['sha256']]);
        if ($check->fetchColumn()) {
            throw new RuntimeException('Этот CSV уже был импортирован. Повторный импорт запрещён.');
        }
        $clientMap=[];
        $engagementMap=[];
        foreach ($data['clients'] as $external=>$client) {
            $s=$db->prepare('INSERT INTO clients(name,platform_text,source_kind,is_demo,created_by)
                VALUES (?,?,?,?,?)');
            $s->execute([$client['name'],$client['platform'],$client['source'],$client['demo'],$actor]);
            $clientMap[$external]=(int)$db->lastInsertId();
            $s=$db->prepare('INSERT INTO engagements(client_id,started_on,ended_on,billing_day,payment_mode,
                currency,status) VALUES (?,?,?,?,?,?,?)');
            $s->execute([$clientMap[$external],$client['started'],$client['archived'],
                $client['billing_day'],$client['mode'],$client['currency'],
                $client['archived']!==null?'archived':'active']);
            $engagementMap[$external]=(int)$db->lastInsertId();
        }
        $periodMap=[];
        foreach ($data['periods'] as $key=>$p) {
            $s=$db->prepare('INSERT INTO billing_periods(engagement_id,period_start,period_end,is_final)
                VALUES (?,?,?,?)');
            $archived=$data['clients'][$p['id']]['archived'];
            $isFinal=$archived!==null && $p['end']===(new DateTimeImmutable($archived))->modify('+1 day')->format('Y-m-d');
            $s->execute([$engagementMap[$p['id']],$p['start'],$p['end'],(int)$isFinal]);
            $periodMap[$key]=(int)$db->lastInsertId();
        }
        $charge=$db->prepare('INSERT INTO charge_lines(client_id,period_id,category,label,amount,currency,reason)
            VALUES (?,?,?,?,?,?,"Исторический импорт CSV")');
        $payment=$db->prepare('INSERT INTO payment_events(client_id,period_id,event_type,amount,currency,occurred_on,note)
            VALUES (?,?,?,?,?,?,"Исторический импорт CSV")');
        foreach ($data['lines'] as $line) {
            $id=$clientMap[$line['id']];
            $period=$periodMap[$line['period_key']];
            $currency=$data['clients'][$line['id']]['currency'];
            if ($line['billed']!==null) {
                $charge->execute([$id,$period,$line['category'],$line['label'],$line['billed'],$currency]);
            }
            foreach (['received'=>'received','refunded'=>'refund'] as $field=>$event) {
                if ($line[$field]!==null) {
                    $payment->execute([$id,$period,$event,$line[$field],$currency,$line['date']]);
                }
            }
        }
        adc_audit($db,$actor,'import_history_csv','import',null,[
            'sha256'=>$data['sha256'],'clients'=>$data['client_count'],
            'periods'=>$data['period_count'],'rows'=>$data['row_count']
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    } finally {
        $db->query("SELECT RELEASE_LOCK('adclients-history-import')");
    }
}

function adc_import_action(PDO $db,array $user,string $action):bool {
    if (!in_array($action,['preview_history_csv','commit_history_csv'],true)) return false;
    adc_require_director($user);
    $file=$_FILES['history_csv'] ?? null;
    if (!is_array($file) || ($file['error']??-1)!==UPLOAD_ERR_OK ||
        !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Выберите CSV-файл для проверки.');
    }
    $data=adc_import_parse($file['tmp_name']);
    if ($action==='preview_history_csv') {
        $_SESSION['history_import_preview']=[
            'sha256'=>$data['sha256'],'clients'=>$data['client_count'],
            'periods'=>$data['period_count'],'rows'=>$data['row_count']
        ];
        adc_flash('CSV проверен: '.$data['client_count'].' клиентов, '.
            $data['period_count'].' периодов, '.$data['row_count'].' строк. Ничего не импортировано.');
    } else {
        $expected=$_SESSION['history_import_preview']??null;
        if (!is_array($expected) || !hash_equals((string)($expected['sha256']??''),$data['sha256'])) {
            throw new RuntimeException('Сначала выполните предпросмотр именно этого CSV.');
        }
        adc_import_apply($db,$data,(int)$user['id']);
        unset($_SESSION['history_import_preview']);
        adc_flash('История импортирована: '.$data['client_count'].' клиентов, '.
            $data['period_count'].' периодов.');
    }
    adc_redirect('?view=team');
}

function adc_import_panel(array $user):string {
    if ($user['role']!=='director') return '';
    $csrf=adc_csrf();
    $header=implode(',',adc_import_headers());
    $html='<details class="card settings-disclosure"><summary>Импорт клиентов из CSV</summary>'.
        '<p class="muted">Загрузите таблицу в формате CSV (до 2 МБ). Сначала проверьте содержимое, затем подтвердите импорт.</p>'.
        '<details><summary>Требования к файлу</summary>'.
        '<p class="muted">Разделитель — запятая, кодировка UTF-8. До 2000 строк. '.
        'Используйте шаблон требуемых столбцов.</p>'.
        '<code>'.adc_h($header).'</code></details>';
    $html.='<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.$csrf.'">'.
        '<input type="hidden" name="action" value="preview_history_csv">'.
        '<label class="field">Проверить CSV без записи в базу<input type="file" name="history_csv" accept=".csv,text/csv" required></label>'.
        '<button type="submit">Предпросмотр</button></form>';
    if (isset($_SESSION['history_import_preview']) && is_array($_SESSION['history_import_preview'])) {
        $p=$_SESSION['history_import_preview'];
        $html.='<p><strong>Проверено: '.(int)$p['clients'].' клиентов / '.
            (int)$p['periods'].' периодов / '.(int)$p['rows'].' строк.</strong></p>'.
            '<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.$csrf.'">'.
            '<input type="hidden" name="action" value="commit_history_csv">'.
            '<label class="field">Повторно выбрать тот же CSV<input type="file" name="history_csv" accept=".csv,text/csv" required></label>'.
            '<button type="submit">Подтвердить импорт</button></form>';
    }
    return $html.'</details>';
}
