<?php
declare(strict_types=1);

/**
 * Read-only comparison of a source installation and an independently
 * restored database + private PDF directory. Never alters either side.
 */
function adc_restore_tables(): array {
    return [
        'users','login_attempts','audit_events','personal_tasks',
        'personal_task_completions','clients','engagements','billing_periods',
        'document_bundles','charge_lines','payment_events',
        'client_entries','engagement_terms'
    ];
}

function adc_restore_counts(PDO $db): array {
    $names=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $counts=[];
    foreach(adc_restore_tables() as $table){
        if(!in_array($table,$names,true)){
            throw new RuntimeException('Required database schema is incomplete.');
        }
        // Table identifier is only from the fixed internal allowlist above.
        $counts[$table]=(string)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    return $counts;
}

/** Exact MySQL DECIMAL strings, not binary floats. Values are never printed. */
function adc_restore_finance(PDO $db): array {
    $queries=[
        'charges'=>'SELECT currency,category,is_void,COUNT(*) rows_count,
            COALESCE(SUM(amount),0) total FROM charge_lines
            GROUP BY currency,category,is_void ORDER BY currency,category,is_void',
        'payments'=>'SELECT currency,event_type,COUNT(*) rows_count,
            COALESCE(SUM(amount),0) total FROM payment_events
            GROUP BY currency,event_type ORDER BY currency,event_type',
        'entries'=>'SELECT currency,category,entry_kind,is_void,
            (sent_at IS NOT NULL) as is_sent,(received_at IS NOT NULL) as is_received,
            COUNT(*) rows_count,COUNT(amount) has_amount,
            COALESCE(SUM(amount),0) total FROM client_entries
            GROUP BY currency,category,entry_kind,is_void,is_sent,is_received
            ORDER BY currency,category,entry_kind,is_void,is_sent,is_received'
    ];
    $result=[];
    foreach($queries as $name=>$sql){
        $result[$name]=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}

/** Hash metadata without printing PDF storage keys or customer names. */
function adc_restore_pdf_index(PDO $db): array {
    $rows=$db->query('SELECT id,storage_key,content_sha256,is_void
        FROM document_bundles ORDER BY id');
    $hash=hash_init('sha256');
    $files=[];
    $total=0;
    foreach($rows as $row){
        $total++;
        hash_update($hash,json_encode([
            (string)$row['id'],(string)($row['storage_key']??''),
            (string)($row['content_sha256']??''),(string)$row['is_void']
        ],JSON_THROW_ON_ERROR)."\n");
        if((int)$row['is_void']!==0 || $row['storage_key']===null)continue;
        $key=(string)$row['storage_key'];
        $sha=(string)($row['content_sha256']??'');
        if(!preg_match('/\A[a-f0-9]{48}\.pdf\z/D',$key) ||
           !preg_match('/\A[a-f0-9]{64}\z/D',$sha)){
            throw new RuntimeException('Private PDF metadata is invalid.');
        }
        if(isset($files[$key]) && !hash_equals($files[$key],$sha)){
            throw new RuntimeException('Conflicting private PDF metadata.');
        }
        $files[$key]=$sha;
    }
    return ['fingerprint'=>hash_final($hash),'records'=>$total,'files'=>$files];
}

function adc_restore_verify_files(string $directory,array $files): int {
    if(is_link($directory) || !is_dir($directory) || !is_readable($directory)){
        throw new RuntimeException('Private PDF directory is unavailable.');
    }
    $count=0;
    foreach($files as $key=>$sha){
        $path=$directory.DIRECTORY_SEPARATOR.$key;
        if(is_link($path) || !is_file($path) || !is_readable($path)){
            throw new RuntimeException('One or more private PDFs are missing.');
        }
        $actual=@hash_file('sha256',$path);
        if(!is_string($actual) || !hash_equals($sha,$actual)){
            throw new RuntimeException('One or more private PDFs are corrupted.');
        }
        $count++;
    }
    return $count;
}

/** Does not write to the filesystem or database; a mismatch blocks release. */
function adc_restore_compare(PDO $source,PDO $restored,
                             string $sourcePdfDir,string $restoredPdfDir): array {
    $sourceCounts=adc_restore_counts($source);
    $restoredCounts=adc_restore_counts($restored);
    if($sourceCounts!==$restoredCounts)
        throw new RuntimeException('Table row counts differ between databases.');
    if(adc_restore_finance($source)!==adc_restore_finance($restored))
        throw new RuntimeException('Financial ledger aggregates differ.');

    $s=adc_restore_pdf_index($source);
    $r=adc_restore_pdf_index($restored);
    if($s['records']!==$r['records'] ||
        !hash_equals($s['fingerprint'],$r['fingerprint'])){
        throw new RuntimeException('Private PDF database metadata differs.');
    }
    $sourceNum=adc_restore_verify_files($sourcePdfDir,$s['files']);
    $restoredNum=adc_restore_verify_files($restoredPdfDir,$r['files']);
    if($sourceNum!==$restoredNum)
        throw new RuntimeException('Private PDF counts differ.');
    return ['tables'=>count($sourceCounts),
            'rows'=>array_sum(array_map('intval',$sourceCounts)),
            'pdfs'=>$restoredNum,'fingerprint_match'=>true,'finance_match'=>true];
}
