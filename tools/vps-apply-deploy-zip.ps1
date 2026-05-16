# Aplica no VPS o ZIP criado NO PC por pack-deploy-zip.ps1.
# Mantem intactos: pasta data\ no servidor e config.local.php (destino ja existente).
#
# PASSOS NO VPS
#   1) Copie para o servidor a pasta Extrator-deploy (ZIP + vps-apply-deploy-zip.ps1).
#   2) Veja no IIS (Gestor do IIS > Sites > seu site) o "Caminho fisico" e use esse valor em -SiteRoot.
#   3) Admin PowerShell:
#      cd PastaOndeEstaOZIP
#      Set-ExecutionPolicy -Scope CurrentUser RemoteSigned -Force
#      dir *.zip
#      .\vps-apply-deploy-zip.ps1 -ZipPath ".\NOME.zip" -SiteRoot "C:\Apps\Extrator"
#   4) Recicle o Application Pool no IIS ou: Restart-WebAppPool -Name "NomeDoPool"
#   5) Abra /health.php no browser (BUILD_ID e Master Extrator).

param(
    [Parameter(Mandatory = $true)]
    [string] $ZipPath,

    [string] $SiteRoot = "C:\Apps\Extrator"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $ZipPath)) {
    throw "ZIP nao encontrado: $ZipPath"
}
if (-not (Test-Path $SiteRoot)) {
    throw "Pasta do site nao existe: $SiteRoot (ajuste -SiteRoot ao caminho fisico do IIS)"
}

$unpack = Join-Path $env:TEMP ("extrator-unpack-{0}" -f ([Guid]::NewGuid().ToString("N")))
if (Test-Path $unpack) { Remove-Item $unpack -Recurse -Force }
New-Item -ItemType Directory -Force -Path $unpack | Out-Null

try {
    Write-Host "A extrair ZIP..." -ForegroundColor Cyan
    Expand-Archive -LiteralPath $ZipPath -DestinationPath $unpack -Force

    Write-Host "A copiar sobre $SiteRoot (preserva data\, config.local.php ja no destino)... " -ForegroundColor Cyan

    # /XO = nao substitui versions mais novas no destino (opcional seguranca de relogio skew)
    # Nao existe pasta data no ZIP empacotado no PC — data\ local no servidor fica intacta se robocopy nao elimina.
    robocopy $unpack $SiteRoot /E `
        /XD "data" `
        /XF "config.local.php" `
        /NFL /NDL /NJH /NJS /nc /ns /np /R:2 /W:2

    if ($LASTEXITCODE -ge 8) {
        throw ("robocopy falhou codigo " + $LASTEXITCODE)
    }

    Write-Host ""
    Write-Host "Concluido. Garantir pastas e permissoes:" -ForegroundColor Green
    New-Item -ItemType Directory -Force -Path (Join-Path $SiteRoot "data\sessions") | Out-Null
    New-Item -ItemType Directory -Force -Path (Join-Path $SiteRoot "data\out") | Out-Null
    Write-Host "  icacls `"$SiteRoot\data`" /grant `"IIS AppPool\Extrat:(OI)(CI)M`" /T" -ForegroundColor DarkGray
    Write-Host "  (Substitua Extrat pelo nome real do Application Pool)" -ForegroundColor DarkGray

    Import-Module WebAdministration -ErrorAction SilentlyContinue
    Write-Host "`n Opcional reiniciar pool:" -ForegroundColor Yellow
    Write-Host '  Restart-WebAppPool -Name "Extrat"' -ForegroundColor White
}
finally {
    if (Test-Path $unpack) { Remove-Item $unpack -Recurse -Force }
}
