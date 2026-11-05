<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - Lone Oak Billing</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 70rem; padding: 0 1rem; color: #222; }
        table { border-collapse: collapse; margin: 0.5rem 0 1.5rem; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 0.3rem 0.6rem; text-align: left; vertical-align: top; }
        th { background: #f3f3f3; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .invoice { border-top: 2px solid #222; margin-top: 2rem; }
        .muted { color: #666; }
        nav a { margin-right: 1rem; }
    </style>
</head>
<body>
    <nav><a href="{{ route('clients.index') }}">Clients</a></nav>
    @yield('content')
</body>
</html>
