CivicPulse is a Laravel 13 application that serves both the admin web panel (`routes/web.php`) and the mobile API (`routes/api.php`) from a single app.

## Local development (no Docker)

```
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=0.0.0.0 --port=8000
npm run dev   # in a second terminal, for Vite HMR
```

### Admin login

| Email | Password |
|---|---|
| admin@civicpulse.test | password |

## Docker

The app ships with a multi-stage `Dockerfile` with two targets:

- **`app`** — PHP 8.4-FPM, compiled Composer vendor + built Vite assets, runs `php-fpm`.
- **`web`** — nginx, serves static files directly and proxies `*.php` requests to `app:9000`.

`docker-compose.yml` wires these together with a MySQL container and a queue/scheduler worker, all on an isolated `civicpulse` bridge network. Nothing here binds to host ports 80/443, so it won't collide with anything else already running on a machine.

### Run it locally

```
cp .env.docker.example .env
# fill in APP_KEY (see below), DB_PASSWORD, DB_ROOT_PASSWORD

docker compose build
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force --seed
```

Visit http://localhost:8081.

### Services

| Service | Image target | Purpose |
|---|---|---|
| `app` | `app` | PHP-FPM, handles all requests |
| `web` | `web` | nginx, public entrypoint on `127.0.0.1:8081` |
| `queue` | `app` | `php artisan queue:work` |
| `scheduler` | `app` | `php artisan schedule:work` |
| `db` | `mysql:8.0` | database, persisted in the `db-data` volume |

Uploaded files (`storage/app/public/...`) and logs/cache live in the shared `storage-data` volume, mounted into both `app` and `web` so nginx can serve `public/storage/...` directly without proxying through PHP.

### Environment variables

Start from `.env.docker.example`, which differs from `.env.example` in a few Docker-specific ways:

- `DB_HOST` is always overridden to `db` by `docker-compose.yml`, regardless of what's in `.env`.
- `DB_ROOT_PASSWORD` — new variable, only used to initialize the `mysql` container; Laravel never reads it.
- `FIREBASE_CREDENTIALS` — points at `/var/www/html/storage/app/firebase-service-account.json`. Copy your Firebase service-account JSON into the `storage-data` volume at that path (e.g. `docker compose cp firebase-service-account.json app:/var/www/html/storage/app/`) — push notifications are a no-op (logged, not thrown) until this exists.
- `IMAGE_TAG` — which tag `docker-compose.yml` pulls (defaults to `latest`); not read by Laravel.

## CI/CD

`.github/workflows/deploy.yml` runs on every push/PR to `main`:

1. **test** — installs PHP/Composer/npm deps against a MySQL service container, builds assets, runs migrations, runs `php artisan test --compact`.
2. **build-and-push** *(main only)* — builds both Docker targets and pushes them to GitHub Container Registry:
   - `ghcr.io/mishenthakshana/civic-pulse-app:latest` / `:<sha>`
   - `ghcr.io/mishenthakshana/civic-pulse-web-nginx:latest` / `:<sha>`
3. **deploy** *(main only)* — SSHes into the VPS, hard-resets its git clone to `origin/main` (so `docker-compose.yml`, nginx config, etc. stay in sync too — not just the app image), then `docker compose pull && docker compose up -d`, then runs migrations.

### Required GitHub secrets

Set these under the repo's **Settings → Secrets and variables → Actions**:

| Secret | Value |
|---|---|
| `VPS_HOST` | VPS IP or hostname |
| `VPS_USER` | SSH user to deploy as |
| `VPS_SSH_KEY` | Private key of a dedicated deploy keypair (see below) |
| `VPS_PORT` | *(optional)* SSH port, defaults to 22 |

`GITHUB_TOKEN` (used to push to GHCR) is provided automatically — no setup needed, but make sure the repo's package visibility is set the way you want under **Packages** once the first image is pushed.

## VPS deployment (one-time setup)

The VPS already runs other sites behind its own nginx + systemd + Postgres. This app runs fully containerized and independent of that: its own nginx, its own MySQL, on an internal Docker network, exposed to the host only on `127.0.0.1:8081`. The existing host nginx reverse-proxies the public domain to that port — nothing here touches ports 80/443 directly or any other site's config.

1. **Install Docker**, if not already present:
   ```
   curl -fsSL https://get.docker.com | sh
   ```

2. **Create a dedicated deploy key** for GitHub Actions (run on your own machine, not the VPS):
   ```
   ssh-keygen -t ed25519 -C "github-actions-civicpulse" -f ./civicpulse_deploy_key -N ""
   ```
   Append `civicpulse_deploy_key.pub` to the VPS's `~/.ssh/authorized_keys` for the deploy user, then paste the contents of `civicpulse_deploy_key` (the private key) into the `VPS_SSH_KEY` GitHub secret. Delete the local copies afterward.

3. **Clone the repo onto the VPS as the deploy directory** (the `deploy` job runs `git fetch` + `git reset --hard` here on every push, so this must be a real git clone, not just a copy of `docker-compose.yml`):
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
   Also drop your Firebase service-account JSON into `storage/app/firebase-service-account.json` inside the `storage-data` volume (see the Docker section above) if push notifications are needed.

5. **First deploy:**
   ```
   docker compose pull
   docker compose up -d
   docker compose exec app php artisan migrate --force
   ```
   The GitHub Actions `deploy` job repeats exactly this (minus `pull`/`up -d`'s first-run setup) on every push after this — just the plain `migrate --force`, no `--seed`.

6. **Seed the admin user (one-time only, not part of the automated deploy):**
   ```
   docker compose exec app php artisan db:seed --force
   ```
   This creates `admin@civicpulse.test` with the password `password`, plus the default categories/departments. Do **not** run it again on this database — categories/departments use `firstOrCreate` and are safe to repeat, but the admin user is created with a plain `create()` and will throw a duplicate-email error on a second run.

   Change the seeded password immediately since it's now live on the public domain:
   ```
   docker compose exec app php artisan tinker --execute 'App\Models\User::where("email","admin@civicpulse.test")->first()->update(["password"=>Hash::make("<your-real-password>")]);'
   ```

7. **Point the host nginx at it.** Use `docker/vps-nginx-host.conf.example` as a template — it's a reference file only, meant to be copied onto the VPS as a new server block alongside the existing sites:
   ```
   sudo cp docker/vps-nginx-host.conf.example /etc/nginx/sites-available/civicpulse.ribluma.com
   sudo ln -s /etc/nginx/sites-available/civicpulse.ribluma.com /etc/nginx/sites-enabled/
   sudo nginx -t && sudo systemctl reload nginx
   sudo certbot --nginx -d civicpulse.ribluma.com
   ```

After this, every push to `main` builds, tests, and redeploys automatically.

### Rollback

Every image is also pushed with its commit SHA as a tag. To roll back, on the VPS:
```
export IMAGE_TAG=<previous-sha>
docker compose pull
docker compose up -d
```
`docker compose pull` is required — `up -d` alone reuses whatever image is already cached locally and won't fetch the SHA tag if it isn't already there.

### Common operations (VPS or local)

Run from the directory containing `docker-compose.yml` (`/srv/civicpulse` on the VPS).

```
docker compose restart              # restart all services, same containers/images — never pulls anything new
docker compose restart app          # restart just one service (app, web, queue, scheduler, db)

docker compose pull && docker compose up -d   # fetch the latest :latest image from GHCR and recreate if it changed
docker compose up -d --pull always            # same idea, one command

docker compose up -d --force-recreate  # recreate everything even if the image didn't change (e.g. after editing .env)

docker compose down                 # stop and remove containers (volumes are kept)
docker compose up -d                # start them again — reuses whatever image is already cached locally

docker compose logs -f app          # tail logs for one service
docker compose ps                   # container status

docker compose exec app sh          # shell into the app container
docker compose exec app php artisan tinker
```

A plain `docker compose restart` does **not** pick up `.env` changes — it just restarts the existing container process. Use `docker compose up -d --force-recreate` after editing `.env` so the container is rebuilt with the new environment.
