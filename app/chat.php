<?php
declare(strict_types=1);
/** Private team chat. All entrypoints are behind established session and CSRF checks. */
function adc_chat_status(PDO $db,int $uid):array {
    $s=$db->prepare('SELECT last_read_id FROM team_chat_reads WHERE user_id=?');
    $s->execute([$uid]);$last=(int)($s->fetchColumn()?:0);
    $s=$db->prepare('SELECT COUNT(*) unread,COALESCE(SUM(mentioned_user_id=?),0) mentions
        FROM team_chat_messages WHERE id>? AND author_user_id<>?');
    $s->execute([$uid,$last,$uid]);$r=$s->fetch();
    $s=$db->prepare('SELECT COALESCE(MAX(id),0) FROM team_chat_messages WHERE author_user_id<>?');
    $s->execute([$uid]);
    return ['unread'=>(int)$r['unread'],'mentions'=>(int)$r['mentions'],
            'newest_other_id'=>(int)$s->fetchColumn()];
}
function adc_chat_rows(PDO $db,int $after=0):array {
    $sql='SELECT m.*,u.display_name author,mu.display_name mention_name,c.name client_name
        FROM team_chat_messages m JOIN users u ON u.id=m.author_user_id
        LEFT JOIN users mu ON mu.id=m.mentioned_user_id
        LEFT JOIN clients c ON c.id=m.client_id';
    if($after){
        $s=$db->prepare($sql.' WHERE m.id>? ORDER BY m.id ASC LIMIT 60');
        $s->execute([$after]);return $s->fetchAll();
    }
    return array_reverse($db->query($sql.' ORDER BY m.id DESC LIMIT 60')->fetchAll());
}
function adc_chat_safe_text(string $body):string {
    $parts=preg_split('~(https?://[^\s<>"\x00-\x1F]+)~iu',$body,-1,PREG_SPLIT_DELIM_CAPTURE);
    $out='';
    foreach($parts?:[$body] as $p){
        if(preg_match('~^https?://~i',$p)&&filter_var($p,FILTER_VALIDATE_URL)&&mb_strlen($p)<=1500)
            $out.='<a href="'.adc_h($p).'" target="_blank" rel="noopener noreferrer nofollow">'.adc_h($p).'</a>';
        else $out.=adc_h($p);
    }
    return nl2br($out);
}
function adc_chat_item(array $m,int $uid):string {
    $class=(int)$m['author_user_id']===$uid?' mine':((int)$m['mentioned_user_id']===$uid?' ping':'');
    $out='<article class="chat-msg'.$class.'" data-mid="'.(int)$m['id'].'">'.
       '<div class="chat-meta"><strong>'.adc_h((string)$m['author']).'</strong><time>'.
       adc_h(gmdate('d.m.Y H:i',strtotime((string)$m['created_at'].' UTC')).' UTC').'</time></div>';
    if($m['mentioned_user_id']!==null)
        $out.='<div class="chat-ping">@'.adc_h((string)($m['mention_name']??'Сотрудник')).'</div>';
    $out.='<div>'.adc_chat_safe_text((string)$m['body']).'</div>';
    if($m['client_id']!==null)
        $out.='<a class="chat-link" href="/?view=clients&client='.(int)$m['client_id'].'">↗ '.
           adc_h((string)($m['client_name']??'Карточка клиента')).'</a>';
    return $out.'</article>';
}
function adc_chat_html(PDO $db,array $user):string {
    $uid=(int)$user['id'];$rows=adc_chat_rows($db);
    $last=$rows?(int)$rows[count($rows)-1]['id']:0;
    $out='<section class="card"><h1>Чат команды</h1>'.
       '<p class="muted">Общий чат директора и сотрудников. Для адресного уведомления выберите коллегу в поле @.</p>'.
       '<div class="actions"><button type="button" class="secondary" data-chat-sound>Включить звук</button>'.
       '<span data-chat-state class="muted" role="status"></span></div>'.
       '<div id="chat-feed" class="chat-feed" data-last="'.$last.'" role="log" aria-live="polite">';
    foreach($rows as $row)$out.=adc_chat_item($row,$uid);
    if(!$rows)$out.='<p class="muted" data-empty>Сообщений пока нет.</p>';
    $out.='</div><form method="post" class="chat-compose" data-chat-compose>'.
       '<input type="hidden" name="csrf" value="'.adc_csrf().'">'.
       '<input type="hidden" name="action" value="chat_send">'.
       '<label class="field">Сообщение<textarea name="message" rows="3" maxlength="2000" required '.
       'placeholder="Напишите сообщение или вставьте ссылку…"></textarea></label>'.
       '<div class="chat-picks"><label class="field">Упомянуть сотрудника<select name="mention">'.
       '<option value="">Никого</option>';
    $staff=$db->prepare('SELECT id,display_name FROM users WHERE is_active=1 AND id<>? ORDER BY display_name,id');
    $staff->execute([$uid]);
    foreach($staff as $p)$out.='<option value="'.(int)$p['id'].'">@'.adc_h((string)$p['display_name']).'</option>';
    $out.='</select></label><label class="field">Ссылка на клиента<select name="client">'.
       '<option value="">Без привязки</option>';
    $selected=filter_var($_GET['client']??null,FILTER_VALIDATE_INT);
    foreach($db->query('SELECT id,name FROM clients ORDER BY id DESC LIMIT 250') as $p)
        $out.='<option value="'.(int)$p['id'].'"'.
           ($selected && (int)$selected===(int)$p['id']?' selected':'').'>'.adc_h((string)$p['name']).'</option>';
    return $out.'</select></label></div><button type="submit">Отправить</button></form></section>';
}
function adc_chat_post(string $name):string {
    $value=$_POST[$name]??null;
    return is_string($value)?trim($value):'';
}
function adc_chat_send(PDO $db,array $user):void {
    $body=adc_chat_post('message');$mentionRaw=adc_chat_post('mention');$clientRaw=adc_chat_post('client');
    if($body===''||!preg_match('/\S/u',$body)||mb_strlen($body)>2000||
       strlen($body)>8000||str_contains($body,"\0"))
        throw new RuntimeException('Сообщение должно содержать от 1 до 2000 символов.');
    $mention=null;$client=null;
    if($mentionRaw!==''){
        if(!ctype_digit($mentionRaw)||(int)$mentionRaw<1||(int)$mentionRaw===(int)$user['id'])
            throw new RuntimeException('Неверное упоминание.');
        $s=$db->prepare('SELECT id FROM users WHERE id=? AND is_active=1');
        $s->execute([(int)$mentionRaw]);
        if(!$s->fetchColumn())throw new RuntimeException('Сотрудник не найден или отключён.');
        $mention=(int)$mentionRaw;
    }
    if($clientRaw!==''){
        if(!ctype_digit($clientRaw)||(int)$clientRaw<1)
            throw new RuntimeException('Неверная ссылка на клиента.');
        $s=$db->prepare('SELECT id FROM clients WHERE id=?');$s->execute([(int)$clientRaw]);
        if(!$s->fetchColumn())throw new RuntimeException('Клиент не найден.');
        $client=(int)$clientRaw;
    }
    $s=$db->prepare('SELECT created_at FROM team_chat_messages WHERE author_user_id=? ORDER BY id DESC LIMIT 1');
    $s->execute([(int)$user['id']]);$last=$s->fetchColumn();
    if($last && strtotime((string)$last.' UTC')>time()-2)
        throw new RuntimeException('Подождите пару секунд перед следующим сообщением.');
    $s=$db->prepare('INSERT INTO team_chat_messages(author_user_id,body,mentioned_user_id,client_id,created_at)
         VALUES (?,?,?,?,UTC_TIMESTAMP(6))');
    $s->execute([(int)$user['id'],$body,$mention,$client]);
}
function adc_chat_read(PDO $db,int $uid,int $requested):void {
    $max=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM team_chat_messages')->fetchColumn();
    $s=$db->prepare('INSERT INTO team_chat_reads(user_id,last_read_id,updated_at)
        VALUES (?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE
        last_read_id=GREATEST(last_read_id,VALUES(last_read_id)),updated_at=UTC_TIMESTAMP()');
    $s->execute([$uid,min(max($requested,0),$max)]);
}
function adc_chat_poll(PDO $db,array $user):never {
    $uid=(int)$user['id'];$after=filter_var($_GET['after']??0,FILTER_VALIDATE_INT);
    $after=($after===false||$after===null)?0:max(0,(int)$after);
    $payload=adc_chat_status($db,$uid);
    $rows=($_GET['status']??'')==='1'?[]:adc_chat_rows($db,$after);
    $payload['ok']=true;
    $payload['messages']=array_map(static fn(array $r):array=>
        ['id'=>(int)$r['id'],'html'=>adc_chat_item($r,$uid)],$rows);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR);
    exit;
}
