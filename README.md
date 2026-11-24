# Lead Assignment & Follow-up System

A CRM module built with **Laravel 12, Filament 5 and MySQL 8.4**. New leads are distributed to sales agents with Round Robin while respecting daily capacity. The system also audits every status change, escalates ignored leads to the responsible manager, enforces role-based visibility on the server, and exposes a REST API documented with Swagger plus signed webhooks.

> **▶ To run the project, follow [`SETUP.md`](SETUP.md)** (about 5 minutes from a fresh clone).

- **Technical note** (design decisions, concurrency, indexes, measurements): [`docs/TECHNICAL_NOTES.md`](docs/TECHNICAL_NOTES.md)
- **Specs** (spec-driven development): [`specs/`](specs/): constitution → requirements → design → tasks

---

## Requirements

- PHP **8.3, 8.4 or 8.5** with `pdo_mysql`, `intl`, `mbstring`, `zip` (dependencies are locked for PHP 8.3+)
- Composer 2
- MySQL **8.0.16+** (8.4 recommended; CHECK constraints are used). Docker is the easiest way to get it.

## Setup

```bash
git clone <repo-url> lead-crm && cd lead-crm
composer install
cp .env.example .env
php artisan key:generate
```

Start MySQL. This creates both `lead_crm` and `lead_crm_testing` on port **3307**:

```bash
docker compose up -d
```

<details><summary>No docker compose? The same thing with plain Docker</summary>

```bash
docker run -d --name lead-crm-mysql -p 3307:3306 \
  -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=lead_crm -e MYSQL_USER=lead_crm -e MYSQL_PASSWORD=secret \
  -v "$PWD/docker/mysql/init:/docker-entrypoint-initdb.d:ro" mysql:8.4
```
If you use your own MySQL server instead, create the `lead_crm` and `lead_crm_testing` databases and update `DB_*` in `.env` and `phpunit.xml`.
</details>

Create the schema and the demo data (leads are created through the real Round Robin flow):

```bash
php artisan migrate --seed
```

## Run

```bash
php artisan serve                 # http://127.0.0.1:8000
php artisan queue:work --queue=escalations,webhooks,default
php artisan schedule:work         # runs the escalation sweeper every minute
```

| What | URL |
|---|---|
| Home (links to everything) | http://127.0.0.1:8000 |
| Admin panel (Filament, role-aware dashboard) | http://127.0.0.1:8000/admin |
| **Agent Workspace** (Angular SPA on the REST API) | http://127.0.0.1:8000/workspace |
| Swagger UI | http://127.0.0.1:8000/api/documentation |
| OpenAPI JSON | http://127.0.0.1:8000/docs?api-docs.json |

### Demo accounts (password: `password`)

| Role | Email | Sees |
|---|---|---|
| Admin | `admin@example.com` | everything |
| Sales Manager | `manager.cairo@example.com`, `manager.alex@example.com` | their team's leads |
| Sales Agent | `agent1.cairo@example.com` … `agent3.cairo@example.com`, `agent1.alex@example.com`, `agent2.alex@example.com` | their own leads |
| Inactive agent | `agent3.alex@example.com` | cannot sign in, never receives leads |

### Using the API

1. `POST /api/v1/auth/tokens` with `{"email": "admin@example.com", "password": "password"}`.
2. In Swagger UI click **Authorize** and paste the token, or send `Authorization: Bearer <token>`.

Send an inbound lead webhook (the secret comes from `WEBHOOK_SECRET_WEBSITE` in `.env`):

```bash
BODY='{"external_id":"web-1","name":"Mona Adel","phone":"+201112223334"}'; TS=$(date +%s); SIG="sha256=$(printf '%s' "$TS.$BODY" | openssl dgst -sha256 -hmac 'change-me-website' | cut -d' ' -f2)"; curl -s -X POST http://127.0.0.1:8000/api/v1/webhooks/leads -H 'Content-Type: application/json' -H 'Accept: application/json' -H 'X-Webhook-Source: website' -H "X-Webhook-Timestamp: $TS" -H "X-Webhook-Signature: $SIG" -d "$BODY"
```

## Frontend

There are two user interfaces on the same backend. Neither makes authorization decisions: all of it happens on the server.

- **Admin panel (Filament 5), the required deliverable:** the back-office, with a branded, role-aware dashboard (KPI cards, 14-day trend, pipeline chart, capacity today, most-overdue leads), lead management, bulk assignment, users, teams, webhooks, and a notification bell for escalations.
- **Agent Workspace (Angular 22), an optional extra:** a lightweight client for the sales team, built **only on the public REST API** (standalone components, signals, functional interceptors and guards, Tailwind 4). It exercises the API end to end and shows that no business logic lives in any UI: both interfaces call the same Actions with the same authorization. It is not a second admin panel and not needed to review the assessment. See [SETUP.md → Why are there two interfaces?](SETUP.md#why-are-there-two-interfaces).

Both dashboards read their numbers from one server-side class (`App\Queries\LeadDashboardStats`), so they always agree.

The workspace build is **committed** to `public/workspace-assets`, so Node.js is not needed to run it. To change the frontend:

```bash
cd frontend && npm ci
npm start            # dev server on http://localhost:4200/workspace/ (proxies /api to :8000)
npm run build        # writes public/workspace-assets
```

## Tests

The test suite runs against **real MySQL** (database `lead_crm_testing`). Row locks, FULLTEXT and CHECK constraints cannot be proven on SQLite.

```bash
php artisan test
```

| Suite | What it covers |
|---|---|
| `tests/Unit` | Architecture rules (SOLID/layering enforced with Pest `arch()`) |
| `tests/Feature` | Assignment, capacity, audit, visibility, bulk assign, escalation, Filament pages, API, webhooks, query count |
| `tests/Integration` | FULLTEXT search (needs committed data) |
| `tests/Concurrency` | **Parallel OS processes** racing on the same state: Round Robin fairness, capacity, bulk vs auto assignment, lock proof, concurrent escalation |

Run one suite: `php artisan test --testsuite=Concurrency`.

## Performance check with 500,000 leads

```bash
php artisan db:seed --class=LargeLeadDatasetSeeder   # ~30 s, chunked inserts
php artisan leads:benchmark                          # timings + EXPLAIN per role/filter
```

## Production notes

```bash
php artisan optimize && php artisan filament:optimize   # cache config, routes, events, views, Filament components
```
Also enable OPcache. Use Redis for `QUEUE_CONNECTION` and `CACHE_STORE`, and run the queue workers under Supervisor or Horizon. Keep `schedule:run` in cron: the escalation sweeper is the safety net for lost delayed jobs.

## Configuration

| Env | Default | Meaning |
|---|---|---|
| `LEADS_ESCALATION_DELAY_MINUTES` | 30 | Minutes a lead may stay `new` before escalation |
| `LEADS_DEFAULT_DAILY_CAPACITY` | 20 | Default capacity for new agents |
| `LEADS_BUSINESS_TIMEZONE` | Africa/Cairo | Defines "today" for daily capacity (timestamps are stored in UTC) |
| `LEADS_BULK_ASSIGN_MAX` | 500 | Maximum leads per bulk assignment |
| `WEBHOOK_SECRET_*` | — | Inbound webhook secret per lead source |
| `WEBHOOKS_ALLOW_PRIVATE_URLS` | false | Local development only: allow http/private outbound URLs |
