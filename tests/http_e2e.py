#!/usr/bin/env python3
"""Real HTTP request E2E on a disposable local PHP/MySQL application.
Uses only stdlib + curl; no browser mocks and no real customer information.
"""
import os
import re
import subprocess
import tempfile
from pathlib import Path
from urllib.parse import urlencode

BASE = os.environ.get("ADC_HTTP_TEST_BASE", "http://127.0.0.1:8085")
PASSWORD = "CI-only-fictional-password-12345"
ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / "adclients-documents"
WORK = Path(tempfile.mkdtemp(prefix="adclient-http-"))
JARS = {role: WORK / (role + ".cookie") for role in ("director", "employee", "visitor")}

def check(ok, description):
    if not ok:
        raise AssertionError(description)

def request(role, path="/", payload=None, fields=None):
    header_file = WORK / "headers"
    output_file = WORK / "response"
    cmd = [
        "curl", "--silent", "--show-error", "--max-time", "12",
        "-b", str(JARS[role]), "-c", str(JARS[role]),
        "-D", str(header_file), "-o", str(output_file),
        "-w", "%{http_code}",
    ]
    if payload is not None:
        cmd.extend(["-X", "POST"])
        for k, v in payload.items():
            cmd.extend(["--data-urlencode", str(k) + "=" + str(v)])
    if fields is not None:
        cmd.extend(["-X", "POST"])
        for k, v in fields.items():
            cmd.extend(["-F", str(k) + "=" + str(v)])
    cmd.append(BASE + path)
    proc = subprocess.run(cmd, capture_output=True, text=True, check=True)
    return int(proc.stdout), header_file.read_text(), output_file.read_bytes()

def html(data):
    return data.decode("utf-8")

def csrf(text):
    match = re.search(r'name="csrf" value="([a-f0-9]{64})"', text)
    check(match is not None, "CSRF field missing")
    return match.group(1)

def location(headers):
    match = re.search(r"^Location:\s*(.+)$", headers, flags=re.I | re.M)
    return match.group(1).strip() if match else ""

def do_login(role):
    code, _, content = request(role)
    check(code == 200 and "Вход" in html(content), role + " login form")
    token = csrf(html(content))
    code, headers, _ = request(role, payload={
        "csrf": token, "action": "login",
        "email": "web-" + role + "@example.test",
        "password": PASSWORD
    })
    check(code == 303 and location(headers) == "/", role + " login succeeds")
    code, _, content = request(role, "/?view=clients")
    check(code == 200 and "Клиенты" in html(content), role + " client dashboard")
    return csrf(html(content))

def create_demo(token):
    code, headers, _ = request("director", payload={
        "csrf": token, "action": "save_client", "client_name": "Disposable CI demo",
        "platform_text": "Fictional Ads", "source_kind": "inbound",
        "legal_type": "legal", "started_on": "2026-10-21",
        "billing_day": "31", "payment_mode": "prepayment",
        "currency": "BYN", "quoted_amount": "123.00", "is_demo": "1"
    })
    m = re.search(r"client=(\d+)", location(headers))
    check(code == 303 and m is not None, "Created demo through actual client HTTP form")
    return int(m.group(1))

def uploaded(role, action, token, client, pdf, doc_id=None, attachment_type="application/pdf", period_id=None):
    fields = {
        "csrf": token, "action": action, "client_id": client,
        "needs_invoice": "1", "needs_act": "1",
        "transfer_due_on": "2026-10-25",
        "document": "@" + str(pdf) + ";filename=ci-fixture.pdf;type=" + attachment_type
    }
    if doc_id is not None:
        fields["document_id"] = doc_id
    if period_id is not None:
        fields["period_id"] = period_id
    return request(role, fields=fields)

def document_ids(text):
    return list(map(int, re.findall(r'\?download=(\d+)', text)))

try:
    status, _, page = request("visitor", "/?download=1")
    check(status == 200 and "Вход" in html(page) and not page.startswith(b"%PDF-"),
          "Unauthenticated PDF access remains behind login")
    director_csrf = do_login("director")
    employee_csrf = do_login("employee")

    client = create_demo(director_csrf)
    status, _, page = request("director", "/?view=clients&client=" + str(client))
    check(status == 200 and "Disposable CI demo" in html(page) and "Загрузить PDF" in html(page),
          "Slide-out demo client and upload form present")
    match = re.search(r'Сотрудничество #(\d+)', html(page))
    check(match is not None, "Engagement exists for HTTP demo")
    eng = int(match.group(1))
    status, _, _ = request("director", payload={
        "csrf": director_csrf, "action": "save_period",
        "client_id": client, "engagement_id": eng,
        "period_start": "2026-10-21", "period_end": "2026-11-21"
    })
    check(status == 303, "Create a real period through form action")
    status, _, page = request("director", "/?view=clients&client=" + str(client))
    match = re.search(r'<option value="(\d+)">2026-10-21', html(page))
    check(match is not None, "New period available in PDF upload selector")
    period_id = int(match.group(1))


    pdf = WORK / "test.pdf"
    pdf.write_bytes(b"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n")
    bad = WORK / "not-a-pdf.pdf"
    bad.write_text("<html>This is not PDF</html>")

    status, _, page = uploaded("employee", "upload_document", employee_csrf, client, pdf, period_id=period_id)
    check(status == 403 and "Недостаточно прав" in html(page), "Employee forbidden to upload original PDF")
    status, _, page = uploaded("director", "upload_document", director_csrf, client, bad, period_id=period_id)
    check(status == 200 and "Разрешены только PDF" in html(page), "Reject fake PDF before storage")
    check(not DOCS.exists() or not list(DOCS.glob("*.pdf")), "Invalid PDF wrote no file")
    status, _, page = uploaded("director", "upload_document", director_csrf, client, pdf)
    check(status == 200 and "сначала выберите расчётный период" in html(page),
          "Financial PDF cannot bypass period linkage needed for stale-document protection")
    check(not DOCS.exists() or not list(DOCS.glob("*.pdf")), "Unlinked financial PDF was not saved")


    status, headers, _ = uploaded("director", "upload_document", director_csrf, client, pdf, period_id=period_id)
    check(status == 303, "Director PDF upload accepted: " + str(status))
    status, _, page = request("director", "/?view=clients&client=" + str(client))
    ids = document_ids(html(page))
    check(len(ids) == 1, "Exactly one PDF after upload")
    old_id = ids[0]
    check(len(list(DOCS.glob("*.pdf"))) == 1, "Private PDF written outside document root")

    status, headers, data = request("director", "/?download=" + str(old_id))
    check(status == 200 and data == pdf.read_bytes() and "application/pdf" in headers,
          "Director downloads exact uploaded PDF bytes")
    status, headers, data = request("employee", "/?download=" + str(old_id))
    check(status == 200 and data == pdf.read_bytes(), "Employee can download authenticated PDF")
    status, _, content = request("visitor", "/?download=" + str(old_id))
    check(status == 200 and "Вход" in html(content) and not content.startswith(b"%PDF-"),
          "Unauthenticated download cannot reveal document contents")

    # Employee marks transfer; does not get mutation rights on original PDF.
    status, _, _ = request("employee", payload={
        "csrf": employee_csrf, "action": "transfer_document",
        "client_id": client, "document_id": old_id
    })
    check(status == 303, "Employee may record handover")
    status, _, page = request("director", "/?view=clients&client=" + str(client))
    check("Передано " in html(page), "Transfer shown in drawer")

    # A finance edit invalidates an already-transferred invoice and act.
    status, _, _ = request("employee", payload={
        "csrf": employee_csrf, "action": "save_charge",
        "client_id": client, "period_id": period_id,
        "category": "advertising", "label": "Changed fee",
        "amount": "155.00", "reason": "Updated cost"
    })
    check(status == 303, "Employee may change financial amount")
    status, _, page = request("employee", "/?view=clients&client=" + str(client))
    check("Требуется замена PDF" in html(page), "Stale financial PDF flagged visibly")
    status, _, page = request("employee", "/?download=" + str(old_id))
    check(status == 409 and not page.startswith(b"%PDF-"),
          "Employee cannot download stale invoice after financial change")
    status, _, page = request("employee", payload={
        "csrf": employee_csrf, "action": "transfer_document",
        "client_id": client, "document_id": old_id
    })
    check(status == 200 and "должен заменить PDF" in html(page),
          "Employee cannot mark an outdated PDF as transferred")
    status, _, old_pdf = request("director", "/?download=" + str(old_id))
    check(status == 200 and old_pdf == pdf.read_bytes(),
          "Director may inspect old PDF before replacement")


    # CSRF is mandatory even for valid director session.
    status, _, page = request("director", payload={
        "csrf": "not-a-token", "action": "void_document",
        "client_id": client, "document_id": old_id
    })
    check(status == 403, "Director cannot bypass CSRF")

    replacement = WORK / "replacement.pdf"
    replacement.write_bytes(b"%PDF-1.5\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n")
    status, _, _ = uploaded("director", "replace_document", director_csrf, client, replacement, old_id, period_id=period_id)
    check(status == 303, "Director replaces PDF")
    status, _, page = request("director", "/?view=clients&client=" + str(client))
    ids = document_ids(html(page))
    check(len(ids) == 1 and ids[0] != old_id, "Replacement creates a new document version")
    check("Ожидает передачи" in html(page), "Replacing document requires a new handover")
    new_id = ids[0]
    status, _, _ = request("director", "/?download=" + str(old_id))
    check(status == 404, "Replaced document is unavailable")
    status, _, downloaded = request("employee", "/?download=" + str(new_id))
    check(status == 200 and downloaded == replacement.read_bytes(), "Employee gets replacement PDF")
    check(len(list(DOCS.glob("*.pdf"))) == 1, "Old PDF removed from private storage")

    status, _, page = request("employee", payload={
        "csrf": employee_csrf, "action": "void_document",
        "client_id": client, "document_id": new_id
    })
    check(status == 403 and "Недостаточно прав" in html(page), "Employee forbidden to delete PDF")

    status, _, _ = request("director", payload={
        "csrf": director_csrf, "action": "delete_demo", "client_id": client
    })
    check(status == 303, "Director deletes demo")
    status, _, _ = request("director", "/?download=" + str(new_id))
    check(status == 404, "Deleting demo cascades to document metadata")
    check(not list(DOCS.glob("*.pdf")), "Demo deletes stored PDF bytes too")
    status, _, page = request("director", "/?view=clients")
    check("Disposable CI demo" not in html(page), "Deleted demo removed from client table")

    # Historical canonical CSV is previewed and then committed in a
    # director-only HTTP multipart flow. No raw CSV is persisted on server.
    header = ("external_client_id,client_name,is_demo,started_on,billing_day,"
              "payment_mode,currency,period_start,period_end,category,work_name,"
              "billed,received,refunded,payment_date,archived_on,platform_text,source_kind")
    rows = [
        "legacy-ci-1,Imported CI client,0,2026-09-01,7,prepayment,USD,"
        "2026-09-01,2026-10-01,advertising,Ads,0.10,0.10,,2026-09-15,,Fictional,inbound",
        "legacy-ci-1,Imported CI client,0,2026-09-01,7,prepayment,USD,"
        "2026-09-01,2026-10-01,development,Landing,0.20,0.20,,2026-09-16,,Fictional,inbound",
    ]
    csv_file = WORK / "synthetic-history.csv"
    csv_file.write_text(header + "\n" + "\n".join(rows) + "\n")
    def history(role, action):
        return request(role, fields={
            "csrf": director_csrf if role == "director" else employee_csrf,
            "action": action,
            "history_csv": "@" + str(csv_file) + ";filename=synthetic-history.csv;type=text/csv"
        })
    status, _, page = history("employee", "preview_history_csv")
    check(status == 403 and "Недостаточно прав" in html(page),
          "Employee has no access to history import")
    status, _, _ = history("director", "commit_history_csv")
    check(status == 200, "Import cannot commit without preview")
    status, _, page = history("director", "preview_history_csv")
    check(status == 303, "Director preview checks canonical CSV without writing")
    status, _, page = request("director", "/?view=team")
    check("Проверено: 1 клиентов / 1 периодов / 2 строк" in html(page),
          "Preview count visible in settings")
    status, _, _ = history("director", "commit_history_csv")
    check(status == 303, "Director confirms exact same CSV")
    status, _, page = request("director", "/?view=clients")
    check("Imported CI client" in html(page), "Historical client materializes in actual client table")
    status, _, page = history("director", "preview_history_csv")
    check(status == 303, "Duplicate CSV can be previewed")
    status, _, page = history("director", "commit_history_csv")
    check(status == 200 and "уже был импортирован" in html(page),
          "Repeated identical CSV blocked against duplicates")
    status, _, page = request("director", "/?view=analytics")
    check("Imported CI client" in html(page) and "0,30 USD" in html(page),
          "Historical imported charges and payments shown precisely in analytics")

    # Search and type filters must remain literal, URL-safe and preserve the
    # selected filters when a client drawer is opened/closed.
    status, _, page = request("director", "/?view=clients&q=Imported+CI&demo=0")
    rendered = html(page)
    check(status == 200 and "Imported CI client" in rendered, "Search shows matching real client")
    check('name="q"' in rendered and 'value="Imported CI"' in rendered,
          "Search input preserves query")
    match = re.search(r'href="([^"]+client=\d+)"', rendered)
    check(match is not None and "q=Imported%20CI" in match.group(1),
          "Client links retain the filter in HTML-escaped URL")
    selected_url = match.group(1).replace("&amp;", "&")
    status, _, page = request("director", selected_url)
    check("Imported CI client" in html(page), "Filtered client drawer opens")
    check("q=Imported%20CI" in html(page) and "Закрыть ✕" in html(page),
          "Closing drawer can return to same search")
    status, _, page = request("director", "/?view=clients&q=Imported+CI&demo=1")
    check("Imported CI client" not in html(page), "Demo filter excludes real search result")
    status, _, page = request("director", "/?view=clients&q=%25")
    check("Imported CI client" not in html(page), "SQL substring search treats percent literally")
    status, _, page = request("director", "/?view=clients&q=%3Cscript%3Ebad%3C%2Fscript%3E")
    check('<script>bad</script>' not in html(page) and "&lt;script&gt;" in html(page),
          "Search value is escaped; cannot inject HTML")


    print("HTTP E2E passed: authentication, client form, PDF upload/download, roles, CSRF, replacement, transfer, cascade")
finally:
    for file in WORK.iterdir():
        file.unlink()
    WORK.rmdir()
