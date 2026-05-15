@echo off
setlocal
cd /d "%~dp0"

set "OUT=%~dp0publish"
if exist "%OUT%" rmdir /s /q "%OUT%"

dotnet publish "%~dp0ExtractorVpsSetup.csproj" ^
  -c Release ^
  -r win-x64 ^
  --self-contained true ^
  -p:PublishSingleFile=true ^
  -p:IncludeNativeLibrariesForSelfExtract=true ^
  -p:DebugType=None ^
  -p:DebugSymbols=false ^
  -o "%OUT%"

if errorlevel 1 exit /b 1

echo.
echo Concluido. Copie para o VPS:
echo   %OUT%\ExtractorVpsSetup.exe
echo.
exit /b 0
