<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
function auth_ok(bool $condition,string $why):void {
    if (!$condition) throw new RuntimeException('Auth regression: '.$why);
}
$now=1792600000;
$recent=['auth_issued_at'=>$now-10,'auth_last_seen_at'=>$now-2];
auth_ok(adc_auth_session_timely($recent,$now),'Recently issued session');
auth_ok(adc_auth_session_timely(['auth_issued_at'=>$now-12*3600,'auth_last_seen_at'=>$now-30*60],$now),
    'Boundary minutes/hours are allowed');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now-12*3600-1,'auth_last_seen_at'=>$now-2],$now),
    'Absolute 12-hour expiry');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now-12*3600+1,'auth_last_seen_at'=>$now-30*60-1],$now),
    'Thirty-minute idle expiry');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now+1,'auth_last_seen_at'=>$now],$now),
    'Future issue date is invalid');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now-10,'auth_last_seen_at'=>$now+1],$now),
    'Future activity timestamp is invalid');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now-10,'auth_last_seen_at'=>$now-11],$now),
    'Activity before creation is invalid');
auth_ok(!adc_auth_session_timely(['user_id'=>1],$now),'Legacy unbound session expires');
auth_ok(!hash_equals(adc_auth_binding('same user old hash'),adc_auth_binding('same user new hash')),
    'Password update revokes previous binding');
echo "Auth regression passed: revocation binding and idle/absolute expiration\n";
