# A página não abre (VPS Windows + IIS + domínio)

Segue uma ordem lógica. Anota **o que vês no browser** (timeout, 404, 403, 500, certificado, página em branco).

---

## 1. O domínio aponta para o sítio certo?

No **teu PC** (PowerShell ou CMD):

```text
nslookup ext.buscaapp.com
```

- O **Address** deve ser o **IP público do teu VPS** (onde está o IIS).
- Se ainda for o IP da **Hostinger** (ex.: `82.25.72.221`), o browser **nunca** chega ao IIS do VPS. Ajusta o registo **A** do nome `ext` na zona DNS (ver `docs/DOMINIO-HOSTINGER-SITE-VPS.md`).

---

## 2. No próprio servidor, o IIS responde?

No VPS, abre o browser **no servidor** (RDP) ou PowerShell:

```powershell
Invoke-WebRequest -Uri "http://127.0.0.1/" -UseBasicParsing -TimeoutSec 10
```

(Se o site tiver **nome de anfitrião** obrigatório no binding, testa com cabeçalho Host:)

```powershell
Invoke-WebRequest -Uri "http://127.0.0.1/" -Headers @{ Host = "ext.buscaapp.com" } -UseBasicParsing -TimeoutSec 10
```

- **Falha / timeout:** site não está a ouvir, binding errado, ou IIS parado.
- **Status 200** com HTML: IIS e PHP provavelmente OK; o problema é **fora** (DNS, firewall da cloud, SSL só no 443, etc.).

---

## 3. Firewall do Windows (e do fornecedor do VPS)

- No Windows: portas **TCP 80** e **443** abertas nas **regras de entrada**.
- No painel do **VPS** (Hetzner, OVH, Azure, etc.): **security group / firewall** também tem de permitir 80/443 de **0.0.0.0/0** (ou dos teus IPs) até ao servidor.

Sem isto, de fora vês **timeout** ou **não carrega**.

---

## 4. Site no IIS

- **Caminho físico** = pasta onde está o `index.php` (ex.: `C:\apps\Extrator`).
- **Ligação:** pelo menos **HTTP 80** ou **HTTPS 443** com nome de anfitrião **`ext.buscaapp.com`** (ou vazio para teste por IP — só para diagnóstico).
- **Documento predefinido:** inclui **`index.php`**.
- **Pool** iniciado; sem erros no Visualizador de eventos → **Windows Logs** → **Application** (e erros do PHP se configurados).

---

## 5. PHP no IIS

- Extensão **FastCGI** / mapeamento ***.php** → `php-cgi.exe` (PHP 8.x).
- Extensões PHP: **openssl**, **pdo_sqlite**, **dom**.
- Pasta **`data`** com permissão de escrita para a identidade do pool (`IIS AppPool\NomeDoPool` ou `IIS_IUSRS`).

Se PHP não estiver mapeado, costuma dar **404** ao pedir `.php` ou descarregar o ficheiro em branco.

---

## 6. `config.local.php`

Se existir mas com `app_secret` inválido, a app pode mostrar erro. Se **não** existir, deves ver a página **“Configuração necessária”** (não fica totalmente em branco a menos que outro erro ocorra antes).

---

## 7. HTTPS / certificado

- Se só configuraste **HTTPS** e o certificado **não** corresponde ao nome `ext.buscaapp.com`, o browser bloqueia ou avisa.
- Testa primeiro **http://ext.buscaapp.com** (porta 80). Se **HTTP** abrir e **HTTPS** não, o problema é **SSL** no IIS.

---

## 8. Mensagens típicas

| Sintoma | Onde olhar |
|--------|------------|
| **Demora e falha / timeout** | DNS para IP errado; firewall 80/443; cloud security group. |
| **502 / 500.0** (IIS) | PHP FastCGI, permissões, caminho do `php-cgi`. |
| **404** | Caminho físico errado; `index.php` não na raiz do site; handler PHP. |
| **Certificado inválido** | Binding 443 e certificado com SAN para `ext.buscaapp.com`. |

---

## 9. Logs úteis

- **IIS:** `%SystemDrive%\inetpub\logs\LogFiles\` (W3SVC…).
- **PHP:** `php.ini` → `log_errors`, `error_log`.
- **Event Viewer** → Windows Logs → Application.

---

Depois de identificar **uma** destas linhas (DNS vs IIS vs firewall vs PHP), corrigir só esse ponto costuma destravar o resto.
