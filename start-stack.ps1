$translationWorkerCount = 5
$productSyncWorkerCount = 2


Write-Host "Shutting down first" -ForegroundColor Green
docker compose down

Write-Host "Building Docker images..." -ForegroundColor Green
docker compose build

Write-Host "Starting services..." -ForegroundColor Green
docker compose up -d --scale worker-translation=$translationWorkerCount --scale worker-product-sync=$productSyncWorkerCount

Write-Host "Stack started successfully!" -ForegroundColor Green