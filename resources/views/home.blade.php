<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Lead CRM · Lead Assignment &amp; Follow-up System</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/favicon.svg') }}">
    {{-- Self-contained styles: the home page needs no asset build. --}}
    <style>
        :root { --bg: #f8fafc; --card: #fff; --text: #0f172a; --muted: #64748b; --border: #e2e8f0; --brand: #4f46e5; --brand-soft: #eef2ff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #020617; --card: #0f172a; --text: #f1f5f9; --muted: #94a3b8; --border: rgba(255,255,255,.1); --brand: #818cf8; --brand-soft: rgba(99,102,241,.15); }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100dvh; background: var(--bg); color: var(--text); font: 16px/1.6 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
        main { max-width: 64rem; margin: 0 auto; padding: 4rem 1.5rem; }
        .brand { display: flex; align-items: center; gap: .75rem; font-weight: 700; font-size: 1.125rem; }
        h1 { margin: 3rem 0 0; font-size: clamp(2rem, 5vw, 3rem); line-height: 1.1; letter-spacing: -.025em; }
        .lead { margin-top: 1rem; max-width: 40rem; color: var(--muted); font-size: 1.125rem; }
        .grid { display: grid; gap: 1rem; margin-top: 3rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        a.card { display: block; padding: 1.5rem; border: 1px solid var(--border); border-radius: 1rem; background: var(--card); color: inherit; text-decoration: none; transition: transform .15s, box-shadow .15s, border-color .15s; }
        a.card:hover, a.card:focus-visible { transform: translateY(-2px); border-color: var(--brand); box-shadow: 0 10px 30px -12px rgba(79,70,229,.35); outline: none; }
        .tag { display: inline-block; padding: .125rem .5rem; border-radius: 999px; background: var(--brand-soft); color: var(--brand); font-size: .75rem; font-weight: 600; }
        a.card h2 { margin: .75rem 0 .25rem; font-size: 1.125rem; }
        a.card p { margin: 0; color: var(--muted); font-size: .9375rem; }
        .meta { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--border); color: var(--muted); font-size: .875rem; }
        code { padding: .1rem .35rem; border-radius: .375rem; background: var(--brand-soft); color: var(--brand); font-size: .8125rem; }
    </style>
</head>
<body>
<main>
    <div class="brand">
        <img src="{{ asset('images/favicon.svg') }}" alt="" width="36" height="36"> Lead CRM
    </div>

    <h1>Lead Assignment &amp; Follow-up System</h1>
    <p class="lead">
        Concurrency-safe Round Robin assignment with daily capacity, an audit trail for every status change,
        idempotent escalation after 30 minutes, and role-based visibility enforced on the server.
    </p>

    <div class="grid">
        <a class="card" href="{{ url('/admin') }}">
            <span class="tag">Filament 5</span>
            <h2>Admin panel →</h2>
            <p>Role-aware dashboard, lead management, bulk assignment, users, teams and webhooks.</p>
        </a>
        <a class="card" href="{{ url('/workspace') }}">
            <span class="tag">Angular 22</span>
            <h2>Agent Workspace →</h2>
            <p>A separate single-page app built only on the public REST API.</p>
        </a>
        <a class="card" href="{{ url('/api/documentation') }}">
            <span class="tag">OpenAPI 3</span>
            <h2>API documentation →</h2>
            <p>Interactive Swagger UI for every endpoint, including signed webhooks.</p>
        </a>
    </div>

    <p class="meta">
        Demo accounts (password <code>password</code>): <code>admin@example.com</code>,
        <code>manager.cairo@example.com</code>, <code>agent1.cairo@example.com</code>.
    </p>
</main>
</body>
</html>
