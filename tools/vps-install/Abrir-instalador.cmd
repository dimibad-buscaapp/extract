@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"

if exist "%~dp0publish\ExtractorVpsSetup.exe" (
  echo A executar: publish\ExtractorVpsSetup.exe
  "%~dp0publish\ExtractorVpsSetup.exe"
  echo.
  pause
  exit /b %ERRORLEVEL%
)

if exist "%~dp0ExtractorVpsSetup.exe" (
  echo A executar: ExtractorVpsSetup.exe
  "%~dp0ExtractorVpsSetup.exe"
  echo.
  pause
  exit /b %ERRORLEVEL%
)

echo.
echo  NAO FOI ENCONTRADO ExtractorVpsSetup.exe
echo.
echo  O executavel NAO vem pelo Git. No teu PC com .NET SDK 8:
echo    1) Abre CMD na pasta: tools\vps-install
echo    2) Executa: build.cmd
echo    3) Copia o ficheiro  publish\ExtractorVpsSetup.exe  para este VPS
echo       ^(pasta a tua escolha, por ex. Ambiente de Trabalho ou esta pasta^)
echo    4) Volta a correr este ficheiro OU no PowerShell:
echo        ^& ".\ExtractorVpsSetup.exe"
echo.
pause
exit /b 1
