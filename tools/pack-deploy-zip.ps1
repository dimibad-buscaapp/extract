# Empacota o Extrator neste PC para copiar ao VPS (sem .git, sem data/, sem config.local.php).
#
# Como usar (PowerShell na pasta php-hostinger OU em php-hostinger/tools):
#   cd "C:\Users\hp\Desktop\curso ia\php-hostinger\tools"
#   .\pack-deploy-zip.ps1
#
# O ZIP fica no Ambiente de trabalho, pasta Extrator-deploy\

$ErrorActionPreference = "Stop"

$root = if (Test-Path (Join-Path $PSScriptRoot "..\bootstrap.php")) {
    (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
} elseif (Test-Path (Join-Path $PSScriptRoot "bootstrap.php")) {
    (Resolve-Path $PSScriptRoot).Path
} else {
    throw "Coloque este script dentro de php-hostinger/tools (ou execute a partir dessa pasta)."
}

$bootstrap = Join-Path $root "bootstrap.php"
if (-not (Test-Path $bootstrap)) { throw "Nao encontrado: $bootstrap" }

$line = ""
$hit = Select-String -Path $bootstrap -Pattern "EXTRACTOR_BUILD_ID" | Select-Object -First 1
if ($hit) {
    $line = $hit.Line
}
if ($line -match "EXTRACTOR_BUILD_ID\s*=\s*'([^']+)'") {
    $buildId = $Matches[1]
} elseif ($line -match 'EXTRACTOR_BUILD_ID\s*=\s*"([^"]+)"') {
    $buildId = $Matches[1]
} else {
    $buildId = "sem-build-id"
}

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$zipName = "Extrator-deploy-{0}-{1}.zip" -f $buildId, $stamp
$desktopPack = Join-Path ([Environment]::GetFolderPath('Desktop')) "Extrator-deploy"
New-Item -ItemType Directory -Force -Path $desktopPack | Out-Null

$staging = Join-Path $env:TEMP ("extrator-staging-{0}" -f $stamp)
if (Test-Path $staging) { Remove-Item $staging -Recurse -Force }

Write-Host "Origem : $root" -ForegroundColor Cyan
Write-Host "BUILD_ID: $buildId" -ForegroundColor Cyan
Write-Host "Staging : $staging" -ForegroundColor DarkGray

# Copia só código público para staging (exclusões iguais ao que o VPS já tem em segurança)
$null = robocopy $root $staging /E `
    /XD .git data node_modules .cursor .vs `
    /XF config.local.php .gitignore thumbs.db *.sqlite *.sqlite-journal *.sqlite-wal *.sqlite-shm `
    /NFL /NDL /NJH /NJS /nc /ns /np /R:1 /W:1

if ($LASTEXITCODE -ge 8) {
    throw ("robocopy falhou (codigo {0})" -f $LASTEXITCODE)
}

$zipPath = Join-Path $desktopPack $zipName
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $zipPath -CompressionLevel Optimal -Force

Remove-Item $staging -Recurse -Force

$vpsApply = Join-Path $PSScriptRoot "vps-apply-deploy-zip.ps1"
if (Test-Path $vpsApply) {
    Copy-Item $vpsApply (Join-Path $desktopPack "vps-apply-deploy-zip.ps1") -Force
}

Write-Host ""
Write-Host "ZIP criado:" -ForegroundColor Green
Write-Host "  $zipPath"
Write-Host ""
Write-Host "Ambiente de trabalho\Extrator-deploy\ tambem contem vps-apply-deploy-zip.ps1 para levar ao VPS." -ForegroundColor DarkGray

Write-Host ""
Write-Host "No VPS:" -ForegroundColor Yellow
Write-Host "  1) Copiar a pasta Ambiente de trabalho\Extrator-deploy\ inteira para o servidor (ZIP + PS1)." -ForegroundColor Gray
Write-Host "  2) PowerShell Administrador na pasta onde estao os ficheiros:" -ForegroundColor Gray
Write-Host '       Set-ExecutionPolicy -Scope CurrentUser RemoteSigned -Force   # se scripts bloqueados' -ForegroundColor DarkGray
Write-Host ('       .\vps-apply-deploy-zip.ps1 -ZipPath ".\\{0}" -SiteRoot "C:\\Apps\\Extrator"' -f $zipName) -ForegroundColor White
Write-Host '    (Altere SiteRoot ao caminho fisico do IIS.)' -ForegroundColor DarkGray
Write-Host ('  3) Abrir /health.php -- BUILD_ID = {0} -- linha "Master Extrator" deve ficar OK.' -f $buildId) -ForegroundColor Gray