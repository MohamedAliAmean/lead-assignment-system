# Setup & Run Guide

Step-by-step instructions to run the project from a fresh clone. It takes about **5 minutes**.

> Short on time? Follow **[Quick start](#quick-start)**, then open http://127.0.0.1:8000.

---

## 1. Prerequisites

| Tool | Version | Check |
|---|---|---|
| PHP | **8.3, 8.4 or 8.5** | `php -v` |
| PHP extensions | `pdo_mysql`, `mbstring`, `intl`, `zip`, `xml`, `curl`, `bcmath` (usually present) | `php -m` |
| Composer | 2.x | `composer -V` |
| MySQL | **8.0.16+** (8.4 recommended) | Docker below, or your own server |
| Docker | any recent version | only if you use the bundled MySQL |
| Node.js | **not required** | the Angular build is committed. Node 20+ is needed only to *change* the frontend |

<details>
<summary>Installing the PHP extensions</summary>

- **Ubuntu/Debian:** `sudo apt install php8.3-cli php8.3-mysql php8.3-mbstring php8.3-intl php8.3-zip php8.3-xml php8.3-curl php8.3-bcmath`
- **macOS (Homebrew):** `brew install php composer` includes everything needed.
- **Windows:** use **WSL2 (Ubuntu)** and follow the Ubuntu line, or Laravel Herd. Run every command below inside that environment.
</details>

---

## Quick start

```bash
git clone https://github.com/MohamedAliAmean/lead-assignment-system.git
cd lead-assignment-system

composer install
cp .env.example .env
php artisan key:generate

docker compose up -d          # MySQL 8.4 on port 3307 (creates lead_crm + lead_crm_testing)
# wait ~20 s for MySQL to become ready on first start, then:
php artisan migrate --seed

php artisan serve             # http://127.0.0.1:8000
```

Open **http://127.0.0.1:8000** and sign in with `admin@example.com` / `password`.

---

## 2. Step by step

### 2.1 Install PHP dependencies

```bash
composer install
```

### 2.2 Environment file

```bash
cp .env.example .env
php artisan key:generate
```

The defaults in `.env.example` already match the bundled MySQL container (host `127.0.0.1`, port **3307**, user `lead_crm`, password `secret`).

### 2.3 Database

**Option A: bundled MySQL (recommended)**

```bash
docker compose up -d
docker compose ps             # wait until "healthy"
```

This starts MySQL 8.4 on port **3307** (so it does not clash with a local MySQL on 3306). It creates:
- `lead_crm`: the application database
- `lead_crm_testing`: used by the test suite

<details>
<summary>No <code>docker compose</code> plugin? The same thing with plain Docker</summary>

```bash
docker run -d --name lead-crm-mysql -p 3307:3306 \
  -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=lead_crm \
  -e MYSQL_USER=lead_crm -e MYSQL_PASSWORD=secret \
  -v "$PWD/docker/mysql/init:/docker-entrypoint-initdb.d:ro" mysql:8.4
```
</details>

**Option B: your own MySQL server**

```sql
CREATE DATABASE lead_crm;
CREATE DATABASE lead_crm_testing;
```

Then set `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` in `.env`. The tests reuse those connection settings and only switch the database name to `lead_crm_testing` (see `phpunit.xml`).

> SQLite is not supported: the project relies on MySQL row locks, FULLTEXT indexes and CHECK constraints.

### 2.4 Schema and demo data

```bash
php artisan migrate --seed
```

This creates 1 admin, 2 teams (each with a manager and 3 agents, one of them inactive), about 60 demo leads (created through the real Round Robin flow, so you can see capacity limits applied) and a demo webhook subscription.

### 2.5 Run the application

```bash
php artisan serve
```

Optional, to see the background features working (run each in its own terminal):

```bash
php artisan queue:work --queue=escalations,webhooks,default   # escalation + webhook jobs
php artisan schedule:work                                     # escalation sweeper, every minute
```

---

## 3. What to open

| URL | What it is |
|---|---|
| http://127.0.0.1:8000 | Home page, with links to everything below |
| http://127.0.0.1:8000/admin | **Admin panel** (Filament 5): role-aware dashboard, leads, bulk assignment, users, teams, webhooks |
| http://127.0.0.1:8000/workspace | **Agent Workspace** (Angular 22 SPA, built only on the REST API) |
| http://127.0.0.1:8000/api/documentation | **Swagger UI** for the REST API |

### Demo accounts (password: `password`)

| Role | Email | Sees |
|---|---|---|
| Admin | `admin@example.com` | everything |
| Sales Manager | `manager.cairo@example.com` · `manager.alex@example.com` | their team's leads |
| Sales Agent | `agent1.cairo@example.com` · `agent2.cairo@example.com` · `agent3.cairo@example.com` · `agent1.alex@example.com` · `agent2.alex@example.com` | their own leads |
| Inactive agent | `agent3.alex@example.com` | cannot sign in, never receives leads |

> **Try this:** sign in as an agent and open another agent's lead URL (e.g. `/admin/leads/1`): you get **404**. Authorization is enforced on the server, not only hidden in the UI.

### Using the API from Swagger UI

1. Expand **POST /api/v1/auth/tokens** → *Try it out* → `{"email": "admin@example.com", "password": "password"}` → *Execute*.
2. Copy the `token`, click **Authorize** at the top, paste it.
3. Call any endpoint. Results are scoped to that user's role.

---

## 4. Run the tests

```bash
php artisan test
```

Expected: **all tests pass** (177) in about 25 seconds. The tests use the `lead_crm_testing` database on the same MySQL server, so MySQL must be running.

| Suite | Command | Covers |
|---|---|---|
| All | `php artisan test` | everything |
| Concurrency | `php artisan test --testsuite=Concurrency` | **real parallel PHP processes** racing on assignment, capacity and escalation |
| Feature | `php artisan test --testsuite=Feature` | assignment, capacity, audit, authorization, escalation, Filament, API, webhooks |
| Architecture | `php artisan test --testsuite=Unit` | layering / SOLID rules (Pest `arch()`) |

---

## 5. Performance check (optional)

```bash
php artisan db:seed --class=LargeLeadDatasetSeeder   # adds ~500,000 leads in ~30 s
php artisan leads:benchmark                          # timings + EXPLAIN plans per role and filter
```

To go back to the small demo dataset: `php artisan migrate:fresh --seed`.

---

## 6. Troubleshooting

| Problem | Fix |
|---|---|
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL is not running or still starting: `docker compose ps`, wait for `healthy`. |
| `Access denied for user 'lead_crm'` | The Docker volume was created earlier with other credentials: `docker compose down -v && docker compose up -d`. |
| Port **3307** already in use | `FORWARD_DB_PORT=3310 docker compose up -d`, and set `DB_PORT=3310` in `.env`. |
| Port **8000** already in use | `php artisan serve --port=8080`, and set `APP_URL=http://127.0.0.1:8080` (used by Swagger "Try it out"). |
| `could not find driver` | Install the PHP MySQL extension (`pdo_mysql`), see Prerequisites. |
| `composer install` complains about extensions | Install the extension it names (usually `intl` or `zip`). |
| Tests fail with `Unknown database 'lead_crm_testing'` | You use your own MySQL: run `CREATE DATABASE lead_crm_testing;`. |
| Swagger UI is empty | `php artisan l5-swagger:generate` (it is also generated automatically when `L5_SWAGGER_GENERATE_ALWAYS=true`). |
| `/workspace` shows "has not been built" | The build is committed; if it was deleted: `cd frontend && npm ci && npm run build`. |
| "Escalated today" stays at 0 | Escalation runs on the queue: start `php artisan queue:work …` (section 2.5). Leads escalate 30 minutes after creation. |

---

## 7. Changing the Angular frontend (optional)

```bash
cd frontend
npm ci
npm start        # dev server: http://localhost:4200/workspace/ (proxies /api to :8000; keep `php artisan serve` running)
npm run build    # rebuilds public/workspace-assets
```

---

## 8. Where to read next

- [`README.md`](README.md): overview
- [`docs/TECHNICAL_NOTES.md`](docs/TECHNICAL_NOTES.md): assignment strategy, concurrency protection, authorization, queue idempotency, performance (indexes, eager loading, measurements)
- [`specs/`](specs/): constitution → requirements → design → tasks (spec-driven development)
