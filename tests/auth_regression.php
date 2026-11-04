<?php
declare(strict_types=1);
require __DIR__.'/../app/core.php';
function auth_ok(bool $condition,string $why):void {
    if (!$condition) throw new RuntimeException('Auth regression: '.$why);
}
$now=1792600000;
$recent=['auth_issued_at'=>$now-10,'auth_last_seen_at'=>$now-2];
auth_ok(adc_auth_session_timely($recent,$now),'Recently issued session');
auth_ok(adc_auth_session_timely(['auth_issued_at'=>$now-12*3600,'auth_last_seen_at'=>$now-60*60],$now),
    'Returning after an hour does not require another password');
auth_ok(adc_auth_session_timely(['auth_issued_at'=>$now-ADC_AUTH_SESSION_LIFETIME,
    'auth_last_seen_at'=>$now-ADC_AUTH_IDLE_LIFETIME],$now),
    'Thirty-day absolute and seven-day idle boundaries are accepted');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now-ADC_AUTH_SESSION_LIFETIME-1,
    'auth_last_seen_at'=>$now-2],$now),
    'Absolute thirty-day expiry');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now-ADC_AUTH_IDLE_LIFETIME-100,
    'auth_last_seen_at'=>$now-ADC_AUTH_IDLE_LIFETIME-1],$now),
    'Seven-day idle expiry');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now+1,'auth_last_seen_at'=>$now],$now),
    'Future issue date is invalid');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now-10,'auth_last_seen_at'=>$now+1],$now),
    'Future activity timestamp is invalid');
auth_ok(!adc_auth_session_timely(['auth_issued_at'=>$now-10,'auth_last_seen_at'=>$now-11],$now),
    'Activity before creation is invalid');
auth_ok(!adc_auth_session_timely(['user_id'=>1],$now),'Legacy unbound session expires');
auth_ok(!hash_equals(adc_auth_binding('same user old hash'),adc_auth_binding('same user new hash')),
    'Password update revokes previous binding');
adc_session();
auth_ok((int)session_get_cookie_params()['lifetime']===ADC_AUTH_SESSION_LIFETIME,
    'Browser receives a persistent rather than browser-only login cookie');
auth_ok((int)ini_get('session.gc_maxlifetime')===ADC_AUTH_SESSION_LIFETIME,
    'PHP garbage collection keeps authenticated sessions as long as the cookie');
$_SESSION['user_id']=123;
adc_auth_session_logout();
auth_ok(!isset($_SESSION['user_id']) && session_status()===PHP_SESSION_NONE,
    'Explicit logout destroys authenticated session even with a persistent cookie');
echo "Auth regression passed: persistent session, revocation binding and idle/absolute expiration\n";
