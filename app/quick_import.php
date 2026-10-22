<?php
declare(strict_types=1);

/**
 * Quick onboarding: one new client + one initial engagement per CSV row.
 * This intentionally NEVER imports real payment events, billed lines or PDFs.
 * Historical finance has its own strict 18-column importer.
 */
function adc_quick_import_headers(): array {
    return ['client_name','is_demo','started_on','payment_mode','currency',
        'quoted_amount','legal_type','source_kind','platform_text','source_note',
        'previous_name_text','requires_acts','requires_specs','comment_text'];
}

/** A synthetic, safe-to-download template; never contains customer data. */
function adc_quick_import_template(): array {
    return [
        adc_quick_import_headers(),
        ['ООО Пример рекламодателя','0','2026-10-01','prepayment','BYN',
         '1250.00','legal','referral','Контекстная реклама','','','1','1',
         'Вымышленные данные для примера'],
        ['Демо пример','1','2026-10-05','postpayment','USD',
         '','individual','inbound','Демо услуга','','','0','0','']
    ];
}

function adc_quick_import_parse(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Не удалось прочитать CSV клиентов.');
    }
    $size=filesize($path);
    if ($size===false || $size<30 || $size>2*1024*1024) {
        throw new RuntimeException('Размер CSV должен быть от 30 байт до 2 МБ.');
    }
    $raw=file_get_contents($path);
    if (!is_string($raw) || !mb_check_encoding($raw,'UTF-8') ||
        str_contains($raw,"\0")) {
        throw new RuntimeException('Нужен CSV в UTF-8 без бинарных данных.');
    }
    $file=fopen($path,'rb');
    if (!$file) throw new RuntimeException('CSV недоступен для чтения.');
    $rows=[];$seen=[];
    try {
        $headers=fgetcsv($file,0,',','"','');
        if (!is_array($headers)) throw new RuntimeException('Нет заголовков CSV.');
        $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);
        if ($headers!==adc_quick_import_headers()) {
            throw new RuntimeException('Колонки должны точно совпадать с шаблоном быстрого импорта.');
        }
        $line=1;
        while(($record=fgetcsv($file,0,',','"',''))!==false){
            $line++;
            if($record===[null])continue;
            if($line>501)throw new RuntimeException('В одной загрузке максимум 500 клиентов.');
            if(count($record)!==count($headers)){
                throw new RuntimeException('Неверное число колонок в строке '.$line.'.');
            }
            $r=array_combine($headers,array_map('trim',$record));
            $name=$r['client_name'];
            if($name==='' || mb_strlen($name)>240) {
                throw new RuntimeException('Укажите имя клиента до 240 символов в строке '.$line.'.');
            }
            foreach(['is_demo','requires_acts','requires_specs'] as $boolean){
                if(!in_array($r[$boolean],['0','1'],true)){
                    throw new RuntimeException('Поле '.$boolean.' должно быть 0 или 1 в строке '.$line.'.');
                }
            }
            if(!in_array($r['legal_type'],['legal','individual'],true) ||
               !in_array($r['source_kind'],['advertising','referral','old_contact','inbound','other'],true) ||
               !in_array($r['payment_mode'],['prepayment','postpayment'],true)){
                throw new RuntimeException('Неверный тип клиента, источник или условия оплаты в строке '.$line.'.');
            }
            foreach([['platform_text',400],['source_note',240],['previous_name_text',240],['comment_text',5000]] as [$key,$limit]){
                if(mb_strlen($r[$key])>$limit){
                    throw new RuntimeException('Слишком длинное поле '.$key.' в строке '.$line.'.');
                }
            }
            $r['started_on']=adc_w_date($r['started_on']);
            $r['currency']=adc_w_currency($r['currency']);
            $quote=$r['quoted_amount'];
            // The legacy engagement field requires DECIMAL NOT NULL:
            // zero here is an *unknown reference*, never an actual charge.
            $r['quoted_amount']=in_array($quote,['','0','0.0','0.00'],true)
                ? '0.00' : adc_w_amount($quote,false);
            $r['billing_day']=(int)substr($r['started_on'],8,2);
            $key=mb_strtolower($name,'UTF-8')."\0".$r['is_demo'];
            if(isset($seen[$key])){
                throw new RuntimeException('Повторяющийся клиент в строках '.$seen[$key].' и '.$line.'.');
            }
            $seen[$key]=$line;
            $rows[]=$r;
        }
    } finally { fclose($file); }
    if(!$rows)throw new RuntimeException('Нет клиентов для импорта.');
    return ['sha256'=>hash('sha256',$raw),'rows'=>$rows,'count'=>count($rows),
        'real'=>count(array_filter($rows,static fn($r)=>$r['is_demo']==='0')),
        'demo'=>count(array_filter($rows,static fn($r)=>$r['is_demo']==='1'))];
}

/** The director reviews this before commit and it runs again inside the lock. */
function adc_quick_import_assert_new(PDO $db,array $rows): void {
    $exists=$db->prepare('SELECT id FROM clients
        WHERE is_demo=? AND LOWER(TRIM(name))=LOWER(?) LIMIT 1');
    foreach($rows as $row){
        $exists->execute([(int)$row['is_demo'],$row['client_name']]);
        if($exists->fetchColumn()!==false){
            throw new RuntimeException('Один или несколько клиентов уже есть в системе. '
                .'Повторный импорт запрещён. Используйте редактирование карточки.');
        }
    }
}

function adc_quick_import_apply(PDO $db,array $parsed,int $actor):void {
    if($actor<1 || !is_array($parsed['rows']??null) ||
       !is_string($parsed['sha256']??null) ||
       !preg_match('/\A[a-f0-9]{64}\z/D',$parsed['sha256'])) {
        throw new RuntimeException('Импорт не был подтверждён корректно.');
    }
    // Reuse the history import's mutex, preventing two bulk imports from
    // racing. No new tables/DDL; the entire batch is a single transaction.
    $lock=(int)$db->query("SELECT GET_LOCK('adclients-history-import',10)")->fetchColumn();
    if($lock!==1)throw new RuntimeException('Другой массовый импорт выполняется.');
    try{
        $db->beginTransaction();
        $check=$db->prepare('SELECT id FROM audit_events
            WHERE action="import_quick_clients"
            AND JSON_UNQUOTE(JSON_EXTRACT(details_json,"$.sha256"))=? LIMIT 1');
        $check->execute([$parsed['sha256']]);
        if($check->fetchColumn()) {
            throw new RuntimeException('Этот файл уже импортирован.');
        }
        adc_quick_import_assert_new($db,$parsed['rows']);
        $addClient=$db->prepare('INSERT INTO clients
            (name,is_demo,platform_text,legal_type,source_kind,source_note,
             previous_name_text,requires_acts,requires_specs,comment_text,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $addTerms=$db->prepare('INSERT INTO engagements
            (client_id,started_on,billing_day,payment_mode,currency,quoted_amount)
            VALUES (?,?,?,?,?,?)');
        foreach($parsed['rows'] as $row) {
            $addClient->execute([
                $row['client_name'],(int)$row['is_demo'],$row['platform_text'],
                $row['legal_type'],$row['source_kind'],$row['source_note'],
                $row['previous_name_text'],(int)$row['requires_acts'],
                (int)$row['requires_specs'],$row['comment_text'],$actor
            ]);
            $clientId=(int)$db->lastInsertId();
            $addTerms->execute([$clientId,$row['started_on'],$row['billing_day'],
                $row['payment_mode'],$row['currency'],$row['quoted_amount']]);
        }
        adc_audit($db,$actor,'import_quick_clients','import',null,[
            'sha256'=>$parsed['sha256'],'clients'=>$parsed['count'],
            'real'=>$parsed['real'],'demo'=>$parsed['demo']
        ]);
        $db->commit();
    } catch(Throwable $error) {
        if($db->inTransaction())$db->rollBack();
        throw $error;
    } finally {
        $db->query("SELECT RELEASE_LOCK('adclients-history-import')");
    }
}

function adc_quick_import_action(PDO $db,array $user,string $action): bool {
    if(!in_array($action,['preview_quick_clients','commit_quick_clients'],true))return false;
    adc_require_director($user);
    $file=$_FILES['quick_csv']??null;
    if(!is_array($file) || ($file['error']??-1)!==UPLOAD_ERR_OK ||
       !is_string($file['tmp_name']??null) ||
       !is_uploaded_file($file['tmp_name'])){
        throw new RuntimeException('Выберите CSV для проверки.');
    }
    $data=adc_quick_import_parse($file['tmp_name']);
    if($action==='preview_quick_clients'){
        adc_quick_import_assert_new($db,$data['rows']);
        $_SESSION['quick_clients_preview']=[
            'sha256'=>$data['sha256'],'count'=>$data['count'],
            'real'=>$data['real'],'demo'=>$data['demo'],
            'names'=>array_slice(array_column($data['rows'],'client_name'),0,8)
        ];
        adc_flash('Проверено: '.$data['count'].' новых клиентов. Ничего ещё не сохранено.');
        adc_redirect('?view=team#quick-import');
    }
    $approved=$_SESSION['quick_clients_preview']??null;
    if(!is_array($approved) ||
       !is_string($approved['sha256']??null) ||
       !hash_equals($approved['sha256'],$data['sha256'])){
        throw new RuntimeException('Сначала проверьте именно этот файл через предпросмотр.');
    }
    adc_quick_import_apply($db,$data,(int)$user['id']);
    unset($_SESSION['quick_clients_preview']);
    adc_flash('Добавлено '.$data['count'].' клиентов (рабочих: '.$data['real'].
        ', демо: '.$data['demo'].'). Теперь можно добавить документы и оплаты в карточках.');
    adc_redirect('?view=clients');
}

function adc_quick_import_panel(array $user):string {
    if(($user['role']??'')!=='director')return '';
    $csrf=adc_csrf();
    $html='<details class="card settings-disclosure" id="quick-import">'.
        '<summary>Массовое добавление клиентов — быстрый CSV</summary>'.
        '<p class="muted">Одна строка = один новый клиент с первоначальными условиями. '.
        'Никаких начислений, оплат или документов этот способ не создаёт. '.
        'Повторяющиеся названия и уже существующие клиенты блокируются. '.
        'До перевода GitHub в PRIVATE используйте только вымышленные данные.</p>'.
        '<p><a class="btn secondary" href="/?export=quick_clients_template">'.
        'Скачать шаблон CSV</a></p>'.
        '<p class="muted">UTF-8 с запятыми; суммы пишутся 1500.00, дата — ГГГГ-ММ-ДД. '.
        'Пустая сумма означает «не указана», а не поступление денег. '.
        'Не более 500 строк и 2 МБ. Столбцы и порядок менять нельзя.</p>'.
        '<form method="post" enctype="multipart/form-data">'.
        '<input type="hidden" name="csrf" value="'.adc_h($csrf).'">'.
        '<input type="hidden" name="action" value="preview_quick_clients">'.
        '<label class="field">Файл новых клиентов<input name="quick_csv" type="file" '.
        'accept=".csv,text/csv" required></label>'.
        '<button type="submit">Проверить перед импортом</button></form>';
    $preview=$_SESSION['quick_clients_preview']??null;
    if(is_array($preview)){
        $html.='<p><strong>Готово к импорту: '.(int)$preview['count'].
            ' · реальных: '.(int)$preview['real'].
            ' · демо: '.(int)$preview['demo'].'</strong></p>';
        if(!empty($preview['names']) && is_array($preview['names'])){
            $html.='<p class="muted">Первые клиенты: '.
                adc_h(implode(', ',array_map('strval',$preview['names']))).'</p>';
        }
        $html.='<p class="muted">Для подтверждения снова выберите тот же файл. '.
            'Если файл изменится, система не выполнит импорт.</p>'.
            '<form method="post" enctype="multipart/form-data">'.
            '<input type="hidden" name="csrf" value="'.adc_h($csrf).'">'.
            '<input type="hidden" name="action" value="commit_quick_clients">'.
            '<label class="field">Повторно выбрать проверенный CSV<input name="quick_csv" '.
            'type="file" accept=".csv,text/csv" required></label>'.
            '<button type="submit" data-confirm="Добавить всех указанных новых клиентов?">'.
            'Подтвердить добавление '.$preview['count'].' клиентов</button></form>';
    }
    return $html.'</details>';
}
