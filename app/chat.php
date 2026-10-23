<?php
declare(strict_types=1);
/** Authenticated internal chat: general room + one room per client. */

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
    $sql='SELECT COUNT(*) unread,COALESCE(SUM(
         CASE WHEN m.mentioned_user_id=? OR EXISTS(
           SELECT 1 FROM team_chat_mentions um WHERE um.message_id=m.id AND um.user_id=?
         ) THEN 1 ELSE 0 END),0) mentions
       FROM team_chat_messages m
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
function adc_chat_rows(PDO $db,int $after=0,int $thread=0,int $uid=0):array {
    $sql='SELECT m.*,u.display_name author,mu.display_name mention_name,c.name client_name,
        EXISTS(SELECT 1 FROM team_chat_mentions tags
               WHERE tags.message_id=m.id AND tags.user_id=?) is_mentioned
        FROM team_chat_messages m
        JOIN users u ON u.id=m.author_user_id
        LEFT JOIN users mu ON mu.id=m.mentioned_user_id
        LEFT JOIN clients c ON c.id=m.client_id
        WHERE COALESCE(m.thread_client_id,0)=?';
    if($after>0){
        $s=$db->prepare($sql.' AND m.id>? ORDER BY m.id ASC LIMIT 60');
        $s->execute([$uid,$thread,$after]);return $s->fetchAll();
    }
    $s=$db->prepare($sql.' ORDER BY m.id DESC LIMIT 60');
    $s->execute([$uid,$thread]);
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
    $time=gmdate('d.m.Y H:i',strtotime((string)$m['created_at'].' UTC')).' UTC';
    $out='<article class="chat-msg'.$class.'" data-mid="'.(int)$m['id'].'">'.
        '<div class="chat-meta"><strong>'.adc_h((string)$m['author']).'</strong>'.
        '<time>'.adc_h($time).'</time></div>'.
        '<div>'.adc_chat_safe_text((string)$m['body'],$m['client_id']===null?null:(int)$m['client_id']).'</div>';
    if($m['client_id']!==null)
        $out.='<a class="chat-link" href="/?view=clients&amp;client='.(int)$m['client_id'].'">↗ Карточка: '.
            adc_h((string)($m['client_name']??'Клиент')).'</a>';
    return $out.'</article>';
}
function adc_chat_html(PDO $db,array $user):string {
    $uid=(int)$user['id'];
    $thread=adc_chat_thread($db,$_GET['thread']??null);
    $rows=adc_chat_rows($db,0,$thread,$uid);
    $last=$rows?(int)$rows[count($rows)-1]['id']:0;
    $q=is_string($_GET['chat_q']??null)?trim((string)$_GET['chat_q']):'';
    $q=mb_substr($q,0,65);
    $s=$db->prepare('SELECT c.id,c.name FROM clients c
        WHERE ?="" OR LOCATE(LOWER(?),LOWER(c.name))>0
        ORDER BY (SELECT COALESCE(MAX(id),0) FROM team_chat_messages cm
          WHERE cm.thread_client_id=c.id) DESC,c.id DESC LIMIT 40');
    $s->execute([$q,$q]);
    $clients=$s->fetchAll();
    if($thread){
        $s=$db->prepare('SELECT name FROM clients WHERE id=?');
        $s->execute([$thread]);$selectedName=(string)$s->fetchColumn();
    } else $selectedName='Общий чат';
    $out='<section class="card"><h1>Чат команды</h1>'.
       '<p class="muted">Общий чат и переписки по клиентам. В сообщении набери @ для коллеги или # для клиента.</p>'.
       '<div class="actions"><button class="secondary" type="button" data-chat-sound>Включить звук</button>'.
       '<span data-chat-state role="status" class="muted"></span></div>'.
       '<div class="chat-layout"><aside class="chat-sidebar" aria-label="Переписки">'.
       '<a class="chat-room'.($thread===0?' selected':'').'" data-room="0" href="/?view=chat">💬 Общий чат'.
       (($count=adc_chat_thread_unread($db,$uid,0))?' <span class="chat-room-count">'.$count.'</span>':'').'</a>'.
       '<form method="get" class="chat-search"><input type="hidden" name="view" value="chat">'.
       '<label for="chat-client-search">Чаты клиентов</label>'.
       '<input id="chat-client-search" type="search" name="chat_q" maxlength="65" '.
       'placeholder="Найти клиента…" value="'.adc_h($q).'"></form>'.
       '<div class="chat-room-list">';
    foreach($clients as $client){
        $id=(int)$client['id'];$active=$thread===$id;
        $n=adc_chat_thread_unread($db,$uid,$id);
        $out.='<a class="chat-room'.($active?' selected':'').'" data-room="'.$id.'" href="/?view=chat&amp;thread='.$id.'">'.
            '<span class="chat-room-name">'.adc_h((string)$client['name']).'</span>'.
            ($n?'<span class="chat-room-count">'.$n.'</span>':'').'</a>';
    }
    if(!$clients)$out.='<p class="muted">Клиенты не найдены.</p>';
    $out.='</div></aside><div class="chat-content"><div class="chat-heading"><h2>'.
        adc_h($selectedName).'</h2>'.
        ($thread?'<a href="/?view=clients&amp;client='.$thread.'">Карточка клиента ↗</a>':'').
        '</div><div id="chat-feed" class="chat-feed" data-thread="'.$thread.'" data-last="'.$last.'" '.
        'role="log" aria-live="polite">';
    foreach($rows as $m)$out.=adc_chat_item($m,$uid);
    if(!$rows)$out.='<p data-empty class="muted">Пока нет сообщений. Начните обсуждение.</p>';
    $out.='</div><form method="post" class="chat-compose" data-chat-compose>'.
        '<input type="hidden" name="csrf" value="'.adc_csrf().'">'.
        '<input type="hidden" name="action" value="chat_send">'.
        '<input type="hidden" name="thread" value="'.$thread.'">'.
        '<label class="field" for="chat-text">Сообщение</label>'.
        '<div class="chat-input-box"><textarea name="message" id="chat-text" rows="3" maxlength="2000" required '.
        'placeholder="Вопрос команде… @сотрудник #клиент" autocomplete="off"></textarea>'.
        '<div class="chat-suggest" data-chat-suggestions hidden role="listbox"></div></div>'.
        '<p class="muted chat-hint">Наберите @ и выберите сотрудника или # и выберите клиента из подсказок.</p>'.
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
function adc_chat_send(PDO $db,array $user):void {
    $body=adc_chat_post('message');
    if($body===''||!preg_match('/\S/u',$body)||mb_strlen($body)>2000||
       strlen($body)>8000||str_contains($body,"\0"))
        throw new RuntimeException('Сообщение должно содержать от 1 до 2000 символов.');
    $thread=adc_chat_thread($db,adc_chat_post('thread'));
    if(adc_chat_post('thread')!=='' && adc_chat_post('thread')!=='0' && !$thread)
        throw new RuntimeException('Переписка клиента не найдена.');
    [$mentioned,$client]=adc_chat_references($db,$body,(int)$user['id']);
    // Posting and mention notification are atomic. No financial data is touched.
    $db->beginTransaction();
    try{
        $s=$db->prepare('INSERT INTO team_chat_messages
          (author_user_id,body,mentioned_user_id,client_id,thread_client_id,created_at)
          VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))');
        $s->execute([(int)$user['id'],$body,$mentioned[0]??null,$client,$thread?:null]);
        $messageId=(int)$db->lastInsertId();
        if($mentioned){
            $s=$db->prepare('INSERT INTO team_chat_mentions(message_id,user_id) VALUES (?,?)');
            foreach($mentioned as $id)$s->execute([$messageId,$id]);
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
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
