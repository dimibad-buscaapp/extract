# Configurar o site no Windows Server (IIS) + domínio `ext.buscaapp.com`

Pressupõe o código em **`C:\apps\Extrator`** e PHP 8.x instalado (ex.: [PHP para Windows](https://windows.php.net/download/) ou via Web Platform Installer / pacote da Hostinger).

---

## 1. DNS (domínio → VPS)

Quando **já tens o domínio** registado, o que falta é no painel de **DNS** (às vezes chamado “Zona DNS”, “Gerir DNS”, “Records”) criar um registo que mande o subdomínio (ou a raiz) para o **IP público do Windows Server** onde corre o IIS.

### Onde configurar

- O domínio pode estar no **Registo.br**, **Hostinger**, **Cloudflare**, **GoDaddy**, etc.
- Os DNS efectivos são os que aparecem em **Nameservers** no registo do domínio. Se apontares o domínio para os nameservers da **Cloudflare**, editas os registos **na Cloudflare**, não só no sítio onde compraste o domínio.

### Registo que normalmente precisas (subdomínio para o extrator)

| Tipo | Nome / Host | Valor / Destino | Notas |
|------|-------------|-----------------|--------|
| **A** | `ext` | `IPv4 do teu VPS` | Fica `ext.tudominio.com` → servidor. (Alguns painéis pedem o domínio completo no host; outros só `ext`.) |
| **AAAA** | `ext` | IPv6 do VPS | Só se o servidor e o teu plano usarem **IPv6** de forma estável; senão podes omitir. |

- **TTL:** `300` ou `3600` segundos é habitual; valores baixos propagam alterações mais depressa.
- **Apaga ou corrige** registos **A** antigos para o mesmo nome (`ext`) que ainda apontem para o alojamento partilhado ou outro IP — só pode haver um destino “ganhador” por nome.

### Se quiseres o site na raiz (`tudominio.com` sem `ext.`)

| Tipo | Nome / Host | Valor |
|------|-------------|--------|
| **A** | `@` ou vazio ou `tudominio.com` | IPv4 do VPS |

(Confirma no teu painel a sintaxe exacta para “raiz do domínio”.)

### Cloudflare (muito comum)

- Cria o registo **A** `ext` → IP do VPS.
- **Proxy** (nuvem laranja): pode ficar **ligado** (tráfego passa pela Cloudflare) ou **desligado** (“DNS only”) se quiseres ligar directo ao servidor para depurar SSL no IIS. Com proxy ligado, o certificado visto pelo visitante é o da Cloudflare; no servidor podes usar **Origin Certificate** ou certificado válido para o hostname.

### Depois de gravar

1. Espera **alguns minutos a algumas horas** (propagação).
2. No teu PC: `nslookup ext.tudominio.com` ou `ping ext.tudominio.com` — deve mostrar o **IP do VPS**.
3. No **IIS**, a ligação do site deve ter **nome de anfitrião** igual ao FQDN que usas (ex.: `ext.tudominio.com`) e porta **443** com certificado para esse nome.

> Se o domínio estiver na **Hostinger** mas o **site correr noutro VPS**, o registo **A** do subdomínio tem de ser o **IP desse VPS**, não o IP do alojamento partilhado por defeito.

### Exemplo usado no projecto

- Subdomínio de referência: **`ext.buscaapp.com`** → registo **A** `ext` no domínio `buscaapp.com` → IP público do servidor Windows.

### Domínio temporário (antes do domínio final)

Pode testar ou servir o site **sem** já ter `ext.buscaapp.com` pronto:

| Opção | Notas |
|--------|--------|
| **Só pelo IP** | `http://SEU_IP/` — no IIS, a ligação pode deixar o “nome do anfitrião” vazio ou usar o IP. **HTTPS** com Let’s Encrypt para IP puro é limitado; para testes rápidos costuma ser **HTTP**. |
| **nip.io / sslip.io** | Ex.: `http://203-0-113-10.nip.io` resolve para `203.0.113.10`. Útil para ter um **nome** sem registar domínio; o IIS usa esse nome na ligação (host header). |
| **DuckDNS, No-IP, etc.** | Nome gratuito que aponta para o IP do VPS; depois pode-se pedir **Let’s Encrypt** para esse nome. |
| **Ngrok / Cloudflare Tunnel** | Túnel até ao `localhost` no VPS — dá URL `https://xxxx.ngrok.io` temporária; bom para demos sem abrir portas (conforme o produto). |

O código usa o host do pedido para o URL do webhook no admin; ao mudar o domínio, **actualize o URL no Asaas** se usar PIX.

---

## 2. Firewall do Windows Server

Abra as portas **HTTP (80)** e **HTTPS (443)** para o tráfego entrante:

- **Windows Defender Firewall** → Regras de entrada → Nova regra → Porta → TCP **80** e **443** → Permitir.

(Se só testar localmente, pode usar `http://localhost` sem abrir 80 na rede.)

---

## 3. IIS — site e pasta física

1. Abra **Gestor dos Serviços de Informação Internet (IIS)**.
2. **Pools de aplicações** → Crie um pool (ex.: `ExtratorPool`):
   - **.NET CLR:** “Sem código gerido” (o site é PHP, não precisa de ASP.NET).
3. **Sites** → **Adicionar Web Site**:
   - **Nome:** `ext.buscaapp.com` (ou `Extrator`)
   - **Pool:** `ExtratorPool`
   - **Caminho físico:** `C:\apps\Extrator`
   - **Ligação:**
     - Tipo **http**, porta **80**, nome do anfitrião **`ext.buscaapp.com`** (ou vazio para testar por IP).
     - Depois adicione **https** porta **443** com o certificado SSL (Let’s Encrypt via win-acme, ou certificado do painel da Hostinger se aplicável).
4. **Documento predefinido:** inclua **`index.php`** e coloque-o no topo se necessário.
5. **Mapeamento de manipulador** para PHP: com o [PHP Manager for IIS](https://www.phpmanager.net/) ou registo manual do `php-cgi.exe` para `*.php`.

Reinicie o site ou o pool após alterações.

---

## 4. Permissões na pasta `data`

O utilizador que corre o pool (ex.: `IIS AppPool\ExtratorPool` ou `IUSR`) precisa de **Modificar** em:

`C:\apps\Extrator\data`

Sem isto, o SQLite (`app.sqlite`) e os downloads em `data\out` falham.

---

## 5. “Conexão com o VPS” — o que significa no vosso caso

| Cenário | O que fazer |
|--------|-------------|
| **O site PHP está neste mesmo Windows Server** | Não há “ligação” separada: o IIS serve os ficheiros em `C:\apps\Extrator`. O PHP fala com a Internet (HTTP) para descobrir links e descarregar, como já está no código. |
| **Domínio na Hostinger, mas o site corre neste VPS** | Só **DNS**: o registo `ext` tem de apontar para o **IP deste VPS** (ver secção 1). O painel da Hostinger para “alojamento” pode ficar sem uso para este subdomínio. |
| **Querem um segundo servidor** (ex.: Python/Playwright só para login pesado) | Isso **não está ligado** ao painel PHP actual: seria um **API HTTP** noutra máquina (IP/porta, HTTPS, chave). Hoje o extrator do painel usa só **PHP + `file_get_contents`/`stream`**. Podemos acrescentar no futuro campos em `config.local.php` (URL + chave) e chamadas no `api.php` se definirem o contrato do worker. |

---

## 6. Checklist rápido

- [ ] `C:\apps\Extrator` com `index.php`, `config.local.php` (com `app_secret` válido).
- [ ] DNS `ext` → IP público do servidor onde está o IIS.
- [ ] Firewall **80/443** abertos.
- [ ] Site IIS → caminho físico `C:\apps\Extrator`, PHP a processar `.php`.
- [ ] HTTPS com certificado válido em `https://ext.buscaapp.com`.
- [ ] Escrita em `C:\apps\Extrator\data` para o utilizador do pool.

---

## 7. Asaas / webhook (se usarem PIX)

O URL do webhook é gerado no **painel Admin → Financeiro** com base no pedido HTTP. Com HTTPS e domínio correcto, configure no Asaas o mesmo URL (sem espaços no fim).

---

Para erros concretos (500, página em branco, “config.local.php ausente”), use o **Visualizador de eventos** do Windows (registos da aplicação / IIS) e o **log de erros PHP** (`php.ini` → `error_log`).
