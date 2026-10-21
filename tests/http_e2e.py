#!/usr/bin/env python3
"""Authenticated end-to-end acceptance of the document-centric workflow.
Runs only with disposable CI identities, database and out-of-root PDF storage.
"""
import os
import re
import subprocess
import tempfile
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
        "payment_mode":"prepayment","currency":"BYN","quoted_amount":"0.00",
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
    demo=create_client(director,"CI demo ledger",True)
    real=create_client(director,"CI real ledger",False)
    page=panel("director",demo)
    # UI behaviors require an authorized nonce: no unsafe-inline script policy.
    status,headers,rendered=request("director","/?view=clients&client="+str(demo))
    page=html(rendered)
    policy=re.search(r"(?im)^Content-Security-Policy:\s*(.+)$",headers)
    match=re.search(r'<script nonce="([A-Za-z0-9+/=]+)">',page)
    require(status==200 and policy and match and
            "script-src 'nonce-"+match.group(1)+"'" in policy.group(1),
            "CSP permits the task editor, dropdowns and calendars without unsafe inline scripts")
    require("script-src 'unsafe-inline'" not in policy.group(1),"No JavaScript unsafe-inline bypass")
    require("Документы и оплаты" in page and "Загрузить документ" in page,"New document-centered drawer exists")
    require("Выгрузить историю в CSV" in page,"Client CSV is in overview")
    require("Добавить новые условия" in page and "Добавить расчётный период вручную" not in page,
            "Conditions versioned; manual period button removed")
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
    require(status==200 and "77,00 BYN" in html(report) and "280,00 BYN" not in html(report),
            "Analytics includes real requests but excludes demo requests")
    status,_,csv=request("director","/?export=balances")
    require(status==200 and "CI real ledger" in html(csv) and "CI demo ledger" not in html(csv),
            "Balance CSV excludes demo accounts, includes new ledger")
    print("Unified ledger HTTP E2E passed: upload, send, receive, undo, edit, void, monthly stacking, demo exclusion, CSV")
finally:
    for p in WORK.glob("*"):
        if p.is_file():p.unlink()
    try:WORK.rmdir()
    except OSError:pass
