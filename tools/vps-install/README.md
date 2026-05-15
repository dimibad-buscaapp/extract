# ExtractorVpsSetup.exe — instalação rápida no VPS Windows

Ferramenta em **.NET 8** que, no **Windows Server** (incl. 2025):

1. Faz **`git clone`** ou **`git pull`** para `C:\apps\Extrator` (ou outro caminho com `--dir`).
2. Cria **`config.local.php`** a partir de `config.example.php` com **`app_secret`** aleatório (64 hex), **só se** o ficheiro ainda não existir (nunca sobrescreve).
3. Garante pastas **`data`** e **`data\out`** e tenta permissões (**icacls**) para **IIS_IUSRS** e **BUILTIN\\Users** na pasta `data`.

**Não** instala PHP nem IIS — só prepara o código e config mínima. SSL, site IIS e DNS continuam manuais (ver `docs\` na raiz do repositório).

## Requisitos no VPS

- [Git for Windows](https://git-scm.com/download/win) no PATH.
- Para **publicar** o `.exe`: no teu PC (ou no servidor se tiveres SDK), [.NET SDK 8](https://dotnet.microsoft.com/download/dotnet/8.0).

## Gerar o .exe

Na pasta `tools\vps-install`, **uma** das opções:

**A) CMD (recomendado se o PowerShell bloquear scripts)** — não depende da *execution policy*:

```cmd
cd /d "c:\Users\hp\Desktop\curso ia\php-hostinger\tools\vps-install"
build.cmd
```

(Na linha de comandos, **Enter** entre `cd` e `build.cmd`. Ou numa só linha: `cd /d "...\vps-install" && build.cmd`.)

**B) PowerShell** — se aparecer erro “execução de scripts foi desabilitada”, use só esta sessão:

```powershell
Set-Location "c:\Users\hp\Desktop\curso ia\php-hostinger\tools\vps-install"
powershell -ExecutionPolicy Bypass -File .\build.ps1
```

Ou, de forma permanente para o teu utilizador (mais cómodo para desenvolvimento):

```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned
```

Depois volta a executar `.\build.ps1`.

**C) Só `dotnet`** (com SDK no PATH):

```powershell
dotnet publish .\ExtractorVpsSetup.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -o .\publish
```

Saída: `tools\vps-install\publish\ExtractorVpsSetup.exe` (ficheiro único **self-contained** ~60–70 MB; não precisa de runtime .NET instalado no VPS).

Copia esse `.exe` para o VPS (RDP, rede, etc.) e executa.

## Uso no VPS

Abre **PowerShell como Administrador** (recomendado para `icacls`), na pasta onde está o `.exe`:

```powershell
.\ExtractorVpsSetup.exe
```

Repo Git por defeito: `https://github.com/dimibad-buscaapp/extract.git`  
Podes alterar com variável de ambiente ou argumento:

```powershell
$env:EXTRACTOR_GIT_URL = "https://github.com/ORG/OUTRO.git"
.\ExtractorVpsSetup.exe -y
```

```powershell
.\ExtractorVpsSetup.exe --git "https://github.com/org/privado.git" --dir "D:\Sites\Extrator" --branch main -y
```

| Opção | Significado |
|--------|-------------|
| `--git URL` | URL do repositório (repo **privado**: PAT na URL ou Git Credential Manager). |
| `--dir CAMINHO` | Pasta de instalação (defeito: `C:\apps\Extrator`). |
| `--branch NOME` | Ramo no primeiro clone (defeito: `main`). |
| `--skip-config` | Só Git; não cria `config.local.php`. |
| `--skip-acl` | Não executa `icacls`. |
| `-y` / `--non-interactive` | Termina sem pedir Enter. |

## Segurança

- Repositório **privado**: não commits com tokens na URL; preferir credenciais do Git no servidor ou URL com PAT só em sessão interactiva.
- O `config.local.php` gerado tem segredo forte; revê **Asaas**, **reCAPTCHA** e **seeds** antes de produção.

## Compilar sem o script

```powershell
dotnet publish .\ExtractorVpsSetup.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -o .\publish
```
