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
        'client_entries','engagement_terms','team_chat_messages','team_chat_reads','team_chat_mentions','team_chat_thread_reads'
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

/**
 * SHA-256 of every column of every row, in PRIMARY KEY order.
 *
 * PDO unbuffered reads ensure that even a large restored SQL database does
 * not require loading complete tables into memory. Values are length-framed
 * as raw DB bytes with a distinct NULL tag, so NULL, empty, 0, binary and
 * different UTF-8 strings cannot be silently confused.
 *
 * Only the hardcoded table list is accepted; column names are validated
 * against the MySQL identifier grammar before being used in ORDER BY.
 */
function adc_restore_table_fingerprint(PDO $db,string $table): array {
    if(!in_array($table,adc_restore_tables(),true)){
        throw new RuntimeException('Unknown table in restore verifier.');
    }
    $columnsQuery=$db->query('SHOW COLUMNS FROM '.$table);
    $columns=$columnsQuery->fetchAll(PDO::FETCH_ASSOC);
    $columnsQuery->closeCursor();
    if(!$columns)throw new RuntimeException('Required database schema is incomplete.');
    $names=[];
    $meta=[];
    foreach($columns as $column){
        $name=(string)$column['Field'];
        if(!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D',$name)){
            throw new RuntimeException('Database column name is unsupported.');
        }
        $names[]=$name;
        $meta[]=[$name,(string)$column['Type'],(string)$column['Null'],
            $column['Default'],(string)$column['Extra']];
    }
    $indexQuery=$db->query("SHOW INDEX FROM ".$table." WHERE Key_name='PRIMARY'");
    $indices=$indexQuery->fetchAll(PDO::FETCH_ASSOC);
    $indexQuery->closeCursor();
    if(!$indices)throw new RuntimeException('Primary key is required for safe streaming.');
    usort($indices,static fn($a,$b)=>
        (int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
    $keys=[];
    foreach($indices as $index){
        $key=(string)$index['Column_name'];
        if(!in_array($key,$names,true)){
            throw new RuntimeException('Primary key definition does not match the table.');
        }
        $keys[]=$key;
    }
    $schema=hash('sha256',json_encode([$meta,$keys],JSON_THROW_ON_ERROR));
    $hasher=hash_init('sha256');
    hash_update($hasher,"adc-db-rows-v2\0".$table."\0");
    $count=0;
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    $query=null;
    try{
        $query=$db->query('SELECT * FROM '.$table.' ORDER BY '.implode(',',$keys));
        while(($row=$query->fetch(PDO::FETCH_ASSOC))!==false){
            hash_update($hasher,"R");
            foreach($names as $name){
                $value=$row[$name];
                if($value===null){
                    hash_update($hasher,"N");
                    continue;
                }
                $bytes=(string)$value;
                hash_update($hasher,"V");
                hash_update($hasher,pack('N',strlen($bytes)));
                hash_update($hasher,$bytes);
            }
            $count++;
        }
    } finally {
        if($query instanceof PDOStatement)$query->closeCursor();
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);
    }
    return ['schema'=>$schema,'records'=>$count,'sha256'=>hash_final($hasher)];
}

function adc_restore_row_fingerprints(PDO $db):array{
    $result=[];
    foreach(adc_restore_tables() as $table){
        $result[$table]=adc_restore_table_fingerprint($db,$table);
    }
    return $result;
}

/** Open stable read-only InnoDB snapshots. No SQL writes or schema changes. */
function adc_restore_snapshot(PDO $db):void{
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
}

/**
 * Inventory every application-managed private PDF, including historical and
 * void/replaced files. A restore that loses an old PDF must NOT be accepted
 * merely because no active document currently references it.
 *
 * Only strict 48-hex .pdf names belong to the application namespace.
 * Non-application files are intentionally ignored, never deleted/altered.
 */
function adc_restore_pdf_inventory(string $directory): array {
    if(is_link($directory) || !is_dir($directory) || !is_readable($directory)){
        throw new RuntimeException('Private PDF directory is unavailable.');
    }
    $inventory=[];
    foreach(new DirectoryIterator($directory) as $entry){
        $key=$entry->getFilename();
        if(!preg_match('/\A[a-f0-9]{48}\.pdf\z/D',$key))continue;
        $path=$entry->getPathname();
        if($entry->isLink() || !$entry->isFile() || !$entry->isReadable()){
            throw new RuntimeException('Private PDF inventory contains an inaccessible file.');
        }
        $sha=@hash_file('sha256',$path);
        if(!is_string($sha)){
            throw new RuntimeException('Private PDF inventory could not be hashed.');
        }
        $inventory[$key]=$sha;
    }
    ksort($inventory,SORT_STRING);
    return $inventory;
}

/** Does not write to the filesystem or database; a mismatch blocks release. */
function adc_restore_compare(PDO $source,PDO $restored,
                             string $sourcePdfDir,string $restoredPdfDir): array {
    // An InnoDB point-in-time view prevents a write in another request from
    // making a single side internally inconsistent during a long comparison.
    // Copies still MUST refer to the same logical backup moment.
    $sourceStarted=false;
    $restoredStarted=false;
    try {
        adc_restore_snapshot($source);
        $sourceStarted=true;
        adc_restore_snapshot($restored);
        $restoredStarted=true;
        $sourceCounts=adc_restore_counts($source);
        $restoredCounts=adc_restore_counts($restored);
        if($sourceCounts!==$restoredCounts)
            throw new RuntimeException('Table row counts differ between databases.');
        if(adc_restore_finance($source)!==adc_restore_finance($restored))
            throw new RuntimeException('Financial ledger aggregates differ.');

        // Unlike counts/totals, full row digests also detect corrupted names,
        // employee tasks, authentication fields, comments and audit events.
        if(adc_restore_row_fingerprints($source)!==
           adc_restore_row_fingerprints($restored)){
            throw new RuntimeException('Full database row fingerprints differ.');
        }
        $s=adc_restore_pdf_index($source);
        $r=adc_restore_pdf_index($restored);
        if($s['records']!==$r['records'] ||
            !hash_equals($s['fingerprint'],$r['fingerprint'])){
            throw new RuntimeException('Private PDF database metadata differs.');
        }
        $source->exec('COMMIT'); $sourceStarted=false;
        $restored->exec('COMMIT'); $restoredStarted=false;
    } finally {
        if($sourceStarted)try{$source->exec('ROLLBACK');}catch(Throwable $ignored){}
        if($restoredStarted)try{$restored->exec('ROLLBACK');}catch(Throwable $ignored){}
    }
    // All application PDFs are compared, not just those currently linked as
    // active. Existing historical and replaced PDF files remain recoverable.
    $si=adc_restore_pdf_inventory($sourcePdfDir);
    $ri=adc_restore_pdf_inventory($restoredPdfDir);
    if($si!==$ri){
        throw new RuntimeException('Private PDF directory inventory differs.');
    }
    foreach($s['files'] as $key=>$sha){
        if(!isset($si[$key]) || !hash_equals($sha,$si[$key])){
            throw new RuntimeException('One or more active private PDFs are missing or corrupted.');
        }
    }
    return ['tables'=>count($sourceCounts),
            'rows'=>array_sum(array_map('intval',$sourceCounts)),
            'pdfs'=>count($s['files']),'stored_pdfs'=>count($si),
            'fingerprint_match'=>true,'finance_match'=>true,'full_rows_match'=>true];
}
