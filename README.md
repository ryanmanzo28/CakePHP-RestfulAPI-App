# HydraCor

Small workout tracker app with:

- `app/` for the frontend
- `api/` for the CakePHP backend
- Docker for local development

## Run locally

```bash
docker compose up -d --build
```

Open:

- Frontend: http://localhost:8080/
- API: http://localhost:8080/api

## Project structure

```text
app/   static frontend pages, JS, and CSS
api/   CakePHP API, models, controllers, config, migrations
docker/ nginx and container startup files
scripts/ helper scripts for local setup
```

## Notes

- Database settings come from `docker-compose.yml`
- JWT auth is used for protected API routes
- Workout and feed data are handled through the API under `/api/workouts`

