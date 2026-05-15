# Actualiza C:\Apps\Extrator a partir do GitHub (ZIP) sem apagar config.local.php nem a base SQLite.
# Executar no VPS: PowerShell como Administrador
#   cd C:\Apps\Extrator\tools
#   .\atualizar-vps.ps1

$ErrorActionPreference = "Stop"
$dest = "C:\Apps\Extrator"
if ($PSScriptRoot -match "Extrator") {
    $dest = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
}

Write-Host "Destino: $dest" -ForegroundColor Cyan

$zip = Join-Path $env:TEMP "extract-main.zip"
$url = "https://github.com/dimibad-buscaapp/extract/archive/refs/heads/main.zip"

Write-Host "A descarregar repositorio..." -ForegroundColor Yellow
Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing

$extractRoot = Join-Path $env:TEMP "extract-main-unzip"
if (Test-Path $extractRoot) { Remove-Item $extractRoot -Recurse -Force }
Expand-Archive -Path $zip -DestinationPath $env:TEMP -Force

$src = Join-Path $env:TEMP "extract-main"
if (-not (Test-Path $src)) {
    Write-Host "ERRO: pasta extract-main nao encontrada apos unzip" -ForegroundColor Red
    exit 1
}

# Preservar segredos e dados
$preserve = @(
    "config.local.php",
    "data\app.sqlite",
    "data\app.sqlite-wal",
    "data\app.sqlite-shm"
)

Write-Host "A copiar ficheiros (preservando config.local.php e app.sqlite)..." -ForegroundColor Yellow
robocopy $src $dest /E /XO /XD ".git" /XF $preserve /NFL /NDL /NJH /NJS /nc /ns /np
if ($LASTEXITCODE -ge 8) {
    Write-Host "ERRO robocopy codigo $LASTEXITCODE" -ForegroundColor Red
    exit 1
}

# Garantir pastas data
New-Item -ItemType Directory -Force -Path (Join-Path $dest "data\sessions") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $dest "data\out") | Out-Null

Write-Host ""
Write-Host "Concluido. Teste no browser:" -ForegroundColor Green
Write-Host "  http://SEU-IP/diag.php"
Write-Host "  http://SEU-IP/health.php"
Write-Host "  http://SEU-IP/index.php"
Write-Host ""
Write-Host "Permissoes (ajuste Extrat ao nome do pool):" -ForegroundColor Cyan
Write-Host '  icacls "' + $dest + '\data" /grant "IIS AppPool\Extrat:(OI)(CI)M" /T'
Write-Host "  iisreset"
