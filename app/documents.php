<?php
declare(strict_types=1);

/**
 * PDF files never live inside public_html. The application streams them only
 * after authentication. The role check for every mutation is server-side.
 */

function adc_doc_directory(): string {
    // app/ is directly inside public_html, so the domain root is two levels up.
    return dirname(__DIR__, 2) . '/adclients-documents';
}

function adc_doc_path(string $key): string {
    if (!preg_match('/^[a-f0-9]{48}\.pdf$/D', $key)) {
        throw new RuntimeException('Некорректный идентификатор документа.');
    }
    return adc_doc_directory() . '/' . $key;
}

/** Only return the path when the private file still matches its recorded bytes.
 * A missing, replaced or tampered PDF must never be downloaded or handed over.
 */
function adc_doc_verified_path(array $doc): ?string {
    $key = $doc['storage_key'] ?? null;
    $sha = $doc['content_sha256'] ?? null;
    if (!is_string($key) || !is_string($sha) ||
        !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
        return null;
    }
    try {
        $path = adc_doc_path($key);
    } catch (RuntimeException $error) {
        return null;
    }
    if (is_link(adc_doc_directory()) || is_link($path) ||
        !is_file($path) || !is_readable($path)) {
        return null;
    }
    $actual = @hash_file('sha256', $path);
    return is_string($actual) && hash_equals($sha, $actual) ? $path : null;
}

function adc_doc_ensure_directory(): void {
    $dir = adc_doc_directory();
    if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700))) {
        throw new RuntimeException('Недоступно закрытое хранилище документов.');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Хранилище документов не разрешает запись.');
    }
}

function adc_doc_pdf(string $file): void {
    if (!is_file($file) || filesize($file) < 8 || filesize($file) > 20 * 1024 * 1024) {
        throw new RuntimeException('Размер PDF должен быть от 8 байт до 20 МБ.');
    }
    $handle = fopen($file, 'rb');
    if (!$handle) {
        throw new RuntimeException('Не удалось проверить файл.');
    }
    try {
        $prefix = fread($handle, 5);
    } finally {
        fclose($handle);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    if ($prefix !== '%PDF-' || !in_array($mime, ['application/pdf', 'application/octet-stream'], true)) {
        throw new RuntimeException('Разрешены только PDF-документы.');
    }
}

function adc_doc_record(PDO $db, int $clientId, int $docId, bool $lock = false): array {
    $stmt = $db->prepare('SELECT * FROM document_bundles WHERE id=? AND client_id=? AND is_void=0' .
        ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$docId, $clientId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Документ не найден у этого клиента.');
    }
    return $row;
}

function adc_doc_requires_replacement(PDO $db,int $docId): bool {
    // An actual PDF replacement clears the most recent invalidation.
    // Metadata-only edits must not make an obsolete PDF appear valid.
    $stmt=$db->prepare('SELECT action FROM audit_events
        WHERE object_type="document_bundle" AND object_id=?
          AND action IN ("invalidate_document","replace_document")
        ORDER BY id DESC LIMIT 1');
    $stmt->execute([$docId]);
    return $stmt->fetchColumn()==='invalidate_document';
}

function adc_doc_labels(): array {
    $types = ['needs_contract','needs_spec','needs_invoice','needs_act'];
    $out = [];
    foreach ($types as $type) {
        $out[] = isset($_POST[$type]) ? 1 : 0;
    }
    if (array_sum($out) === 0) {
        throw new RuntimeException('Выберите хотя бы один тип документа.');
    }
    return $out;
}


function adc_doc_action(PDO $db,array $user,string $action): bool {
    if (!in_array($action,['upload_document','edit_document','replace_document','void_document','transfer_document','delete_demo','delete_empty_client'],true)) return false;
    $actor=(int)$user['id'];$clientId=adc_w_id('client_id');
    if ($action!=='transfer_document') adc_require_director($user);
    $db->beginTransaction();$client=adc_w_client($db,$clientId,true);
    if ($action==='delete_empty_client') {
        if ((int)$client['is_demo']!==0 ||
            adc_input('delete_confirmation')!=='УДАЛИТЬ' ||
            !adc_w_can_delete_empty_client($db,$clientId)) {
            throw new RuntimeException('Удаление запрещено. Проверьте подтверждение и отсутствие документов, оплат, периодов и истории сотрудничества.');
        }
        $db->prepare('DELETE FROM clients WHERE id=? AND is_demo=0')->execute([$clientId]);
        adc_audit($db,$actor,'delete_empty_client','client',$clientId);
        $db->commit();
        adc_flash('Случайно созданный клиент удалён.');
        adc_redirect('?view=clients');
    }
    if ($action==='delete_demo') {
        if ((int)$client['is_demo']!==1) throw new RuntimeException('Удалять можно только демо-клиента.');
        $q=$db->prepare('SELECT storage_key FROM document_bundles WHERE client_id=? AND storage_key IS NOT NULL');
        $q->execute([$clientId]);$files=$q->fetchAll(PDO::FETCH_COLUMN);
        $db->prepare('DELETE FROM clients WHERE id=?')->execute([$clientId]);
        adc_audit($db,$actor,'delete_demo_client','client',$clientId);
        $db->commit();
        foreach($files as $key) {try {$path=adc_doc_path($key);if(is_file($path))unlink($path);}catch(Throwable $e){error_log('Demo file cleanup failed');}}
        adc_flash('Демо-клиент удалён.');adc_redirect('?view=clients');
    }
    $docId=null;$existing=null;
    if ($action!=='upload_document') {$docId=adc_w_id('document_id');$existing=adc_doc_record($db,$clientId,$docId,true);}
    if ($action==='transfer_document') {
        if ($existing['transferred_at']!==null) {
            $db->commit();adc_flash('Документ уже отправлен.');
            adc_redirect(adc_w_client_location($db,$clientId));
        }
        if (adc_doc_requires_replacement($db,$docId)) throw new RuntimeException('Суммы изменены: директор должен заменить PDF перед передачей.');
        if (adc_doc_verified_path($existing)===null) throw new RuntimeException('Не удалось проверить PDF.');
        $db->prepare('UPDATE document_bundles SET transferred_at=UTC_TIMESTAMP() WHERE id=?')->execute([$docId]);
        $db->prepare('UPDATE client_entries SET sent_at=UTC_TIMESTAMP() WHERE document_id=? AND is_void=0')->execute([$docId]);
        adc_audit($db,$actor,'transfer_document','document_bundle',$docId);
        $db->commit();adc_flash('Документ отмечен как отправленный.');adc_redirect(adc_w_client_location($db,$clientId));
    }
    if ($action==='void_document') {
        $receipt=$db->prepare('SELECT id FROM client_entries
            WHERE document_id=? AND is_void=0 AND received_at IS NOT NULL FOR UPDATE');
        $receipt->execute([$docId]);
        if ($receipt->fetchColumn()!==false) {
            throw new RuntimeException('Сначала отмените получение оплаты, затем удалите документ.');
        }
        $db->prepare('UPDATE client_entries SET is_void=1 WHERE document_id=?')->execute([$docId]);
        $db->prepare('UPDATE document_bundles SET is_void=1,storage_key=NULL WHERE id=?')->execute([$docId]);
        adc_audit($db,$actor,'void_document','document_bundle',$docId);
        $db->commit();
        if ($existing['storage_key']) {try {$p=adc_doc_path($existing['storage_key']);if(is_file($p))unlink($p);}catch(Throwable $e){error_log('PDF cleanup failed');}}
        adc_flash('Документ и связанные с ним суммы удалены.');adc_redirect(adc_w_client_location($db,$clientId));
    }
    // The same upload form can create a card-payment request without a PDF.
    if ($action==='upload_document' && isset($_POST['is_card'])) {
        $db->rollBack();
        return adc_ledger_action($db,$user,'create_card_entry');
    }
    $newFile=null;$oldPath=null;
    $uploaded=$_FILES['document']??null;
    $hasFile=is_array($uploaded)&&($uploaded['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK;
    if (!$hasFile && $action==='upload_document') throw new RuntimeException('Выберите PDF.');
    if (!$hasFile && is_array($uploaded)&&!in_array(($uploaded['error']??-1),[UPLOAD_ERR_NO_FILE,UPLOAD_ERR_OK],true)) throw new RuntimeException('Ошибка загрузки PDF.');
    if ($hasFile) {
        if (!is_uploaded_file($uploaded['tmp_name'])) throw new RuntimeException('Некорректная загрузка.');
        adc_doc_pdf($uploaded['tmp_name']);
        $original=basename(str_replace('\\','/',(string)$uploaded['name']));
        if (!preg_match('/\.pdf$/iD',$original)||mb_strlen($original)>250) throw new RuntimeException('Недопустимое имя PDF.');
        adc_doc_ensure_directory();
        $key=bin2hex(random_bytes(24)).'.pdf';$newFile=adc_doc_path($key);
        $hash=hash_file('sha256',$uploaded['tmp_name']);
        if (!$hash||!move_uploaded_file($uploaded['tmp_name'],$newFile)) throw new RuntimeException('Не удалось сохранить PDF.');
        @chmod($newFile,0600);
    } else {
        $original=$existing['original_filename'];$key=$existing['storage_key'];$hash=$existing['content_sha256'];
    }
    try {
        $flags=adc_doc_labels();
        $amountFlag=isset($_POST['has_amount']);
        [$amount,$currency]=adc_ledger_money_from_post();
        $category=adc_w_pick('category',['advertising','development','other']);
        $note=adc_input('note');if(mb_strlen($note)>500)throw new RuntimeException('Комментарий слишком длинный.');
        $periodText=adc_input('period_id');$periodId=$periodText===''?null:adc_w_id('period_id');
        if($periodId!==null)adc_w_period($db,$clientId,$periodId);
        $due=adc_input('transfer_due_on');if($due!=='')adc_w_date($due);
        if ($existing) {
            if ($hasFile && $existing['storage_key']) $oldPath=adc_doc_path($existing['storage_key']);
            $db->prepare('UPDATE document_bundles SET period_id=?,storage_key=?,original_filename=?,content_sha256=?,
                needs_contract=?,needs_spec=?,needs_invoice=?,needs_act=?,transfer_due_on=?,
                transferred_at=IF(?=1,NULL,transferred_at) WHERE id=?')
                ->execute([$periodId,$key,$original,$hash,...$flags,$due===''?null:$due,$hasFile?1:0,$docId]);
            $check=$db->prepare('SELECT id,amount,currency,category,received_at,sent_at FROM client_entries WHERE document_id=? AND is_void=0 FOR UPDATE');
            $check->execute([$docId]);$entry=$check->fetch();
            if ($entry) {
                // A paid entry is tied to one received PDF version and to its
                // financial category. Keep the receipt immutable until the
                // employee explicitly reverses it (including PDF replacement).
                if ($entry['received_at']!==null &&
                    ($hasFile || $amount===null ||
                     adc_fin_minor((string)$amount)!==adc_fin_minor((string)$entry['amount']) ||
                     $currency!==$entry['currency'] ||
                     $category!==$entry['category'])) {
                    throw new RuntimeException('Сначала отмените получение оплаты, затем измените сумму, валюту, категорию или PDF.');
                }
                $db->prepare('UPDATE client_entries SET amount=?,currency=?,category=?,note=?,
                    sent_at=IF(?=1,NULL,sent_at),received_at=IF(? IS NULL,NULL,received_at) WHERE id=?')
                    ->execute([$amount,$currency,$category,$note,$hasFile?1:0,$amount,$entry['id']]);
            } else {
                $db->prepare('INSERT INTO client_entries(client_id,document_id,entry_kind,amount,currency,category,note,sent_at)
                    VALUES (?,?,"document",?,?,?,?,?)')->execute([$clientId,$docId,$amount,$currency,$category,$note,$hasFile?null:$existing['transferred_at']]);
            }
        } else {
            $q=$db->prepare('INSERT INTO document_bundles(client_id,period_id,storage_key,original_filename,content_sha256,
               needs_contract,needs_spec,needs_invoice,needs_act,transfer_due_on,uploaded_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$clientId,$periodId,$key,$original,$hash,...$flags,$due===''?null:$due,$actor]);
            $docId=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO client_entries(client_id,document_id,entry_kind,amount,currency,category,note)
                VALUES (?,?,"document",?,?,?,?)')->execute([$clientId,$docId,$amount,$currency,$category,$note]);
        }
        adc_audit($db,$actor,$existing?($hasFile?'replace_document':'edit_document'):'upload_document','document_bundle',$docId);
        $db->commit();
    } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();if($newFile&&is_file($newFile))unlink($newFile);throw $e;}
    if ($oldPath&&is_file($oldPath))unlink($oldPath);
    adc_flash('Документ сохранён.');adc_redirect(adc_w_client_location($db,$clientId));
}
function adc_doc_download(PDO $db, array $user): never {
    $docId=filter_var($_GET['download'] ?? null,FILTER_VALIDATE_INT);
    if (!$docId || $docId < 1) {
        http_response_code(400);
        exit('Некорректный документ.');
    }
    $stmt=$db->prepare('SELECT storage_key,original_filename,content_sha256 FROM document_bundles
        WHERE id=? AND is_void=0 AND storage_key IS NOT NULL');
    $stmt->execute([$docId]);
    $doc=$stmt->fetch();
    if (!$doc) {
        http_response_code(404);
        exit('Документ не найден.');
    }
    if ($user['role'] !== 'director' && adc_doc_requires_replacement($db,(int)$docId)) {
        http_response_code(409);
        exit('Документ устарел. Ожидается новая версия от директора.');
    }
    $path=adc_doc_verified_path($doc);
    if ($path === null) {
        http_response_code(409);
        exit('PDF отсутствует или повреждён. Обратитесь к директору.');
    }
    $ascii='document-'.$docId.'.pdf';
    $unicode=rawurlencode((string)$doc['original_filename']);
    header('Content-Type: application/pdf');
    header('Content-Length: '.(string)filesize($path));
    header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.$unicode);
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function adc_doc_panel(PDO $db, array $user, int $clientId): string {
    $stmt=$db->prepare('SELECT d.*,p.period_start,p.period_end FROM document_bundles d
        LEFT JOIN billing_periods p ON p.id=d.period_id
        WHERE d.client_id=? AND d.is_void=0 ORDER BY d.id DESC');
    $stmt->execute([$clientId]);
    $documents=$stmt->fetchAll();
    $html='<section class="card"><h3>Документы</h3>';
    foreach ($documents as $doc) {
        $id=(int)$doc['id'];
        $stale=adc_doc_requires_replacement($db,$id);
        $types=[];
        foreach (['needs_contract'=>'Договор','needs_spec'=>'Спецификация','needs_invoice'=>'Счёт','needs_act'=>'Акт'] as $col=>$label) {
            if ((int)$doc[$col]===1) $types[]=$label;
        }
        $html.='<div class="item"><div><strong>'.adc_h((string)$doc['original_filename']).'</strong><p class="muted">'.
            adc_h(implode(' · ',$types)).' · '.
            adc_h((string)($doc['period_start'] ?? 'Без периода')).' · '.
            ($stale ? 'Требуется замена PDF после изменения суммы' :
              ($doc['transferred_at'] ? 'Передано '.adc_h($doc['transferred_at']) : 'Ожидает передачи')) .
            ($doc['transfer_due_on'] ? ' · Передать до '.adc_h($doc['transfer_due_on']) : '') .
            '</p></div><div class="actions">';
        if (!$stale || $user['role']==='director') {
            $html.='<a class="btn secondary" href="/?download='.$id.'">Скачать</a>';
        }
        if (!$doc['transferred_at'] && !$stale) {
            $html.=adc_w_form_start('transfer_document').adc_w_hidden('client_id',$clientId).
                adc_w_hidden('document_id',$id).'<button type="submit">Передано</button></form>';
        }
        if ($user['role']==='director') {
            $html.='<details><summary>Заменить</summary>'.
                adc_doc_upload_form($db,$clientId,$id).'</details>'.
                adc_w_form_start('void_document').adc_w_hidden('client_id',$clientId).
                adc_w_hidden('document_id',$id).'<button class="secondary" type="submit">Удалить</button></form>';
        }
        $html.='</div></div>';
    }
    // The upload action is the empty state: no repetitive technical message.
    if ($user['role']==='director') {
        $html.='<details class="doc-upload"><summary>Загрузить документ</summary>'.
            adc_doc_upload_form($db,$clientId).'</details>';
    }
    return $html.'</section>';
}


function adc_doc_upload_form(PDO $db,int $clientId,?int $replaceId=null): string {
    $existing=$replaceId===null?null:adc_doc_record($db,$clientId,$replaceId);
    $current=null;
    if($replaceId!==null){
        $q=$db->prepare('SELECT * FROM client_entries WHERE document_id=? AND is_void=0');
        $q->execute([$replaceId]);$current=$q->fetch()?:null;
    }
    $periods=[''=>'Без периода'];
    $q=$db->prepare('SELECT p.id,p.period_start FROM billing_periods p
        JOIN engagements e ON e.id=p.engagement_id WHERE e.client_id=? ORDER BY p.period_start DESC LIMIT 100');
    $q->execute([$clientId]);foreach($q as $r)$periods[$r['id']]=$r['period_start'];
    $h='<form class="doc-upload-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.adc_csrf().'">'.
      adc_w_hidden('action',$replaceId?'edit_document':'upload_document').adc_w_hidden('client_id',$clientId);
    if($replaceId!==null)$h.=adc_w_hidden('document_id',$replaceId);
    if ($replaceId===null) $h.='<div class="card-payment-switch">'.adc_w_check('is_card','Оплата на карту (без PDF)',false).'</div>';
    $h.='<div class="document-only"><label class="field">PDF'.($replaceId!==null?' (можно не менять)':'').
      '<input type="file" name="document" accept=".pdf,application/pdf"></label></div>'.
      '<div class="document-only">'.adc_w_check('needs_contract','Договор',(bool)($existing['needs_contract']??false)).
      adc_w_check('needs_spec','Спецификация',(bool)($existing['needs_spec']??false)).
      adc_w_check('needs_invoice','Счёт',(bool)($existing['needs_invoice']??false)).
      adc_w_check('needs_act','Акт',(bool)($existing['needs_act']??false)).'</div>'.
      '<div class="ledger-money-toggle">'.adc_w_check('has_amount','Указать сумму к оплате',($current['amount']??null)!==null).
      '<div class="ledger-money-fields">'.str_replace(' required','',adc_ledger_form_money($current?:[])).'</div></div>'.
      adc_w_field('Комментарий','note',(string)($current['note']??''),'text','maxlength="500"').
      adc_w_field('Передать до (необязательно)','transfer_due_on',(string)($existing['transfer_due_on']??''),'date').
      '<details><summary>Привязать к старому периоду (необязательно)</summary>'.
      adc_w_select('Расчётный период','period_id',$periods,(string)($existing['period_id']??'')).'</details>'.
      '<button type="submit">'.($replaceId?'Сохранить изменения':'Загрузить документ').'</button></form>';
    return $h;
}
