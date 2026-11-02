# Changelog

All notable changes to the CivicPulse web panel and API are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Releases are named by the date they were deployed, since every push to `main` deploys automatically. Each entry lists the commits it covers. **Upgrade notes** call out anything that needs attention when deploying or rolling back: new migrations, environment variables, GitHub secrets or API changes.

How to maintain this file is described in the [README](README.md#maintaining-the-changelog).

## [Unreleased]

### Added
- **Department integration API** (`/api/integrations/v1`). Departments' own systems can read the reports assigned to them and post status updates back:
  - `GET /department`: connection check, the department's workload, and the key's permissions.
  - `GET /reports`: filter by `status` and `category_id`, sync incrementally with `updated_since`, sort, and paginate (up to 100 per page).
  - `GET /reports/{id}`: one report with its full status timeline.
  - `POST /reports/{id}/updates`: set the status, add a public note and an optional photo. The citizen gets a notification.
  - `GET /categories`: reference data.
- **Department API keys.** Admins issue and revoke keys under **Departments → API access**, either read-only or read & update. A key is shown once and only its SHA-256 hash is stored. The panel shows each key's prefix, who issued it and when it was last used. Keys are limited to 120 requests per minute.
- **Public developer documentation** at `/developers`. It covers a quick start, authentication, errors, rate limits, every endpoint with examples, the report object, a sync guide (with Python code) and key-security guidance.
- **OpenAPI 3 specification** at `/openapi/integrations-v1.json`, for Postman or Swagger import and client generation.
- **API activity in the audit log.** Updates posted by department systems appear in the activity log as "*Department* (Integration API)". Issuing and revoking keys is logged too.
- Report timelines in the panel and the citizen app label API-posted updates as "*Department* (via API)".

### Changed
- Panel report updates and API report updates now share one code path (`ReportUpdateService`). History, citizen notifications and audit entries are identical whichever way an update arrives.
- Any posted status update now bumps the report's `updated_at`, so `updated_since` syncs pick up note-only updates too.

### Fixed
- **Deploys failing on a dropped image download.** A deploy failed when GHCR reset the connection mid-download of the AI image. The deploy now retries `docker compose pull` up to 5 times with backoff, and uses `--quiet` so per-layer progress no longer floods the Actions log. A failed pull leaves the previous release running, because `up -d` and the migrations never run.
- The intelephense "undefined method `user`" warning in `ActivityLog::record()` (now uses `Auth::user()`).

### Security
- **Department keys and citizen tokens are isolated.** Each works only on its own API.
- **No reporter email or NIC** is included in integration API responses.
- **A key can't see another department's reports.** It gets a `404` for them, the same as a missing report.

### Upgrade notes
- **Migrations:** `create_department_api_keys_table`, and `add_changed_by_department_id_to_report_status_history_table` (nullable column). Both are additive, so rolling the image back is safe.
- No new environment variables or secrets.
- When changing the integration API, keep the routes, the `/developers` page and `public/openapi/integrations-v1.json` in step. `DeveloperDocsTest` fails otherwise.

## [2026-11-02]

Commit `d30b0ed`.

### Added
- **Staff role.** A new `staff` role, between citizen and administrator. Staff can use the dashboard, citizens, reports and analytics. Administration (categories, departments, staff accounts, activity log) and deleting citizens stay admin-only, and staff don't see the Administration sidebar section.
- **Staff Accounts page** (`/staff`). Create admin and staff accounts, edit them, reset passwords, activate or deactivate, and delete. It shows each account's last activity and how many actions it has logged. Admins can't demote, deactivate or delete themselves.
- **Activity Log page** (`/activity-log`). An audit trail of panel actions: sign-in and sign-out, citizen edits, approvals, rejections (with reason), activation and deletion, report status changes, and category, department and staff changes. It filters by account, action, date range and text, and entries survive deletion of the account that made them.
- A seeded staff account, `staff@civicpulse.test`.

### Removed
- The "coming soon" placeholder page. Every sidebar section is now real.

### Security
- **Citizen routes no longer resolve panel accounts.** Previously `/citizens/{id}/…` accepted any user ID, so with a staff role a staff member could have deactivated an administrator. Citizen routes now resolve only citizens, and staff routes only panel accounts.
- **Deactivated accounts are signed out immediately.** A deactivated panel account is now signed out on its next request; before, deactivation only applied at the next login.

### Upgrade notes
- **Migration:** `create_activity_logs_table`.
- The seeder now also creates `staff@civicpulse.test`. It only runs on fresh seeds, so existing production data is unaffected.

## [2026-11-01]

Commit `29d842c`.

### Added
- **AI report screening.**
  - The Python AI service now lives in this repository under `ai/` and runs as the internal `ai` container.
  - Every new citizen report is screened (Gemini 3.5 Flash-Lite, falling back to 3.1 Flash-Lite) before anything is stored. Reports flagged as spam or scams are refused with a `422` and the reasons.
  - Screening fails closed: if the service is unreachable, the report is refused unless `CIVIC_AI_FAIL_OPEN=true`.
- **CI for the AI service.** A `test-ai` job runs its pytest suite, and the `civic-pulse-ai` image is built and pushed to GHCR.
- **Deploy changes for the AI service.** The deploy writes `ai/.env` from the `GEMINI_API_KEY` secret, and waits for the AI service's health check, failing loudly if it doesn't come up.

### Upgrade notes
- **New GitHub secret:** `GEMINI_API_KEY`. Without it, every new report is refused.
- **New environment variables:** `CIVIC_AI_ENABLED`, `CIVIC_AI_URL`, `CIVIC_AI_TIMEOUT`, `CIVIC_AI_FAIL_OPEN`. In Docker, `CIVIC_AI_URL` is set by Compose.
- **Local development:** run the AI service on port 8001, or set `CIVIC_AI_ENABLED=false`.

## [2026-10-22]

Commit `594dc8e`.

### Security
- Pinned the PHP base image to `8.4.27` to patch CVE-2026-109618 and CVE-2026-109619 (unauthenticated RCE). The floating `php:8.4-fpm-alpine` tag had let the build cache keep serving the vulnerable 8.4.25.

## [2026-09-22]

Commits `b0890b7`, `2db450f`, `c0c973d`, `f6d5c70`.

### Added
- **Contact phone on reports.** Citizens give a contact phone number when reporting. It is shown on the report page and falls back to the account's phone.
- **Photo lightbox.** Report and progress photos open full-size on the report page.

### Changed
- **Category is optional.** Citizens can submit a report without choosing a category; it shows as "Uncategorized".
- **Default seed data.** Categories and departments are seeded by dedicated `CategorySeeder` / `DepartmentSeeder` classes using `firstOrCreate`, so they are safe to re-run.
- **Easier modal closing.** Modals now also close on a backdrop click or the Escape key.

### Fixed
- **Report photos missing in the Android app.** TLS is terminated before the request reaches the container, so photo links were generated as `http://`, which Android blocks. All URLs are now forced to HTTPS when `APP_URL` is `https://`.

### Upgrade notes
- **Migrations:** `make_category_id_nullable_on_reports_table`, `add_contact_phone_to_reports_table`.
- **Citizen API:** `POST /api/v1/reports` now requires `contact_phone`, and `category_id` is optional.

## [2026-09-21]

Commits `e1d4a55`, `c1e61d9`, `bd7cf0e`, `170494d`, `9709167`, `f831a45`, `dd4fa8c`.

### Added
- **Admin panel:**
  - **Dashboard:** report counts, average resolution time, pending citizens, most-voted open reports, department workload.
  - **Reports:** filterable queue and detail page. Staff assign a department, change status, add a note and a progress photo, and the citizen is notified.
  - **Analytics:** resolution rate, median first response, reports by category, a six-month trend, resolution time by department.
  - Category and department management.
- **Citizen API:**
  - Categories, the report feed (newest / most voted), my reports, submitting a report with photo and GPS, a report's detail with status history, upvotes.
  - In-app notifications and FCM device registration.
- **Push notifications** through the Firebase Admin SDK. Sends are a logged no-op when no credentials are configured.
- **Docker:**
  - Multi-stage `Dockerfile` with `app` (PHP-FPM) and `web` (nginx) targets.
  - `docker-compose.yml` with MySQL, a queue worker and a scheduler on an isolated network, exposed only on `127.0.0.1:8081`.
- **CI/CD:** GitHub Actions tests against MySQL, builds and pushes images to GHCR, and deploys to the VPS over SSH. A template host nginx config is included.

### Changed
- The deploy hard-resets the VPS git clone to `origin/main`, so compose and nginx config stay in sync with the images.
- Seeding was moved out of the automated first deploy into an explicit one-time step.
- `fakerphp/faker` is now a production dependency, so `db:seed` works inside the production image.

### Fixed
- **PHP version mismatch.** `composer.lock` required PHP ≥ 8.4.1 while `composer.json`, the Dockerfile and CI declared 8.3, which broke `composer install` in CI. Everything now targets PHP 8.4.
- **Missing runtime libraries in the Docker image.** Removing the `-dev` build packages had also removed the shared libraries the PHP extensions need. The runtime libraries are now kept and only the build dependencies are removed.

## [2026-08-02]

Commit `1fed26f`. Initial version.

### Added
- **Citizen registration** (`POST /api/v1/register`): name, email, phone, NIC number and NIC photos (front and back; image or PDF). Accounts start as *pending*.
- **Citizen sign-in** (`POST /api/v1/login`, `GET /api/v1/me`) with Sanctum tokens. Pending, rejected and deactivated accounts are refused with a reason, and `/me` revokes tokens for accounts that are no longer allowed.
- **Admin panel sign-in**, and **citizen management**: review NIC photos, approve, reject with a reason, edit, activate/deactivate, delete.
- Role-based access middleware, and the Pest test suite.
