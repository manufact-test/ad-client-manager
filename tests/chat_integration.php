<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/chat.php';
$db=adc_db(['db'=>[
    'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1',
    'port'=>(int)(getenv('TEST_DB_PORT')?:3306),
    'name'=>getenv('TEST_DB_NAME')?:'adclients_test',
    'user'=>getenv('TEST_DB_USER')?:'root',
    'password'=>getenv('TEST_DB_PASSWORD')?:'',
]]);
adc_schema($db);adc_schema($db);
$ok=static function(bool $value,string $why):void {
    if(!$value)throw new RuntimeException('Chat integration: '.$why);
};
$users=[];
foreach(['director','employee','employee'] as $role){
    $key=count($users)+1;
    $name='Chat User '.$key;
    $db->prepare('INSERT INTO users(email,display_name,password_hash,role)
        VALUES (?,?,"fixture-hash",?)')->execute([
        'chat-'.bin2hex(random_bytes(8)).'@example.test',$name,$role]);
    $users[]=['id'=>(int)$db->lastInsertId(),'name'=>$name];
}
[$a,$b,$c]=$users;
$clientName='Fictional Chat Factory '.bin2hex(random_bytes(3));
$db->prepare('INSERT INTO clients(name,is_demo) VALUES (?,1)')->execute([$clientName]);
$client=(int)$db->lastInsertId();
$alice=adc_chat_alias($a['name'],$a['id'],'@');
$bob=adc_chat_alias($b['name'],$b['id'],'@');
$charlie=adc_chat_alias($c['name'],$c['id'],'@');
$clientTag=adc_chat_alias($clientName,$client,'#');
$ok(str_contains($bob,(string)$b['id'])&&str_starts_with($clientTag,'#'),
    'Stable unique usernames and client tags');
$_POST=['message'=>'Hello '.$bob.' and '.$charlie.' about '.$clientTag.
    ' <script>alert(1)</script> https://example.org','thread'=>'0'];
adc_chat_send($db,['id'=>$a['id'],'role'=>'director']);
$general=adc_chat_rows($db,0,0,$b['id']);$m=end($general);
$ok($m&&(int)$m['author_user_id']===$a['id'],'General room contains new message');
$ok((int)$m['client_id']===$client,'Client tag resolved into persistent client link');
$tagCount=(int)$db->query('SELECT COUNT(*) FROM team_chat_mentions WHERE message_id='.(int)$m['id'])->fetchColumn();
$ok($tagCount===2,'Two tagged employees both receive mention');
$html=adc_chat_item($m,$b['id']);
$ok(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>'),
    'XSS escaped');
$ok(str_contains($html,'rel="noopener noreferrer nofollow"'),
    'External URL secured');
$ok(str_contains($html,'view=chat&amp;thread='.$client),
    'Clickable client tag opens internal client chat');
$unread=adc_chat_status($db,$b['id']);
$ok($unread['mentions']>=1&&$unread['unread']>=1,
    'Tagged recipient receives unread notification');
adc_chat_read($db,$b['id'],(int)$m['id'],0);
$after=adc_chat_status($db,$b['id']);
$ok($after['unread']===0&&$after['mentions']===0,'Reading general chat clears only general unread');
$ok(adc_chat_status($db,$c['id'])['mentions']>=1,
    'Other tagged employee retains an independent notification');
$_POST=['message'=>'Проверь, пожалуйста, платёж '.$alice,
    'thread'=>(string)$client];
adc_chat_send($db,['id'=>$b['id'],'role'=>'employee']);
$privateRoom=adc_chat_rows($db,0,$client,$a['id']);$reply=end($privateRoom);
$ok((int)$reply['thread_client_id']===$client,'Client conversation persists separately');
$ok(!array_filter($general,static fn($x)=>(int)$x['id']===(int)$reply['id']),
    'Client messages cannot leak into general room query');
$ok(adc_chat_thread_unread($db,$a['id'],$client)===1,'Client room unread count');
$ok(adc_chat_thread_unread($db,$a['id'],0)===0,'Unrelated room stays read');
adc_chat_read($db,$a['id'],(int)$reply['id'],0);
$ok(adc_chat_thread_unread($db,$a['id'],$client)===1,
    'Marking general chat read cannot mark client thread read');
adc_chat_read($db,$a['id'],(int)$reply['id'],$client);
$ok(adc_chat_thread_unread($db,$a['id'],$client)===0,
    'Only client thread watermark advances');
$_POST=['message'=>'Suspicious @fake_999999999 and #fake_999999999','thread'=>'0'];
adc_chat_send($db,['id'=>$c['id'],'role'=>'employee']);
$fake=adc_chat_rows($db,0,0,$a['id']);$last=end($fake);
$ok((int)$last['id']>(int)$m['id'] && $last['mentioned_user_id']===null
    && $last['client_id']===null,'Forged tags do not target people or clients');
$_POST=['message'=>'Unknown thread','thread'=>'999999999'];
$blocked=false;
try{adc_chat_send($db,['id'=>$a['id'],'role'=>'director']);}catch(RuntimeException $e){$blocked=true;}
$ok($blocked,'Unknown client conversation rejected');
// Pin and reply must stay inside their own conversation.
$_POST=['thread'=>'0','message_id'=>(string)$m['id']];
adc_chat_toggle_pin($db,['id'=>$b['id']]);
$generalPin=adc_chat_pinned($db,0);
$ok($generalPin&&(int)$generalPin['id']===(int)$m['id'],'General chat pinned');
$ok(adc_chat_pinned($db,$client)===null,'Pin does not leak into client chat');
$_POST=['message'=>'Reply to your request','thread'=>'0',
    'reply_to_id'=>(string)$m['id']];
adc_chat_send($db,['id'=>$b['id']]);
$messages=adc_chat_rows($db,0,0,$a['id']);$answer=end($messages);
$ok((int)$answer['reply_to_id']===(int)$m['id'],'Reply reference persisted');
$replyHtml=adc_chat_item($answer,$a['id']);
$ok(str_contains($replyHtml,'chat-replied')&&
    str_contains($replyHtml,'focus='.(int)$m['id']),
    'Reply context links back to exact message');
$ok(str_contains($replyHtml,'Ответить')&&
    str_contains($replyHtml,'chat_toggle_pin'),'Context actions rendered');
$_POST=['thread'=>(string)$client,'message_id'=>(string)$reply['id']];
adc_chat_toggle_pin($db,['id'=>$a['id']]);
$clientPin=adc_chat_pinned($db,$client);
$ok($clientPin&&(int)$clientPin['id']===(int)$reply['id'],
    'Client chat has its own pin');
$_POST=['thread'=>(string)$client,'message_id'=>(string)$m['id']];
$rejected=false;
try{adc_chat_toggle_pin($db,['id'=>$a['id']]);}
catch(RuntimeException $e){$rejected=true;}
$ok($rejected,'Cannot pin another thread message');
$_POST=['thread'=>(string)$client,'message'=>'Bad cross-thread reply',
    'reply_to_id'=>(string)$m['id']];
$rejected=false;
try{adc_chat_send($db,['id'=>$a['id']]);}
catch(RuntimeException $e){$rejected=true;}
$ok($rejected,'Cannot reply to another conversation');
$_POST=['thread'=>'0','message_id'=>(string)$m['id']];
adc_chat_toggle_pin($db,['id'=>$b['id']]);
$ok(adc_chat_pinned($db,0)===null,'Pin can be removed');
$ok(adc_chat_pinned($db,$client)!==null,'Removing pin leaves other rooms pinned');
// Exercise the full chat page rendering, not only message helpers.
$_GET=['view'=>'chat'];
$page=adc_chat_html($db,['id'=>$a['id'],'role'=>'director']);
$ok(str_contains($page,'Чат команды') && str_contains($page,'Общий чат'),
    'Director can render the complete general chat without HTTP 500');
$_GET=['view'=>'chat','thread'=>(string)$client];
$page=adc_chat_html($db,['id'=>$b['id'],'role'=>'employee']);
$ok(str_contains($page,'chat-feed') && str_contains($page,$clientName),
    'Employee can render a client conversation without HTTP 500');
$ok(str_contains($page,'chat-pinned')&&str_contains($page,'Закреплено') &&
    str_contains($page,'data-reply-id'),
    'Pinned client banner and reply composer rendered');
unset($_GET['thread']);
echo "Chat integration PASS: multi mentions, inline staff/client tags, safe links, separate rooms, isolated unread, persistence\n";
