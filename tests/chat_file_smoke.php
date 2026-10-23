<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/chat.php';

$ok=static function(bool $pass,string $name):void {
    if(!$pass)throw new RuntimeException('Chat files: '.$name);
};
$png=base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/JP8AAAAASUVORK5CYII=',
    true);
$tmp=tempnam(sys_get_temp_dir(),'adc_chat_');
if($tmp===false)throw new RuntimeException('No disposable test temp path');
try {
    file_put_contents($tmp,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    [$name,$ext,$mime,$size]=adc_chat_validate_file($tmp,'invoice.pdf');
    $ok($name==='invoice.pdf'&&$ext==='pdf'&&$mime==='application/pdf'&&$size>8,
        'Valid PDF');
    file_put_contents($tmp,(string)$png);
    [$name,$ext,$mime,$size]=adc_chat_validate_file($tmp,'screen.png');
    $ok($ext==='png'&&$mime==='image/png'&&$size>8,'Valid screenshot');
    file_put_contents($tmp,'<html><script>alert(1)</script></html>');
    $blocked=false;
    try{adc_chat_validate_file($tmp,'invoice.pdf');}
    catch(RuntimeException $e){$blocked=true;}
    $ok($blocked,'Reject fake PDF');
    $blocked=false;
    try{adc_chat_validate_file($tmp,'evil.php');}
    catch(RuntimeException $e){$blocked=true;}
    $ok($blocked,'Reject executable extension');
    $blocked=false;
    file_put_contents($tmp,(string)$png);
    try{adc_chat_validate_file($tmp,"bad\r\nname.png");}
    catch(RuntimeException $e){$blocked=true;}
    $ok($blocked,'Reject CRLF filename');
    $ok(str_ends_with(adc_chat_attachment_dir(),'/adclients-chat-files') &&
        !str_contains(adc_chat_attachment_dir(),'/public_html/'),
        'Store attachments outside public web root');
    $stored=['attachment_id'=>9,'attachment_name'=>'screen.png',
        'attachment_mime'=>'image/png','author_user_id'=>1,'is_mentioned'=>0,
        'mentioned_user_id'=>null,'id'=>12,'author'=>'Fixture',
        'created_at'=>'2026-10-23 09:15:00','body'=>'Screenshot','client_id'=>null];
    $view=adc_chat_item($stored,2);
    $ok(str_contains($view,'chat_attachment=9') &&
        str_contains($view,'chat-file chat-image') &&
        str_contains($view,'12:15')&&!str_contains($view,' UTC'),
        'Authenticated preview with Minsk +03:00 time');
    $stored['attachment_name']='<img src=x onerror=alert(1)>.png';
    $view=adc_chat_item($stored,2);
    $ok(str_contains($view,'&lt;img')&&!str_contains($view,'<img src=x'),
        'HTML-escape malicious filenames');
} finally {
    @unlink($tmp);
}
echo "Chat files smoke PASS: formats, spoof protection, private storage and Minsk time\n";
