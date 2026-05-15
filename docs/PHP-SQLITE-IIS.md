# Erro: `could not find driver` (registo / SQLite)

Significa que o **PHP usado pelo IIS** não carrega a extensão **PDO SQLite**.

## 1) Confirmar

No VPS, PowerShell:

```powershell
C:\PHP\php.exe -m | Select-String pdo_sqlite
C:\PHP\php.exe --ini
```

Se **não** listar `pdo_sqlite`, ou **Loaded Configuration File** for `(none)`, o passo 2 é obrigatório.

No browser (se existir): `http://SEU-IP/diag.php` — linha **PHP pdo_sqlite** deve estar **OK**.

## 2) Criar / editar `php.ini`

Na pasta do **php-cgi.exe** (ex. `C:\PHP`):

```powershell
$p = "C:\PHP"
Copy-Item "$p\php.ini-production" "$p\php.ini" -Force
notepad "$p\php.ini"
```

No Notepad, **Guardar como** → tipo **Todos os ficheiros** → nome **`php.ini`** (não `php.ini.txt`).

Descomente (remova o `;` no início):

```ini
extension_dir = "ext"
extension=pdo_sqlite
extension=sqlite3
extension=openssl
extension=curl
extension=mbstring
extension=fileinfo
cgi.force_redirect = 0
```

Se `extension_dir` não funcionar, use caminho absoluto:

```ini
extension_dir = "C:/PHP/ext"
```

## 3) Reiniciar IIS

```powershell
iisreset
```

## 4) Testar de novo

```powershell
C:\PHP\php.exe -m | Select-String pdo_sqlite
```

Deve aparecer **pdo_sqlite**. Depois teste **registo** no site.

## Nota

O `php.exe` na linha de comandos e o **php-cgi.exe** do IIS devem usar o **mesmo** `php.ini` (mesma pasta `C:\PHP`).
