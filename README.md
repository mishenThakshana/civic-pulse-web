# CivicPulse — Web Panel & API

CivicPulse lets citizens report local infrastructure problems (potholes, broken street lights, garbage, flooding…) from a mobile app, and lets the council triage them, route them to the responsible department, and keep citizens informed until each one is fixed.

This repository is the server side. One Laravel 13 application serves three audiences:

| Surface | Who uses it | Where |
|---|---|---|
| **Admin panel** | Council administrators and staff | `routes/web.php`, `resources/views/panel/` |
| **Citizen API** (`/api/v1`) | The CivicPulse mobile app | `routes/api.php`, `app/Http/Controllers/Api/` |
| **Department integration API** (`/api/integrations/v1`) | Departments' own work-order / ticketing systems | `routes/api.php`, `app/Http/Controllers/Api/Integrations/` |

Next to it, `ai/` holds a Python (FastAPI) **report screening service**. Every new report passes through it before it is stored, so spam and scam submissions never reach the database.

See [CHANGELOG.md](CHANGELOG.md) for what changed, and when.

## Contents

- [Features](#features)
- [Roles and permissions](#roles-and-permissions)
- [Tech stack and repository layout](#tech-stack-and-repository-layout)
- [Local development](#local-development)
- [Configuration](#configuration)
- [APIs](#apis)
- [Docker](#docker)
- [CI/CD](#cicd)
- [VPS deployment (one-time setup)](#vps-deployment-one-time-setup)
- [Operations](#operations)
- [Maintaining the changelog](#maintaining-the-changelog)

## Features

### Admin panel

- **Dashboard.** Open / in-progress / resolved counts, average resolution time, citizens awaiting approval, the most-voted open reports, and each department's open workload.
- **Citizens.** Review registrations against the NIC photos (front and back, image or PDF). Approve, or reject with a reason the citizen sees when signing in. Edit details, and deactivate or delete accounts.
- **Reports.** A filterable queue (status, category, department, search) ordered by citizen votes. The detail page shows the photo, map link, contact details and the full status timeline. Staff assign a department, change the status, add a public note and an optional progress photo; each update notifies the citizen in-app and by push.
- **Analytics.** Resolution rate, median first-response time, reports by category, a six-month submitted-vs-resolved trend, and resolution time by department.
- **Categories and departments.** Manage the categories citizens choose from and the departments reports are routed to. Each department page shows its workload and average resolution time.
- **Department API access.** Issue and revoke API keys that let a department's own systems connect (see [Department integration API](#department-integration-api)).
- **Staff accounts.** Create, edit, reset passwords for, deactivate and delete panel accounts. Admins can't demote, deactivate or delete themselves.
- **Activity log.** An audit trail of every panel action (sign-ins, citizen reviews, report updates, configuration and account changes, API key changes, and updates posted by department systems), with filters by account, action, date range and text.

### Citizen API (mobile app)

Registration with NIC upload and admin approval, sign-in with Sanctum tokens, report submission (photo + GPS + address + contact phone), a public report feed with upvotes, "my reports", in-app notifications, and push notifications through Firebase Cloud Messaging.

### Department integration API

Per-department API keys (read-only, or read & update). Departments pull the reports assigned to them, sync incrementally with `updated_since`, and post status updates back, which notify the citizen. Full documentation for integrators is public at **`/developers`**, with a machine-readable OpenAPI spec at `/openapi/integrations-v1.json`.

### AI report screening

New reports are screened by the `ai/` service (Gemini 3.5 Flash-Lite, falling back to 3.1 Flash-Lite) before anything is stored. Reports flagged as spam or scams are refused with a `422`. If the service is unreachable, reports are refused too (fail closed) unless `CIVIC_AI_FAIL_OPEN=true`. See [ai/README.md](ai/README.md).

## Roles and permissions

| Capability | Administrator | Staff | Citizen |
|---|:-:|:-:|:-:|
| Sign in to the admin panel | ✓ | ✓ | – |
| Dashboard, reports, analytics | ✓ | ✓ | – |
| Review, edit and (de)activate citizens | ✓ | ✓ | – |
| Delete citizens | ✓ | – | – |
| Categories, departments, API keys | ✓ | – | – |
| Staff accounts and activity log | ✓ | – | – |
| Use the mobile app (citizen API) | – | – | ✓ once approved |

Roles are enforced by the `role:` middleware (`app/Http/Middleware/RoleMiddleware.php`). A deactivated panel account is signed out on its next request, not only at its next login.

## Tech stack and repository layout

- PHP 8.4+ (developed on 8.5), Laravel 13, Sanctum 4, Pest 5, Pint
- MySQL 8 (SQLite works for quick local runs)
- Blade + plain CSS/JS, bundled by Vite 8. There is no front-end framework.
- Firebase Admin SDK (`kreait/laravel-firebase`) for push notifications
- Python 3.11 + FastAPI for the AI screening service

```
app/
  Enums/                 UserRole, ReportStatus, ApprovalStatus, ActivityAction
  Http/Controllers/      panel controllers
  Http/Controllers/Api/  citizen API; Api/Integrations/ = department API
  Http/Middleware/       RoleMiddleware, AuthenticateDepartmentApiKey
  Models/                User, Report, Department, DepartmentApiKey, ActivityLog, …
  Services/              ReportUpdateService, ReportScreeningService, PushNotificationService
ai/                      AI screening service (own README, tests and Dockerfile)
database/                migrations, factories, seeders
docker/                  nginx/php config, entrypoint, host nginx example
public/openapi/          OpenAPI spec for the integration API
resources/views/         panel/, auth/, developers/ (public API docs)
tests/Feature/           Pest feature tests (panel, Api/, integration API)
```

`ReportUpdateService` is the single code path for posting a status update. Both the panel and the integration API use it, so history, notifications and the audit log stay consistent.

## Local development

```
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=0.0.0.0 --port=8000
npm run dev   # in a second terminal, for Vite HMR
```

`composer run dev` starts the server, queue worker, log tail and Vite together.

**Report submission needs the AI service.** Screening fails closed, so the mobile app's report submission is refused until either:

- you run the AI service on the port `.env.example` expects:
  ```
  cd ai && cp .env.example .env    # add a GEMINI_API_KEY for real screening
  pip install -r requirements.txt  # see ai/README.md for CPU-only torch
  uvicorn app.main:app --reload --port 8001
  ```
- or you set `CIVIC_AI_ENABLED=false` in `.env` to skip screening locally.

### Panel logins (seeded)

| Email | Password | Role |
|---|---|---|
| admin@civicpulse.test | password | Administrator |
| staff@civicpulse.test | password | Staff |

The seeder also creates the default categories and departments.

### Tests

```
php artisan test --compact                 # whole suite
php artisan test --compact --filter=Staff  # one area
vendor/bin/pint --dirty                    # format changed PHP files
cd ai && python -m pytest -q               # AI service tests
```

The PHP tests run against MySQL (`civic_pulse_testing` on `127.0.0.1`, user `root`, no password; see `phpunit.xml`). Create that database once before the first run.

## Configuration

The variables beyond Laravel's defaults:

| Variable | File | Purpose |
|---|---|---|
| `APP_URL` | `.env` | Used for every generated link, including report photo URLs and the base URL shown on `/developers`. When it starts with `https://`, all URLs are forced to HTTPS (needed behind the TLS-terminating proxy, or Android blocks the images). |
| `FIREBASE_CREDENTIALS` | `.env` | Path to a Firebase service-account JSON. Without it, push notifications are skipped and logged, never thrown. |
| `CIVIC_AI_ENABLED` | `.env` | `false` skips AI screening entirely. Default `true`. |
| `CIVIC_AI_URL` | `.env` | AI service base URL. Default `http://127.0.0.1:8001`; Docker Compose overrides it to `http://ai:8000`. |
| `CIVIC_AI_TIMEOUT` | `.env` | Seconds to wait for screening. Default `25`. |
| `CIVIC_AI_FAIL_OPEN` | `.env` | `true` accepts reports when the AI service is down. Default `false`, which refuses them. |
| `GEMINI_API_KEY` | `ai/.env` | Enables the multimodal screening model. In production the deploy job writes this from a GitHub secret. Other AI settings are documented in `ai/.env.example`. |

## APIs

### Citizen API — `/api/v1`

Used by the mobile app. Authenticate with `POST /login` and send the returned Sanctum token as `Authorization: Bearer <token>`. Citizens can only sign in once an admin has approved their registration.

| Method | Path | Description |
|---|---|---|
| POST | `/register` | Register with name, email, phone, NIC number, NIC photos (front/back; image or PDF) and password (with `password_confirmation`). Creates a *pending* account; no token is issued. |
| POST | `/login` | Returns a token. Refused for pending, rejected or deactivated accounts, with the reason. |
| GET | `/me` | Revalidates a stored token. Revokes it and returns `403` if the account is no longer allowed. |
| GET | `/categories` | Report categories. |
| GET | `/reports` | Public report feed. `?status=`, `?sort=newest\|most_voted`. |
| GET | `/reports/mine` | The signed-in citizen's own reports. |
| POST | `/reports` | Submit a report (multipart: `photo`, `description`, `latitude`, `longitude`, `address`, `contact_phone`, optional `category_id`). AI-screened first; `422` if flagged. |
| GET | `/reports/{id}` | One report with its status history. |
| POST / DELETE | `/reports/{id}/upvote` | Add or remove the citizen's vote. |
| GET | `/notifications` | In-app notifications. |
| POST | `/devices` | Register an FCM device token for push notifications. |

### Department integration API

For departments connecting their own systems. **Public documentation: `/developers`**, with the OpenAPI spec at [`public/openapi/integrations-v1.json`](public/openapi/integrations-v1.json).

| Method | Path | Description |
|---|---|---|
| GET | `/department` | Connection check: the key's department, workload and permissions. |
| GET | `/categories` | Report categories. |
| GET | `/reports` | Reports assigned to the department. `?status`, `?category_id`, `?updated_since`, `?sort`, `?per_page`. |
| GET | `/reports/{id}` | One report with its full timeline. Reports belonging to other departments return `404`. |
| POST | `/reports/{id}/updates` | Post a status update and note (optional photo). Notifies the citizen. Needs a *read & update* key. |

How it works:

- **Authentication.** `Authorization: Bearer cpk_…`. Keys are issued in the panel under **Departments → API access**. The key is shown once and only its SHA-256 hash is stored. Revoking a key takes effect immediately.
- **Isolation.** Department keys and citizen tokens are separate systems; neither works on the other's API. Responses never include a reporter's email or NIC.
- **Limits and auditing.** 120 requests per minute per key. Every update is recorded in the activity log, and the key's last-used time is shown in the panel.
- **Keeping the docs honest.** `tests/Feature/DeveloperDocsTest.php` fails if the routes, the `/developers` page and the OpenAPI spec stop matching. When you change the integration API, update all three and bump the spec's `info.version`. Breaking changes go in a new `/v2`.

## Docker

The multi-stage `Dockerfile` has two targets:

- **`app`**: PHP 8.4-FPM (base image pinned to a patched release), compiled Composer vendor and built Vite assets; runs `php-fpm`.
- **`web`**: nginx; serves static files directly and proxies `*.php` requests to `app:9000`.

`docker-compose.yml` wires these together with MySQL, a queue worker, a scheduler and the AI service, all on an isolated `civicpulse` bridge network. Nothing binds to host ports 80/443, so it won't collide with anything else already running on the machine.

### Run it locally

```
cp .env.docker.example .env
# fill in APP_KEY (see below), DB_PASSWORD, DB_ROOT_PASSWORD
cp ai/.env.example ai/.env   # optional: add GEMINI_API_KEY

docker compose build
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force --seed
```

Visit http://localhost:8081.

### Services

| Service | Image | Purpose |
|---|---|---|
| `app` | `civic-pulse-app` (target `app`) | PHP-FPM; handles all requests |
| `web` | `civic-pulse-web-nginx` (target `web`) | nginx; the public entrypoint on `127.0.0.1:8081` |
| `queue` | `civic-pulse-app` | `php artisan queue:work` |
| `scheduler` | `civic-pulse-app` | `php artisan schedule:work` |
| `ai` | `civic-pulse-ai` (built from `ai/`) | Report screening (FastAPI on port 8000). Internal only, never exposed on the host. |
| `db` | `mysql:8.0` | Database, persisted in the `db-data` volume |

Uploaded files (`storage/app/public/...`), logs and cache live in the shared `storage-data` volume. It is mounted into both `app` and `web`, so nginx serves `public/storage/...` directly without going through PHP.

### Environment variables

Start from `.env.docker.example`. It differs from `.env.example` in a few Docker-specific ways:

- `DB_HOST` is always overridden to `db`, and `CIVIC_AI_URL` to `http://ai:8000`, by `docker-compose.yml`, whatever `.env` says.
- `DB_ROOT_PASSWORD` is a new variable, used only to initialise the `mysql` container. Laravel never reads it.
- `FIREBASE_CREDENTIALS` points at `/var/www/html/storage/app/firebase-service-account.json`. Copy your service-account JSON into the `storage-data` volume at that path (e.g. `docker compose cp firebase-service-account.json app:/var/www/html/storage/app/`).
- `IMAGE_TAG` sets which tag `docker-compose.yml` pulls (defaults to `latest`). Laravel doesn't read it.
- The `ai` service reads `ai/.env` (optional; git-ignored).

## CI/CD

`.github/workflows/deploy.yml` runs on every push and pull request to `main`:

1. **test**: installs PHP 8.4, Composer and npm dependencies against a MySQL service container, builds assets, runs migrations and `php artisan test --compact`.
2. **test-ai**: installs the AI service's Python 3.11 dependencies (CPU-only torch) and runs `pytest`.
3. **build-and-push** *(push to `main` only, after both test jobs pass)*: builds and pushes three images to GitHub Container Registry, each tagged `:latest` and `:<commit-sha>`:
   - `ghcr.io/mishenthakshana/civic-pulse-app`
   - `ghcr.io/mishenthakshana/civic-pulse-web-nginx`
   - `ghcr.io/mishenthakshana/civic-pulse-ai`
4. **deploy** *(main only)*: runs one at a time. A deploy started while another is running waits for it, rather than both recreating containers at once. It SSHes into the VPS and:
   - hard-resets its git clone to `origin/main`, so `docker-compose.yml`, nginx config and the rest stay in sync, not just the images;
   - rewrites `ai/.env` from the `GEMINI_API_KEY` secret;
   - pulls the new images, retrying up to 5 times with backoff because GHCR occasionally drops large layer downloads, then runs `docker compose up -d` and `migrate --force`. If the pull still fails, nothing is restarted and the previous release keeps running;
   - waits up to ~90 s for the AI service's health check, failing the deploy (with its logs) if it doesn't come up;
   - prunes old images.

### Required GitHub secrets

Set these under the repo's **Settings → Secrets and variables → Actions**:

| Secret | Value |
|---|---|
| `VPS_HOST` | VPS IP or hostname |
| `VPS_USER` | SSH user to deploy as |
| `VPS_SSH_KEY` | Private key of a dedicated deploy keypair (see below) |
| `VPS_PORT` | *(optional)* SSH port; defaults to 22 |
| `GEMINI_API_KEY` | Google Gemini API key for AI screening. Without it the AI service can't screen, and new reports are refused. |

`GITHUB_TOKEN` (used to push to GHCR) is provided automatically. Once the first images are pushed, check that the packages' visibility under **Packages** is what you want.

## VPS deployment (one-time setup)

The VPS already runs other sites behind its own nginx, systemd and Postgres. This app runs fully containerised and independently of them: its own nginx, MySQL and AI service on an internal Docker network, exposed to the host only on `127.0.0.1:8081`. The existing host nginx reverse-proxies the public domain to that port. Nothing here touches ports 80/443 directly or any other site's config.

1. **Install Docker**, if not already present:
   ```
   curl -fsSL https://get.docker.com | sh
   ```

2. **Create a dedicated deploy key** for GitHub Actions (on your own machine, not the VPS):
   ```
   ssh-keygen -t ed25519 -C "github-actions-civicpulse" -f ./civicpulse_deploy_key -N ""
   ```
   Append `civicpulse_deploy_key.pub` to the VPS's `~/.ssh/authorized_keys` for the deploy user, then paste the contents of `civicpulse_deploy_key` (the private key) into the `VPS_SSH_KEY` GitHub secret. Delete the local copies afterwards.

3. **Clone the repo onto the VPS as the deploy directory.** The `deploy` job runs `git fetch` + `git reset --hard` here on every push, so this must be a real git clone, not just a copy of `docker-compose.yml`:
   ```
   sudo mkdir -p /srv/civicpulse
   git clone https://github.com/mishenThakshana/civic-pulse-web.git /srv/civicpulse
   cd /srv/civicpulse
   ```

4. **Create `.env`** (git-ignored, so `git reset --hard` never touches it) and fill in real secrets:
   ```
   cp .env.docker.example .env
   php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"   # → APP_KEY
   openssl rand -base64 32   # → DB_PASSWORD / DB_ROOT_PASSWORD
   ```
   Set `APP_URL` to the public `https://` domain. If you need push notifications, also put your Firebase service-account JSON at `storage/app/firebase-service-account.json` inside the `storage-data` volume (see [Docker](#docker)). You don't need to create `ai/.env` by hand: every deploy writes it from the `GEMINI_API_KEY` secret.

5. **First deploy:**
   ```
   docker compose pull
   docker compose up -d
   docker compose exec app php artisan migrate --force
   ```
   On every later push, the `deploy` job runs the plain `migrate --force` (no `--seed`).

6. **Seed the initial accounts (one time only, not part of the automated deploy):**
   ```
   docker compose exec app php artisan db:seed --force
   ```
   This creates `admin@civicpulse.test` (administrator) and `staff@civicpulse.test` (staff), both with the password `password`, plus the default categories and departments. Do **not** run it again on this database. Categories and departments use `firstOrCreate` and are safe to repeat, but the two accounts use a plain `create()` and will throw a duplicate-email error.

   These accounts are now live on the public domain, so immediately sign in as the admin and use **Staff Accounts** to change both passwords. If you don't need the demo staff account, delete it. To do it from the shell instead:
   ```
   docker compose exec app php artisan tinker --execute 'App\Models\User::where("email","admin@civicpulse.test")->first()->update(["password"=>Hash::make("<your-real-password>")]);'
   ```

7. **Point the host nginx at it.** Use `docker/vps-nginx-host.conf.example` as a template. It's a reference file only, meant to be copied onto the VPS as a new server block alongside the existing sites:
   ```
   sudo cp docker/vps-nginx-host.conf.example /etc/nginx/sites-available/civicpulse.ribluma.com
   sudo ln -s /etc/nginx/sites-available/civicpulse.ribluma.com /etc/nginx/sites-enabled/
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot --nginx -d civicpulse.ribluma.com
   ```

After this, every push to `main` builds, tests and redeploys automatically.

## Operations

### Rollback

Every image is also pushed with its commit SHA as a tag. To roll back, run this on the VPS:
```
export IMAGE_TAG=<previous-sha>
docker compose pull
docker compose up -d
```
`docker compose pull` is required: `up -d` alone reuses whatever image is cached locally, and won't fetch the SHA tag if it isn't already there.

Rolling back images does **not** roll back database migrations. Check [CHANGELOG.md](CHANGELOG.md) for the migrations a release added before rolling back past it.

### Common commands (VPS or local)

Run these from the directory containing `docker-compose.yml` (`/srv/civicpulse` on the VPS).

```
docker compose restart              # restart all services, same containers/images — never pulls anything new
docker compose restart app          # restart one service (app, web, queue, scheduler, ai, db)

docker compose pull && docker compose up -d   # fetch the latest :latest images from GHCR and recreate what changed
docker compose up -d --pull always            # same idea, one command

docker compose up -d --force-recreate  # recreate everything even if no image changed (e.g. after editing .env)

docker compose down                 # stop and remove containers (volumes are kept)
docker compose up -d                # start them again, reusing cached images

docker compose logs -f app          # tail logs for one service
docker compose logs -f ai           # AI screening logs: check here first if reports are being refused
docker compose ps                   # container status

docker compose exec app sh          # shell into the app container
docker compose exec app php artisan tinker
```

A plain `docker compose restart` does **not** pick up `.env` changes; it only restarts the existing container process. After editing `.env`, run `docker compose up -d --force-recreate` so the container gets the new environment.

### Troubleshooting

| Symptom | Likely cause |
|---|---|
| Every new report is refused with a screening error | The AI service is down, or `GEMINI_API_KEY` is missing or invalid. Check `docker compose logs ai`. As a stop-gap, `CIVIC_AI_FAIL_OPEN=true` accepts reports unscreened. |
| Report photos don't load in the Android app | `APP_URL` isn't `https://…`, so links are generated as `http://`. |
| Push notifications never arrive | `FIREBASE_CREDENTIALS` is missing or unreadable. Sends are skipped and logged. |
| A department's integration returns `401` | Its key was revoked, or the wrong key is being sent. Check **Departments → API access** for the key prefix and last-used time. |

## Maintaining the changelog

[CHANGELOG.md](CHANGELOG.md) follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

- **Add an entry with the change itself.** Put a line under `## [Unreleased]` in the same commit or PR. Group it under **Added**, **Changed**, **Fixed**, **Security**, **Removed** or **Deprecated**, and write for someone running or integrating with the system, not as a commit message.
- **Call out operational impact** in a `### Upgrade notes` list: new migrations, new environment variables or GitHub secrets, changes to the integration or citizen APIs, anything to do by hand after deploying.
- **Releasing:** when a set of changes ships to production, rename `[Unreleased]` to the deploy date (`## [YYYY-MM-DD]`), list the commit range, and start a fresh `[Unreleased]` section above it.
