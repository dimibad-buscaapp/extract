# Compila um único .exe (self-contained) para copiar ao VPS Windows Server.
# Requer .NET SDK 8+ instalado: https://dotnet.microsoft.com/download/dotnet/8.0

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$out = Join-Path $PSScriptRoot "publish"
if (Test-Path $out) { Remove-Item $out -Recurse -Force }

dotnet publish .\ExtractorVpsSetup.csproj `
    -c Release `
    -r win-x64 `
    --self-contained true `
    -p:PublishSingleFile=true `
    -p:IncludeNativeLibrariesForSelfExtract=true `
    -p:DebugType=None `
    -p:DebugSymbols=false `
    -o $out

Write-Host ""
Write-Host "Concluído. Copie para o VPS:" -ForegroundColor Green
Write-Host "  $out\ExtractorVpsSetup.exe"
Write-Host ""
Write-Host "No VPS (PowerShell como Administrador recomendado para icacls):" -ForegroundColor Cyan
Write-Host "  .\ExtractorVpsSetup.exe"
Write-Host "  .\ExtractorVpsSetup.exe -y    # sem Enter no fim (scripts)"
