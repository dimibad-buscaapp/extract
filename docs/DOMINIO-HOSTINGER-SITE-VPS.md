# Mesmo domínio (`ext.buscaapp.com`), site no **teu VPS** (não no `public_html`)

Quando o domínio e a **zona DNS** estão na Hostinger mas queres servir o PHP **no Windows Server** (IIS em `C:\apps\Extrator`), o tráfego segue o **registo DNS**: tem de apontar para o **IP público do VPS**, não para o IP do alojamento partilhado (`82.25.72.221` no teu painel antigo).

O alojamento **“Website” ext.buscaapp.com** na Hostinger deixa de receber visitas para esse nome assim que o DNS mudar (podes manter o plano para e-mail, outro subdomínio, ou cancelar mais tarde).

---

## 1. No VPS (faz primeiro ou em paralelo)

1. **Código** em `C:\apps\Extrator` (`git clone` / `git pull`) — ver [`INSTALACAO-GIT-VPS.md`](INSTALACAO-GIT-VPS.md).
2. **`config.local.php`** no servidor (`app_secret`, Asaas, etc.) — **nunca** no Git.
3. **IIS:** site com caminho físico **`C:\apps\Extrator`**, PHP 8.x, documento `index.php`.
4. **Ligações (bindings):**
   - `http://ext.buscaapp.com` (porta 80) — pode usar só para redireccionar para HTTPS.
   - `https://ext.buscaapp.com` (443) com certificado válido para **`ext.buscaapp.com`**.
   - Se quiseres **`https://www.ext.buscaapp.com`:** segunda ligação HTTPS com o mesmo certificado (**SAN** com os dois nomes) ou certificado **wildcard** adequado; emissores como Let’s Encrypt (win-acme, etc.) permitem vários hostnames num só certificado.
5. **Firewall:** TCP **80** e **443** abertos para a Internet.
6. **Permissões:** o utilizador do app pool com **Modificar** em `C:\apps\Extrator\data`.

Teste **antes** de mudar o DNS, acedendo pelo **IP** ou por um nome de teste, para garantir que o site e o PHP respondem.

---

## 2. DNS na Hostinger (domínio `buscaapp.com`)

Entra no **hPanel** → **Domínios** → **`buscaapp.com`** → **DNS / Zona DNS** (ou “Editor de zona DNS” / “Manage DNS”).

### Registo `ext` → VPS

| Tipo | Nome (host) | Aponta para | Acção |
|------|-------------|-------------|--------|
| **A** | `ext` | **IPv4 público do teu VPS** | Cria ou **edita** este registo. O valor **não** pode ser o IP do alojamento partilhado (`82.25.72.221`) se quiseres o site no VPS. |

- Se existir mais do que um **A** para `ext`, deixa só o que aponta para o **VPS**.
- **TTL:** 300 ou 3600.

### Registo `www.ext` (opcional, como no painel Hostinger)

Para **`www.ext.buscaapp.com`** ir ao mesmo servidor:

| Tipo | Nome (host) | Aponta para |
|------|-------------|-------------|
| **CNAME** | `www.ext` | `ext.buscaapp.com.` (com ponto final se o painel pedir FQDN) **ou** `ext.buscaapp.com` conforme o formulário |

**Alternativa:** outro **A** com nome `www.ext` e o **mesmo IP do VPS** (útil se o painel não aceitar CNAME nessa combinação).

### Nameservers

Se continuares com **nameservers Hostinger** (`nova.dns-parking.com`, `cosmos.dns-parking.com`), estes passos são **no hPanel** da Hostinger. Se mudares os nameservers para Cloudflare ou outro, edita os registos **lá**.

---

## 3. Depois de gravar o DNS

1. No PC (ou no VPS): `nslookup ext.buscaapp.com` → deve mostrar o **IP do VPS**.
2. Abre `https://ext.buscaapp.com` no browser. Se ainda aparecer o site antigo da Hostinger, é **cache DNS** ou propagação: espera ou testa com rede móvel / `nslookup` noutro DNS (ex.: `8.8.8.8`).
3. **Asaas (PIX):** no painel Admin → Financeiro, copia de novo o **URL do webhook** e actualiza no Asaas se o host tiver mudado de ambiente de testes para produção.

---

## 4. HTTPS e dois nomes (`ext` e `www.ext`)

- O certificado no IIS tem de incluir **todos** os nomes que usas em HTTPS (pelo menos `ext.buscaapp.com`; se usas `www.ext.buscaapp.com`, inclui esse também no certificado ou emissão multi-SAN).
- Opcional: no IIS (**URL Rewrite**), redireccionar `www.ext.buscaapp.com` → `ext.buscaapp.com` (ou o inverso) para um só URL canónico e cookies consistentes.

---

## 5. Checklist

- [ ] VPS: `C:\apps\Extrator` + `config.local.php` + `data` gravável.
- [ ] IIS: bindings `ext.buscaapp.com` (+ `www.ext` se quiseres) e SSL correcto.
- [ ] Firewall 80/443.
- [ ] DNS: **A** `ext` → IP do VPS; **CNAME** ou **A** para `www.ext` se necessário.
- [ ] `nslookup` confirma o IP do VPS.
- [ ] Webhook Asaas actualizado se aplicável.

Mais detalhe de IIS e firewall: [`CONFIGURAR-SITE-IIS-DNS.md`](CONFIGURAR-SITE-IIS-DNS.md).
