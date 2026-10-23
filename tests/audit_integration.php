<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
require __DIR__.'/../app/audit.php';
function audit_check(bool $condition,string $label):void {
    if (!$condition) throw new RuntimeException('Audit regression: '.$label);
}
$db=adc_db(['db'=>[
    'host'=>getenv('TEST_DB_HOST')?:'127.0.0.1',
    'name'=>getenv('TEST_DB_NAME')?:'adclients_test',
    'user'=>getenv('TEST_DB_USER')?:'root',
    'password'=>getenv('TEST_DB_PASSWORD')?:'',
]]);
adc_schema($db);
$db->beginTransaction();
try {
    $_GET=[];
    $director=['role'=>'director'];
    $employee=['role'=>'employee'];
    audit_check(adc_audit_panel($db,$employee)==='','Employee cannot see financial audit');
    for($i=0;$i<53;$i++) {
        adc_audit($db,null,'audit-fixture-'.$i,'test',null,['private'=>'<tag>']);
    }
    $db->prepare('INSERT INTO audit_events (action,object_type,object_id,details_json)
        VALUES ("<script>alert(1)</script>","test",?,?)')->execute([123,'{"s":"<img onerror=evil>"}']);
    $page=adc_audit_panel($db,$director);
    audit_check(str_contains($page,'Журнал изменений'),'Director audit panel');
    audit_check(str_contains($page,'Действие системы'),'Unknown technical event label hidden');
    audit_check(!str_contains($page,'<script>alert(1)</script>'),'Raw script not rendered');
    audit_check(!str_contains($page,'<img onerror'),'Payload not rendered as HTML');
    audit_check(str_contains($page,'audit_before='),'Large history paginated');
    preg_match('/audit_before=(\d+)/',$page,$match);
    audit_check(isset($match[1]),'Pagination marker');
    $_GET=['audit_before'=>$match[1]];
    $older=adc_audit_panel($db,$director);
    audit_check(str_contains($older,'Действие системы'),'Earlier audit entries loaded in Russian');
    $_GET=['audit_before'=>'-10'];
    audit_check(str_contains(adc_audit_panel($db,$director),'Действие системы'),
        'Invalid pagination is ignored safely');
} finally {
    $db->rollBack();
    $_GET=[];
}
echo "Audit integration passed: roles, paging and HTML-escaped audit content\n";
