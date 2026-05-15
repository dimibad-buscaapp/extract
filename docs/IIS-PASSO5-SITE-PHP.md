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

## 5.6 — Permissões em `data`

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
- Confirma **`php.ini`** e que **`C:\apps\Extrator\data`** é gravável pelo pool.

Guia geral de DNS e firewall: [`CONFIGURAR-SITE-IIS-DNS.md`](CONFIGURAR-SITE-IIS-DNS.md) · problemas de ligação: [`SITE-NAO-ABRE-VPS.md`](SITE-NAO-ABRE-VPS.md).
