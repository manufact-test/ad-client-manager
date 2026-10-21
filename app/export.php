<?php
declare(strict_types=1);

/**
 * Safe read-only CSV exports for external reconciliation.
 * "Real" financial report must NEVER include demo data; a scoped client
 * ledger may contain a demo only when explicitly requested by exact ID.
 */
function adc_export_safe_cell(string $value): string {
    // Excel, LibreOffice and some CSV consumers evaluate leading = + - @
    // or leading control/whitespace followed by formula operators.
    if (preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $value)) {
        return $value; // Valid numeric values, including negative adjustments.
    }
    if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) ||
        preg_match('/^[\t\r\n]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function adc_export_decimal(int $minor): string {
    $sign=$minor<0?'-':'';
    if ($minor===PHP_INT_MIN) {
        throw new OverflowException('Недопустимая сумма для выгрузки.');
    }
    $absolute=abs($minor);
    return $sign . intdiv($absolute,100) . '.' .
        str_pad((string)($absolute % 100),2,'0',STR_PAD_LEFT);
}

function adc_export_encode(array $rows): string {
    $stream=fopen('php://temp','w+');
    if (!$stream) throw new RuntimeException('Не удалось сформировать CSV.');
    try {
        fwrite($stream,"\xEF\xBB\xBF");
        foreach ($rows as $row) {
            $cells=array_map(static fn($cell)=>adc_export_safe_cell((string)$cell),$row);
            if (fputcsv($stream,$cells,';','"','')===false) {
                throw new RuntimeException('Не удалось записать строку CSV.');
            }
        }
        rewind($stream);
        $text=stream_get_contents($stream);
        if (!is_string($text)) throw new RuntimeException('Ошибка формирования CSV.');
        return $text;
    } finally {
        fclose($stream);
    }
}

/** @return array<int,array<int,string|int>> */
function adc_export_balances(PDO $db): array {
    $rows=[['Клиент ID','Клиент','Валюта','Реклама','Разработка','Прочее',
        'Начислено','Получено','Возвращено','Поступило нетто','Баланс']];
    $sql='SELECT c.id,c.name,t.currency,
        COALESCE((SELECT SUM(ch.amount) FROM charge_lines ch
            WHERE ch.client_id=c.id AND ch.currency=t.currency AND ch.is_void=0
              AND ch.category="advertising"),0) advertising,
        COALESCE((SELECT SUM(ch.amount) FROM charge_lines ch
            WHERE ch.client_id=c.id AND ch.currency=t.currency AND ch.is_void=0
              AND ch.category="development"),0) development,
        COALESCE((SELECT SUM(ch.amount) FROM charge_lines ch
            WHERE ch.client_id=c.id AND ch.currency=t.currency AND ch.is_void=0
              AND ch.category="other"),0) other,
        COALESCE((SELECT SUM(p.amount) FROM payment_events p
            WHERE p.client_id=c.id AND p.currency=t.currency AND p.event_type="received"),0) received,
        COALESCE((SELECT SUM(p.amount) FROM payment_events p
            WHERE p.client_id=c.id AND p.currency=t.currency AND p.event_type="refund"),0) refund
        FROM clients c JOIN (
            SELECT client_id,currency FROM charge_lines WHERE is_void=0
            UNION SELECT client_id,currency FROM payment_events
        ) t ON t.client_id=c.id
        WHERE '.adc_demo_analytics_predicate('c').'
        ORDER BY c.id,t.currency';
    foreach ($db->query($sql) as $row) {
        $ad=adc_fin_minor((string)$row['advertising']);
        $dev=adc_fin_minor((string)$row['development']);
        $other=adc_fin_minor((string)$row['other']);
        $received=adc_fin_minor((string)$row['received']);
        $refund=adc_fin_minor((string)$row['refund']);
        $bill=adc_fin_add(adc_fin_add($ad,$dev),$other);
        $net=adc_fin_add($received,-$refund);
        $rows[]=[(int)$row['id'],(string)$row['name'],(string)$row['currency'],
            adc_export_decimal($ad),adc_export_decimal($dev),adc_export_decimal($other),
            adc_export_decimal($bill),adc_export_decimal($received),adc_export_decimal($refund),
            adc_export_decimal($net),adc_export_decimal(adc_fin_add($bill,-$net))];
    }
    return $rows;
}

/** @return array<int,array<int,string|int>> */
function adc_export_client_ledger(PDO $db,int $clientId): array {
    $client=adc_w_client($db,$clientId);
    $rows=[['Клиент ID','Клиент','Демо','Сотрудничество ID','Период ID',
        'Дата начала периода','Дата конца периода','Дата операции',
        'Вид операции','Категория','Работа/примечание','Сумма','Валюта','ID операции']];
    $stmt=$db->prepare('SELECT e.id engagement_id,p.id period_id,p.period_start,p.period_end,
        ch.id operation_id,ch.category,ch.label,ch.amount,ch.currency,ch.is_void,
        "charge" entry_type,COALESCE(p.period_start,DATE(ch.created_at)) occurred_on
        FROM charge_lines ch LEFT JOIN billing_periods p ON p.id=ch.period_id
        LEFT JOIN engagements e ON e.id=p.engagement_id
        WHERE ch.client_id=?
        UNION ALL
        SELECT e.id engagement_id,p.id period_id,p.period_start,p.period_end,
        pe.id operation_id,pe.event_type category,pe.note label,pe.amount,pe.currency,
        0 is_void,"payment" entry_type,pe.occurred_on
        FROM payment_events pe LEFT JOIN billing_periods p ON p.id=pe.period_id
        LEFT JOIN engagements e ON e.id=p.engagement_id
        WHERE pe.client_id=?
        ORDER BY occurred_on,entry_type,operation_id');
    $stmt->execute([$clientId,$clientId]);
    foreach ($stmt as $line) {
        $kind=$line['entry_type']==='charge'
            ? ((int)$line['is_void']===1?'Начисление аннулировано':'Начисление')
            : ($line['category']==='refund'?'Возврат':'Поступление');
        $amount=adc_fin_minor((string)$line['amount']);
        if ($line['entry_type']==='payment' && $line['category']==='refund') {
            $amount=-$amount; // Show a refund as a negative cash movement.
        }
        // Voided charge remains visible as historical evidence; it is
        // explicitly labeled and excluded from the effective billed totals.
        $rows[]=[(int)$clientId,(string)$client['name'],(int)$client['is_demo'],
            (int)$line['engagement_id'],(int)$line['period_id'],
            (string)($line['period_start']??''),(string)($line['period_end']??''),
            (string)$line['occurred_on'],$kind,
            (string)$line['category'],(string)($line['label']??''),
            adc_export_decimal($amount),(string)$line['currency'],(int)$line['operation_id']];
    }
    return $rows;
}

function adc_export_csv(PDO $db,array $user,string $report): never {
    if (!in_array($user['role']??'', ['director','employee'],true)) {
        http_response_code(403);
        exit('Нет доступа к выгрузке.');
    }
    if ($report==='balances') {
        $rows=adc_export_balances($db);
        $filename='adclients-real-balances.csv';
    } elseif ($report==='client') {
        $clientId=filter_var($_GET['client_id']??null,FILTER_VALIDATE_INT);
        if (!$clientId || $clientId<1) {
            http_response_code(400);
            exit('Клиент не указан.');
        }
        $rows=adc_export_client_ledger($db,$clientId);
        $filename='adclients-client-'.(int)$clientId.'-ledger.csv';
    } else {
        http_response_code(404);
        exit('Отчёт не найден.');
    }
    $data=adc_export_encode($rows);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($data));
    header('X-Content-Type-Options: nosniff');
    echo $data;
    exit;
}
