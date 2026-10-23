<?php
declare(strict_types=1);
/** Authenticated internal chat: general room + one room per client. */
/** Attachments are always stored outside public_html, never in the Git repository. */
function adc_chat_attachment_dir():string {
    return dirname(__DIR__,2).'/adclients-chat-files';
}
function adc_chat_attachment_types():array {
    return [
        'pdf'=>['application/pdf','application/octet-stream'],
        'png'=>['image/png','application/octet-stream'],
        'jpg'=>['image/jpeg','application/octet-stream'],
        'webp'=>['image/webp','application/octet-stream'],
    ];
}
/** Pure validator can also test malformed/suspicious files without uploading. */
function adc_chat_validate_file(string $path,string $filename):array {
    if(!is_file($path)||!is_readable($path))throw new RuntimeException('Не удалось прочитать файл.');
    $size=(int)filesize($path);
    if($size<8||$size>10*1024*1024)
        throw new RuntimeException('Размер вложения: от 8 байт до 10 МБ.');
    $name=basename(str_replace('\\','/',$filename));
    if($name===''||mb_strlen($name)>120||preg_match('/[\x00-\x1F\x7F]/',$name))
        throw new RuntimeException('Проверьте название файла (не более 120 символов).');
    $ext=strtolower((string)pathinfo($name,PATHINFO_EXTENSION));
    if($ext==='jpeg')$ext='jpg';
    $types=adc_chat_attachment_types();
    if(!isset($types[$ext]))throw new RuntimeException('Разрешены PDF, PNG, JPG и WebP.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if(!is_string($mime)||!in_array($mime,$types[$ext],true))
        throw new RuntimeException('Содержимое файла не соответствует его типу.');
    $fh=fopen($path,'rb');
    if(!$fh)throw new RuntimeException('Не удалось проверить вложение.');
    try{$prefix=fread($fh,16);}finally{fclose($fh);}
    $valid=match($ext){
        'pdf'=>str_starts_with($prefix,'%PDF-'),
        'png'=>str_starts_with($prefix,"\x89PNG\r\n\x1a\n"),
        'jpg'=>str_starts_with($prefix,"\xFF\xD8\xFF"),
        'webp'=>strlen($prefix)>=12 && substr($prefix,0,4)==='RIFF' &&
            substr($prefix,8,4)==='WEBP',
        default=>false,
    };
    if(!$valid)throw new RuntimeException('Недопустимое содержимое вложения.');
    return [$name,$ext,$mime,$size];
}
function adc_chat_receive_file():?array {
    $file=$_FILES['chat_attachment']??null;
    if($file===null)return null;
    if(!is_array($file)||!is_int($file['error']??null))
        throw new RuntimeException('Некорректное вложение.');
    $error=$file['error'];
    if($error===UPLOAD_ERR_NO_FILE)return null;
    if($error!==UPLOAD_ERR_OK)
        throw new RuntimeException('Не удалось загрузить вложение. Максимум 10 МБ.');
    if(!is_string($file['tmp_name']??null)||!is_string($file['name']??null)||
       !is_uploaded_file($file['tmp_name']))
        throw new RuntimeException('Недопустимый файл.');
    [$name,$ext,$mime,$size]=adc_chat_validate_file($file['tmp_name'],$file['name']);
    $dir=adc_chat_attachment_dir();
    if(is_link($dir)||(!is_dir($dir) && !mkdir($dir,0700))||
       !is_dir($dir)||!is_writable($dir))
        throw new RuntimeException('Закрытое хранилище вложений недоступно.');
    $key=bin2hex(random_bytes(24)).'.'.$ext;
    $path=$dir.'/'.$key;
    if(!move_uploaded_file($file['tmp_name'],$path))
        throw new RuntimeException('Не удалось сохранить вложение.');
    @chmod($path,0600);
    $hash=@hash_file('sha256',$path);
    if(!is_string($hash)){
        @unlink($path);
        throw new RuntimeException('Не удалось проверить сохранённый файл.');
    }
    return ['path'=>$path,'key'=>$key,'name'=>$name,
        'mime'=>$mime,'size'=>$size,'hash'=>$hash];
}
function adc_chat_download(PDO $db):never {
    $id=filter_var($_GET['chat_attachment']??null,FILTER_VALIDATE_INT);
    if(!$id||$id<1){http_response_code(400);exit('Неверное вложение.');}
    $s=$db->prepare('SELECT * FROM team_chat_attachments WHERE id=?');
    $s->execute([(int)$id]);$a=$s->fetch();
    if(!$a){http_response_code(404);exit('Вложение не найдено.');}
    $key=(string)$a['storage_key'];
    $name=(string)$a['original_filename'];
    $mime=(string)$a['mime_type'];
    $allowed=['application/pdf'=>'pdf','image/png'=>'png',
        'image/jpeg'=>'jpg','image/webp'=>'webp'];
    if(!isset($allowed[$mime])||
       !preg_match('/\A[a-f0-9]{48}\.'.preg_quote($allowed[$mime],'/').'\z/D',$key)||
       !preg_match('/\A[a-f0-9]{64}\z/D',(string)$a['content_sha256'])){
        http_response_code(404);exit('Вложение недоступно.');
    }
    $dir=adc_chat_attachment_dir();$path=$dir.'/'.$key;
    if(is_link($dir)||is_link($path)||!is_file($path)||!is_readable($path)||
       (int)filesize($path)!==(int)$a['byte_size']||
       !hash_equals((string)$a['content_sha256'],(string)@hash_file('sha256',$path))){
        http_response_code(409);exit('Вложение отсутствует или повреждено.');
    }
    $disposition=$mime==='application/pdf'?'attachment':'inline';
    header('Content-Type: '.$mime);
    header('Content-Length: '.(string)$a['byte_size']);
    header('Content-Disposition: '.$disposition.'; filename="chat-attachment-'.$id.'.'.
        $allowed[$mime].'"; filename*=UTF-8\'\''.rawurlencode($name));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}


function adc_chat_thread(PDO $db,mixed $raw):int {
    if($raw===null||$raw===''||$raw==='0')return 0;
    if(!is_string($raw)&&!is_int($raw))return 0;
    $value=(string)$raw;
    if(!ctype_digit($value)||(int)$value<1)return 0;
    $id=(int)$value;
    $s=$db->prepare('SELECT id FROM clients WHERE id=?');
    $s->execute([$id]);
    return $s->fetchColumn()?$id:0;
}
function adc_chat_alias(string $name,int $id,string $prefix):string {
    $name=mb_strtolower(trim($name));
    $name=preg_replace('/[^\p{L}\p{N}]+/u','_',$name)??'';
    $name=trim(mb_substr($name,0,35),'_');
    return $prefix.($name!==''?$name:($prefix==='@'?'сотрудник':'клиент')).'_'.$id;
}
function adc_chat_status(PDO $db,int $uid):array {
    // A simple LEFT JOIN avoids a correlated EXISTS in the message aggregate
    // (older hosted MySQL variants can reject complex statement preparations).
    $sql='SELECT COUNT(*) unread,
          COALESCE(SUM(CASE WHEN m.mentioned_user_id=? OR um.user_id IS NOT NULL
             THEN 1 ELSE 0 END),0) mentions
       FROM team_chat_messages m
       LEFT JOIN team_chat_mentions um ON um.message_id=m.id AND um.user_id=?
       LEFT JOIN team_chat_thread_reads r ON r.user_id=? AND r.thread_key=COALESCE(m.thread_client_id,0)
       WHERE m.author_user_id<>? AND m.id>COALESCE(r.last_read_id,0)';
    $s=$db->prepare($sql);$s->execute([$uid,$uid,$uid,$uid]);
    $values=$s->fetch();
    $s=$db->prepare('SELECT COALESCE(MAX(id),0) FROM team_chat_messages WHERE author_user_id<>?');
    $s->execute([$uid]);
    $newest=(int)$s->fetchColumn();
    $s=$db->prepare('SELECT COALESCE(m.thread_client_id,0) room,COUNT(*) unread
        FROM team_chat_messages m LEFT JOIN team_chat_thread_reads r
        ON r.user_id=? AND r.thread_key=COALESCE(m.thread_client_id,0)
        WHERE m.author_user_id<>? AND m.id>COALESCE(r.last_read_id,0)
        GROUP BY COALESCE(m.thread_client_id,0)');
    $s->execute([$uid,$uid]);$rooms=[];
    foreach($s->fetchAll() as $room)$rooms[(string)$room['room']]=(int)$room['unread'];
    return ['unread'=>(int)$values['unread'],'mentions'=>(int)$values['mentions'],
       'newest_other_id'=>$newest,'room_unread'=>$rooms];
}
function adc_chat_thread_unread(PDO $db,int $uid,int $thread):int {
    $s=$db->prepare('SELECT COUNT(*) FROM team_chat_messages m
        LEFT JOIN team_chat_thread_reads r ON r.user_id=? AND r.thread_key=?
        WHERE COALESCE(m.thread_client_id,0)=? AND m.author_user_id<>? AND m.id>COALESCE(r.last_read_id,0)');
    $s->execute([$uid,$thread,$thread,$uid]);
    return (int)$s->fetchColumn();
}
/** The focus view lets a pinned or replied-to message be revisited after pagination. */
function adc_chat_rows(PDO $db,int $after=0,int $thread=0,int $uid=0,int $focus=0):array {
    $sql='SELECT m.*,u.display_name author,mu.display_name mention_name,c.name client_name,
        att.id attachment_id,att.original_filename attachment_name,
        att.mime_type attachment_mime,att.byte_size attachment_size,
        reply.body reply_body,ru.display_name reply_author,
        CASE WHEN tags.user_id IS NULL THEN 0 ELSE 1 END is_mentioned
        FROM team_chat_messages m
        JOIN users u ON u.id=m.author_user_id
        LEFT JOIN users mu ON mu.id=m.mentioned_user_id
        LEFT JOIN clients c ON c.id=m.client_id
        LEFT JOIN team_chat_mentions tags ON tags.message_id=m.id AND tags.user_id=?
        LEFT JOIN team_chat_attachments att ON att.message_id=m.id
        LEFT JOIN team_chat_messages reply ON reply.id=m.reply_to_id
          AND COALESCE(reply.thread_client_id,0)=COALESCE(m.thread_client_id,0)
        LEFT JOIN users ru ON ru.id=reply.author_user_id
        WHERE COALESCE(m.thread_client_id,0)=?';
    if($after>0){
        $s=$db->prepare($sql.' AND m.id>? ORDER BY m.id ASC LIMIT 60');
        $s->execute([$uid,$thread,$after]);return $s->fetchAll();
    }
    $s=$db->prepare($sql.($focus>0?' AND m.id<=?':'').
        ' ORDER BY m.id DESC LIMIT 60');
    $params=[$uid,$thread];
    if($focus>0)$params[]=$focus;
    $s->execute($params);
    return array_reverse($s->fetchAll());
}
function adc_chat_token_parts(string $body):array {
    $parts=preg_split('~(https?://[^\s<>"\x00-\x1F]+|(?<![\p{L}\p{N}_])[@#][\p{L}\p{N}_]{1,70})~iu',
        $body,-1,PREG_SPLIT_DELIM_CAPTURE);
    return $parts===false?[$body]:$parts;
}
function adc_chat_safe_text(string $body,?int $clientId=null):string {
    $html='';
    foreach(adc_chat_token_parts($body) as $p){
        if(preg_match('~^https?://~i',$p)&&filter_var($p,FILTER_VALIDATE_URL)&&mb_strlen($p)<=1500){
            $html.='<a href="'.adc_h($p).'" target="_blank" rel="noopener noreferrer nofollow">'.
                adc_h($p).'</a>';
        } elseif(preg_match('/^@[\p{L}\p{N}_]+$/u',$p)){
            $html.='<strong class="chat-tag-person">'.adc_h($p).'</strong>';
        } elseif($clientId && preg_match('/^#[\p{L}\p{N}_]*_'.(int)$clientId.'$/u',$p)){
            $html.='<a class="chat-tag-client" href="/?view=chat&amp;thread='.$clientId.'">'.
                adc_h($p).'</a>';
        } else $html.=adc_h($p);
    }
    return nl2br($html);
}
function adc_chat_item(array $m,int $uid):string {
    $mine=(int)$m['author_user_id']===$uid;
    $tagged=(int)($m['is_mentioned']??0)===1||(int)($m['mentioned_user_id']??0)===$uid;
    $class=$mine?' mine':($tagged?' ping':'');
    // The database stores UTC; show team timestamps in Minsk local time.
    $created=new DateTimeImmutable((string)$m['created_at'],new DateTimeZone('UTC'));
    $time=$created->setTimezone(new DateTimeZone('Europe/Minsk'))->format('d.m.Y H:i');
    $out='<article id="chat-msg-'.(int)$m['id'].'" class="chat-msg'.$class.
        '" data-mid="'.(int)$m['id'].'">'.
        '<div class="chat-meta"><strong>'.adc_h((string)$m['author']).'</strong>'.
        '<time>'.adc_h($time).'</time></div>';
    $thread=(int)($m['thread_client_id']??0);
    if(($m['reply_to_id']??null)!==null){
        $replyId=(int)$m['reply_to_id'];
        $preview=mb_substr(trim((string)($m['reply_body']??'Сообщение недоступно')),0,130);
        if($preview==='')$preview='Вложение';
        $out.='<a class="chat-replied" href="/?view=chat&amp;thread='.$thread.
            '&amp;focus='.$replyId.'#chat-msg-'.$replyId.'">↪ '.
            adc_h((string)($m['reply_author']??'Сотрудник')).': '.adc_h($preview).'</a>';
    }
    $out.='<div>'.adc_chat_safe_text((string)$m['body'],
        $m['client_id']===null?null:(int)$m['client_id']).'</div>';
    if($m['client_id']!==null)
        $out.='<a class="chat-link" href="/?view=clients&amp;client='.(int)$m['client_id'].'">↗ Карточка: '.
            adc_h((string)($m['client_name']??'Клиент')).'</a>';
    if(isset($m['attachment_id']) && $m['attachment_id']!==null){
        $id=(int)$m['attachment_id'];$url='/?chat_attachment='.$id;
        $name=adc_h((string)$m['attachment_name']);
        $kind=(string)$m['attachment_mime'];
        if(str_starts_with($kind,'image/')){
            $out.='<a class="chat-file chat-image" href="'.$url.'" target="_blank" rel="noopener">'.
                '<img src="'.$url.'" alt="'.$name.'" loading="lazy">'.
                '<span>📎 '.$name.'</span></a>';
        }else{
            $out.='<a class="chat-file" href="'.$url.'">📄 '.$name.' · Скачать PDF</a>';
        }
    }
    $preview=mb_substr(trim((string)$m['body']),0,120);
    if($preview==='')$preview='Вложение';
    $out.='<div class="chat-msg-tools"><button type="button" class="chat-reply-btn secondary"'.
        ' data-chat-reply="'.(int)$m['id'].'" data-reply-preview="'.
        adc_h((string)$m['author'].': '.$preview).'">↩ Ответить</button>'.
        '<form method="post"><input type="hidden" name="csrf" value="'.adc_csrf().'">'.
        '<input type="hidden" name="action" value="chat_toggle_pin">'.
        '<input type="hidden" name="thread" value="'.$thread.'">'.
        '<input type="hidden" name="message_id" value="'.(int)$m['id'].'">'.
        '<button class="secondary chat-pin-btn" type="submit">📌 Закрепить / снять</button>'.
        '</form></div>';
    return $out.'</article>';
}
function adc_chat_html(PDO $db,array $user):string {
    $uid=(int)$user['id'];
    $GLOBALS['adc_chat_read_stage']='thread';
    $thread=adc_chat_thread($db,$_GET['thread']??null);
    $GLOBALS['adc_chat_read_stage']='messages';
    $focus=filter_var($_GET['focus']??null,FILTER_VALIDATE_INT);
    $focus=($focus!==false && $focus!==null && $focus>0)?(int)$focus:0;
    if($focus){
        $probe=$db->prepare('SELECT id FROM team_chat_messages
            WHERE id=? AND COALESCE(thread_client_id,0)=?');
        $probe->execute([$focus,$thread]);
        if(!$probe->fetchColumn())$focus=0;
    }
    $rows=adc_chat_rows($db,0,$thread,$uid,$focus);
    $last=$rows?(int)$rows[count($rows)-1]['id']:0;
    $pin=adc_chat_pinned($db,$thread);
    $q=is_string($_GET['chat_q']??null)?trim((string)$_GET['chat_q']):'';
    $q=mb_substr($q,0,65);
    $GLOBALS['adc_chat_read_stage']='clientlist';
    if ($q==='') {
        $clients=$db->query('SELECT id,name FROM clients ORDER BY id DESC LIMIT 40')->fetchAll();
    } else {
        $s=$db->prepare('SELECT id,name FROM clients WHERE name LIKE ? ORDER BY id DESC LIMIT 40');
        $s->execute(['%'.$q.'%']);
        $clients=$s->fetchAll();
    }
    $GLOBALS['adc_chat_read_stage']='unread';
    // Notifications are an accessory, not a reason to lock out the chat.
    // If a hosted MySQL variant fails the aggregate, allow people to read
    // and send messages while the exact DB error is diagnosed separately.
    $statusWarning=false;
    try {
        $unread=adc_chat_status($db,$uid)['room_unread'];
    } catch (PDOException $statusError) {
        error_log('ADC-CHAT-UNREAD-'.(int)($statusError->errorInfo[1]??0).
            ' '.$statusError->getCode().' '.$statusError->getMessage());
        $unread=[];
        $statusWarning=true;
    }
    $GLOBALS['adc_chat_read_stage']='selected';
    if($thread){
        $s=$db->prepare('SELECT name FROM clients WHERE id=?');
        $s->execute([$thread]);$selectedName=(string)$s->fetchColumn();
    } else $selectedName='Общий чат';
    $GLOBALS['adc_chat_read_stage']='render';
    $out='<section class="card"><div class="chat-top"><h1>Чат команды</h1>'.
       '<button class="secondary" type="button" data-chat-sound>Выключить звук</button></div>'.
       '<p class="muted">Общий чат и переписки по клиентам. В сообщении набери @ для коллеги или # для клиента.</p>'.
       '<div><span data-chat-state role="status" class="muted">'.
       ($statusWarning?'Уведомления временно недоступны, переписка работает.':'').
       '</span></div>'.
       '<div class="chat-layout"><aside class="chat-sidebar" aria-label="Переписки">'.
       '<a class="chat-room'.($thread===0?' selected':'').'" data-room="0" href="/?view=chat">💬 Общий чат'.
       (($count=(int)($unread['0']??0))?' <span class="chat-room-count">'.$count.'</span>':'').'</a>'.
       '<form method="get" class="chat-search"><input type="hidden" name="view" value="chat">'.
       '<label for="chat-client-search">Чаты клиентов</label>'.
       '<input id="chat-client-search" type="search" name="chat_q" maxlength="65" '.
       'placeholder="Найти клиента…" value="'.adc_h($q).'"></form>'.
       '<div class="chat-room-list">';
    foreach($clients as $client){
        $id=(int)$client['id'];$active=$thread===$id;
        $n=(int)($unread[(string)$id]??0);
        $out.='<a class="chat-room'.($active?' selected':'').'" data-room="'.$id.'" href="/?view=chat&amp;thread='.$id.'">'.
            '<span class="chat-room-name">'.adc_h((string)$client['name']).'</span>'.
            ($n?'<span class="chat-room-count">'.$n.'</span>':'').'</a>';
    }
    if(!$clients)$out.='<p class="muted">Клиенты не найдены.</p>';
    $out.='</div></aside><div class="chat-content"><div class="chat-heading"><h2>'.
        adc_h($selectedName).'</h2>'.
        ($thread?'<a href="/?view=clients&amp;client='.$thread.'">Карточка клиента ↗</a>':'').
        '</div>';
    if($pin){
        $summary=mb_substr(trim((string)$pin['body']),0,100);
        if($summary==='')$summary='Вложение';
        $out.='<div class="chat-pinned"><a href="/?view=chat&amp;thread='.$thread.
            '&amp;focus='.(int)$pin['id'].'#chat-msg-'.(int)$pin['id'].'">'.
            '📌 Закреплено · '.adc_h((string)$pin['author']).': '.adc_h($summary).
            '</a><form method="post"><input type="hidden" name="csrf" value="'.
            adc_csrf().'"><input type="hidden" name="action" value="chat_toggle_pin">'.
            '<input type="hidden" name="thread" value="'.$thread.'">'.
            '<input type="hidden" name="message_id" value="'.(int)$pin['id'].'">'.
            '<button type="submit" class="secondary">Открепить</button></form></div>';
    }
    if($focus)$out.='<p><a href="/?view=chat&amp;thread='.$thread.
        '">← Вернуться к последним сообщениям</a></p>';
    $out.='<div id="chat-feed" class="chat-feed" data-thread="'.$thread.
        '" data-last="'.$last.'" data-focused="'.($focus?'1':'0').
        '" role="log" aria-live="polite">';
    foreach($rows as $m)$out.=adc_chat_item($m,$uid);
    if(!$rows)$out.='<p data-empty class="muted">Пока нет сообщений. Начните обсуждение.</p>';
    $out.='</div><form method="post" enctype="multipart/form-data" class="chat-compose" data-chat-compose>'.
        '<input type="hidden" name="csrf" value="'.adc_csrf().'">'.
        '<input type="hidden" name="action" value="chat_send">'.
        '<input type="hidden" name="thread" value="'.$thread.'">'.
        '<input type="hidden" name="reply_to_id" value="0" data-reply-id>'.
        '<div class="chat-reply-preview" data-reply-preview hidden>'.
        '<span data-reply-label></span>'.
        '<button type="button" class="secondary" data-reply-cancel>✕</button></div>'.
        '<label class="field" for="chat-text">Сообщение</label>'.
        '<div class="chat-input-box"><textarea name="message" id="chat-text" rows="3" maxlength="2000" '.
        'placeholder="Вопрос команде… @сотрудник #клиент" autocomplete="off"></textarea>'.
        '<div class="chat-suggest" data-chat-suggestions hidden role="listbox"></div></div>'.
        '<p class="muted chat-hint">Наберите @ и выберите сотрудника или # и выберите клиента из подсказок.</p>'.
        '<label class="chat-attach">📎 Прикрепить PDF или скриншот (до 10 МБ)'.
        '<input type="file" name="chat_attachment" accept=".pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/png,image/jpeg,image/webp"></label>'.
        '<button type="submit">Отправить сообщение</button></form></div></div></section>';
    return $out;
}
function adc_chat_post(string $name):string {
    $x=$_POST[$name]??null;return is_string($x)?trim($x):'';
}
function adc_chat_references(PDO $db,string $body,int $self):array {
    preg_match_all('~(?<![\p{L}\p{N}_])([@#][\p{L}\p{N}_]{1,70})~u',$body,$matches);
    $users=[];$client=null;$seen=[];
    foreach($matches[1] as $tag){
        if(isset($seen[$tag]))continue;
        $seen[$tag]=true;
        if(!preg_match('/^([@#]).+_(\d+)$/u',$tag,$m))continue;
        $id=(int)$m[2];if($id<1)continue;
        if($m[1]==='@'){
            $s=$db->prepare('SELECT id,display_name FROM users WHERE id=? AND is_active=1');
            $s->execute([$id]);$person=$s->fetch();
            if($person && $id!==$self && hash_equals(adc_chat_alias((string)$person['display_name'],$id,'@'),$tag))
                $users[$id]=true;
        } else {
            $s=$db->prepare('SELECT id,name FROM clients WHERE id=?');$s->execute([$id]);
            $found=$s->fetch();
            if($found && hash_equals(adc_chat_alias((string)$found['name'],$id,'#'),$tag) && $client===null)
                $client=$id;
        }
    }
    if(count($users)>10)throw new RuntimeException('До 10 сотрудников в одном сообщении.');
    return [array_keys($users),$client];
}
function adc_chat_pinned(PDO $db,int $thread):?array {
    $s=$db->prepare('SELECT m.id,m.body,u.display_name author
        FROM team_chat_pins p JOIN team_chat_messages m ON m.id=p.message_id
        JOIN users u ON u.id=m.author_user_id WHERE p.thread_key=?
          AND COALESCE(m.thread_client_id,0)=?');
    $s->execute([$thread,$thread]);
    return $s->fetch()?:null;
}
function adc_chat_toggle_pin(PDO $db,array $user):void {
    $threadRaw=adc_chat_post('thread');
    $thread=adc_chat_thread($db,$threadRaw);
    if($threadRaw!=='' && $threadRaw!=='0' && !$thread)
        throw new RuntimeException('Переписка клиента не найдена.');
    $raw=adc_chat_post('message_id');
    if(!ctype_digit($raw)||(int)$raw<1)throw new RuntimeException('Неверное сообщение.');
    $id=(int)$raw;
    $s=$db->prepare('SELECT id FROM team_chat_messages WHERE id=?
      AND COALESCE(thread_client_id,0)=?');
    $s->execute([$id,$thread]);
    if(!$s->fetchColumn())throw new RuntimeException('Сообщение не принадлежит переписке.');
    $db->beginTransaction();
    try {
        $s=$db->prepare('SELECT message_id FROM team_chat_pins WHERE thread_key=? FOR UPDATE');
        $s->execute([$thread]);$existing=$s->fetchColumn();
        if($existing!==false && (int)$existing===$id){
            $s=$db->prepare('DELETE FROM team_chat_pins WHERE thread_key=? AND message_id=?');
            $s->execute([$thread,$id]);
        }else{
            $s=$db->prepare('INSERT INTO team_chat_pins(thread_key,message_id,pinned_by,created_at)
                VALUES (?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE
                message_id=VALUES(message_id),pinned_by=VALUES(pinned_by),
                created_at=UTC_TIMESTAMP(6)');
            $s->execute([$thread,$id,(int)$user['id']]);
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function adc_chat_send(PDO $db,array $user):void {
    $body=adc_chat_post('message');
    $hasFile=is_array($_FILES['chat_attachment']??null) &&
        ($_FILES['chat_attachment']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK;
    if((!$hasFile && ($body===''||!preg_match('/\S/u',$body)))||
       mb_strlen($body)>2000||strlen($body)>8000||str_contains($body,"\0"))
        throw new RuntimeException('Напишите сообщение или прикрепите файл (текст до 2000 символов).');
    $thread=adc_chat_thread($db,adc_chat_post('thread'));
    if(adc_chat_post('thread')!=='' && adc_chat_post('thread')!=='0' && !$thread)
        throw new RuntimeException('Переписка клиента не найдена.');
    [$mentioned,$client]=adc_chat_references($db,$body,(int)$user['id']);
    $replyRaw=adc_chat_post('reply_to_id');
    $replyId=null;
    if($replyRaw!=='' && $replyRaw!=='0'){
        if(!ctype_digit($replyRaw)||(int)$replyRaw<1)
            throw new RuntimeException('Неверный ответ на сообщение.');
        $s=$db->prepare('SELECT id FROM team_chat_messages WHERE id=?
            AND COALESCE(thread_client_id,0)=?');
        $s->execute([(int)$replyRaw,$thread]);
        if(!$s->fetchColumn())
            throw new RuntimeException('Ответ возможен только в текущей переписке.');
        $replyId=(int)$replyRaw;
    }
    // Save private bytes first, then commit message and attachment metadata atomically.
    $saved=null;
    try{
        $saved=adc_chat_receive_file();
        $db->beginTransaction();
        $s=$db->prepare('INSERT INTO team_chat_messages
          (author_user_id,body,mentioned_user_id,client_id,thread_client_id,reply_to_id,created_at)
          VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $s->execute([(int)$user['id'],$body,$mentioned[0]??null,$client,$thread?:null,$replyId]);
        $messageId=(int)$db->lastInsertId();
        if($saved){
            $db->prepare('INSERT INTO team_chat_attachments
                (message_id,storage_key,original_filename,mime_type,byte_size,content_sha256,created_at)
                VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([
                $messageId,$saved['key'],$saved['name'],$saved['mime'],
                $saved['size'],$saved['hash'],
            ]);
        }
        if($mentioned){
            $s=$db->prepare('INSERT INTO team_chat_mentions(message_id,user_id) VALUES (?,?)');
            foreach($mentioned as $id)$s->execute([$messageId,$id]);
        }
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        if($saved && is_file($saved['path'])) @unlink($saved['path']);
        throw $e;
    }
}
function adc_chat_read(PDO $db,int $uid,int $requested,int $thread=0):void {
    $thread=adc_chat_thread($db,$thread);
    $s=$db->prepare('SELECT COALESCE(MAX(id),0) FROM team_chat_messages
      WHERE COALESCE(thread_client_id,0)=?');
    $s->execute([$thread]);$max=(int)$s->fetchColumn();
    $s=$db->prepare('INSERT INTO team_chat_thread_reads(user_id,thread_key,last_read_id,updated_at)
        VALUES (?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
          last_read_id=GREATEST(last_read_id,VALUES(last_read_id)),updated_at=UTC_TIMESTAMP()');
    $s->execute([$uid,$thread,min(max($requested,0),$max)]);
}
function adc_chat_suggest(PDO $db,array $user):never {
    $type=$_GET['type']??'';$q=is_string($_GET['q']??null)?trim($_GET['q']):'';
    $q=mb_substr($q,0,60);
    if(!in_array($type,['@','#'],true)){http_response_code(400);exit;}
    $rows=[];
    if($type==='@'){
        $s=$db->prepare('SELECT id,display_name name FROM users WHERE is_active=1
            AND id<>? AND LOCATE(LOWER(?),LOWER(display_name))>0 ORDER BY display_name LIMIT 15');
        $s->execute([(int)$user['id'],$q]);$rows=$s->fetchAll();
    } else {
        $s=$db->prepare('SELECT id,name FROM clients
            WHERE LOCATE(LOWER(?),LOWER(name))>0 ORDER BY name LIMIT 15');
        $s->execute([$q]);$rows=$s->fetchAll();
    }
    $suggestions=array_map(static fn(array $v):array=>[
        'name'=>(string)$v['name'],
        'token'=>adc_chat_alias((string)$v['name'],(int)$v['id'],$type),
    ],$rows);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo json_encode(['results'=>$suggestions],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_THROW_ON_ERROR);exit;
}
function adc_chat_poll(PDO $db,array $user):never {
    $uid=(int)$user['id'];$after=filter_var($_GET['after']??0,FILTER_VALIDATE_INT);
    $after=($after===false||$after===null)?0:max(0,(int)$after);
    $thread=adc_chat_thread($db,$_GET['thread']??null);
    $payload=adc_chat_status($db,$uid);
    $rows=($_GET['status']??'')==='1'?[]:adc_chat_rows($db,$after,$thread,$uid);
    $payload['ok']=true;
    $payload['messages']=array_map(static fn(array $r):array=>
        ['id'=>(int)$r['id'],'html'=>adc_chat_item($r,$uid)],$rows);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store,private');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_THROW_ON_ERROR);exit;
}
