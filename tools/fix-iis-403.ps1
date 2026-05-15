# Corrige HTTP 403 no IIS — permissões e autenticação anónima.
# Executar no VPS: PowerShell como Administrador
#   cd C:\Apps\Extrator\tools
#   .\fix-iis-403.ps1

$ErrorActionPreference = "Stop"
$siteRoot = "C:\Apps\Extrator"
if ($PSScriptRoot -match "Extrator") {
    $siteRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
}

$siteName = "Extrator"
$poolName = "Extrator"
if (-not (Test-Path "IIS:\Sites\$siteName")) {
    $siteName = (Get-Website | Where-Object { $_.physicalPath -like "*Extrator*" } | Select-Object -First 1).Name
}
if ($siteName) {
    $poolName = (Get-Website -Name $siteName).applicationPool
    Write-Host "Site IIS: $siteName | Pool: $poolName" -ForegroundColor Cyan
} else {
    Write-Host "AVISO: site Extrator nao encontrado no IIS — ajuste `$siteName no script." -ForegroundColor Yellow
    $poolName = "ExtratorPool"
}

$poolIdentity = "IIS AppPool\$poolName"
Write-Host "Raiz: $siteRoot" -ForegroundColor Cyan

# Leitura na raiz do site; escrita só em data
icacls $siteRoot /grant "IIS_IUSRS:(OI)(CI)RX" /T | Out-Null
icacls $siteRoot /grant "${poolIdentity}:(OI)(CI)RX" /T | Out-Null
icacls (Join-Path $siteRoot "data") /grant "${poolIdentity}:(OI)(CI)M" /T | Out-Null
Write-Host "Permissoes icacls aplicadas." -ForegroundColor Green

Import-Module WebAdministration -ErrorAction SilentlyContinue
if (Get-Module WebAdministration) {
    if ($siteName -and (Test-Path "IIS:\Sites\$siteName")) {
        Set-WebConfigurationProperty -Filter "/system.webServer/security/authentication/anonymousAuthentication" -PSPath "IIS:\Sites\$siteName" -Name enabled -Value $true
        Write-Host "Autenticacao anonima: ON no site $siteName" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "Reinicie IIS:" -ForegroundColor Yellow
Write-Host "  iisreset"
Write-Host ""
Write-Host "Teste no PC:" -ForegroundColor Yellow
Write-Host "  http://ext.buscaapp.com/"
Write-Host "  http://ext.buscaapp.com/health.php"
