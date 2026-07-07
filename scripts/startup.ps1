$ROOT = Split-Path -Parent $MyInvocation.MyCommand.Path

docker compose down 

Write-Host "Building containers..."
docker compose build

Write-Host "Starting containers..."
docker compose up -d

Write-Host "Running migrations..."
docker compose exec app sh -lc "cd /var/www/html/api && ./bin/cake migrations migrate"

Write-Host "Done!"