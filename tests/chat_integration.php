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
adc_schema($db);
$ok=static function(bool $value,string $label):void {
    if(!$value)throw new RuntimeException('Chat integration: '.$label);
};
$users=[];
foreach(['director','employee'] as $role) {
    $db->prepare('INSERT INTO users(email,display_name,password_hash,role)
        VALUES (?,?,"test-hash",?)')->execute([
        'chat-'.bin2hex(random_bytes(8)).'@example.test','Fixture '.$role,$role]);
    $users[$role]=(int)$db->lastInsertId();
}
$_POST=['message'=>'Hello <script>alert(1)</script> https://example.org',
    'mention'=>(string)$users['employee'],'client'=>''];
adc_chat_send($db,['id'=>$users['director'],'role'=>'director']);
$rows=adc_chat_rows($db);
$last=end($rows);
$ok($last&&(int)$last['mentioned_user_id']===$users['employee'],'Stored stable mentioned user');
$html=adc_chat_item($last,$users['employee']);
$ok(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>'),'Escaped message');
$ok(str_contains($html,'rel="noopener noreferrer nofollow"'),'Safe links');
$unread=adc_chat_status($db,$users['employee']);
$ok($unread['mentions']>=1 && $unread['unread']>=1,'Unread mention');
adc_chat_read($db,$users['employee'],(int)$last['id']);
$unread=adc_chat_status($db,$users['employee']);
$ok($unread['unread']===0&&$unread['mentions']===0,'Read marker');
$_POST=['message'=>'Unknown mention','mention'=>'999999999','client'=>''];
$threw=false;
try{adc_chat_send($db,['id'=>$users['employee'],'role'=>'employee']);}
catch(RuntimeException $e){$threw=true;}
$ok($threw,'Reject unknown mention');
echo "Chat integration PASS: messages, mentions, read receipts, XSS, links and validation\n";
