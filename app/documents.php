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
    $stmt=$db->prepare('SELECT 1 FROM audit_events
        WHERE object_type="document_bundle" AND object_id=?
          AND action="invalidate_document" LIMIT 1');
    $stmt->execute([$docId]);
    return (bool)$stmt->fetchColumn();
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

function adc_doc_action(PDO $db, array $user, string $action): bool {
    if (!in_array($action,['upload_document','replace_document','void_document','transfer_document','delete_demo'],true)) {
        return false;
    }
    $actor=(int)$user['id'];
    $clientId=adc_w_id('client_id');
    if (in_array($action,['upload_document','replace_document','void_document','delete_demo'],true)) {
        adc_require_director($user);
    }
    $db->beginTransaction();
    $client = adc_w_client($db,$clientId,true);
    if ($action === 'delete_demo') {
        if ((int)$client['is_demo'] !== 1) {
            throw new RuntimeException('Удалять можно только демонстрационных клиентов.');
        }
        $stmt=$db->prepare('SELECT storage_key FROM document_bundles WHERE client_id=? AND storage_key IS NOT NULL');
        $stmt->execute([$clientId]);
        $keys=$stmt->fetchAll(PDO::FETCH_COLUMN);
        $db->prepare('DELETE FROM clients WHERE id=? AND is_demo=1')->execute([$clientId]);
        adc_audit($db,$actor,'delete_demo_client','client',$clientId);
        $db->commit();
        foreach ($keys as $key) {
            try {
                $path=adc_doc_path($key);
                if (is_file($path) && !unlink($path)) {
                    error_log('Ad Client Manager: demo file cleanup failed');
                }
            } catch (Throwable $error) {
                error_log('Ad Client Manager: invalid storage key during demo cleanup');
            }
        }
        adc_flash('Демо-клиент вместе с операциями и PDF удалён.');
        adc_redirect('?view=clients');
    }

    $docId = null;
    $oldFile = null;
    if (in_array($action,['replace_document','void_document','transfer_document'],true)) {
        $docId=adc_w_id('document_id');
        $existing=adc_doc_record($db,$clientId,$docId,true);
        $oldFile=$existing['storage_key'];
    }
    if ($action === 'transfer_document') {
        if (adc_doc_requires_replacement($db,(int)$docId)) {
            throw new RuntimeException('Суммы изменены: директор должен заменить PDF перед передачей.');
        }
        if ($oldFile === null || adc_doc_verified_path($existing) === null) {
            throw new RuntimeException('PDF отсутствует или повреждён. Сообщите директору, передача невозможна.');
        }
        if ($existing['transferred_at'] !== null) {
            $db->commit();
            adc_flash('Документ уже отмечен как переданный. Повторная запись не создана.');
            adc_redirect(adc_w_client_location($db,$clientId));
        }
        $db->prepare('UPDATE document_bundles SET transferred_at=UTC_TIMESTAMP() WHERE id=? AND client_id=? AND is_void=0')
            ->execute([$docId,$clientId]);
        adc_audit($db,$actor,'transfer_document','document_bundle',$docId);
        $db->commit();
        adc_flash('Документ отмечен как переданный.');
        adc_redirect(adc_w_client_location($db,$clientId));
    }
    if ($action === 'void_document') {
        $db->prepare('UPDATE document_bundles SET is_void=1,storage_key=NULL WHERE id=? AND client_id=?')
            ->execute([$docId,$clientId]);
        adc_audit($db,$actor,'void_document','document_bundle',$docId);
        $db->commit();
        if ($oldFile) {
            $path=adc_doc_path($oldFile);
            if (is_file($path) && !unlink($path)) error_log('Ad Client Manager: document deletion failed');
        }
        adc_flash('Документ удалён.');
        adc_redirect(adc_w_client_location($db,$clientId));
    }

    $file=$_FILES['document'] ?? null;
    if (!is_array($file) || ($file['error'] ?? -1) !== UPLOAD_ERR_OK ||
        !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Выберите PDF для загрузки.');
    }
    adc_doc_pdf($file['tmp_name']);
    $original=basename(str_replace('\\','/',(string)($file['name'] ?? 'document.pdf')));
    if ($original === '' || mb_strlen($original) > 250 || !preg_match('/\.pdf$/iD',$original)) {
        throw new RuntimeException('Имя файла должно заканчиваться на .pdf (до 250 символов).');
    }
    $periodText=adc_input('period_id');
    $periodId=$periodText === '' ? null : adc_w_id('period_id');
    if ($periodId !== null) {
        adc_w_period($db,$clientId,$periodId);
    }
    $due=adc_input('transfer_due_on');
    if ($due !== '') adc_w_date($due);
    $flags=adc_doc_labels();
    if ($periodId === null && ($flags[1] === 1 || $flags[2] === 1 || $flags[3] === 1)) {
        throw new RuntimeException('Для счёта, акта или спецификации сначала выберите расчётный период.');
    }
    adc_doc_ensure_directory();
    $key=bin2hex(random_bytes(24)).'.pdf';
    $path=adc_doc_path($key);
    $sha=hash_file('sha256',$file['tmp_name']);
    if ($sha === false || !move_uploaded_file($file['tmp_name'],$path)) {
        throw new RuntimeException('Не удалось сохранить PDF.');
    }
    @chmod($path,0600);
    try {
        if ($action === 'replace_document') {
            $db->prepare('UPDATE document_bundles SET is_void=1,storage_key=NULL WHERE id=? AND client_id=?')
                ->execute([$docId,$clientId]);
        }
        $stmt=$db->prepare('INSERT INTO document_bundles(client_id,period_id,storage_key,original_filename,
            content_sha256,needs_contract,needs_spec,needs_invoice,needs_act,transfer_due_on,uploaded_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$clientId,$periodId,$key,$original,$sha,...$flags,$due===''?null:$due,$actor]);
        $newId=(int)$db->lastInsertId();
        adc_audit($db,$actor,$action,'document_bundle',$newId,['replaced'=>$docId,'sha256'=>$sha]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        if (is_file($path)) unlink($path);
        throw $error;
    }
    if ($action === 'replace_document' && $oldFile) {
        $prev=adc_doc_path($oldFile);
        if (is_file($prev) && !unlink($prev)) error_log('Ad Client Manager: replaced PDF cleanup failed');
    }
    adc_flash('PDF сохранён. Передача отмечается отдельно.');
    adc_redirect(adc_w_client_location($db,$clientId));
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

function adc_doc_upload_form(PDO $db, int $clientId, ?int $replaceId=null): string {
    // A replacement should carry existing metadata by default.
    $existing=$replaceId === null ? null : adc_doc_record($db,$clientId,$replaceId);
    $choices=[''=>'Без периода'];
    $stmt=$db->prepare('SELECT p.id,p.period_start,p.period_end FROM billing_periods p
        JOIN engagements e ON e.id=p.engagement_id WHERE e.client_id=?
        ORDER BY p.period_start DESC LIMIT 100');
    $stmt->execute([$clientId]);
    foreach ($stmt as $p) {
        $choices[$p['id']]=$p['period_start'].' — '.$p['period_end'];
    }
    // Include an old selected period even if beyond the latest 100 options.
    if ($existing && $existing['period_id'] !== null && !isset($choices[(string)$existing['period_id']])) {
        $p=adc_w_period($db,$clientId,(int)$existing['period_id']);
        $choices[(string)$p['id']]=$p['period_start'].' — '.$p['period_end'];
    }
    $html='<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.adc_csrf().'">'.
        adc_w_hidden('action',$replaceId?'replace_document':'upload_document').
        adc_w_hidden('client_id',$clientId);
    if ($replaceId) $html.=adc_w_hidden('document_id',$replaceId);
    $html.='<p class="muted">Выберите PDF и отметьте, какие документы он содержит.</p>'.
        '<label class="field">PDF (до 20 МБ)<input type="file" name="document" accept=".pdf,application/pdf" required></label>'.
        adc_w_select('Расчётный период','period_id',$choices,(string)($existing['period_id']??'')).
        adc_w_check('needs_contract','Договор',(bool)($existing['needs_contract']??false)).
        adc_w_check('needs_spec','Спецификация',(bool)($existing['needs_spec']??false)).
        adc_w_check('needs_invoice','Счёт',(bool)($existing['needs_invoice']??false)).
        adc_w_check('needs_act','Акт',(bool)($existing['needs_act']??false)).
        adc_w_field('Передать до','transfer_due_on',(string)($existing['transfer_due_on']??''),'date').
        '<button type="submit">'.($replaceId?'Заменить PDF':'Загрузить PDF').'</button></form>';
    return $html;
}
