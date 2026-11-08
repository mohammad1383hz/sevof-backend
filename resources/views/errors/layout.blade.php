<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('code') · @yield('title') · Sevof</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:#F4F2EC;color:#1E2A28;font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .card{width:100%;max-width:440px;padding:32px;text-align:center;background:#fff;border:1px solid #DAD5C7;border-radius:16px;box-shadow:0 1px 2px rgba(30,42,40,.06)}
        .icon{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;margin-bottom:12px;border-radius:50%;background:#DCEFEA;color:#1B6F63}
        .icon svg{width:32px;height:32px}
        .code{font-size:40px;font-weight:700;line-height:1.2}
        h1{margin:4px 0 8px;font-size:20px}
        p{margin:0;color:#5B6560;font-size:15px}
        .foot{margin-top:12px;color:#9BA69F;font-size:13px}
        .actions{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:24px}
        .btn{display:inline-block;padding:9px 18px;border-radius:10px;font:600 14px/1.4 inherit;text-decoration:none;cursor:pointer;border:1px solid #1B6F63}
        .btn-primary{background:#1B6F63;color:#fff}
        .btn-secondary{background:#fff;color:#1B6F63}
    </style>
</head>
<body>
    @php($sevofFront = rtrim((string) config('app.front_url'), '/') ?: url('/'))
    <main class="card">
        <div class="icon" aria-hidden="true">@yield('icon')</div>
        <div class="code">@yield('code')</div>
        <h1>@yield('title', 'Something went wrong')</h1>
        <p>@yield('description')</p>
        @hasSection('footnote')
            <p class="foot">@yield('footnote')</p>
        @endif
        <div class="actions">
            <a class="btn btn-primary" href="{{ $sevofFront }}">Go to Sevof</a>
            @hasSection('secondaryLabel')
                <button type="button" class="btn btn-secondary" onclick="@yield('secondaryAction', 'history.back()')">@yield('secondaryLabel')</button>
            @endif
        </div>
    </main>
</body>
</html>
