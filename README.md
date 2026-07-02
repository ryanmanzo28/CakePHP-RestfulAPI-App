
Hydracor Project — repo layout and quick start

Repo layout:

- **/api**: Backend (CakePHP-style app). Contains `composer.json`, `src/`, `config/`, `webroot/`, and `vendor/`.
- **/app**: Frontend (static/JS/CSS pages served by the web server).

Quick start (builds backend from `/api`):

1. Install PHP dependencies for the API:

```bash
docker run --rm -v "$PWD/api":/app -w /app composer:2 composer install --no-interaction
```

2. Build and start the Docker stack:

```bash
docker-compose build --no-cache app
docker-compose up -d
```

3. Visit http://localhost:8080/ for the site; API endpoints are under `/api` (e.g. `/api/workouts`).

Notes:
- Backend code is contained in `/api` so it can be developed and installed independently.
- `api/webroot/index.php` and `api/config/bootstrap.php` are minimal stubs; replace with a full CakePHP bootstrap after you run `composer install`.

Convenience: automated local setup script
---------------------------------------

A helper script `scripts/setup_local.sh` is provided to build the Docker stack, wait for the database, apply SQL migrations from `api/db/migrations`, and run a simple HTTP smoke test:

```bash
./scripts/setup_local.sh
```

This script uses `docker compose` and requires Docker to be installed and running.

