# Instalação com Git no VPS Windows (`C:\apps\Extrator`)

Este guia assume **Windows Server** (ou Windows com função de servidor web) e pasta **`C:\apps\Extrator`** (nome alinhado ao projecto).

## 1. Git no teu PC (desenvolvimento)

1. Instala o [Git for Windows](https://git-scm.com/download/win) se ainda não tiveres (`git` no PowerShell tem de funcionar).
2. No PowerShell:

```powershell
cd "C:\Users\hp\Desktop\curso ia\php-hostinger"

git init
git add .
git commit -m "Import inicial do painel PHP"

git branch -M main
```

## 2. Criar o repositório remoto (GitHub, GitLab, etc.)

1. No site do Git, cria um repositório **vazio** (sem README gerado, para evitar conflito no primeiro push).
2. Copia o URL HTTPS (ex.: `https://github.com/TEU_USER/extrator-php.git`).
3. No PC:

```powershell
git remote add origin "https://github.com/TEU_USER/extrator-php.git"
git push -u origin main
```

(Se pedir login, usa um **Personal Access Token** em vez da palavra-passe da conta, conforme o GitHub.)

## 3. Git no VPS

- Instala **Git for Windows** no VPS, ou o pacote mínimo `git` se usares outro ambiente.
- Garante que a pasta **`C:\apps`** existe (ou cria-a com permissões de administrador).

## 4. Clonar para `C:\apps\Extrator`

Abre **PowerShell** ou **cmd** no servidor:

```powershell
mkdir C:\apps -ErrorAction SilentlyContinue
cd C:\apps

git clone "https://github.com/TEU_USER/extrator-php.git" Extrator
cd C:\apps\Extrator
```

O site fica com a raiz do PHP em `C:\apps\Extrator` (onde estão `index.php`, `panel.php`, etc.).

**Instalador opcional (.exe):** ver [`tools/vps-install/README.md`](../tools/vps-install/README.md) — `git clone` / `git pull`, `config.local.php` inicial e permissões em `data\` num único executável no Windows Server.

## 5. Configuração local no VPS (obrigatório)

1. Copia o exemplo de configuração:

```powershell
copy config.example.php config.local.php
```

2. Edita **`config.local.php`** no Notepad ou VS Code:
   - **`app_secret`**: mínimo 16 caracteres aleatórios (obrigatório).
   - Opcional: **`seed_super_master_*`** para criar o Super Master na primeira base vazia (ver `README.md`).
   - Ajusta **`asaas_*`** se fores usar PIX.

**Não** faças commit de `config.local.php` — já está no `.gitignore`.

## 6. Permissões da pasta `data`

O PHP precisa de **escrita** em `C:\apps\Extrator\data` (SQLite, downloads, `out/`).

- Se usares **IIS**: dá permissão de modificação à identidade do pool de aplicações (ex.: `IIS AppPool\NomeDoPool` ou `IUSR`) sobre `C:\apps\Extrator\data`.
- Confirma que existem `data\.htaccess` e `data/out\.htaccess` (vêm do Git).

## 7. Servidor web (IIS ou outro)

- Aponta o **site** ou **virtual directory** do IIS para **`C:\apps\Extrator`** (document root = pasta onde está `index.php`).
- Activa **HTTPS** e certificado (Let's Encrypt ou o da Hostinger, conforme o teu caso).
- Versão **PHP 8.x** com extensões: `openssl`, `pdo_sqlite`, `dom`.

## 8. Actualizar o código no VPS (depois)

```powershell
cd C:\apps\Extrator
git pull origin main
```

(Nunca sobrescrevas `config.local.php` nem `data/app.sqlite` com o Git — continuam ignorados.)

## Resumo de caminhos

| Item            | Caminho                          |
|-----------------|----------------------------------|
| Projecto no VPS | `C:\apps\Extrator`               |
| Base SQLite     | `C:\apps\Extrator\data\app.sqlite` (criada ao primeiro acesso) |
| Config secreta  | `C:\apps\Extrator\config.local.php` (criada por ti) |

Se o domínio público for **`https://ext.buscaapp.com`**, o document root do IIS deve servir exactamente esta pasta (ou um alias equivalente).
