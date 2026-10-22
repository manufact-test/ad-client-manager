<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
require __DIR__ . '/app/core.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
$adcJsNonce=base64_encode(random_bytes(24));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $adcJsNonce . "'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
adc_session();

function adc_page(string $title, string $body, ?array $user = null): never {
    global $adcJsNonce;
    $name = $user ? adc_h((string) $user['display_name']) : '';
    $menu = '';
    if ($user) {
        $view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'clients';
        $menu = '<div class="header-inner"><nav class="top-nav" aria-label="Основные разделы">';
        foreach (['overview'=>'Обзор','clients'=>'Клиенты','payments'=>'Оплаты','control'=>'Контроль','tasks'=>'Мои задачи','archive'=>'Архив',
                  'analytics'=>'Аналитика','team'=>'Настройки'] as $id=>$label) {
            $menu .= '<a href="/?view='.$id.'"'.($view===$id?' class="active" aria-current="page"':'').
                '>'.adc_h($label).'</a>';
        }
        $menu .= '</nav><div class="account"><span>'.$name.' · '.
            ($user['role']==='director'?'Директор':'Сотрудник').'</span>'.
            '<form method="post" class="inline"><input type="hidden" name="csrf" value="'.adc_csrf().'">'.
            '<input type="hidden" name="action" value="logout"><button type="submit" class="link">Выйти</button></form>'.
            '</div></div>';
    }
    echo '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>' . adc_h($title) . ' · Ad Client Manager</title>'
       . '<style>
       *{box-sizing:border-box}body{font:15px/1.5 system-ui,-apple-system,Segoe UI,Arial,sans-serif;margin:0;background:#f6f7fa;color:#182130}
       header{padding:16px max(20px,calc((100vw - 980px)/2));background:#fff;border-bottom:1px solid #e8ebf1;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}
       main{max-width:980px;margin:28px auto;padding:0 16px 48px}.brand{font-weight:750;font-size:19px}nav{display:flex;gap:16px;align-items:center;font-size:13px;color:#526071}
       h1{font-size:27px;margin:4px 0 12px}h2{font-size:19px;margin:0 0 14px}p{margin:0 0 12px}small,.muted{color:#667285}
       .card{background:#fff;border:1px solid #e5e8ef;border-radius:14px;padding:22px;margin:0 0 16px}
       .narrow{max-width:475px;margin:55px auto}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:16px}.stack{display:grid;gap:10px}
       .field{display:grid;gap:5px;margin:0 0 13px}label{font-weight:600;font-size:13px}input,select{width:100%;padding:10px 11px;border:1px solid #cfd5df;border-radius:8px;font:inherit;background:white;color:inherit}
       input[type=checkbox]{width:auto;accent-color:#4d46cb}.check{display:flex;align-items:center;gap:8px;margin:8px 0;font-size:14px}
       button,.btn{font:inherit;border:1px solid #3b35a8;border-radius:8px;background:#493fbc;color:white;padding:9px 13px;cursor:pointer;text-decoration:none;display:inline-block}
       button.secondary,.btn.secondary{background:#fff;color:#2b286d;border-color:#c5c9dd}.link{border:none;color:#3b35a8;background:transparent;padding:0}
       .inline{display:inline-block}.actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.item{padding:11px 0;border-bottom:1px solid #edf0f3;display:flex;align-items:center;justify-content:space-between;gap:12px}
       .item:last-child{border-bottom:0}.item-title{font-weight:650}.badge{font-size:12px;font-weight:650;padding:2px 7px;background:#edf0f7;border-radius:30px;color:#48556b}
       .demo{background:#fff0c7;color:#735016}.good{background:#e4f6eb;color:#176739}.notice{border-left:3px solid #5147bc;background:#f0effe;padding:12px;border-radius:5px;margin:12px 0}
       .error{border-left:3px solid #c73333;background:#fff1f1;padding:11px;border-radius:5px;margin:12px 0}
       .months{display:flex;gap:10px;justify-content:space-between;align-items:center;margin-bottom:16px}
       .tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 23px}.tabs a{padding:9px 13px;color:#48465c;text-decoration:none;border-radius:8px}.tabs a.active{background:#eae8ff;color:#3025a7;font-weight:700}
       textarea{width:100%;padding:10px;border:1px solid #cfd5df;border-radius:8px;font:inherit;resize:vertical}.field{min-width:0}
       details{margin:10px 0}summary{cursor:pointer;color:#4239a9;font-weight:650;padding:8px 0}.period{border:1px solid #e6e9f1;border-radius:10px;padding:12px;margin:10px 0}.period>summary{color:#232c44}
       h3{font-size:16px;margin:14px 0 10px}.drawer{position:fixed;right:0;top:0;bottom:0;width:min(740px,100vw);background:#f7f8fb;padding:18px;overflow:auto;z-index:20;box-shadow:-20px 0 70px #1921392e}.drawer-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.drawer-head h2{margin:0}.drawer-shade{position:fixed;inset:0;background:#0b133c38;z-index:19}.drawer .card{margin-top:14px}
       
       .table-scroll{overflow-x:auto;max-width:100%} :is(a,button,input,select,textarea,summary,.table-scroll):focus-visible{outline:3px solid #c17b08;outline-offset:3px}table{border-collapse:collapse;width:100%;min-width:480px}td,th{border-bottom:1px solid #edf0f3;padding:9px 6px;text-align:left;font-size:14px}tr:hover td{background:#fafaff}td:last-child,th:last-child{white-space:nowrap}
       @media(max-width:560px){header{padding:12px 16px}.item{align-items:flex-start;flex-direction:column}.card{padding:16px}h1{font-size:23px}.drawer{padding:12px}.drawer .actions{width:100%}.drawer-head{align-items:flex-start}}
       
        :root{--accent:#4d43c2;--accent-dark:#3629a7;--accent-soft:#eeecff;--line:#e2e5ef;--text:#19223a}
        body{color:var(--text);background:#f6f7fb;overflow-x:hidden}
        header{padding:0 18px;display:block}
        .header-inner{max-width:948px;margin:auto;min-height:64px;display:flex;align-items:center;justify-content:space-between;gap:14px}
        .top-nav{display:flex;align-items:center;gap:4px;flex-wrap:wrap;font-size:14px}
        .top-nav a{padding:11px 13px;border-radius:9px;color:#30395a;text-decoration:none;white-space:nowrap}
        .top-nav a.active{color:var(--accent-dark);background:var(--accent-soft);font-weight:750}
        .top-nav a:hover{background:#f3f1ff}
        .account{display:flex;align-items:center;gap:14px;font-size:12px;color:#596680;white-space:nowrap}
        main{width:100%;max-width:980px;padding:0 16px 56px;margin:24px auto}
        .card{min-width:0;max-width:100%;overflow-wrap:anywhere;padding:22px;border-color:var(--line)}
        .card .grid{grid-template-columns:repeat(auto-fit,minmax(min(100%,245px),1fr))}
        .field,.field input,.field select,.field textarea{min-width:0;max-width:100%}
        code,pre{display:block;max-width:100%;white-space:pre-wrap;overflow-wrap:anywhere}
        button,.btn{background:var(--accent);border-color:var(--accent);font-weight:600}
        button:hover,.btn:hover{background:var(--accent-dark);color:#fff}
        .btn.secondary:hover,button.secondary:hover{color:var(--accent-dark);background:var(--accent-soft)}
        :is(input,select,textarea){accent-color:var(--accent)}
        :is(input,select,textarea):focus{border-color:var(--accent);outline:2px solid #9188e9;outline-offset:1px;box-shadow:0 0 0 3px #ebe8ff}
        :is(a,button,input,select,textarea,summary,.table-scroll):focus-visible{outline:2px solid var(--accent)!important;outline-offset:2px}
        input[type=date]{min-height:43px}
        input[type=date]::-webkit-calendar-picker-indicator{opacity:.9;cursor:pointer;background-color:#edeafe;border-radius:6px;padding:6px;background-size:16px}
        select{cursor:pointer;background-color:#fff;appearance:none;-webkit-appearance:none;
          padding-right:38px;background-image:linear-gradient(45deg,transparent 50%,#4d43c2 50%),
          linear-gradient(135deg,#4d43c2 50%,transparent 50%);
          background-position:calc(100% - 20px) 50%,calc(100% - 15px) 50%;
          background-size:5px 5px,5px 5px;background-repeat:no-repeat}
        select:hover{border-color:#8f88d6}
        .drawer .customer-tools nav,.customer-tools nav{flex-wrap:wrap}
        .customer-tools .btn{font-size:13px;padding:8px 12px}
        .drawer .client-overview{margin-top:14px}
        .drawer .client-overview .muted{margin:5px 0 0}
        .customer-edit>summary{font-weight:700}
        .settings-disclosure>summary{font-size:16px}
        .task-month .task-month-body{margin-top:6px}
        .task-day .badge{vertical-align:middle}
        .task-inline-edit .field{margin-bottom:8px}
        
        details>summary{list-style:none;display:flex;align-items:center;gap:10px;padding:9px 0}
        details>summary::-webkit-details-marker{display:none}
        details>summary::before{content:"";width:7px;height:7px;flex:none;border-right:2px solid var(--accent);border-bottom:2px solid var(--accent);transform:rotate(-45deg);transition:transform .15s ease;margin-right:3px}
        details[open]>summary::before{transform:rotate(45deg)}
        .drawer{width:min(880px,100vw);padding:22px;background:#f7f8fc}
        .drawer .card{margin-top:15px}.drawer-head{position:sticky;top:-22px;z-index:2;background:#f7f8fc;padding:12px 0;box-shadow:0 1px 0 #e2e5ef}
        .drawer .period{background:#fff}.drawer .item>div{min-width:0}.drawer .actions{flex-shrink:0}
        .summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
        .summary-metric{padding:15px;border:1px solid var(--line);background:#fbfbff;border-radius:11px}
        .summary-metric small{display:block;margin-bottom:5px}.summary-metric strong{font-size:20px}
        .source-choice .conditional-other{display:none}
        .source-choice:has(select option[value="other"]:checked) .conditional-other{display:grid}
        .task-month{margin-bottom:16px}.task-month>h2{font-size:18px;padding:0 0 13px;border-bottom:1px solid var(--line);margin:0}
        .task-day{padding:10px 0 14px;border-bottom:1px solid var(--line)}.task-day:last-child{border-bottom:none;padding-bottom:0}
        .task-day>h3{margin:0;padding:3px 0 6px}
        .task-row{display:flex;justify-content:space-between;align-items:center;gap:16px;min-height:46px;padding:6px 0}
        .task-row+.task-row{border-top:1px dashed #e2e5ef}
        .task-actions{display:flex;gap:10px;align-items:center}.task-actions form{margin:0}
        .task-actions .btn,.task-actions button{min-width:82px;height:39px;text-align:center}
        .task-inline-edit{margin:6px 0 12px;padding:16px;border-radius:10px;background:#f8f7ff;border:1px solid #dedaf9}
        .task-create .check{margin:4px 0 10px}.task-create .grid{gap:12px}
        .customer-edit{margin-top:15px}.customer-tools{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
        @media(max-width:760px){.header-inner{align-items:flex-start;flex-direction:column;padding:9px 0}.top-nav{overflow-x:auto;flex-wrap:nowrap;width:100%;scrollbar-width:thin}.top-nav a{padding:9px 11px}.account{align-self:flex-end}.card{padding:16px}.drawer{padding:12px}.drawer-head{top:-12px}}
        @media(max-width:480px){main{padding:0 12px 40px;margin-top:18px}.task-row{flex-direction:column}.task-actions{align-self:flex-end}.top-nav{font-size:13px}.summary-grid{grid-template-columns:1fr 1fr}}
        
        /* A client profile is a workflow, not a pile of shortcut anchors. */
        .customer-profile .drawer-head{padding:16px 2px;align-items:center}
        .customer-profile .drawer-head h2{font-size:23px;line-height:1.3;overflow-wrap:anywhere}
        .customer-profile .card{margin:14px 0;padding:22px;border-radius:14px}
        .customer-profile .card>h3{margin:0 0 16px;font-size:19px}
        .customer-profile .profile-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .profile-facts>div{display:flex;flex-direction:column;gap:4px;min-width:0}
        .profile-facts small{font-weight:500;color:#75819a}
        .profile-facts strong{overflow-wrap:anywhere;font-size:15px}
        .client-note{margin:18px 0 0;padding:12px 14px;border-radius:9px;background:#f6f7fb;color:#4b5873}
        .customer-profile .summary-grid{grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:20px}
        .customer-profile .summary-metric{min-width:0;padding:13px}
        .customer-profile .summary-metric strong{font-size:clamp(15px,2.2vw,20px);overflow-wrap:anywhere}
        .engagement-block{border-top:1px solid var(--line);padding:18px 0}
        .engagement-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;margin:0 0 12px}
        .engagement-head span{font-size:13px}
        .client-period{margin:10px 0;border-color:#e5e6f1;background:#fcfcff}
        .client-period>summary{padding:6px 0 12px;font-weight:750;color:#292851}
        .period-content{display:grid;gap:6px}
        .period-total{margin:10px 0 0;display:flex;justify-content:space-between;gap:12px}
        .customer-profile .sub-action{padding:7px 0;border-top:1px solid #eff0f6;margin:8px 0}
        .customer-profile .sub-action:first-of-type{border-top:0}
        .customer-profile .sub-action>summary{font-weight:600;color:#4840b4}
        .customer-profile .sub-action>form{margin:10px 0}
        .customer-profile .customer-edit{border:0;margin:0;padding:0;background:none}
        .customer-profile .customer-edit>summary{font-weight:600;font-size:15px}
        .customer-profile .doc-upload{margin:0}
        .customer-profile .actions{gap:10px}
        .customer-profile button,.customer-profile .btn{max-width:100%}
        .repeat-choice{grid-column:1/-1;padding:4px 0 8px}
        .repeat-choice>.field{margin-top:8px}
        .repeat-choice .manual-previous{display:none}
        .repeat-choice:has(select[name="previous_client_id"] option[value="__manual__"]:checked) .manual-previous{display:block}
        .repeat-choice .manual-previous .field{max-width:100%}
        .customer-more>details{margin:6px 0;padding:7px 0}
        @media(max-width:550px){.customer-profile .profile-facts{grid-template-columns:1fr 1fr;gap:12px}
          .customer-profile .summary-grid{grid-template-columns:1fr 1fr}
          .customer-profile .card{padding:16px}
          .engagement-head{flex-direction:column}
          .customer-profile .item{flex-direction:row;align-items:center}
          .customer-profile .summary-metric{padding:11px}
          .customer-profile .summary-metric strong{font-size:16px}}

        [hidden]{display:none!important}
        .task-month .task-day{border:0;border-radius:10px;padding:13px 14px;margin:10px 0}
        .task-month .task-day:nth-of-type(odd){background:#f6f7fd}
        .task-month .task-day:nth-of-type(even){background:#edecfa}
        .task-day>h3{font-size:14px;color:#373b62;padding:0 0 10px}
        .task-day .task-row{display:flex;align-items:center;justify-content:space-between;min-height:44px;padding:8px 2px}
        .task-day .task-row>.task-title{flex:1;line-height:1.35}
        .task-day .task-actions{align-items:center;gap:10px;flex-shrink:0}
        .task-day .task-actions form{display:flex;align-items:center;margin:0}
        .task-day .task-actions button{height:40px;min-width:85px;display:inline-flex;align-items:center;justify-content:center}
        .task-inline-edit{margin:8px 0 14px;border:1px solid #d9d6f4;background:#fff}
        .task-inline-edit h3{margin:0 0 12px}
        .task-edit-controls{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:10px 0 18px}
        .task-edit-controls button{min-width:98px;min-height:40px}
        .task-delete{padding-top:14px;border-top:1px solid #edeaf9}
        .task-delete button{background:#fff;color:#6c3345;border:1px solid #e1c2c9}
        .task-delete button:hover{background:#fff1f2;color:#9d2e47}
        .task-month>h2{margin-bottom:10px}
        input[type="date"]{min-height:44px;padding-right:12px}
        input[type="date"]::-webkit-calendar-picker-indicator{opacity:1;margin:0 1px 0 0;padding:7px;border-radius:7px;background-color:#ece8ff;cursor:pointer}
        .source-choice .conditional-other{margin-top:7px}
        @media(max-width:640px){
          .task-day .task-row{flex-direction:column;align-items:stretch;gap:10px}
          .task-day .task-actions{justify-content:flex-end;align-self:stretch}
          .task-day .task-actions button{min-width:84px}
        }

        .analytics-filter{display:flex;align-items:end;gap:12px;flex-wrap:wrap;margin-bottom:18px}
        .analytics-filter label{min-width:145px;display:grid;gap:5px}
        .analytics-filter select{min-height:42px}
        .analytics-filter button{min-height:42px}
        .analytics-overview-title{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:15px}
        .analytics-overview-title h2{margin:0}
        .analytics-kpis{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .analytics-kpi{display:flex;flex-direction:column;gap:6px;min-width:0;padding:16px 18px;background:#f8f8ff;border-radius:12px;border:1px solid #e7e5fa}
        .analytics-kpi>small{font-weight:650;color:#68748a}
        .analytics-kpi>strong{font-size:clamp(18px,2.2vw,27px);line-height:1.2;overflow-wrap:anywhere}
        .analytics-kpi>span{font-size:12px;color:#71809a}
        .analytics-chart-row{display:grid;grid-template-columns:75px minmax(120px,1fr) 145px;gap:12px;align-items:center;margin:10px 0}
        .analytics-chart-month{font-size:13px;font-weight:650;color:#535c7e}
        .analytics-chart-track{display:grid;grid-template-rows:repeat(2,12px);gap:4px}
        .analytics-chart-bar{height:12px;border-radius:2px 7px 7px 2px;min-width:0}
        .analytics-chart-bar.billed{background:#5144bd}
        .analytics-chart-bar.received{background:#77bde9}
        .analytics-chart-label{font-size:12px;white-space:nowrap;text-align:right;color:#50607a}
        .analytics-export{margin:0 0 28px}
        @media(max-width:600px){.analytics-kpis{grid-template-columns:1fr 1fr;gap:8px}
          .analytics-kpi{padding:12px}
          .analytics-chart-row{grid-template-columns:60px minmax(90px,1fr);gap:8px}
          .analytics-chart-label{grid-column:2;justify-self:end}
          .analytics-filter label{flex:1;min-width:120px}}
.ledger-row{padding:13px 0;display:flex;align-items:flex-start;justify-content:space-between;gap:15px;border-top:1px solid #e8e7f4}.ledger-row:nth-of-type(even){background:#faf9ff}.ledger-main{flex:1;min-width:0}.ledger-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.ledger-actions form{margin:0}.ledger-actions button,.ledger-actions .btn{font-size:12px;padding:7px 10px;min-width:0}.ledger-edit{width:100%}.ledger-edit form{min-width:min(320px,100%)}.ledger-paid{margin-top:12px;padding-top:9px;border-top:1px solid #dad6f7}.status-cell{min-width:150px}.table-scroll th:nth-child(3),.table-scroll td:nth-child(3){text-align:right}.table-scroll tbody tr:nth-child(even) td{background:#f7f6fe}.client-summary-header{display:flex;align-items:center;justify-content:space-between;gap:12px}.ledger-panel summary{cursor:pointer}.ledger-money-toggle:not(:has(input[name=has_amount]:checked)) .ledger-money-fields{display:none}@media(max-width:680px){.ledger-row{flex-direction:column}.ledger-actions{width:100%}}

        /* Accessible native backing controls with consistently purple, custom pickers. */
        .ac-select{position:relative;min-width:0}.ac-select>select{position:absolute!important;opacity:0!important;width:1px!important;height:1px!important;pointer-events:none!important}
        .ac-trigger{display:flex;width:100%;min-height:43px;align-items:center;justify-content:space-between;text-align:left;padding:10px 12px;background:#fff;color:#1a2242;border:1px solid #ced1e6;border-radius:8px;cursor:pointer;font-size:inherit;font-weight:500}
        .ac-trigger:hover{background:#f8f6ff;border-color:#8c83df;color:#211954}.ac-trigger::after{content:"";width:8px;height:8px;border-right:2px solid #5143bb;border-bottom:2px solid #5143bb;transform:rotate(45deg);margin-left:10px;margin-top:-5px}
        .ac-menu,.ac-calendar{position:absolute;top:calc(100% + 5px);left:0;right:0;z-index:30;background:#fff;border:1px solid #ddd8fc;border-radius:10px;box-shadow:0 12px 30px #29205b30;padding:6px;max-height:240px;overflow-y:auto}
        .ac-menu[hidden],.ac-calendar[hidden]{display:none}.ac-option{width:100%;display:block;text-align:left;border:0;border-radius:6px;padding:9px 10px;background:transparent;color:#202344;font-weight:500;white-space:normal;cursor:pointer}
        .ac-option:hover,.ac-option[aria-selected=true]{background:#ebe8ff;color:#3021a4}
        .ac-date-wrap{position:relative;display:flex;align-items:stretch;gap:5px}.ac-date-wrap>input{flex:1;min-width:0}.ac-date-btn{flex:none;border:1px solid #c5beea;border-radius:8px;background:#efedff;color:#4033a7;min-width:40px;font-size:18px}
        .ac-calendar{right:auto;width:290px;max-width:min(320px,90vw);max-height:none;overflow:visible;padding:12px}
        .ac-cal-head{display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:8px}.ac-cal-head button{background:#efedff;color:#4033a7;border:0;min-width:30px;padding:5px;border-radius:6px}.ac-cal-head span{font-weight:700;font-size:13px}
        .ac-days{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}.ac-days button{background:#fff;color:#292348;border:0;border-radius:7px;height:34px;padding:4px;font-size:12px}.ac-days button:hover,.ac-days button.is-selected{background:#4d43c2;color:#fff}.ac-days button.is-today{outline:1px solid #4d43c2}
        .ac-days small{text-align:center;padding:4px 0;color:#69648b;font-size:11px}
        .ac-calendar .ac-today{width:100%;margin-top:8px;padding:7px;background:#eeecff;color:#4033a7;font-size:12px}

.card-payment-switch{margin-bottom:10px}.doc-upload-form:has(input[name=is_card]:checked) .document-only{display:none}.doc-upload-form:has(input[name=is_card]:checked) .ledger-money-fields{display:block!important}.doc-upload-form:has(input[name=is_card]:checked) .ledger-money-toggle>label.check{display:none}

        /* Final client list: round entire highlighted rows, with breathing room. */
        .client-table-scroll{border-radius:12px;overflow-x:auto}
        .client-list-table{border-collapse:separate;border-spacing:0 5px;min-width:650px}
        .client-list-table thead th{padding:12px 18px;border-bottom:1px solid #e5e6f2}
        .client-list-table tbody td{padding:15px 18px;vertical-align:middle;border:0;background:#fff}
        .client-list-table tbody tr:nth-child(even) td{background:#f3f1ff}
        .client-list-table tbody tr:hover td{background:#eae7ff}
        .client-list-table tbody td:first-child{border-radius:11px 0 0 11px}
        .client-list-table tbody td:last-child{border-radius:0 11px 11px 0}
        .client-list-table .status-cell{padding-left:20px;padding-right:20px}
        .client-list-table .badge{display:inline-block;line-height:1.5;padding:3px 10px}
        .ac-date-wrap{gap:8px}
        .ac-date-wrap .ac-date-btn{min-width:44px;border-radius:10px;background:#efedff;color:#4535b6;display:inline-flex;align-items:center;justify-content:center}
        .ac-date-btn svg{width:20px;height:20px;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
        .ac-date-btn:hover{background:#e1dcff;color:#35269e}
        .ac-calendar{border-radius:13px;box-shadow:0 14px 38px #30206029}
        .ac-days button{border-radius:9px}
        .ac-days button.is-selected{border-radius:9px}
        .task-actions [data-task-toggle][aria-expanded=true]{background:#eeebff;color:#3326a4;border-color:#9d93e7}
        @media(max-width:700px){.client-list-table tbody td{padding:12px 14px}.client-list-table .status-cell{padding:12px 16px}}
</style></head><body>' . ($user ? '<header>'.$menu.'</header>' : '') . '<main>' . $body . '</main><script nonce="' . adc_h($adcJsNonce) . '">
(function(){
  "use strict";
  document.addEventListener("click",function(event){
    var danger=event.target.closest("[data-confirm]");
    if(danger&&!window.confirm(danger.getAttribute("data-confirm"))){event.preventDefault();return;}
    var toggle=event.target.closest("[data-task-toggle]");
    var close=event.target.closest("[data-task-close]");
    if(!toggle&&!close)return;
    var id=(toggle||close).getAttribute(toggle?"data-task-toggle":"data-task-close");
    var target=document.getElementById(id);
    if(!target)return;
    var opened=toggle?target.hidden:false;
    target.hidden=!opened;
    var button=document.querySelector("[data-task-toggle=\""+id+"\"]");
    if(button)button.setAttribute("aria-expanded",opened?"true":"false");
    if(opened){var title=target.querySelector("input[name=\"title\"]");if(title)title.focus({preventScroll:true});}
  });

  document.addEventListener("DOMContentLoaded",function(){
    function closeMenus(except){
      document.querySelectorAll(".ac-menu,.ac-calendar").forEach(function(p){
        if(p!==except)p.hidden=true;
      });
    }
    document.querySelectorAll("select").forEach(function(sel){
      if(sel.closest(".ac-select")||sel.multiple)return;
      var root=document.createElement("div");root.className="ac-select";
      sel.parentNode.insertBefore(root,sel);root.appendChild(sel);
      var trigger=document.createElement("button");trigger.type="button";trigger.className="ac-trigger";
      trigger.setAttribute("aria-haspopup","listbox");
      var menu=document.createElement("div");menu.className="ac-menu";menu.hidden=true;
      menu.setAttribute("role","listbox");
      root.appendChild(trigger);root.appendChild(menu);sel.tabIndex=-1;
      function refresh(){
        var opt=sel.selectedOptions[0];
        trigger.textContent=opt?opt.textContent:"Выберите";
        menu.textContent="";
        Array.from(sel.options).forEach(function(o){
          var item=document.createElement("button");item.type="button";item.className="ac-option";
          item.setAttribute("role","option");item.setAttribute("aria-selected",o.selected?"true":"false");
          item.textContent=o.textContent;item.disabled=o.disabled;
          item.addEventListener("click",function(){
            sel.value=o.value;sel.dispatchEvent(new Event("change",{bubbles:true}));
            closeMenus();trigger.focus();
          });
          menu.appendChild(item);
        });
      }
      refresh();sel.addEventListener("change",refresh);
      trigger.addEventListener("click",function(){
        var hidden=menu.hidden;closeMenus();
        menu.hidden=!hidden;if(hidden){var active=menu.querySelector("[aria-selected=true]");if(active)active.scrollIntoView({block:"nearest"});}
      });
      trigger.addEventListener("keydown",function(e){
        if(e.key==="ArrowDown"||e.key==="Enter"||e.key===" "){e.preventDefault();menu.hidden=false;closeMenus(menu);menu.querySelector("[aria-selected=true]")?.focus();}
      });
      menu.addEventListener("keydown",function(e){
        var items=Array.from(menu.querySelectorAll(".ac-option:not(:disabled)"));
        var n=items.indexOf(document.activeElement);
        if(e.key==="ArrowDown"||e.key==="ArrowUp"){e.preventDefault();items[(n+(e.key==="ArrowDown"?1:items.length-1))%items.length]?.focus();}
        if(e.key==="Escape"){menu.hidden=true;trigger.focus();}
      });
    });
    var monthNames=["Январь","Февраль","Март","Апрель","Май","Июнь","Июль","Август","Сентябрь","Октябрь","Ноябрь","Декабрь"];
    function pad(n){return String(n).padStart(2,"0");}
    function iso(y,m,d){return y+"-"+pad(m)+"-"+pad(d);}
    function dateIso(text){
      var match=/^(\d{2})\.(\d{2})\.(\d{4})$/.exec(text);
      var value=match?(match[3]+"-"+match[2]+"-"+match[1]):text;
      if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return "";
      var y=Number(value.slice(0,4)),m=Number(value.slice(5,7)),d=Number(value.slice(8,10));
      var date=new Date(y,m-1,d);
      return date.getFullYear()===y&&date.getMonth()===m-1&&date.getDate()===d?value:"";
    }
    function showDate(text){var v=dateIso(text);return v?v.slice(8,10)+"."+v.slice(5,7)+"."+v.slice(0,4):text;}
    document.querySelectorAll("input[type=date]").forEach(function(input){
      var wrap=document.createElement("div");wrap.className="ac-date-wrap";
      input.parentNode.insertBefore(wrap,input);wrap.appendChild(input);
      input.type="text";input.placeholder="ДД.ММ.ГГГГ";input.maxLength=10;
      input.pattern="[0-9]{2}\\.[0-9]{2}\\.[0-9]{4}|[0-9]{4}-[0-9]{2}-[0-9]{2}";
      input.value=showDate(input.value);
      input.addEventListener("input",function(){input.setCustomValidity("");});
      input.addEventListener("blur",function(){
        if(!input.value){input.setCustomValidity("");return;}
        var v=dateIso(input.value);
        if(v){input.value=showDate(v);input.setCustomValidity("");}
        else input.setCustomValidity("Укажите существующую дату в формате ДД.ММ.ГГГГ");
      });
      if(input.form)input.form.addEventListener("submit",function(event){
        if(!input.value)return;
        var date=dateIso(input.value);
        if(!date){
          event.preventDefault();
          input.setCustomValidity("Укажите существующую дату в формате ДД.ММ.ГГГГ");
          input.reportValidity();
          return;
        }
        input.value=date;
        input.setCustomValidity("");
      });
      input.autocomplete="off";
      var trigger=document.createElement("button");trigger.type="button";trigger.className="ac-date-btn";
      trigger.setAttribute("aria-label","Выбрать дату");
      var calendarIcon=document.createElementNS("http://www.w3.org/2000/svg","svg");
      calendarIcon.setAttribute("viewBox","0 0 24 24");
      calendarIcon.setAttribute("fill","none");
      calendarIcon.setAttribute("aria-hidden","true");
      calendarIcon.innerHTML="<rect x=\"3\" y=\"5\" width=\"18\" height=\"16\" rx=\"2\"/><path d=\"M8 3v4M16 3v4M3 10h18\"/>";
      trigger.appendChild(calendarIcon);
      var picker=document.createElement("div");picker.className="ac-calendar";picker.hidden=true;
      wrap.appendChild(trigger);wrap.appendChild(picker);
      var v=dateIso(input.value);
      var selected=v?new Date(Number(v.slice(0,4)),Number(v.slice(5,7))-1,Number(v.slice(8,10))):new Date();
      var year=selected.getFullYear(),month=selected.getMonth();
      function draw(){
        picker.textContent="";
        var head=document.createElement("div");head.className="ac-cal-head";
        var prev=document.createElement("button");prev.type="button";prev.textContent="‹";
        var title=document.createElement("span");title.textContent=monthNames[month]+" "+year;
        var next=document.createElement("button");next.type="button";next.textContent="›";
        function change(n){month+=n;if(month<0){month=11;year--;}if(month>11){month=0;year++;}draw();}
        prev.onclick=function(){change(-1);};next.onclick=function(){change(1);};
        head.append(prev,title,next);picker.appendChild(head);
        var days=document.createElement("div");days.className="ac-days";
        ["Пн","Вт","Ср","Чт","Пт","Сб","Вс"].forEach(function(w){var l=document.createElement("small");l.textContent=w;days.appendChild(l);});
        var begin=(new Date(year,month,1).getDay()+6)%7;
        for(var i=0;i<begin;i++)days.appendChild(document.createElement("span"));
        var count=new Date(year,month+1,0).getDate();
        var today=new Date();
        for(var d=1;d<=count;d++){
          let day=d;
          var b=document.createElement("button");b.type="button";b.textContent=String(day);
          var value=iso(year,month+1,day);
          if(value===dateIso(input.value))b.classList.add("is-selected");
          if(value===iso(today.getFullYear(),today.getMonth()+1,today.getDate()))b.classList.add("is-today");
          b.onclick=function(){input.value=showDate(value);input.setCustomValidity("");input.dispatchEvent(new Event("change",{bubbles:true}));closeMenus();};
          days.appendChild(b);
        }
        picker.appendChild(days);
        var todayButton=document.createElement("button");todayButton.type="button";
        todayButton.className="ac-today";todayButton.textContent="Сегодня";
        todayButton.onclick=function(){
          var d=new Date();input.value=showDate(iso(d.getFullYear(),d.getMonth()+1,d.getDate()));input.setCustomValidity("");
          input.dispatchEvent(new Event("change",{bubbles:true}));closeMenus();
        };
        picker.appendChild(todayButton);
      }
      trigger.onclick=function(){
        var opening=picker.hidden;closeMenus();if(opening){var current=dateIso(input.value);var parsed=current?new Date(Number(current.slice(0,4)),Number(current.slice(5,7))-1,Number(current.slice(8,10))):new Date();year=parsed.getFullYear();month=parsed.getMonth();draw();}
        picker.hidden=!opening;
      };
      input.addEventListener("keydown",function(e){if(e.key==="ArrowDown"){e.preventDefault();trigger.click();}});
    });
    document.addEventListener("click",function(e){
      if(!e.target.closest(".ac-select,.ac-date-wrap"))closeMenus();
    });
    document.addEventListener("keydown",function(e){if(e.key==="Escape")closeMenus();});
  });
})();
</script></body></html>';
    exit;
}

function adc_redirect(string $fragment = ''): never {
    header('Location: /' . $fragment, true, 303);
    exit;
}

function adc_input(string $name): string {
    return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
}

function adc_notice(): string {
    if (!isset($_SESSION['flash'])) {
        return '';
    }
    $value = (string) $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="notice" role="status">' . adc_h($value) . '</div>';
}

function adc_flash(string $message): void {
    $_SESSION['flash'] = $message;
}

require __DIR__ . '/app/finance.php';
require __DIR__ . '/app/export.php';
require __DIR__ . '/app/workflow.php';
require __DIR__ . '/app/ledger.php';
require __DIR__ . '/app/documents.php';
require __DIR__ . '/app/reminders.php';
require __DIR__ . '/app/team_tasks.php';
require __DIR__ . '/app/operations.php';
require __DIR__ . '/app/collections.php';
require __DIR__ . '/app/import.php';
require __DIR__ . '/app/quick_import.php';
require __DIR__ . '/app/audit.php';

$config = adc_config();
if ($config === null) {
    http_response_code(503);
    adc_page('Настройка сервера', '<section class="card narrow"><h1>Сервер готовится к запуску</h1><p>Конфигурация базы данных ещё не установлена.</p><small>Для безопасности сайт не принимает пользователей до завершения настройки.</small></section>');
}

try {
    $db = adc_db($config);
    $initialized = adc_has_users($db);
} catch (Throwable $error) {
    // Public diagnostic code contains only a broad category: never expose SQL messages,
    // hostnames, credentials, filenames or stack traces.
    $code = 'ADC-DB-OTHER';
    if (!extension_loaded('pdo_mysql')) {
        $code = 'ADC-DB-DRIVER';
    } elseif ($error instanceof PDOException) {
        $mysqlCode = (int) ($error->errorInfo[1] ?? 0);
        if (in_array($mysqlCode, [1044, 1045, 1698], true)) {
            $code = 'ADC-DB-AUTH';
        } elseif (in_array($mysqlCode, [1049, 1046], true)) {
            $code = 'ADC-DB-NAME';
        } elseif (in_array($mysqlCode, [2002, 2003, 2005, 2013], true)) {
            $code = 'ADC-DB-HOST';
        } elseif ((string) $error->getCode() === '42S02') {
            $code = 'ADC-DB-SCHEMA';
        }
    }
    error_log('Ad Client Manager DB initialization diagnostic: ' . $code);
    http_response_code(503);
    adc_page('Обслуживание', '<section class="card narrow"><h1>Временно недоступно</h1>'
        . '<p>Не удалось подключиться к базе данных.</p>'
        . '<p class="muted">Код диагностики: <strong>' . adc_h($code) . '</strong></p>'
        . '<small>Сообщите только код. Пароль, секрет и файл конфигурации отправлять не нужно.</small></section>');
}

/**
 * Release acceptance: the existing dedicated Hostinger test site has an older
 * MySQL schema. Never attempt implicit DDL on login or ordinary page requests.
 */
function adc_staging_schema_upgrade_needed(PDO $db): bool {
    foreach (['client_entries','engagement_terms'] as $table) {
        if (!$db->query("SHOW TABLES LIKE '$table'")->fetchColumn()) return true;
    }
    $price=$db->query("SHOW COLUMNS FROM engagement_terms LIKE 'quoted_amount'")->fetch();
    if (!$price || strtolower((string)$price['Type'])!=='decimal(16,2)' ||
        $price['Null']!=='YES') return true;
    foreach ([
        ['payment_events', 'submission_key', 'uq_payment_submission'],
        ['billing_periods', 'creation_key', 'uq_period_creation_key'],
    ] as [$table, $column, $index]) {
        $col = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
        $key = $db->query("SHOW INDEX FROM `$table` WHERE Key_name='$index'")->fetch();
        if (!$col || $col['Null'] !== 'YES' || !$key ||
            (int)$key['Non_unique'] !== 0 || $key['Column_name'] !== $column) {
            return true;
        }
    }
    return false;
}

/**
 * The dedicated staging site contains earlier test clients and employees.
 * Migration is additive (two nullable UNIQUE keys), so existing rows must
 * be preserved, not deleted. Reject unfamiliar/broken schema or index shapes.
 */
function adc_staging_schema_upgrade_eligible(PDO $db): bool {
    $required = [
        'users', 'login_attempts', 'audit_events', 'personal_tasks',
        'personal_task_completions', 'clients', 'engagements', 'billing_periods',
        'document_bundles', 'charge_lines', 'payment_events',
    ];
    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($required, $tables)) {
        return false;
    }
    $directorCount = (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE role='director' AND is_active=1"
    )->fetchColumn();
    if ($directorCount < 1) {
        return false;
    }
    if (in_array('engagement_terms',$tables,true)) {
        $price=$db->query("SHOW COLUMNS FROM engagement_terms LIKE 'quoted_amount'")->fetch();
        if ($price && (strtolower((string)$price['Type'])!=='decimal(16,2)' ||
            $price['Null']!=='YES')) return false;
    }
    foreach ([
        ['payment_events', 'submission_key', 'uq_payment_submission'],
        ['billing_periods', 'creation_key', 'uq_period_creation_key'],
    ] as [$table, $column, $index]) {
        // These identifiers are fixed in source, never supplied by the browser.
        $col = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
        $idx = $db->query("SHOW INDEX FROM `$table` WHERE Key_name='$index'")->fetch();
        if ($col !== false && ($col['Null'] !== 'YES' ||
            strtolower((string)$col['Type']) !== 'char(64)')) {
            return false;
        }
        if ($idx !== false && (!$col || (int)$idx['Non_unique'] !== 0 ||
            $idx['Column_name'] !== $column)) {
            return false;
        }
    }
    return true;
}

/** Only numeric record counts, never private names, appear in the director's
 * upgrade preview. This is a view of current data, not a substitute for backup.
 */
function adc_staging_existing_record_counts(PDO $db): array {
    $result = [];
    foreach ([
        'users'=>'пользователей', 'clients'=>'клиентов',
        'charge_lines'=>'начислений', 'payment_events'=>'платежей и возвратов',
        'personal_tasks'=>'личных задач', 'document_bundles'=>'документов',
    ] as $table=>$label) {
        $result[] = $label.': '.(int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    }
    return $result;
}

$schemaUpgradeRequired = $initialized && adc_staging_schema_upgrade_needed($db);
$dedicatedStagingHost =
    strtolower((string)preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST'] ?? '')) ===
    'darkred-crow-882796.hostingersite.com';

$action = adc_input('action');
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        adc_verify_csrf();

        if (!$initialized) {
            if ($action !== 'bootstrap') {
                throw new RuntimeException('Сначала создайте учётную запись директора.');
            }
            $secret = adc_input('setup_secret');
            $email = mb_strtolower(adc_input('email'));
            $displayName = adc_input('display_name');
            $password = adc_input('password');
            if (!hash_equals($config['setup_secret'], $secret)) {
                throw new RuntimeException('Неверный секрет первичной настройки.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($displayName) < 2 ||
                mb_strlen($displayName) > 120 || strlen($password) < 12) {
                throw new RuntimeException('Проверьте email, имя и пароль длиной от 12 символов.');
            }
            adc_schema($db);
            // A database-level lock prevents concurrent first-user requests.
            $lock = (int) $db->query("SELECT GET_LOCK('adclients-director-bootstrap', 5)")->fetchColumn();
            if ($lock !== 1) {
                throw new RuntimeException('Попробуйте создать директора ещё раз.');
            }
            try {
                if (adc_has_users($db)) {
                    throw new RuntimeException('Учётная запись директора уже создана.');
                }
                $passwordHash=password_hash($password,PASSWORD_DEFAULT);
                $db->beginTransaction();
                $stmt = $db->prepare('INSERT INTO users(email, display_name, password_hash, role) VALUES (?, ?, ?, "director")');
                $stmt->execute([$email, $displayName, $passwordHash]);
                $userId = (int) $db->lastInsertId();
                adc_audit($db, $userId, 'bootstrap_director', 'user', $userId);
                $db->commit();
            } finally {
                if ($db->inTransaction()) $db->rollBack();
                $db->query("SELECT RELEASE_LOCK('adclients-director-bootstrap')");
            }
            adc_auth_session_issue($userId,$passwordHash);
            adc_flash('Учётная запись директора создана.');
            adc_redirect();
        }

        if ($action === 'login') {
            $email = mb_strtolower(adc_input('email'));
            $password = adc_input('password');
            $key = adc_identity_hash($email);
            if (adc_login_blocked($db, $key)) {
                throw new RuntimeException('Слишком много попыток. Повторите через 15 минут.');
            }
            $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $candidate = $stmt->fetch();
            if (!$candidate || !password_verify($password, (string) $candidate['password_hash'])) {
                adc_login_failure($db, $key);
                throw new RuntimeException('Неверный email или пароль.');
            }
            $db->prepare('DELETE FROM login_attempts WHERE identity_hash = ?')->execute([$key]);
            adc_auth_session_issue((int)$candidate['id'],(string)$candidate['password_hash']);
            adc_flash('Вход выполнен.');
            adc_redirect();
        }

        $current = adc_current_user($db);
        if (!$current) {
            throw new RuntimeException('Необходимо войти.');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            session_destroy();
            adc_redirect();
        }

        if ($action === 'change_password') {
            $old = adc_input('old_password');
            $new = adc_input('new_password');
            $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([(int)$current['id']]);
            if (!password_verify($old, (string)$stmt->fetchColumn()) || strlen($new) < 12) {
                throw new RuntimeException('Проверьте старый пароль и новый пароль (не менее 12 символов).');
            }
            $newHash=password_hash($new,PASSWORD_DEFAULT);
            $db->beginTransaction();
            $db->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
               ->execute([$newHash, (int)$current['id']]);
            adc_audit($db, (int)$current['id'], 'change_password', 'user', (int)$current['id']);
            $db->commit();
            adc_auth_session_issue((int)$current['id'],$newHash);
            adc_flash('Пароль изменён.');
            adc_redirect();
        }

        if ((int)$current['must_change_password'] === 1) {
            throw new RuntimeException('Сначала измените временный пароль.');
        }

        if ($action === 'staging_upgrade_schema') {
            if ($current['role'] !== 'director' || !$dedicatedStagingHost ||
                !$schemaUpgradeRequired ||
                adc_input('backup_confirmed') !== 'YES' ||
                !adc_staging_schema_upgrade_eligible($db)) {
                throw new RuntimeException('Обновление запрещено: проверьте SQL-резервную копию и структуру базы.');
            }
            // MySQL DDL commits implicitly. Serialize simultaneous upgrades;
            // repeat the schema checks after obtaining the database lock.
            $lock = (int)$db->query("SELECT GET_LOCK('adclients-staging-schema', 8)")->fetchColumn();
            if ($lock !== 1) {
                throw new RuntimeException('Другое обновление базы выполняется. Повторите позже.');
            }
            try {
                if (!adc_staging_schema_upgrade_eligible($db)) {
                    throw new RuntimeException('Обнаружена несовместимая структура базы. Обновление остановлено.');
                }
                if (adc_staging_schema_upgrade_needed($db)) {
                    adc_schema($db);
                    if (adc_staging_schema_upgrade_needed($db)) {
                        throw new RuntimeException('Структура базы не обновилась. Проверка остановлена.');
                    }
                    adc_audit($db, (int)$current['id'],
                        'staging_schema_upgrade', 'database', null);
                }
            } finally {
                $db->query("SELECT RELEASE_LOCK('adclients-staging-schema')");
            }
            adc_flash('Тестовая база обновлена. Учётная запись директора сохранена.');
            adc_redirect();
        }

        if ($schemaUpgradeRequired) {
            throw new RuntimeException('Сначала директор должен обновить тестовую базу.');
        }

        // Connected workflow actions share the established authentication and CSRF gates.
        if (adc_team_task_action($db, $current, $action)) {
            adc_redirect();
        }
        if (adc_ledger_action($db, $current, $action)) {
            adc_redirect();
        }
        if (adc_w_action($db, $current, $action)) {
            adc_redirect();
        }
        if (adc_doc_action($db, $current, $action)) {
            adc_redirect();
        }
        if (adc_import_action($db, $current, $action)) {
            adc_redirect();
        }
        if (adc_quick_import_action($db, $current, $action)) {
            adc_redirect();
        }

        if ($action === 'create_task') {
            $title = adc_validate_task_title(adc_input('title'));
            $dueDate = adc_input('due_date');
            if (!adc_valid_date($dueDate)) {
                throw new RuntimeException('Выберите правильную дату.');
            }
            $monthly = isset($_POST['repeat_monthly']) ? 1 : 0;
            $anchor = $monthly ? (int)(new DateTimeImmutable($dueDate))->format('j') : null;
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO personal_tasks(user_id,title,due_date,repeat_monthly,anchor_day) VALUES(?,?,?,?,?)');
            $stmt->execute([(int)$current['id'], $title, $dueDate, $monthly, $anchor]);
            adc_audit($db,(int)$current['id'],'create_task','personal_task',(int)$db->lastInsertId());
            $db->commit();
            adc_flash('Задача добавлена.');
            adc_redirect('?view=tasks');
        }

        if ($action === 'complete_task' || $action === 'delete_task') {
            $taskId = filter_var(adc_input('task_id'), FILTER_VALIDATE_INT);
            if (!$taskId || $taskId < 1) {
                throw new RuntimeException('Неверная задача.');
            }
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT * FROM personal_tasks WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->execute([$taskId, (int)$current['id']]);
            $task = $stmt->fetch();
            if (!$task) {
                throw new RuntimeException('Задача не найдена.');
            }
            if ($action === 'delete_task') {
                $db->prepare('DELETE FROM personal_tasks WHERE id = ? AND user_id = ?')->execute([$taskId, (int)$current['id']]);
                adc_audit($db, (int)$current['id'], 'delete_task', 'personal_task', $taskId);
                $db->commit();
                adc_flash('Задача удалена.');
                adc_redirect();
            }
            if ((int)$task['is_done'] === 1) {
                throw new RuntimeException('Задача уже выполнена.');
            }
            $db->prepare('INSERT INTO personal_task_completions(task_id,user_id,due_date) VALUES (?,?,?)')
               ->execute([$taskId, (int)$current['id'], $task['due_date']]);
            if ((int)$task['repeat_monthly'] === 1) {
                $nextDate = adc_next_month((string)$task['due_date'], (int)$task['anchor_day']);
                $db->prepare('UPDATE personal_tasks SET due_date = ? WHERE id = ? AND user_id = ?')
                   ->execute([$nextDate, $taskId, (int)$current['id']]);
            } else {
                $db->prepare('UPDATE personal_tasks SET is_done = 1, completed_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?')
                   ->execute([$taskId, (int)$current['id']]);
            }
            adc_audit($db,(int)$current['id'],'complete_task','personal_task',$taskId,
                ['due_date'=>$task['due_date'],'recurring'=>(int)$task['repeat_monthly']===1]);
            $db->commit();
            adc_flash('Готово. Задача отмечена.');
            adc_redirect('?view=tasks');
        }

        /* Legacy demo-only action removed: the unified save_client form must
           collect the same fields for test clients as for real clients. */
        if ($action === 'create_demo') {
            throw new RuntimeException('Используйте общую форму добавления клиента с отметкой Демо.');
        }

        if ($action === 'create_employee') {
            adc_require_director($current);
            $email = mb_strtolower(adc_input('employee_email'));
            $name = adc_input('employee_name');
            $password = adc_input('employee_password');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 2 || mb_strlen($name) > 120 || strlen($password) < 12) {
                throw new RuntimeException('Укажите имя, email и временный пароль от 12 символов.');
            }
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO users(email, display_name, password_hash, role, must_change_password) VALUES (?,?,?,"employee",1)');
            $stmt->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT)]);
            adc_audit($db, (int)$current['id'], 'create_employee', 'user', (int)$db->lastInsertId());
            $db->commit();
            adc_flash('Сотрудник добавлен. Передайте временный пароль безопасным способом.');
            adc_redirect('?view=team');
        }

        if ($action === 'reset_employee_password') {
            adc_require_director($current);
            $employeeId=filter_var(adc_input('employee_id'),FILTER_VALIDATE_INT);
            $password=adc_input('employee_new_password');
            if (!$employeeId || $employeeId<1 || strlen($password)<12 || strlen($password)>1024) {
                throw new RuntimeException('Укажите сотрудника и временный пароль от 12 до 1024 символов.');
            }
            $db->beginTransaction();
            $stmt=$db->prepare('UPDATE users SET password_hash=?,must_change_password=1
                WHERE id=? AND role="employee" AND is_active=1');
            $stmt->execute([password_hash($password,PASSWORD_DEFAULT),$employeeId]);
            if ($stmt->rowCount()!==1) {
                throw new RuntimeException('Сотрудник не найден или уже отключён.');
            }
            adc_audit($db,(int)$current['id'],'reset_employee_password','user',(int)$employeeId);
            $db->commit();
            adc_flash('Временный пароль установлен. Старые сеансы сотрудника отозваны; передайте пароль безопасным способом.');
            adc_redirect('?view=team');
        }

        if ($action === 'deactivate_employee') {
            adc_require_director($current);
            $employeeId = filter_var(adc_input('employee_id'), FILTER_VALIDATE_INT);
            if (!$employeeId || $employeeId < 1) {
                throw new RuntimeException('Не выбран сотрудник.');
            }
            $db->beginTransaction();
            $stmt = $db->prepare('UPDATE users SET is_active = 0 WHERE id = ? AND role = "employee" AND is_active=1');
            $stmt->execute([$employeeId]);
            if (!$stmt->rowCount()) {
                throw new RuntimeException('Сотрудник не найден или уже отключён.');
            }
            adc_audit($db, (int)$current['id'], 'deactivate_employee', 'user', (int)$employeeId);
            $db->commit();
            adc_flash('Доступ сотрудника отключён.');
            adc_redirect('?view=team');
        }

        if ($action === 'activate_employee') {
            adc_require_director($current);
            $employeeId = filter_var(adc_input('employee_id'),FILTER_VALIDATE_INT);
            if (!$employeeId || $employeeId < 1) {
                throw new RuntimeException('Не выбран сотрудник.');
            }
            $db->beginTransaction();
            $stmt=$db->prepare('UPDATE users SET is_active=1 WHERE id=? AND role="employee" AND is_active=0');
            $stmt->execute([$employeeId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Сотрудник не найден или уже включён.');
            }
            adc_audit($db,(int)$current['id'],'activate_employee','user',(int)$employeeId);
            $db->commit();
            adc_flash('Доступ сотрудника восстановлен.');
            adc_redirect('?view=team');
        }

        throw new RuntimeException('Неизвестное действие.');
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($error instanceof PDOException) {
            error_log('Ad Client Manager DB action error: ' . $error->getCode());
            $errorMessage = 'Не удалось выполнить действие. Проверьте корректность данных.';
        } else {
            $errorMessage = $error->getMessage();
        }
    }
}

$alert = $errorMessage ? '<div class="error" role="alert">' . adc_h($errorMessage) . '</div>' : adc_notice();
$csrf = adc_csrf();

if (!$initialized) {
    // Brave/Chromium password managers may inspect adjacent registration fields.
    // Keep regular inputs before secrets; opt out of autofill on one-time setup.
    $html = '<section class="card narrow"><h1>Первичная настройка</h1>'
        . '<p>Создайте первого директора. Эта форма исчезнет после успешной настройки.</p>' . $alert
        . '<form method="post" class="stack" autocomplete="off"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="bootstrap">'
        . '<div class="field"><label>Имя директора</label><input type="text" name="display_name" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="120" required></div>'
        . '<div class="field"><label>Email директора</label><input type="email" name="email" autocomplete="off" autocapitalize="off" spellcheck="false" required></div>'
        . '<div class="field"><label>Пароль (от 12 символов)</label><input type="password" name="password" minlength="12" autocomplete="new-password" required></div>'
        . '<div class="field"><label>Секрет первичной настройки</label><input type="password" name="setup_secret" autocomplete="off" required></div>'
        . '<button type="submit">Создать директора</button></form><p class="muted"><small>Секрет указан в закрытом конфигурационном файле сервера. Не отправляйте его в переписке.</small></p></section>';
    adc_page('Первичная настройка', $html);
}

$user = adc_current_user($db);
if (!$user) {
    $html = '<section class="card narrow"><h1>Вход</h1><p class="muted">Доступ только для сотрудников компании.</p>' . $alert
        . '<form method="post" class="stack"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="login">'
        . '<div class="field"><label>Email</label><input type="email" name="email" autocomplete="username" required></div>'
        . '<div class="field"><label>Пароль</label><input type="password" name="password" autocomplete="current-password" required></div>'
        . '<button type="submit">Войти</button></form></section>';
    adc_page('Вход', $html);
}

if ((int)$user['must_change_password'] === 1) {
    $html = '<section class="card narrow"><h1>Измените временный пароль</h1>' . $alert
        . '<form method="post" class="stack"><input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="change_password">'
        . '<div class="field"><label>Временный пароль</label><input name="old_password" type="password" autocomplete="current-password" required></div>'
        . '<div class="field"><label>Новый пароль (от 12 символов)</label><input name="new_password" type="password" minlength="12" autocomplete="new-password" required></div>'
        . '<button type="submit">Сохранить новый пароль</button></form></section>';
    adc_page('Пароль', $html, $user);
}

if ($schemaUpgradeRequired) {
    if ($user['role'] === 'director' && $dedicatedStagingHost) {
        $eligible = adc_staging_schema_upgrade_eligible($db);
        $body = '<section class="card narrow"><h1>Обновление тестовой базы</h1>'
            . $alert
            . '<p>Новая версия добавляет историю месячных договорных сумм без пересчёта прежних оплат. '
            . 'Директор, сотрудники, клиенты, задачи и существующие документы сохраняются. Новые записи учитываются отдельно от старых.</p>'
            . '<p><strong>Сейчас в тестовой базе:</strong> '
            . adc_h(implode('; ', adc_staging_existing_record_counts($db))) . '.</p>'
            . '<p><strong>Перед подтверждением:</strong> убедитесь, что в Hostinger '
            . 'сохранены резервные копии <strong>MySQL-базы и файлов сайта</strong>. '
            . 'Автоматическое изменение структуры MySQL нельзя отменить обычной кнопкой.</p>';
        if ($eligible) {
            $body .= '<form method="post" class="stack">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="action" value="staging_upgrade_schema">'
                . '<label><input type="checkbox" name="backup_confirmed" value="YES" required>'
                . ' Проверил в Hostinger: сохранены резервные копии файлов и MySQL-базы</label>'
                . '<button type="submit">Обновить тестовую базу</button></form>';
        } else {
            $body .= '<div class="error" role="alert">Автообновление недоступно: '
                . 'схема базы несовместима либо отсутствует действующий директор. '
                . 'Не изменяйте их. Требуется отдельная проверка резервной копии.</div>';
        }
        adc_page('Обновление тестовой базы', $body . '</section>', $user);
    }
    http_response_code(503);
    adc_page('Требуется обновление базы',
        '<section class="card narrow"><h1>База ожидает обновления</h1>'
        . '<p>Операции временно закрыты. Обратитесь к директору.</p></section>',
        $user);
}

// Financial downloads follow the same session and temporary-password gates.
if (isset($_GET['export']) && is_string($_GET['export'])) {
    adc_export_csv($db,$user,$_GET['export']);
}

// Only authenticated users who have changed a temporary password can retrieve PDFs.
if (isset($_GET['download'])) {
    adc_doc_download($db, $user);
}

// The old demo-only and month-switching dashboard has been superseded.
adc_w_page($db, $user, $alert);
