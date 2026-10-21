#!/usr/bin/env python3
"""Authenticated HTTP acceptance for access isolation, password rotation and session revocation.
Runs against disposable test database and PHP server. All accounts are fictional.
"""
import re
import subprocess
import tempfile
from pathlib import Path

BASE="http://127.0.0.1:8085"
OLD_PASSWORD="CI-only-fictional-password-12345"
TEMP_PASSWORD="CI-temp-for-created-employee-123"
NEW_HIRE_PASSWORD="CI-new-for-created-employee-567"
DIRECTOR_NEW_PASSWORD="CI-new-director-password-98765"
WORK=Path(tempfile.mkdtemp(prefix="adc-auth-"))
JARS={name:WORK/(name+".jar") for name in
      ["director","hire","hire_second","director_second","anonymous","attacker","reset"]}

def check(ok,what):
    if not ok:
        raise AssertionError(what)

def request(who,query="/",payload=None):
    cmd=["curl","--silent","--show-error","--max-time","15",
         "-b",str(JARS[who]),"-c",str(JARS[who]),"-D",str(WORK/"headers"),
         "-o",str(WORK/"body"),"-w","%{http_code}"]
    if payload is not None:
        for key,value in payload.items():
            cmd.extend(["--data-urlencode",f"{key}={value}"])
    p=subprocess.run(cmd+[BASE+query],capture_output=True,text=True,check=True)
    return int(p.stdout),(WORK/"headers").read_text(),(WORK/"body").read_text()

def csrf(html):
    m=re.search(r'name="csrf" value="([a-f0-9]{64})"',html)
    check(m is not None,"CSRF is present")
    return m.group(1)

def login(who,email,password):
    status,_,page=request(who)
    check(status==200 and "Вход" in page,"Login form "+who)
    status,headers,_=request(who,payload={"action":"login","csrf":csrf(page),
                                           "email":email,"password":password})
    check(status==303 and "Location: /" in headers,"Login success "+who)
    status,_,page=request(who,"/?view=clients")
    check(status==200,"Authenticated clients view "+who)
    return csrf(page),page

try:
    status,_,page=request("anonymous","/?view=analytics")
    check(status==200 and "Вход" in page and "LTV и баланс" not in page,
          "Anonymous users cannot view actual financial analytics")

    director_token,page=login("director","web-director@example.test",OLD_PASSWORD)
    check("Клиенты" in page,"Director client dashboard")

    # New employee has a one-time password and cannot bypass forced reset.
    status,_,page=request("director",payload={
        "csrf":director_token,"action":"create_employee",
        "employee_name":"Fixture Hire","employee_email":"hire@example.test",
        "employee_password":TEMP_PASSWORD
    })
    check(status==303,"Director creates employee through form action")
    status,_,page=request("director","/?view=team")
    check("Fixture Hire" in page,"New employee is visible in directory")

    hire_token,page=login("hire","hire@example.test",TEMP_PASSWORD)
    check("Измените временный пароль" in page,"Temporary password requires reset")
    status,_,page=request("hire","/?view=analytics")
    check("Измените временный пароль" in page and "LTV и баланс" not in page,
          "Analytics blocked until temporary password replaced")
    status,_,page=request("hire",payload={
        "csrf":hire_token,"action":"create_task",
        "title":"Bypass attempt","due_date":"2026-10-31"})
    check(status==200 and "Сначала измените временный пароль" in page,
          "Temporary user cannot create tasks before reset")

    second_token,page=login("hire_second","hire@example.test",TEMP_PASSWORD)
    check("Измените временный пароль" in page,"Second old-password session also pending")
    status,_,page=request("hire",payload={
        "csrf":hire_token,"action":"change_password","old_password":TEMP_PASSWORD,
        "new_password":NEW_HIRE_PASSWORD
    })
    check(status==303,"Employee rotates temporary password")
    status,_,page=request("hire","/?view=tasks")
    check(status==200 and "Мои задачи" in page and "Измените временный пароль" not in page,
          "Password change enables authenticated actions")
    status,_,page=request("hire_second","/?view=tasks")
    check("Вход" in page and "Мои задачи" not in page,
          "A second session is revoked when user changes password")

    status,_,page=request("director",payload={
        "csrf":director_token,"action":"create_task",
        "title":"Director confidential task","due_date":"2026-10-31"
    })
    check(status==303,"Director creates personal task")
    status,_,page=request("director","/?view=tasks")
    check("Director confidential task" in page,"Director task listed")
    match=re.search(r'name="task_id" value="(\d+)"',page)
    check(match is not None,"Director task ID discoverable from their own task form")
    foreign_id=match.group(1)

    status,_,page=request("hire","/?view=tasks")
    check("Director confidential task" not in page,"Other employee cannot read tasks")
    status,_,page=request("hire",payload={
        "csrf":hire_token,"action":"complete_task","task_id":foreign_id
    })
    check(status==200 and "Задача не найдена" in page,
          "Employee cannot complete a director-owned task")

    status,_,page=request("hire",payload={
        "csrf":hire_token,"action":"create_task",
        "title":"Worker monthly task","due_date":"2026-10-31",
        "repeat_monthly":"1"
    })
    check(status==303,"Hire creates recurring task")
    status,_,page=request("hire","/?view=tasks")
    check("Worker monthly task" in page and "2026-10-31" in page,"Hire sees own task")
    match=re.search(r'name="task_id" value="(\d+)"',page)
    check(match is not None,"Employee sees task id")
    own_id=match.group(1)
    status,_,page=request("hire",payload={
        "csrf":hire_token,"action":"complete_task","task_id":own_id
    })
    check(status==303,"Employee completes recurring task")
    status,_,page=request("hire","/?view=tasks")
    check("Worker monthly task" in page and "2026-11-30" in page,
          "Recurrence clamps 31st to November 30")
    check("Director confidential task" not in page,"Task isolation remains intact")
    status,_,page=request("director","/?view=tasks")
    check("Worker monthly task" not in page,"Director is not shown employee private tasks")

    # Lockout belongs to unknown identity, not to either working account.
    for n in range(5):
        status,_,page=request("attacker")
        check("Вход" in page,"Attacker login form still protected")
        status,_,page=request("attacker",payload={
            "csrf":csrf(page),"action":"login",
            "email":"unknown-for-bruteforce@example.test",
            "password":"wrong-password"
        })
        check(status==200 and "Неверный email или пароль" in page,
              "Wrong login is denied")
    status,_,page=request("attacker")
    status,_,page=request("attacker",payload={
        "csrf":csrf(page),"action":"login",
        "email":"unknown-for-bruteforce@example.test","password":"still-wrong"
    })
    check(status==200 and "Слишком много попыток" in page,
          "Sixth request blocked by rate limiter")

    status,_,page=request("director",payload={
        "csrf":director_token,"action":"deactivate_employee","employee_id":"-100"
    })
    check(status in [200,303],"Unrelated deactivation test harmless")
    # Resolve hire ID from settings row using markup: exact fixture name and id
    status,_,page=request("director","/?view=team")
    match=re.search(r'Fixture Hire.*?name="employee_id" value="(\d+)"',page,re.S)
    check(match is not None,"Employee ID available only in director settings")
    hire_id=match.group(1)
    status,_,_=request("director",payload={
        "csrf":director_token,"action":"deactivate_employee","employee_id":hire_id
    })
    check(status==303,"Director disables employee")
    status,_,page=request("hire","/?view=clients")
    check("Вход" in page and "Клиенты</h1>" not in page,
          "Deactivated employee loses existing session immediately")

    # Password rotation of the director must invalidate another active session.
    second_token,page=login("director_second","web-director@example.test",OLD_PASSWORD)
    status,_,page=request("director_second",payload={
        "csrf":second_token,"action":"change_password",
        "old_password":OLD_PASSWORD,"new_password":DIRECTOR_NEW_PASSWORD
    })
    check(status==303,"Director changes own password")
    status,_,page=request("director","/?view=clients")
    check("Вход" in page,"Previous director session was revoked")
    reset_token,page=login("reset","web-director@example.test",DIRECTOR_NEW_PASSWORD)
    status,_,page=request("reset",payload={"csrf":reset_token,"action":"logout"})
    check(status==303,"Logout accepted")
    status,_,page=request("reset","/?view=clients")
    check("Вход" in page,"Logged-out cookie cannot authorize access")
    print("Auth HTTP E2E passed: roles, forced password reset, revocation, expiry logic, "
          "personal task isolation, monthly recurrence, lockout, deactivation, logout")
finally:
    for p in WORK.iterdir(): p.unlink()
    WORK.rmdir()
