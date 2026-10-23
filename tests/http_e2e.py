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
def create_client(token,name,demo):
    status,headers,_=post("director",token,"save_client",{
        "client_name":name,"platform_text":"Фиктивная реклама",
        "source_kind":"inbound","legal_type":"legal","started_on":"2026-10-21",
        "payment_mode":"prepayment","currency":"BYN",
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
    demo=create_client(director,"CI demo ledger",True)
    real=create_client(director,"CI real ledger",False)
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
    require(status==200 and "chat-reply-btn" in html(initial),
            "Reply action visible next to messages")
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
    require("chat-pinned" in html(pinPage) and
            "focus="+messageid in html(pinPage),
            "Pinned bar visible to colleague in the same client room")
    status,_,focusPage=request("employee","/?view=chat&thread="+str(real)+
                              "&focus="+messageid)
    require(status==200 and 'data-focused="1"' in html(focusPage) and
            'id="chat-msg-'+messageid+'"' in html(focusPage),
            "Pinned message can be revisited even when it is not the newest")
    status,headers,_=post("director",director,"chat_toggle_pin",{
        "thread":str(real),"message_id":messageid})
    require(status==303,"Unpinning the same message succeeds")
    status,_,unpinned=request("director","/?view=chat&thread="+str(real))
    require("chat-pinned" not in html(unpinned),"Unpinned bar disappears")
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
    inline_script=re.search(r'<script nonce="[A-Za-z0-9+/=]+">([\s\S]*?)</script>',page)
    require(inline_script is not None,"Authorized browser interaction script is present")
    js_check=subprocess.run(["node","--check"],input=inline_script.group(1),
                            text=True,capture_output=True)
    require(js_check.returncode==0,"Inline calendar and task JavaScript has valid syntax")
    require("var opened=toggle?target.hidden:false;" in inline_script.group(1),
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
    fake=WORK/"fake.pdf";fake.write_bytes(b"<html>not pdf</html>")
    status,_,error=request("director",files={
        "csrf":director,"action":"upload_document","client_id":demo,
        "document":"@"+str(fake)+";filename=wrong.pdf;type=application/pdf",
        "needs_invoice":"1","has_amount":"1","amount":"100.00","currency":"BYN",
        "category":"advertising"})
    require(status==200 and "Разрешены только PDF" in html(error),"Reject spoofed file")
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
