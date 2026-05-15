# Painel PHP (Hostinger + domínio + SSL)

Este diretório é um **painel em PHP 8.x** para subir na **hospedagem compartilhada** (ex.: Hostinger): mesmo domínio e **SSL** que o servidor já oferece.

**Não** executa Python nem Playwright. Funciona com **HTTP/cURL**, **HTML estático** (links em `<a href>`) e **SQLite** para sites e biblioteca de ficheiros descarregados.

## O que dá para fazer só na hospedagem

- **Sites**: guardar URL base, URL de conteúdo opcional, utilizador opcional, **senha e/ou cookie** (cifrados com `app_secret`), opção “só mesmo domínio” na descoberta.
- **Descobrir**: pedir a página, extrair links com extensões úteis (pdf, zip, mp4, etc.) — **sem** executar JavaScript do site alvo.
- **Downloads**: fila sequencial para `/data/out/` (registada na BD) e link autenticado em `download.php?id=…`.
- **M3U / Xtream / URL única**: formulários no painel (ficheiros em `/data/` com nomes seguros).

Para **login automático**, **páginas que dependem de JS**, **anti-bot** ou **DRM**, use a app **Python** no PC ou num VPS — este pacote **não** substitui isso.

## Domínio de produção

O painel público está previsto para **`https://ext.buscaapp.com/`** (SSL no servidor). Os caminhos relativos (`login.php`, `panel.php`, `billing_webhook.php`, etc.) funcionam na raiz do subdomínio; se instalar numa subpasta, o webhook no admin mostra o URL correcto com base no `HTTP_HOST` e na pasta.

**Git + clone no VPS Windows:** ver [`docs/INSTALACAO-GIT-VPS.md`](docs/INSTALACAO-GIT-VPS.md) (pasta `C:\apps\Extrator`).

**Instalador .exe (clone, `config.local.php`, permissões `data`):** [`tools/vps-install/README.md`](tools/vps-install/README.md) — compila com `tools/vps-install/build.cmd` (ou `build.ps1`) e copia `publish/ExtractorVpsSetup.exe` para o servidor.

**IIS, DNS e domínio `ext.buscaapp.com`:** ver [`docs/CONFIGURAR-SITE-IIS-DNS.md`](docs/CONFIGURAR-SITE-IIS-DNS.md).

**Domínio/DNS na Hostinger, ficheiros no teu VPS (cenário misto):** ver [`docs/DOMINIO-HOSTINGER-SITE-VPS.md`](docs/DOMINIO-HOSTINGER-SITE-VPS.md).

## Instalação na Hostinger

1. Crie uma pasta em **`public_html`** ou aponte o subdomínio **`ext.buscaapp.com`** para a pasta onde enviar os ficheiros (pode ser a raiz do subdomínio).
2. Envie **todos** os ficheiros de `php-hostinger/` (incluindo `data/.htaccess` e `data/out/.htaccess`).
3. Copie `config.example.php` para **`config.local.php`**.
4. Defina **`app_secret`** com pelo menos **16 caracteres** aleatórios (não use o placeholder do exemplo):

   ```bash
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
   ```

5. **Super Master de testes (opcional mas recomendado na primeira instalação):** no `config.local.php` mantenha ou ajuste `seed_super_master_email`, `seed_super_master_password` (mín. 10 caracteres) e `seed_super_master_name`. Na **primeira** carga com base de dados **vazia**, o sistema cria esse utilizador com papel **super_master**. Depois pode alterar **e-mail e senha** em **`panel.php` → Conta** e, em produção, **apagar ou esvaziar** os três campos `seed_*` no config (não são mais necessários).
6. Abra **`https://ext.buscaapp.com/login.php`**, entre com o e-mail e senha seed (por defeito no exemplo: `super@ext.buscaapp.com` / `BuscaApp2026!Test` — **mude já no painel**). Depois use **`panel.php`**.

**Extensões PHP:** `openssl`, `pdo_sqlite`, `dom` (normalmente ativas na Hostinger).

**Permissões:** a pasta `data/` deve ser gravável pelo PHP (ex.: 755 na pasta; ficheiros criados pelo script).

## Segurança

- `data/` e `data/out/` têm `.htaccess` com **acesso HTTP negado** — não sirva listas nem ficheiros em direto; use `download.php` (sessão).
- `download.php` valida caminhos com `realpath` e bloqueia `app.sqlite` e ficheiros ocultos no modo legado `?f=`.
- Não commite `config.local.php`. Use senha forte no painel.
- Os valores **`seed_super_master_*`** no exemplo são só para **teste**; em produção altere a senha em **Conta** e remova os seeds do ficheiro de config.

## Ficheiros principais

| Ficheiro | Função |
|----------|--------|
| `index.php` | Login e redirecionamento para o painel |
| `panel.php` | Dashboard: sites, descoberta, downloads, biblioteca, M3U/Xtream |
| `api.php` | API JSON (CSRF no body) para o painel |
| `bootstrap.php` | Sessão, config, HTTP/stream |
| `download.php` | Download autenticado: `?id=` (BD) ou `?f=` (legado em `/data/`) |
| `includes/db.php` | SQLite em `data/app.sqlite` |
| `includes/crypto.php` | Cifra de credenciais (AES-256-CBC, chave derivada de `app_secret`) |
| `includes/discover.php` | Descoberta de links no HTML |
| `config.local.php` | **Você cria** a partir do example |

## Limitações

- **Sem browser**: só o que vier no HTML da primeira resposta GET (cookies que enviar).
- **Tempo de execução** do PHP na hospedagem pode cortar descargas longas ou muitos ficheiros de seguida — use lotes menores ou aumente limites se o plano permitir.
- `max_download_bytes` em `config.local.php` (padrão ~200 MB por ficheiro).
- Sites com **POST obrigatório**, **tokens só em JS** ou **DRM** não são cobertos aqui.
