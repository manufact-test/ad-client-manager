#!/usr/bin/env python3
"""Browser-equivalent HTTP acceptance of the staging-only director migration.
The PHP server uses a deliberately obsolete MySQL schema in a disposable DB.
"""
import re
import subprocess
import tempfile
from pathlib import Path

BASE="http://darkred-crow-882796.hostingersite.com:8092"
HOST="darkred-crow-882796.hostingersite.com"
TMP=Path(tempfile.mkdtemp(prefix="adc-staging-upgrade-"))
SESSION=None
def check(ok, message):
    if not ok:
        raise AssertionError(message)
def request(data=None):
    global SESSION
    # The real application deliberately marks session cookies Secure. In this
    # disposable HTTP fixture send back the server-issued cookie explicitly,
    # without weakening its production TLS/session settings.
    cmd=["curl","-sS","--max-time","15",
         "--noproxy","*","--resolve",f"{HOST}:8092:127.0.0.1",
         "-D",str(TMP/"headers"),"-o",str(TMP/"body"),"-w","%{http_code}"]
    if SESSION is not None:
        cmd.extend(["-H",f"Cookie: ADCLIENTSESSID={SESSION}"])
    if data is not None:
        for k,v in data.items():
            cmd.extend(["--data-urlencode",f"{k}={v}"])
    result=subprocess.run(cmd+[BASE+"/"],capture_output=True,text=True,check=True)
    headers=(TMP/"headers").read_text(encoding="utf-8")
    match=re.search(r"ADCLIENTSESSID=([A-Za-z0-9]+)",headers)
    if match:
        SESSION=match.group(1)
    return int(result.stdout),(TMP/"body").read_text(encoding="utf-8"),headers
def csrf(body):
    match=re.search(r'name="csrf" value="([a-f0-9]{64})"',body)
    check(match,"CSRF token missing")
    return match.group(1)

status,body,_=request()
check(status==200 and "Вход" in body, "Login page unavailable")
status,body,_=request({"csrf":csrf(body),"action":"login",
                       "email":"staging-director@example.test",
                       "password":"ci-fixture-upgrade-123456"})
check(status==302, "Director could not log in on old schema")
status,body,_=request()
check(status==200 and "Обновление тестовой базы" in body,
      "Director upgrade gate not visible")
check('name="action" value="staging_upgrade_schema"' in body,
      "Upgrade form missing")
status,body,_=request({"csrf":csrf(body),"action":"staging_upgrade_schema"})
check(status==200 and "резервную копию" in body.lower(),
      "Missing backup consent unexpectedly allowed schema changes")
status,body,_=request({"csrf":csrf(body),"action":"staging_upgrade_schema",
                       "backup_confirmed":"YES"})
check(status==302, "Approved test-only migration failed")
status,body,_=request()
check(status==200 and "Обновление тестовой базы" not in body,
      "Upgrade screen remains after successful migration")
print("Staging upgrade HTTP PASS: director login, backup confirmation, migration, normal page")
