#!/usr/bin/env python3
"""Authenticated end-to-end acceptance of the document-centric workflow.
Runs only with disposable CI identities, database and out-of-root PDF storage.
"""
import os
import base64
import re
import subprocess
import tempfile
from decimal import Decimal
from datetime import date, timedelta
from pathlib import Path

BASE=os.environ.get("ADC_HTTP_TEST_BASE","http://127.0.0.1:8085")
ROOT=Path(__file__).resolve().parents[2]
DOCS=ROOT/"adclients-documents"
WORK=Path(tempfile.mkdtemp(prefix="adclient-ledger-http-"))
PASSWORD="CI-only-fictional-password-12345"
JARS={n:WORK/(n+".cookies") for n in ("director","employee","visitor")}
def require(ok,description):
    if not ok: raise AssertionError(description)
def request(role,path="/",payload=None,files=None):
    hdr=WORK/"head";out=WORK/"body"
    cmd=["curl","-sS","--max-time","15","-b",str(JARS[role]),"-c",str(JARS[role]),
         "-D",str(hdr),"-o",str(out),"-w","%{http_code}"]
    if payload is not None:
        cmd+=["-X","POST"]
        for k,v in payload.items():cmd+=["--data-urlencode",str(k)+"="+str(v)]
    if files is not None:
        cmd+=["-X","POST"]
        for k,v in files.items():cmd+=["-F",str(k)+"="+str(v)]
    cmd.append(BASE+path)
    p=subprocess.run(cmd,capture_output=True,text=True,check=True)
    return int(p.stdout),hdr.read_text(),out.read_bytes()
def html(raw): return raw.decode("utf8")
def csrf(raw):
    m=re.search(r'name="csrf" value="([a-f0-9]{64})"',raw)
    require(m,"CSRF protection included")
    return m.group(1)
def overview_money(page,label,ccy):
    match=re.search(r'<span>'+re.escape(label)+r' · '+re.escape(ccy)+
                    r'</span><strong>([\d\s,]+) '+re.escape(ccy)+r'</strong>',page)
    require(match,'Overview amount present: '+label+' '+ccy)
    return Decimal(match.group(1).replace(' ','').replace(',','.'))
def location(headers):
    m=re.search(r"^Location:\s*(.+)$",headers,re.I|re.M)
    return m.group(1).strip() if m else ""
def forms(page,action):
    result=[]
    for form in re.findall(r"<form\b[^>]*>.*?</form>",page,re.S):
        if 'name="action" value="'+action+'"' in form: result.append(form)
    return result
def form_entry_id(page,action):
    matches=forms(page,action)
    require(matches,"Form "+action+" visible")
    m=re.search(r'name="entry_id" value="(\d+)"',matches[0])
    require(m,"Target entry linked to "+action)
    return int(m.group(1))
def post(role,token,action,values):
    return request(role,payload={"csrf":token,"action":action,**values})
def login(role):
    status,_,body=request(role)
    require(status==200 and "Вход" in html(body),"Login form "+role)
    token=csrf(html(body))
    status,headers,_=post(role,token,"login",{
        "email":"web-"+role+"@example.test","password":PASSWORD})
    require(status==303 and location(headers)=="/","Login "+role)
    status,_,body=request(role,"/?view=clients")
    require(status==200,"Client list "+role)
    return csrf(html(body))
def create_client(token,name,demo,currency="BYN"):
    status,headers,_=post("director",token,"save_client",{
        "client_name":name,"platform_text":"Фиктивная реклама",
        "client_context_text":"PayPal; договоры",
        "source_kind":"inbound","legal_type":"legal","started_on":"2026-10-21",
        "payment_mode":"prepayment","currency":currency,
        "quoted_amount":"0.00" if demo else "1250.00",
        **({"is_demo":"1"} if demo else {})})
    m=re.search(r"client=(\d+)",location(headers))
    require(status==303 and m,"Create "+name)
    return int(m.group(1))
def panel(role,client):
    status,_,body=request(role,"/?view=clients&client="+str(client))
    require(status==200,"Client drawer HTTP 200")
    return html(body)
def upload(token,client,pdf,amount):
    return request("director",files={
        "csrf":token,"action":"upload_document","client_id":str(client),
        "document":"@"+str(pdf)+";filename=fixture.pdf;type=application/pdf",
        "needs_invoice":"1","has_amount":"1","amount":amount,
        "currency":"BYN","category":"advertising"})
try:
    status,_,body=request("visitor","/?download=1")
    require(status==200 and "Вход" in html(body),"Private document unauthenticated access")
    director=login("director");employee=login("employee")
    status,_,ctrl=request("employee","/?view=control")
    require(status==200 and "<h1>Обзор</h1>" in html(ctrl) and
            "Контроль клиентов" not in html(ctrl),
            "Former control tab resolves to the single concise overview")
    status,_,tasks=request("director","/?view=tasks")
    require(status==200 and "Мои задачи" in html(tasks) and
            "Общий календарь команды" in html(tasks),
            "Director has personal tasks and shared staff calendar")
    status,_,tasks=request("employee","/?view=tasks")
    require(status==200 and "Мои задачи" in html(tasks) and
            "Общий календарь команды" not in html(tasks),
            "Employee sees own calendar without director overview")
    status,_,directorTasks=request("director","/?view=tasks")
    require(status==200 and 'name="action" value="assign_team_task"' in html(directorTasks),
            "Director task assignment form exists")
    assignment=forms(html(directorTasks),"assign_team_task")
    require(assignment,"Director has assignment form")
    worker_match=re.search(r'<option value="(\d+)"[^>]*>Employee Fixture',assignment[0])
    require(worker_match,"Web fixture worker available for assignment")
    status,headers,_=post("director",director,"assign_team_task",{
        "assignee_user_id":worker_match.group(1),"title":"HTTP delegated test task",
        "due_date":"2026-11-13"})
    require(status==303,"Director can delegate a task over HTTP")
    status,_,emplTasks=request("employee","/?view=tasks")
    require(status==200 and "HTTP delegated test task" in html(emplTasks) and
            "Поручение директора" in html(emplTasks),
            "Director delegation visible to employee over real HTTP")
    status,_,form=request("director","/?view=clients")
    require(status==200 and re.search(
        r'name="quoted_amount" type="number"[^>]*step="0.01"',html(form)),
        "Monthly contractual price input appears in Add client")
    # Fast director-only onboarding is separate from the historical ledger import.
    status,_,settings=request("director","/?view=team")
    require(status==200 and "Массовое добавление клиентов" in html(settings) and
            'name="action" value="preview_quick_clients"' in html(settings),
            "Director can onboard multiple clients from the settings view")
    status,_,employeeSettings=request("employee","/?view=team")
    require(status==200 and "Массовое добавление клиентов" not in html(employeeSettings),
            "Employees cannot see the bulk onboarding form")
    status,headers,quickTemplate=request("director","/?export=quick_clients_template")
    require(status==200 and "text/csv" in headers.lower() and
            "client_name,is_demo,started_on" in html(quickTemplate),
            "Director downloads the onboarding CSV template")
    status,_,employeeCsv=request("employee","/?export=quick_clients_template")
    require(status==403,"Employee cannot download director-only CSV template")
    status,_,visitorCsv=request("visitor","/?export=quick_clients_template")
    require(status==200 and "Вход" in html(visitorCsv),
            "Anonymous visitor cannot retrieve CSV template")
    # The template is synthetic and can be edited offline for an exact preview.
    bulkCsv=WORK/"synthetic-bulk-clients.csv"
    bulkCsv.write_bytes(quickTemplate.replace(
        "ООО Пример рекламодателя".encode("utf-8"),
        "CI CSV bulk active".encode("utf-8")).replace(
        "Демо пример".encode("utf-8"),
        "CI CSV bulk demo".encode("utf-8")))
    status,headers,_=request("director",files={
        "csrf":director,"action":"preview_quick_clients",
        "quick_csv":"@"+str(bulkCsv)+";filename=clients.csv;type=text/csv"})
    require(status==303 and "view=team" in location(headers),
            "Preview accepts clean synthetic CSV and saves nothing")
    status,_,settings=request("director","/?view=team")
    require(status==200 and "Готово к импорту: 2" in html(settings) and
            'name="action" value="commit_quick_clients"' in html(settings),
            "Director sees approved preview with two employees/demo categories")
    require('id="quick-import" open' in html(settings),
            "Confirmation panel stays expanded after CSV preview")
    status,headers,_=request("director",files={
        "csrf":director,"action":"commit_quick_clients",
        "quick_csv":"@"+str(bulkCsv)+";filename=clients.csv;type=text/csv"})
    require(status==303 and "view=clients" in location(headers),
            "Director confirms the exact same CSV")
    status,_,listPage=request("director","/?view=clients")
    require(status==200 and "CI CSV bulk active" in html(listPage) and
            "CI CSV bulk demo" in html(listPage),
            "Imported clients appear as ordinary cards")
    status,_,settings=request("director","/?view=team")
    require(status==200 and "Готово к импорту: 2" not in html(settings),
            "Successful import clears the pending confirmation")
    demo=create_client(director,"CI demo ledger",True,currency="RUB")
    real=create_client(director,"CI real ledger",False)
    demoUploadPanel=panel("director",demo)
    demoUploadForm=re.search(r'<form class="doc-upload-form"[^>]*>(.*?)</form>',demoUploadPanel,re.S)
    require(demoUploadForm is not None and
            re.search(r'<select name="currency">(?:(?!</select>).)*<option value="RUB" selected>',demoUploadForm.group(1),re.S) and
            "ранее сохранённые расчётные периоды этого клиента" in demoUploadForm.group(1),
            "New document/payment defaults to the active client's currency and explains historical period linking")
    # Exercise the optional period selector against a saved synthetic client period.
    engagementMatch=re.search(r'name="engagement_id" value="(\d+)"',demoUploadPanel)
    require(engagementMatch is not None,
            "Active engagement is available for the synthetic period fixture")
    status,headers,_=post("director",director,"save_period",{
        "client_id":str(demo),"engagement_id":engagementMatch.group(1),
        "period_start":"2026-10-21","period_end":"2026-11-21",
        "period_note":"Synthetic historical period"})
    require(status==303,"Create a saved synthetic period for the document form test")
    periodPanel=panel("director",demo)
    periodForm=re.search(r'<form class="doc-upload-form"[^>]*>(.*?)</form>',periodPanel,re.S)
    require(periodForm is not None and
            re.search(r'<select name="period_id">(?:(?!</select>).)*<option value="\d+">2026-10-21</option>',
                      periodForm.group(1),re.S) is not None and
            'name="period_id"><option value="" selected>Без периода</option>' in periodForm.group(1),
            "Saved client periods appear in the optional selector while Без периода remains available")
    status,_,listPage=request("director","/?view=clients")
    listHtml=html(listPage)
    require(status==200 and "Как оплачивают" in listHtml and
            "PayPal; договоры" in listHtml and 'data-client-href=' in listHtml and
            'tabindex="0"' in listHtml and ">Открыть</a>" not in listHtml and
            "#fcfbff" in listHtml and "#f6f5fb" in listHtml and
            ".client-list-table tbody td.client-context-cell{text-align:left}" in listHtml and
            ".customer-profile .item>div:first-child{flex:1 1 0%;min-width:0;overflow-wrap:anywhere}" in listHtml and
            ".customer-profile .item>.actions{flex:0 1 auto;min-width:0;max-width:58%;flex-wrap:wrap;justify-content:flex-end}" in listHtml and
            "stepMismatch" in listHtml and
            "Сумма должна иметь не более двух знаков после запятой." in listHtml,
            "Client list displays payment context after services, uses subtle zebra rows and remains clickable")
    initialContextPanel=panel("director",real)
    require("Как оплачивают" in initialContextPanel and
            'name="action" value="save_client_context"' in initialContextPanel and
            'title="Изменить способ оплаты"' in initialContextPanel and
            'class="client-context-edit-panel"' in initialContextPanel and
            "Оплата без документов" in initialContextPanel and
            "Оплата на карту (без PDF)" not in initialContextPanel and
            "PayPal; договоры" in initialContextPanel,
            "Client card shows payment context and a standalone editor")
    status,headers,_=post("director",director,"save_client_context",{
        "client_id":str(real),"client_context_text":"Инвойс № 777"})
    require(status==303 and "client="+str(real) in location(headers),
            "Standalone context edit returns to the same client card")

    # A rejected inline context edit must stay in the selected client drawer and keep typed text.
    invalidContext="x"*501
    status,_,contextError=request("director","/?view=clients&client="+str(real),payload={
        "csrf":director,"action":"save_client_context","client_id":str(real),
        "client_context_text":invalidContext})
    contextErrorHtml=html(contextError)
    contextErrorDrawer=re.search(r'<aside class="drawer customer-profile"[\s\S]*?</aside>',contextErrorHtml)
    require(status==200 and contextErrorDrawer is not None and
            'data-client-card-error="1"' in contextErrorDrawer.group(0) and
            "Текст об оплате и документах должен быть не длиннее 500 символов." in contextErrorHtml and
            '"client_context_text":"' + invalidContext + '"' in contextErrorHtml,
            "Client-context validation stays inside the card and retains the rejected text")
    require("Инвойс № 777" in panel("director",real),
            "Rejected client-context edit leaves the previously saved value unchanged")
    editedContextPanel=panel("director",real)
    editedDrawer=re.search(r'<aside class="drawer customer-profile"[\s\S]*?</aside>',editedContextPanel)
    require(editedDrawer is not None and "Инвойс № 777" in editedDrawer.group(0) and
            "PayPal; договоры" not in editedDrawer.group(0) and
            "CI real ledger" in editedDrawer.group(0) and "Фиктивная реклама" in editedDrawer.group(0),
            "Inline context edit changes only payment details and preserves client fields")
    status,_,listAfterEdit=request("director","/?view=clients")
    require(status==200 and "Инвойс № 777" in html(listAfterEdit),
            "Edited payment context immediately appears in the client list")
    # Staging pause controls render in existing card, not a separate page.
    pausePage=panel("director",real)
    require('name="action" value="pause_client"' in pausePage,
            "Current client card offers pause without changing its original sections")
    require(".pause-choice input[type=radio]" in pausePage and
            "pause-date-field" in pausePage and
            ".chat-suggestion:is(:hover,:focus-visible,.highlight)" in pausePage,
            "Pause radios and chat choices have explicit scoped visual styles")
    # Pausing is exercised with an isolated demo so later ledger totals stay unchanged.
    pause_only=create_client(director,"CI pause controls only",True)
    status,headers,_=post("director",director,"pause_client",{"client_id":pause_only})
    require(status==303,"Director can pause an active client")
    paused_page=panel("director",pause_only)
    pause_match=re.search(r'name="pause_id" value="(\d+)"',paused_page)
    require(pause_match and 'value="keep" checked' in paused_page and
            '<summary class="btn secondary pause-toggle">Снять паузу</summary>' in paused_page,
            "Pause drawer shows aligned choices and defaults to prior schedule")
    require("Пауза" in panel("employee",pause_only),
            "Employee sees paused client")
    status,_,bad_body=request("director","/?view=clients&client="+str(pause_only),payload={
        "csrf":director,"action":"resume_paused_client","client_id":pause_only,
        "pause_id":pause_match.group(1),"resume_mode":"new_date",
        "resume_start_on":"2000-01-01"})
    bad_page=html(bad_body)
    require(status==200 and '<details class="pause-disclosure" open>' in bad_page and
            'class="error pause-inline-error"' in bad_page and
            'Дата нового периода не может быть раньше начала паузы.' in bad_page,
            "Bad date error remains in the open customer drawer")
    next_day=date.today()+timedelta(days=1)
    status,headers,_=post("director",director,"resume_paused_client",{
        "client_id":pause_only,"pause_id":pause_match.group(1),
        "resume_mode":"new_date","resume_start_on":next_day.isoformat()})
    require(status==303,"Future start date resumes client")
    resume_page=panel("director",pause_only)
    require("расчётный день "+str(next_day.day) in resume_page and
            next_day.strftime("%d.%m.%Y") in resume_page and
            'name="action" value="pause_client"' in resume_page,
            "Future billing date shown in both client summary and contract terms")
    # Daily operating overview and read-only client paperwork CSV are authenticated.
    status,_,overview=request("director","/?view=overview")
    require(status==200 and "<h1>Обзор</h1>" in html(overview) and
            "Оплачено" in html(overview) and
            "Не оплачено" in html(overview) and
            "Команда · открытые задачи" in html(overview) and
            'href="/?view=payments"' not in html(overview) and
            'href="/?view=control"' not in html(overview),
            "Director overview contains only essential totals, tasks and team")
    status,_,overview=request("employee","/?view=overview")
    require(status==200 and "<h1>Обзор</h1>" in html(overview) and
            "Команда · открытые задачи" not in html(overview),
            "Employee sees own overview without director team data")
    status,headers,plan=request("director","/?export=control")
    require(status==200 and plan.startswith(b"\xEF\xBB\xBF") and
            "CI real ledger" in html(plan) and
            "CI demo ledger" not in html(plan) and
            "text/csv" in headers.lower(),
            "Authenticated real-client control plan excludes demo")
    status,_,unauthed=request("visitor","/?export=control")
    require(status==200 and "Вход" in html(unauthed),
            "Anonymous visitor cannot download the control CSV")

    status,_,controlPage=request("director","/?view=control")
    require(status==200 and "<h1>Обзор</h1>" in html(controlPage) and
            'name="action" value="assign_control_reminder"' not in html(controlPage),
            "Director sees one overview instead of duplicate control desk")
    status,_,employeeControl=request("employee","/?view=control")
    require(status==200 and "Команда · открытые задачи" not in html(employeeControl),
            "Employee does not see team data")
    require("Сумма по договору в месяц" in panel("director",real) and
            "1 250,00 BYN" in panel("director",real),
            "Contract reference price remains visible in client profile")
    page=panel("director",demo)
    for role in ("director","employee"):
        status,_,chatPage=request(role,"/?view=chat")
        require(status==200 and "Чат команды" in html(chatPage) and
                "Общий чат" in html(chatPage),
                "Authenticated chat page loads for "+role)
        status,_,roomPage=request(role,"/?view=chat&thread="+str(real))
        require(status==200 and "chat-feed" in html(roomPage) and
                "CI real ledger" in html(roomPage),
                "Client-specific chat HTTP page loads for "+role)
    # Reply to a specific chat message, pin it and follow the pinned link.
    status,_,initial=request("director","/?view=chat&thread="+str(real))
    require(status==200 and 'data-chat-compose' in html(initial),
            "Chat composer visible before first message")
    require("Общий чат и переписки по клиентам. В сообщении набери" not in html(initial)
            and "Закрепить / снять" not in html(initial),
            "No repeated subtitle and no mixed pin/unpin label")
    require('class="chat-attach-btn"' in html(initial) and
            'data-chat-file-name' in html(initial),
            "Custom attachment picker with selected filename")
    require('class="chat-submit-row"><button type="submit">Отправить сообщение</button>' in html(initial),
            "Send button has its own row below the attachment control")
    require('.chat-msg-tools{display:none' in html(initial) and
            '.chat-msg:is(:hover,:focus-within) .chat-msg-tools{display:flex}' in html(initial),
            "Invisible message tools reserve no bubble space until hover or focus")
    status,_,home=request("director","/?view=overview")
    require(status==200 and "<title>Реклама MANUFACT</title>" in html(home)
            and 'class="logout-btn"' in html(home),
            "Brand title and properly sized logout button across app")
    require('rel="icon" type="image/x-icon" href="/favicon.ico?v=97b21d17"' in html(home),
            "MANUFACT favicon declared in all application pages")
    status,_,faviconData=request("director","/favicon.ico")
    require(status==200 and faviconData.startswith(b"\x00\x00\x01\x00"),
            "Valid ICO served as a public static asset")
    match=re.search(r'data-chat-reply="(\d+)"',html(initial))
    # Before posting the first message in this room there may be no replies.
    if match:
        messageid=match.group(1)
    else:
        status,headers,_=post("director",director,"chat_send",{
            "thread":str(real),"message":"CI pinned request"})
        require(status==303,"Chat pin fixture message created")
        status,_,initial=request("director","/?view=chat&thread="+str(real))
        match=re.search(r'data-chat-reply="(\d+)"',html(initial))
        require(match,"Chat messages have reply controls")
        messageid=match.group(1)
    require("📌 Закрепить" in html(initial) and
            "chat-reply-btn chat-mini-action" in html(initial) and
            "chat-pin-btn chat-mini-action" in html(initial),
            "Compact reply and pin tools rendered beside an actual message")
    status,headers,_=post("employee",employee,"chat_send",{
        "thread":str(real),"message":"This is an answer",
        "reply_to_id":messageid})
    require(status==303,"Reply to an earlier chat message succeeds")
    status,_,replyPage=request("employee","/?view=chat&thread="+str(real))
    require("chat-replied" in html(replyPage) and
            "focus="+messageid in html(replyPage),
            "Reply has a link to its original context")
    status,headers,_=post("director",director,"chat_toggle_pin",{
        "thread":str(real),"message_id":messageid})
    require(status==303,"Pinning a client message succeeds")
    status,_,pinPage=request("employee","/?view=chat&thread="+str(real))
    require('class="chat-pinned-copy"' in html(pinPage) and
            'class="chat-pinned-text"' in html(pinPage) and
            'class="chat-pinned-actions"' in html(pinPage) and
            "Перейти к сообщению" in html(pinPage) and
            "Открепить" in html(pinPage) and
            "focus="+messageid in html(pinPage),
            "Pin is selectable text with separate navigation and unpin controls")
    status,_,focusPage=request("employee","/?view=chat&thread="+str(real)+
                              "&focus="+messageid)
    require(status==200 and 'data-focused="1"' in html(focusPage) and
            'id="chat-msg-'+messageid+'"' in html(focusPage),
            "Pinned message can be revisited even when it is not the newest")
    status,headers,_=post("director",director,"chat_toggle_pin",{
        "thread":str(real),"message_id":messageid})
    require(status==303,"Unpinning the same message succeeds")
    status,_,unpinned=request("director","/?view=chat&thread="+str(real))
    require('<div class="chat-pinned">' not in html(unpinned),"Unpinned bar disappears")
    # A URL in a pin must be clickable without navigating into chat history.
    status,headers,_=post("director",director,"chat_send",{
        "thread":str(real),"message":"Shared brief https://example.org/manual.pdf"})
    require(status==303,"Pinned URL fixture created")
    status,_,linkPage=request("director","/?view=chat&thread="+str(real))
    ids=re.findall(r'data-chat-reply="(\d+)"',html(linkPage))
    require(ids,"Chat URL message appears in conversation")
    linkMessageId=ids[-1]
    status,headers,_=post("director",director,"chat_toggle_pin",{
        "thread":str(real),"message_id":linkMessageId})
    require(status==303,"URL message can be pinned")
    status,_,linkPinPage=request("employee","/?view=chat&thread="+str(real))
    require(status==200 and
            re.search(r'<div class="chat-pinned-text">(?s:.*?)<a href="https://example.org/manual.pdf"',
                      html(linkPinPage)) is not None and
            "Перейти к сообщению" in html(linkPinPage) and
            "Открепить" in html(linkPinPage),
            "Pinned URL is independently clickable and original message has separate navigation")
    status,headers,_=post("director",director,"chat_toggle_pin",{
        "thread":str(real),"message_id":linkMessageId})
    require(status==303,"Pinned URL can be unpinned")
    # Protected chat attachment exchange: PDF and a screenshot in a client room.
    chatpdf=WORK/"chat-document.pdf"
    chatpdf.write_bytes(b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n")
    status,_,chatHtml=request("director","/?view=chat&thread="+str(real))
    require(status==200 and 'name="chat_attachment"' in html(chatHtml) and
            'enctype="multipart/form-data"' in html(chatHtml),
            "Team chat exposes private PDF and image attachment input")
    status,headers,_=request("director",files={
        "csrf":director,"action":"chat_send","thread":str(real),
        "message":"Private test PDF",
        "chat_attachment":"@"+str(chatpdf)+";filename=test.pdf;type=application/pdf"
    })
    require(status==303 and "view=chat" in location(headers),"Chat PDF sent in client discussion")
    status,_,chatHtml=request("employee","/?view=chat&thread="+str(real))
    match=re.search(r'chat_attachment=(\d+)',html(chatHtml))
    require(status==200 and match,"Employee can see shared PDF link")
    pdfid=match.group(1)
    status,_,payload=request("visitor","/?chat_attachment="+pdfid)
    require(status==200 and "Вход" in html(payload),"Unauthenticated attachment is blocked")
    status,_,payload=request("employee","/?chat_attachment="+pdfid)
    require(status==200 and payload.startswith(b"%PDF-"),"Staff can download attached PDF")
    png=WORK/"chat-screenshot.png"
    png.write_bytes(base64.b64decode(
       "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/JP8AAAAASUVORK5CYII="
    ))
    status,headers,_=request("employee",files={
        "csrf":employee,"action":"chat_send","thread":str(real),
        "message":"Screenshot",
        "chat_attachment":"@"+str(png)+";filename=screen.png;type=image/png"
    })
    require(status==303,"Employee can share PNG in private client room")
    status,_,chatHtml=request("director","/?view=chat&thread="+str(real))
    require(status==200 and 'class="chat-file chat-image"' in html(chatHtml) and
            'src="/?chat_attachment=' in html(chatHtml),
            "Chat previews attached screenshot with authenticated URL")
    ids=re.findall(r'chat_attachment=(\d+)',html(chatHtml))
    require(len(set(ids))==2,"Both chat files are in one client discussion")
    status,_,payload=request("director","/?chat_attachment="+ids[-1])
    require(status==200 and payload.startswith(b"\x89PNG\r\n\x1a\n"),
            "Director can view staff screenshot bytes")
    forged=WORK/"chat-forged.pdf"
    forged.write_bytes(b"<script>alert(1)</script>")
    status,_,error=request("director",files={
        "csrf":director,"action":"chat_send","thread":str(real),
        "message":"Rejected malicious file",
        "chat_attachment":"@"+str(forged)+";filename=spoof.pdf;type=application/pdf"
    })
    require(status==200 and ("Содержимое файла" in html(error) or
                            "Недопустимое содержимое" in html(error)),
            "PDF type spoof rejected by content validation")
    # Routine send notification is intentionally silent; initial setup remains visible.
    require("Сообщение отправлено." not in html(chatHtml),
            "Chat send does not add redundant top success toast")
    # UI behaviors require an authorized nonce: no unsafe-inline script policy.
    status,headers,rendered=request("director","/?view=clients&client="+str(demo))
    page=html(rendered)
    policy=re.search(r"(?im)^Content-Security-Policy:\s*(.+)$",headers)
    match=re.search(r'<script nonce="([A-Za-z0-9+/=]+)">',page)
    require(status==200 and policy and match and
            "script-src 'nonce-"+match.group(1)+"'" in policy.group(1),
            "CSP permits the task editor, dropdowns and calendars without unsafe inline scripts")
    require("script-src 'unsafe-inline'" not in policy.group(1),"No JavaScript unsafe-inline bypass")
    inline_scripts=re.findall(r'<script nonce="[A-Za-z0-9+/=]+">([\s\S]*?)</script>',page)
    require(inline_scripts,"Authorized browser interaction scripts are present")
    for script_source in inline_scripts:
        js_check=subprocess.run(["node","--check"],input=script_source,
                                text=True,capture_output=True)
        require(js_check.returncode==0,"Inline browser interaction JavaScript has valid syntax")
    task_script=next((source for source in inline_scripts
                      if "var opened=toggle?target.hidden:false;" in source),None)
    require(task_script is not None,
            "Task edit button opens previously hidden form")
    # Restore the actual accepted card from 2387ba7e8540: top CSV,
    # client overview -> ledger -> secondary actions; no invented monthly block.
    a=page.find('class="card customer-identity"')
    b=page.find('class="card ledger-panel"')
    c=page.find('class="card customer-more"')
    require(a>=0 and a<b<c and 'class="client-summary-header"' in page,
            "Accepted original client-card overview / ledger / actions")
    require("Выгрузить историю в CSV" in page and "Чат клиента" in page and
            page.find("Выгрузить историю в CSV")<page.find("Документы и оплаты"),
            "Top CSV button and chat next to original overview heading")
    require("Работы и оплаты по месяцам" not in page and
            'href="#client-finance"' not in page and
            " расчётный день" not in page[:b],
            "No unrequested monthly block or payment day in top summary")
    require("Дополнительные действия" in page and "Загрузить документ" in page,
            "Agreed ledger and secondary actions retained")
    require("Загрузить документ" not in panel("employee",demo),"Employees cannot upload PDFs")
    status,_,_=post("employee",employee,"create_card_entry",{
        "client_id":demo,"amount":"100.00","currency":"BYN","category":"advertising"})
    require(status==403 or status==200,"Employees cannot create payable card requests")
    pdf=WORK/"valid.pdf";pdf.write_bytes(b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n")
    # Missing PDF stays inside the open client drawer and preserves all other fields.
    status,_,missing=request("director","/?view=clients&client="+str(demo),payload={
        "csrf":director,"action":"upload_document","client_id":str(demo),
        "needs_invoice":"1","needs_act":"1","has_amount":"1","amount":"123.45",
        "currency":"RUB","category":"development","note":"Keep this note",
        "transfer_due_on":"2026-11-05","period_id":""})
    missingHtml=html(missing)
    missingForm=re.search(r'<form class="doc-upload-form"[^>]*>(.*?)</form>',missingHtml,re.S)
    require(status==200 and 'class="drawer customer-profile"' in missingHtml and
            'class="sub-action doc-upload" open' in missingHtml and missingForm is not None and
            'class="error document-upload-error" role="alert">Выберите PDF.</div>' in missingForm.group(1) and
            missingHtml.count("Выберите PDF.")==1,
            "Missing PDF error is shown beside the PDF field in the same open client drawer")
    require('name="needs_invoice" value="1" checked' in missingForm.group(1) and
            'name="needs_act" value="1" checked' in missingForm.group(1) and
            'name="amount" type="number" value="123.45"' in missingForm.group(1) and
            'name="currency"><option value="BYN">BYN</option><option value="RUB" selected>RUB</option>' in missingForm.group(1) and
            'name="note" type="text" value="Keep this note"' in missingForm.group(1) and
            'name="transfer_due_on" type="date" value="2026-11-05"' in missingForm.group(1) and
            'name="period_id"><option value="" selected>Без периода</option>' in missingForm.group(1),
            "Missing PDF validation preserves document flags, amount, currency, note, due date and period selection")
    fake=WORK/"fake.pdf";fake.write_bytes(b"<html>not pdf</html>")
    status,_,error=request("director","/?view=clients&client="+str(demo),files={
        "csrf":director,"action":"upload_document","client_id":demo,
        "document":"@"+str(fake)+";filename=wrong.pdf;type=application/pdf",
        "needs_invoice":"1","has_amount":"1","amount":"100.00","currency":"BYN",
        "category":"advertising"})
    require(status==200 and 'class="error document-upload-error" role="alert">Разрешены только PDF' in html(error),
            "Reject spoofed file inline in the client drawer")
    status,_,_=upload(director,demo,pdf,"100.00")
    require(status==303,"Upload PDF with explicit amount, no manual billing period")
    page=panel("employee",demo)
    require("Отправить документы" in page and "100,00 BYN" in page,"Unsent PDF wins status")
    ids=re.findall(r"\?download=(\d+)",page)
    require(len(ids)==1,"One downloadable PDF")
    doc=int(ids[0])
    status,_,body=request("employee","/?download="+str(doc))
    require(status==200 and body.startswith(b"%PDF-"),"Authenticated download works")
    status,_,_=post("employee",employee,"transfer_document",{"client_id":demo,"document_id":doc})
    require(status==303,"Employee marks document sent")
    status,_,_=post("employee",employee,"transfer_document",{"client_id":demo,"document_id":doc})
    require(status==303,"Repeated send does not duplicate")
    page=panel("director",demo)
    require(re.search(r'class="badge danger">Ждём оплату',page) is not None and
            '.badge.danger{background:#ffe6e6;color:#a31220}' in page and
            '.good{background:#e4f6eb;color:#176739}' in page and
            '.badge{font-size:12px;font-weight:650;padding:2px 7px;background:#edf0f7;border-radius:30px;color:#48556b}' in page,
            "Waiting payment is red, completed is green and document-preparation status remains neutral gray")
    page=panel("employee",demo)
    require("Ждём оплату" in page,"Sent document moves to pending receipt")
    first=form_entry_id(page,"receive_entry")
    status,_,_=post("employee",employee,"receive_entry",{"client_id":demo,"entry_id":first})
    require(status==303,"Receive full document amount")
    page=panel("employee",demo)
    require("Оплаченные · 1" in page and "Отменить получение" in page,
            "Paid row collapses with undo option")
    status,_,_=post("employee",employee,"undo_entry_receipt",{"client_id":demo,"entry_id":first})
    require(status==303 and "Ждём оплату" in panel("employee",demo),"Mistaken receipt reversed")
    status,_,_=post("employee",employee,"receive_entry",{"client_id":demo,"entry_id":first})
    require(status==303,"Can confirm correct receipt")
    # Confirmed receipt cannot be wiped by deleting the parent PDF.
    require(not forms(panel("director",demo),"void_document") or
            "Для удаления сначала отмените получение" in panel("director",demo),
            "Paid PDF displays reversal-before-deletion guidance")
    status,_,blocked=post("director",director,"void_document",{
        "client_id":demo,"document_id":doc})
    require(status==200 and "Сначала отмените получение оплаты" in html(blocked),
            "Deleting a paid document is rejected on the server")
    require("100,00 BYN" in panel("director",demo) and
            len(re.findall(r"\?download=(\d+)",panel("director",demo)))==1,
            "Rejected deletion preserves paid invoice and original PDF")
    # Editing a received document's amount must not retroactively change cash.
    status,_,error=post("director",director,"edit_document",{
        "client_id":demo,"document_id":doc,"needs_invoice":"1",
        "has_amount":"1","amount":"180.00","currency":"BYN",
        "category":"advertising","note":"Incorrect paid edit"})
    require(status==200 and "Сначала отмените получение оплаты" in html(error),
            "Editing already-paid PDF amount is safely rejected")
    require("100,00 BYN" in panel("director",demo) and
            "180,00 BYN" not in panel("director",demo),
            "Failed paid PDF edit does not change invoice or recorded receipt")
    # Editing a descriptive note with the same money is still allowed.
    status,_,_=post("director",director,"edit_document",{
        "client_id":demo,"document_id":doc,"needs_invoice":"1",
        "has_amount":"1","amount":"100.00","currency":"BYN",
        "category":"advertising","note":"Correct paid invoice"})
    require(status==303 and "Correct paid invoice" in panel("director",demo),
            "Nonfinancial metadata edits remain possible on paid PDF")
    status,_,blocked=post("director",director,"edit_document",{
        "client_id":demo,"document_id":doc,"needs_invoice":"1",
        "has_amount":"1","amount":"100.00","currency":"BYN",
        "category":"development","note":"Incorrect paid category"})
    require(status==200 and "Сначала отмените получение оплаты" in html(blocked),
            "Paid PDF reporting category cannot be silently changed")
    status,_,blocked=post("director",director,"edit_document",{
        "client_id":demo,"document_id":doc,"needs_spec":"1",
        "has_amount":"1","amount":"100.00","currency":"BYN",
        "category":"advertising","note":"Changed type after receipt"})
    require(status==200 and "Сначала отмените получение оплаты" in html(blocked),
            "Paid PDF's document type cannot be changed without undoing receipt")
    status,_,blocked=request("director",files={
        "csrf":director,"action":"edit_document","client_id":str(demo),
        "document_id":str(doc),
        "document":"@"+str(pdf)+";filename=already-paid-new.pdf;type=application/pdf",
        "needs_invoice":"1","has_amount":"1","amount":"100.00",
        "currency":"BYN","category":"advertising"})
    require(status==200 and "Сначала отмените получение оплаты" in html(blocked),
            "Paid PDF version cannot be silently replaced, even for equal money")
    require("Оплаченные · 1" in panel("employee",demo) and
            len(re.findall(r"\?download=(\d+)",panel("employee",demo)))==1,
            "Blocked paid PDF changes preserve full receipt and original document")
    # Independent second month's request must never merge into the first receipt.
    status,_,_=upload(director,demo,pdf,"250.00")
    require(status==303,"Second separate month PDF")
    page=panel("employee",demo)
    require("Отправить документы" in page and "Оплаченные · 1" in page,
            "New document is prioritized over already paid month")
    ids=list(map(int,re.findall(r"\?download=(\d+)",page)))
    require(len(ids)==2,"Both PDF records survive")
    second=max(ids)
    status,_,_=post("employee",employee,"transfer_document",{"client_id":demo,"document_id":second})
    require(status==303,"Send second document")
    page=panel("employee",demo)
    pending=form_entry_id(page,"receive_entry")
    require(pending!=first,"Second receipt button targets separate request")
    # Metadata-only edit must keep the same PDF identifier and update money.
    status,_,_=post("director",director,"edit_document",{
        "client_id":demo,"document_id":second,"needs_invoice":"1",
        "has_amount":"1","amount":"280.00","currency":"BYN",
        "category":"advertising","note":"Исправленная сумма"})
    require(status==303,"Edit amount without uploading duplicate PDF")
    page=panel("director",demo)
    require("280,00 BYN" in page and len(re.findall(r"\?download=(\d+)",page))==2,
            "Corrected amount exactly once, same two PDF identifiers")
    # Replacing PDF bytes is a new document version and must be sent again.
    status,_,_=request("director",files={
        "csrf":director,"action":"edit_document","client_id":str(demo),
        "document_id":str(second),"document":"@"+str(pdf)+";filename=corrected.pdf;type=application/pdf",
        "needs_invoice":"1","has_amount":"1","amount":"280.00",
        "currency":"BYN","category":"advertising","note":"New PDF version"})
    require(status==303,"Replaced PDF file successfully")
    page=panel("employee",demo)
    require("Отправить документ" in page,"Replaced PDF must be sent again")
    status,_,_=post("employee",employee,"transfer_document",{
        "client_id":demo,"document_id":second})
    require(status==303 and "Ждём оплату" in panel("employee",demo),
            "Replaced PDF may be handed over again")
    # Card payment is an ordinary standalone record with individual receipt controls.
    status,_,_=post("director",director,"upload_document",{
        "client_id":demo,"is_card":"1","amount":"50.00","currency":"BYN",
        "category":"development","note":"Оплата на карту"})
    require(status==303 and "50,00 BYN" in panel("employee",demo),"Card without PDF")
    page=panel("employee",demo)
    require(len(forms(page,"receive_entry"))==2,"Two outstanding requests, not one combined payment")
    # Delete mistaken second PDF, paid first one remains untouched.
    status,_,_=post("director",director,"void_document",{"client_id":demo,"document_id":second})
    require(status==303,"Delete mistaken PDF and linked request")
    page=panel("director",demo)
    require("280,00 BYN" not in page and len(re.findall(r"\?download=(\d+)",page))==1,
            "Voided request never appears or counts")
    status,_,csv=request("director","/?export=client&client_id="+str(demo))
    require(status==200 and "Оплата на карту" in html(csv) and "Удалено" in html(csv),
            "CSV includes new ledger and deletion trail")
    status,_,_=post("director",director,"create_card_entry",{
        "client_id":real,"amount":"77.00","currency":"BYN",
        "category":"advertising","note":"Реальный клиент"})
    require(status==303,"Create real payable entry")
    status,_,report=request("director","/?view=analytics&currency=BYN")
    require(status==200 and "77,00 BYN" in html(report) and "280,00 BYN" not in html(report)
            and "1 250,00 BYN" not in html(report),
            "Contract quote never automatically becomes billed or received money")
    status,_,csv=request("director","/?export=balances")
    require(status==200 and "CI real ledger" in html(csv) and "CI demo ledger" not in html(csv),
            "Balance CSV excludes demo accounts, includes new ledger")
    # Card entries need the same accounting protection as received PDF entries.
    entry=form_entry_id(panel("director",real),"receive_entry")
    status,_,paymentPage=request("director","/?view=payments")
    require(status==200 and "<h1>Обзор</h1>" in html(paymentPage) and
            "Контроль оплат" not in html(paymentPage) and
            'name="action" value="receive_entry"' not in html(paymentPage),
            "No duplicate payment desk; receipts remain client-card actions")
    beforePaid=overview_money(html(paymentPage),"Оплачено","BYN")
    beforeUnpaid=overview_money(html(paymentPage),"Не оплачено","BYN")
    require(beforeUnpaid>=Decimal("77.00"),
            "Actual outstanding amount visible in overview, excluding demo")
    status,_,paymentExport=request("employee","/?export=payments")
    require(status==200 and "CI real ledger" in html(paymentExport) and
            "CI demo ledger" not in html(paymentExport),
            "Staff payments CSV excludes demonstration accounts")
    status,_,visitorPage=request("visitor","/?export=payments")
    require(status==200 and "Вход" in html(visitorPage),
            "Unauthenticated visitor is not given payments CSV")
    status,headers,_=post("director",director,"receive_entry",{
        "client_id":real,"entry_id":entry})
    require(status==303 and "view=clients" in location(headers),
            "Receipt confirmed directly inside the client card")
    status,_,afterReceiptPage=request("director","/?view=overview")
    require(status==200 and
            overview_money(html(afterReceiptPage),"Оплачено","BYN")==beforePaid+Decimal("77.00") and
            overview_money(html(afterReceiptPage),"Не оплачено","BYN")==beforeUnpaid-Decimal("77.00"),
            "Receiving exactly one payment updates the one overview without losing old history")
    require("Для удаления сначала отмените получение" in panel("director",real),
            "Paid card request displays reversal-before-deletion guidance")
    status,_,blocked=post("director",director,"delete_card_entry",{
        "client_id":real,"entry_id":entry})
    require(status==200 and "Сначала отмените получение оплаты" in html(blocked),
            "Paid card entry cannot be deleted without receipt reversal")
    require("77,00 BYN" in panel("director",real) and
            "Оплаченные · 1" in panel("director",real),
            "Paid card deletion refused without changing ledger")
    status,_,blocked=post("director",director,"edit_card_entry",{
        "client_id":real,"entry_id":entry,"amount":"77.00","currency":"BYN",
        "category":"development","note":"Incorrect category edit"})
    require(status==200 and "Сначала отмените получение оплаты" in html(blocked),
            "Paid card category cannot be silently changed")
    status,_,error=post("director",director,"edit_card_entry",{
        "client_id":real,"entry_id":entry,"amount":"88.00","currency":"BYN",
        "category":"advertising","note":"Incorrect paid card edit"})
    require(status==200 and "Сначала отмените получение оплаты" in html(error),
            "Editing a received card request's amount is rejected")
    require("77,00 BYN" in panel("director",real) and "88,00 BYN" not in panel("director",real),
            "Paid card edit cannot rewrite receipts")
    status,_,_=post("director",director,"undo_entry_receipt",{"client_id":real,"entry_id":entry})
    require(status==303,"Card receipt explicitly undone")
    status,_,invalidAmount=request("director","/?view=clients&client="+str(real),payload={
        "csrf":director,"action":"edit_card_entry","client_id":str(real),
        "entry_id":str(entry),"amount":"88.123","currency":"BYN",
        "category":"advertising","note":"Corrected after undo"})
    invalidAmountHtml=html(invalidAmount)
    invalidAmountDrawer=re.search(r'<aside class="drawer customer-profile"[\s\S]*?</aside>',invalidAmountHtml)
    require(status==200 and invalidAmountDrawer is not None and
            'data-client-card-error="1"' in invalidAmountDrawer.group(0) and
            "Сумма должна иметь не более двух знаков после запятой." in invalidAmountHtml and
            '"amount":"88.123"' in invalidAmountHtml and
            'class="error" role="alert">Сумма должна' not in invalidAmountHtml,
            "Invalid card amount stays inline, preserves typed value, and never becomes a page-wide alert")
    require("77,00 BYN" in panel("director",real) and "88,12 BYN" not in panel("director",real),
            "Rejected decimal amount leaves the existing financial entry untouched")
    status,_,_=post("director",director,"edit_card_entry",{
        "client_id":real,"entry_id":entry,"amount":"88.00","currency":"BYN",
        "category":"advertising","note":"Corrected after undo"})
    require(status==303 and "88,00 BYN" in panel("director",real),
            "Corrected card amount permitted after undo")
    # A financial client must never be removable, even by the director.
    require(not forms(panel("director",real),"delete_empty_client"),
            "Real client with payments/requests never exposes a destructive delete")
    status,_,blocked=post("director",director,"delete_empty_client",{
        "client_id":real,"delete_confirmation":"УДАЛИТЬ"})
    require(status==200 and "Удаление запрещено" in html(blocked),
            "Server rejects deletion of client with financial history")
    require("88,00 BYN" in panel("director",real),
            "Rejected deletion preserves all existing financial entries")

    # Accidental new non-demo clients have an explicit, director-only delete.
    accidental=create_client(director,"CI empty accidental client",False)
    require(not forms(panel("director",accidental),"delete_empty_client"),
            "Approved older card does not expose an unrequested delete action")
    require(not forms(panel("employee",accidental),"delete_empty_client"),
            "Employees never see ordinary client deletion")
    status,_,denied=post("employee",employee,"delete_empty_client",{
        "client_id":accidental,"delete_confirmation":"УДАЛИТЬ"})
    require(status==403 and "Недостаточно прав" in html(denied),
            "Ordinary client removal enforces HTTP 403 and server-side director role")
    status,_,denied=post("director",director,"delete_empty_client",{
        "client_id":accidental,"delete_confirmation":"no"})
    require(status==200 and "Удаление запрещено" in html(denied),
            "Client delete requires explicit typed confirmation")
    status,headers,_=post("director",director,"delete_empty_client",{
        "client_id":accidental,"delete_confirmation":"УДАЛИТЬ"})
    require(status==303 and location(headers)=="/?view=clients",
            "Confirmed empty client deletion succeeds")
    require("CI empty accidental client" not in html(request("director","/?view=clients")[2]),
            "Accidental client removed from list without changing others")
    print("Unified ledger HTTP E2E passed: upload, send, receive, undo, protected edits, empty-client cleanup, void, monthly stacking, demo exclusion, CSV")
finally:
    for p in WORK.glob("*"):
        if p.is_file():p.unlink()
    try:WORK.rmdir()
    except OSError:pass
