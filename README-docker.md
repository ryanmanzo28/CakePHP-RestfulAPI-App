Setup and initialize the CakePHP app for Docker

1) Initialize the CakePHP app in `./api` (uses the official Composer image, no composer needed locally):

```bash
chmod +x scripts/init-api.sh
./scripts/init-api.sh
```

2) Build and start the stack:

```bash
docker-compose up --build -d
```

3) If you run into image pull errors, remove local images and rebuild:

```bash
docker-compose down --rmi local --remove-orphans
docker-compose up --build -d
```

Environment variables: set `JWT_SECRET`, `DB_*`, and `MYSQL_ROOT_PASSWORD` in your shell or an `.env` file before `docker-compose up`.
