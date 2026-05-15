using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;

const string DefaultGitUrl = "https://github.com/dimibad-buscaapp/extract.git";
const string DefaultInstallDir = @"C:\apps\Extrator";
const string DefaultBranch = "main";

var gitUrl = Environment.GetEnvironmentVariable("EXTRACTOR_GIT_URL") ?? DefaultGitUrl;
var installDir = DefaultInstallDir;
var branch = DefaultBranch;
var skipConfig = false;
var skipAcl = false;
var nonInteractive = false;

for (var i = 0; i < args.Length; i++)
{
    switch (args[i])
    {
        case "--git" when i + 1 < args.Length:
            gitUrl = args[++i];
            break;
        case "--dir" when i + 1 < args.Length:
            installDir = Path.GetFullPath(args[++i]);
            break;
        case "--branch" when i + 1 < args.Length:
            branch = args[++i];
            break;
        case "--skip-config":
            skipConfig = true;
            break;
        case "--skip-acl":
            skipAcl = true;
            break;
        case "--non-interactive":
        case "-y":
            nonInteractive = true;
            break;
        case "--help":
        case "-h":
            PrintHelp();
            return 0;
        default:
            Console.Error.WriteLine($"Argumento desconhecido: {args[i]}");
            PrintHelp();
            return 2;
    }
}

Console.OutputEncoding = Encoding.UTF8;
Console.WriteLine("=== ExtractorVpsSetup — instalação / actualização no VPS Windows ===");
Console.WriteLine();

if (!IsGitAvailable(out var gitExe))
{
    Console.Error.WriteLine("ERRO: 'git' não encontrado no PATH. Instale Git for Windows e reabra a consola.");
    Console.Error.WriteLine("       https://git-scm.com/download/win");
    return 1;
}

try
{
    installDir = Path.GetFullPath(installDir.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar));
    var parent = Path.GetDirectoryName(installDir);
    if (string.IsNullOrEmpty(parent))
    {
        Console.Error.WriteLine("ERRO: caminho de instalação inválido.");
        return 1;
    }

    Directory.CreateDirectory(parent);

    var gitDir = Path.Combine(installDir, ".git");
    if (!Directory.Exists(gitDir))
    {
        if (Directory.Exists(installDir) && Directory.EnumerateFileSystemEntries(installDir).Any())
        {
            Console.Error.WriteLine($"ERRO: A pasta existe mas não é um clone Git: {installDir}");
            Console.Error.WriteLine("       Escolha outro --dir ou apague/mova o conteúdo e volte a executar.");
            return 1;
        }

        if (Directory.Exists(installDir))
            Directory.Delete(installDir, recursive: false);

        Console.WriteLine($"→ git clone para {installDir}");
        var cloneOk = Run(gitExe, $"clone --branch {QuoteCli(branch)} --depth 1 {QuoteCli(gitUrl)} {QuoteCli(installDir)}", parent);
        if (!cloneOk)
        {
            Console.Error.WriteLine("ERRO: git clone falhou.");
            return 1;
        }
    }
    else
    {
        Console.WriteLine($"→ git pull em {installDir}");
        var pullOk = Run(gitExe, "pull --ff-only", installDir);
        if (!pullOk)
        {
            Console.Error.WriteLine("AVISO: git pull falhou. Resolva conflitos manualmente e execute de novo.");
            return 1;
        }
    }

    var dataDir = Path.Combine(installDir, "data");
    Directory.CreateDirectory(dataDir);
    var outDir = Path.Combine(dataDir, "out");
    Directory.CreateDirectory(outDir);

    if (!skipConfig)
    {
        var example = Path.Combine(installDir, "config.example.php");
        var local = Path.Combine(installDir, "config.local.php");
        if (!File.Exists(example))
        {
            Console.Error.WriteLine($"ERRO: Falta config.example.php em {installDir}");
            return 1;
        }

        if (File.Exists(local))
        {
            Console.WriteLine($"→ config.local.php já existe — não sobrescrito: {local}");
        }
        else
        {
            var secret = Convert.ToHexString(RandomNumberGenerator.GetBytes(32));
            var text = File.ReadAllText(example, Encoding.UTF8);
            var replaced = Regex.Replace(
                text,
                @"'app_secret'\s*=>\s*'[^']*'",
                $"'app_secret' => '{secret}'",
                RegexOptions.None,
                TimeSpan.FromSeconds(2));

            if (replaced == text)
            {
                Console.Error.WriteLine("AVISO: Não foi possível substituir app_secret no modelo; copiando ficheiro sem alterar segredo.");
                File.WriteAllText(local, text, Encoding.UTF8);
                Console.WriteLine($"→ Copiado {local} — EDITE app_secret manualmente (mín. 16 caracteres).");
            }
            else
            {
                File.WriteAllText(local, replaced, Encoding.UTF8);
                Console.WriteLine($"→ Criado {local} com app_secret aleatório (guarde uma cópia segura se precisar).");
            }
        }
    }

    if (!skipAcl)
    {
        Console.WriteLine("→ Permissões na pasta data (IIS_IUSRS + BUILTIN\\Users, herdado)…");
        var icacls = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "icacls.exe");
        if (!File.Exists(icacls))
        {
            Console.WriteLine("   (icacls.exe não encontrado — ignorado.)");
        }
        else
        {
            _ = Run(icacls, $"{QuoteCli(dataDir)} /grant *S-1-5-32-568:(OI)(CI)M /T", installDir, ignoreExitCode: true);
            _ = Run(icacls, $"{QuoteCli(dataDir)} /grant *S-1-5-32-545:(OI)(CI)M /T", installDir, ignoreExitCode: true);
        }
    }

    Console.WriteLine();
    Console.WriteLine("Próximos passos (manual):");
    Console.WriteLine("  1. IIS: site com caminho físico " + installDir);
    Console.WriteLine("  2. PHP 8.x + extensões openssl, pdo_sqlite, dom");
    Console.WriteLine("  3. HTTPS e binding ext.buscaapp.com (ou o teu domínio)");
    Console.WriteLine("  4. DNS A do subdomínio → IP deste servidor");
    Console.WriteLine("  5. Editar config.local.php (Asaas, reCAPTCHA, seeds) se necessário");
    Console.WriteLine();
    Console.WriteLine("Documentação: docs\\INSTALACAO-GIT-VPS.md e docs\\CONFIGURAR-SITE-IIS-DNS.md");

    if (!nonInteractive && OperatingSystem.IsWindows())
    {
        Console.WriteLine();
        Console.WriteLine("Prima Enter para fechar…");
        _ = Console.ReadLine();
    }

    return 0;
}
catch (Exception ex)
{
    Console.Error.WriteLine("ERRO: " + ex.Message);
    return 1;
}

static void PrintHelp()
{
    Console.WriteLine("""
        Uso:
          ExtractorVpsSetup.exe [opções]

        Opções:
          --git URL        Repositório Git (defeito: repo público do projecto ou EXTRACTOR_GIT_URL)
          --dir CAMINHO    Pasta de instalação (defeito: C:\apps\Extrator)
          --branch NOME    Ramo clone inicial (defeito: main)
          --skip-config    Não criar nem alterar config.local.php
          --skip-acl       Não executar icacls na pasta data
          -y, --non-interactive  Não pedir Enter no fim
          -h, --help       Esta ajuda

        Exemplo (repo privado com credenciais na URL — evite gravar em logs):
          ExtractorVpsSetup.exe --git "https://TOKEN@github.com/org/repo.git" --dir "D:\Web\Extrator"
        """);
}

static bool IsGitAvailable(out string path)
{
    path = "git";
    try
    {
        using var p = Process.Start(new ProcessStartInfo
        {
            FileName = "where",
            ArgumentList = { "git" },
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        });
        if (p is null) return false;
        var stdout = p.StandardOutput.ReadToEnd();
        p.WaitForExit(10_000);
        if (p.ExitCode != 0 || string.IsNullOrWhiteSpace(stdout)) return false;
        path = stdout.Trim().Split('\r', '\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries).FirstOrDefault() ?? "git";
        return true;
    }
    catch
    {
        return false;
    }
}

static string QuoteCli(string s)
{
    if (s.Length == 0) return "\"\"";
    if (!s.Contains(' ', StringComparison.Ordinal) && !s.Contains('\t', StringComparison.Ordinal) && !s.Contains('"', StringComparison.Ordinal))
        return s;
    return "\"" + s.Replace("\"", "\\\"", StringComparison.Ordinal) + "\"";
}

static bool Run(string fileName, string arguments, string workingDirectory, bool ignoreExitCode = false)
{
    using var p = Process.Start(new ProcessStartInfo
    {
        FileName = fileName,
        Arguments = arguments,
        WorkingDirectory = workingDirectory,
        UseShellExecute = false,
        RedirectStandardOutput = true,
        RedirectStandardError = true,
        CreateNoWindow = true,
    });
    if (p is null) return false;
    p.OutputDataReceived += (_, e) => { if (e.Data is not null) Console.WriteLine("   " + e.Data); };
    p.ErrorDataReceived += (_, e) => { if (e.Data is not null) Console.Error.WriteLine("   " + e.Data); };
    p.BeginOutputReadLine();
    p.BeginErrorReadLine();
    p.WaitForExit();
    var ok = p.ExitCode == 0;
    if (!ok && !ignoreExitCode)
        Console.Error.WriteLine($"   (exit {p.ExitCode})");
    return ok || ignoreExitCode;
}
