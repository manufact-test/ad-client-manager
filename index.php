<?php
declare(strict_types=1);
http_response_code(503);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Ad Client Manager — обслуживание</title>
<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f7f8fb;font:16px/1.5 system-ui,Arial,sans-serif;color:#1e293b;padding:24px}main{max-width:520px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px}h1{font-size:22px;margin:0 0 10px}p{margin:0;color:#475569}</style>
</head>
<body><main><h1>Ведутся технические работы</h1><p>Вход и первоначальная настройка временно отключены. Проверяем ошибку. Ничего повторно вводить не требуется.</p></main></body>
</html>
