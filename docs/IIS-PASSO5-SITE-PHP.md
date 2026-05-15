# Passo 5 — Site IIS + PHP em `C:\apps\Extrator`

Assume que o **IIS** já está instalado, o serviço **W3SVC** a correr e **`http://127.0.0.1/`** mostra a página de boas-vindas do IIS (ou outro site na porta **80**).

---

## 5.1 — PHP para Windows

1. Descarrega o **ZIP “VS16 x64 Non Thread Safe”** (PHP 8.2 ou 8.3) em [windows.php.net/download](https://windows.php.net/download/).
2. Extrai para uma pasta fixa, por exemplo **`C:\PHP`** (o caminho não deve ter espaços).
3. Na pasta `C:\PHP`, copia **`php.ini-production`** para **`php.ini`**.
4. Edita **`php.ini`** e confirma / descomenta:
   - `extension_dir = "ext"` (ou caminho absoluto para `C:\PHP\ext`)
   - `extension=curl`
   - `extension=openssl`
   - `extension=pdo_sqlite`
   - `extension=mbstring` (recomendado)
   - `extension=fileinfo` (recomendado)
5. Opcional: `cgi.force_redirect = 0` (alguns guias IIS pedem-no para FastCGI).

Teste em linha de comandos (cmd ou PowerShell):

```text
C:\PHP\php.exe -v
C:\PHP\php.exe -m
```

Deve listar **PDO**, **pdo_sqlite**, **openssl**.

### Se no `phpinfo` aparece **Loaded Configuration File (none)**

O IIS está a usar o PHP **sem** `php.ini`. O ficheiro **`php.ini`** tem de existir **na mesma pasta que `php-cgi.exe`** (ex.: `C:\PHP\php.ini` se o executável for `C:\PHP\php-cgi.exe`).

**PowerShell como Administrador** (ajusta `C:\PHP` se o teu PHP estiver doutro sítio):

```powershell
$p = "C:\PHP"
if (-not (Test-Path "$p\php-cgi.exe")) { Write-Host "ERRO: nao existe $p\php-cgi.exe - corrija o caminho"; exit 1 }
Copy-Item "$p\php.ini-production" "$p\php.ini" -Force
Get-Item "$p\php.ini" | Select-Object FullName, Length, LastWriteTime
```

Confirma no Explorador que **não** existe `php.ini.txt` (o Notepad às vezes guarda assim). O nome tem de ser **`php.ini`** só.

Teste na **linha de comandos** (deve mostrar **Loaded Configuration File** com caminho):

```powershell
& "$p\php.exe" --ini
& "$p\php.exe" -m | Select-String -Pattern "pdo_sqlite|openssl"
```

Se **`pdo_sqlite`** não aparecer, edita **`C:\PHP\php.ini`**: descomenta as linhas `extension=openssl`, `extension=pdo_sqlite`, `extension=sqlite3`, `extension=curl`, `extension=mbstring`, `extension=fileinfo` e confirma `extension_dir` (ex.: `extension_dir="C:/PHP/ext"`). Define também:

```ini
cgi.force_redirect = 0
```

Grava, depois:

```powershell
iisreset
```

Recarrega `phpinfo.php` e verifica **Loaded Configuration File** = `C:\PHP\php.ini` e **PDO drivers** = **sqlite**.

**Último recurso** — cria/reescreve `C:\PHP\php.ini` só com o mínimo (pastas com barras `/`):

```ini
extension_dir="C:/PHP/ext"
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=curl
extension=mbstring
extension=fileinfo
cgi.force_redirect=0
```

(Confirma que os ficheiros `php_pdo_sqlite.dll`, etc., existem em `C:\PHP\ext`.)

---

## 5.2 — Ligação PHP ao IIS (FastCGI)

**Opção A — PHP Manager (mais simples)**  
Instala [PHP Manager for IIS](https://www.phpmanager.net/) (versão compatível com o teu IIS). Abre o **PHP Manager** no nível do servidor → **Register new PHP version** → aponta para **`C:\PHP\php-cgi.exe`**.

**Opção B — Manual** (Gestor IIS, nó raiz do servidor):

1. **Definições FastCGI** → **Adicionar aplicação**  
   - Caminho completo: **`C:\PHP\php-cgi.exe`**  
   - Argumentos: deixa vazio ou conforme documentação Microsoft para PHP.
2. **Mapeamentos de manipulador** → **Adicionar mapeamento de módulo**  
   - Pedido: **`*.php`**  
   - Módulo: **`FastCgiModule`**  
   - Nome executável: **`C:\PHP\php-cgi.exe`**  
   - Nome: `PHP_via_FastCGI`

(Reinicia o site ou `iisreset` se algo não pegar de imediato.)

---

## 5.3 — Conflito na porta 80

Só **um** site pode ficar com HTTP **80** e o mesmo “nome de anfitrião” (vazio = qualquer host).

- **Pára** o site **Default Web Site** (ou remove a ligação HTTP :80 desse site) antes de ligar o **Extrator** na **80**.  
- Ou deixa o Extrator noutra porta (ex. **8080**) só para testes: `http://IP:8080/`.

---

## 5.4 — Pool de aplicações

1. **Pools de aplicações** → **Adicionar pool de aplicações**  
   - Nome: **`ExtratorPool`**  
   - **.NET CLR:** **Sem código gerido**  
   - **Modo de pipeline:** **Integrado** (normal para PHP actual).
2. Clica no pool → **Definições avançadas** → **Identidade:** `ApplicationPoolIdentity` (por defeito) ou conta de serviço que preferires.

---

## 5.5 — Criar o site do Extrator

1. **Sites** → **Adicionar Web Site**  
   - **Nome:** `Extrator` (ou `ext.buscaapp.com`)  
   - **Pool:** `ExtratorPool`  
   - **Caminho físico:** **`C:\apps\Extrator`** (onde está `index.php`).
2. **Ligação:**  
   - Tipo **http**, **IP:** Todos não atribuídos, **Porta:** **80**, **Nome do anfitrião:** vazio (teste por IP) **ou** `ext.buscaapp.com` (produção).  
3. **Documento predefinido:** adiciona **`index.php`** e sobe-o acima de `index.html` se existir.
4. Confirma que existe **`C:\apps\Extrator\config.local.php`** com **`app_secret`** válido (mín. 16 caracteres).

Teste no servidor: **`http://127.0.0.1/`** (se binding vazio) ou **`http://127.0.0.1/`** com host `ext.buscaapp.com` via `hosts`, ou **`http://IP/`** conforme a ligação.

---

## 5.6 — Permissões em `data` e `data\sessions`

O PHP grava **sessões** em `data/sessions` (definido em `bootstrap.php`). O pool IIS precisa de **Modificar** em **`data`** (recursivo), para **SQLite** e para **sessões**.

No PowerShell **como administrador** (ajusta o nome do pool se for outro):

```powershell
icacls "C:\apps\Extrator\data" /grant "IIS AppPool\ExtratorPool":(OI)(CI)M /T
```

Se o pool tiver outro nome, substitui **`ExtratorPool`**. Alternativa: `IIS_IUSRS` (menos específico).

---

## 5.7 — HTTPS (depois)

Quando **HTTP** estiver estável, adiciona ligação **https**, porta **443**, certificado para **`ext.buscaapp.com`** (Let’s Encrypt com [win-acme](https://www.win-acme.com/) ou certificado do fornecedor). Abre **TCP 443** no firewall do Windows e no painel do VPS.

---

## 5.8 — Se aparecer erro 500 ou página em branco

- **Visualizador de eventos** → Registos do Windows → Aplicação (origem **IIS AspNetCore** / FastCGI / erros PHP se `log_errors` estiver activo).  
- Confirma **`php.ini`** e que **`C:\apps\Extrator\data`** (e **`data\sessions`**) é gravável pelo pool.

Guia geral de DNS e firewall: [`CONFIGURAR-SITE-IIS-DNS.md`](CONFIGURAR-SITE-IIS-DNS.md) · problemas de ligação: [`SITE-NAO-ABRE-VPS.md`](SITE-NAO-ABRE-VPS.md).

### HTTP 500 após activar `php.ini`

1. **Recarrega** o código no servidor (`git pull`) para trazer **`web.config`** na raiz do site — ajuda o IIS a mostrar **mensagens de erro do PHP** em vez da página genérica “500”.
2. No **`C:\PHP\php.ini`**: temporariamente `display_errors = On`, `log_errors = On` e por exemplo `error_log = "C:\PHP\logs\php-errors.log"` (cria a pasta `C:\PHP\logs`). Depois de `iisreset`, reproduz o erro e abre esse log.
3. **Permissões** em **`C:\Apps\Extrator\data`** (inclui **`data\sessions`** para o PHP): o pool precisa de **Modificar** para criar **`app.sqlite`** e ficheiros de sessão.  
   `icacls "C:\Apps\Extrator\data" /grant "IIS AppPool\Extrat:(OI)(CI)M" /T`  
   (ajusta **`Extrat`** ao nome real do teu pool.)
4. **Visualizador de eventos** do Windows → Registos de aplicações → **Application** (avisos/erros do IIS ou FastCGI).

Quando o 500 desaparecer, em produção: `display_errors = Off`, `errorMode` mais restritivo no `web.config` ou remove o `web.config` se não precisares dele.
