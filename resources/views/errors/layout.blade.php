<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title') | Café Finca Helena</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:#faf6ef;color:#3b1f0e;font:16px/1.55 Arial,Helvetica,sans-serif}
        main{width:100%;max-width:520px;text-align:center}
        .logo{width:84px;height:84px;object-fit:contain;border-radius:14px}
        .brand{margin:10px 0 0;font:700 22px/1.2 Georgia,'Times New Roman',serif}
        .tag{margin:2px 0 26px;font-size:13px;color:#6b5a4b}
        .code{margin:0;font:700 56px/1 Georgia,'Times New Roman',serif;color:#2d5a27}
        h1{margin:8px 0 10px;font:700 24px/1.25 Georgia,'Times New Roman',serif}
        p.msg{margin:0 auto 24px;max-width:420px;color:#4a3a2e}
        a.btn{display:inline-block;min-height:46px;padding:12px 24px;border-radius:10px;background:#2d5a27;color:#fff;font-weight:700;text-decoration:none}
        a.btn:focus-visible{outline:3px solid #c98a3f;outline-offset:2px}
        .alt{display:block;margin-top:14px;font-size:14px;color:#6b5a4b}
    </style>
</head>
<body>
<main>
    <img class="logo" src="{{ asset('media/email/logo.png') }}" alt="" width="84" height="84">
    <p class="brand">Café Finca Helena</p>
    <p class="tag">Café de Norte de Santander</p>
    <p class="code">@yield('code')</p>
    <h1>@yield('heading')</h1>
    <p class="msg">@yield('message')</p>
    <a class="btn" href="{{ url('/') }}">Volver al inicio</a>
    @hasSection('alt')<span class="alt">@yield('alt')</span>@endif
</main>
</body>
</html>
