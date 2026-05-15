<?php

declare(strict_types=1);

function extractor_branding_dir(): string
{
    return EXTRACTOR_DATA . '/branding';
}

function extractor_site_content_path(): string
{
    return EXTRACTOR_DATA . '/site_content.json';
}

/**
 * @return array<string, mixed>
 */
function extractor_branding_defaults(): array
{
    return [
        'site_name' => 'Extrator',
        'meta_description' => 'Guarde sites, encontre ficheiros e descarregue tudo num painel simples, com créditos e suporte.',
        'logo_file' => '',
        'favicon_file' => '',
        'index' => [
            'page_title' => 'Extrator — O seu painel de conteúdos',
            'hero_eyebrow' => 'Painel online · Acesso seguro',
            'hero_title_before' => 'Organize e ',
            'hero_title_highlight' => 'descarregue',
            'hero_title_after' => ' os seus conteúdos num só lugar',
            'hero_lead' => 'Guarde os sites que usa, encontre links de ficheiros e mantenha uma biblioteca pessoal — sem complicação. Tudo num painel claro, com créditos e suporte quando precisar.',
            'hero_cta_primary' => 'Começar agora',
            'hero_cta_secondary' => 'Já tenho conta',
            'hero_trust_empty' => 'Primeira conta neste servidor torna-se administrador principal.',
            'hero_trust_users' => 'Escolha o plano que combina consigo no registo.',
            'hero_visual_title' => 'Tudo num painel',
            'hero_visual_text' => 'Sites guardados · Lista de ficheiros · Descargas · Suporte',
            'nav_how' => 'Como funciona',
            'nav_plans' => 'Planos',
            'nav_login' => 'Entrar',
            'nav_register' => 'Criar conta grátis',
            'how_title' => 'Como funciona',
            'how_sub' => 'Três passos simples — pensado para quem quer resultado, não manual técnico.',
            'steps' => [
                ['title' => 'Crie a sua conta', 'text' => 'Registe-se com e-mail e escolha o plano. Entre no painel em segundos.'],
                ['title' => 'Guarde os seus sites', 'text' => 'Adicione as páginas de onde costuma obter conteúdos. O sistema ajuda a encontrar links úteis.'],
                ['title' => 'Descarregue e organize', 'text' => 'Os ficheiros ficam na sua biblioteca. Pode voltar a descarregar quando quiser, dentro dos seus créditos.'],
            ],
            'features_title' => 'O que pode fazer',
            'features_sub' => 'Ferramentas práticas para o dia a dia — sem precisar de instalar nada no computador.',
            'features' => [
                ['title' => 'Sites favoritos', 'text' => 'Guarde endereços e credenciais de forma segura no servidor.'],
                ['title' => 'Encontrar ficheiros', 'text' => 'Peça ao painel para listar PDFs, vídeos, ZIPs e outros links numa página.'],
                ['title' => 'Biblioteca', 'text' => 'Histórico do que já descarregou, com acesso rápido de novo.'],
                ['title' => 'PIX e planos', 'text' => 'Recarregue créditos conforme o plano (quando o pagamento estiver activo).'],
            ],
            'plans_title' => 'Escolha o seu plano',
            'plans_sub' => 'Preços de referência — pagamento e créditos no painel após o registo.',
            'admin_plan_title' => 'Conta principal',
            'admin_plan_blurb' => 'Reservada à primeira instalação neste servidor — gestão completa.',
            'admin_plan_price' => 'Incluída',
            'admin_plan_cta' => 'Criada no 1.º registo',
            'cta_title' => 'Pronto para experimentar?',
            'cta_sub' => 'Crie a conta em menos de um minuto e explore o painel.',
            'footer_legal' => 'Use apenas conteúdos que lhe pertencem ou que tenha autorização para obter.',
        ],
        'visible_plan_codes' => ['user', 'reseller', 'master'],
        'show_admin_plan_card' => true,
        'featured_plan_code' => 'master',
        'plan_blurbs' => [
            'user' => 'Ideal para quem usa sozinho e quer organizar downloads com simplicidade.',
            'reseller' => 'Para quem revende acesso e gere vários clientes no mesmo painel.',
            'master' => 'Mais créditos e capacidade para equipas ou operações maiores.',
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function extractor_branding(): array
{
    static $cached = null;
    static $cachedMtime = -1;
    $path = extractor_site_content_path();
    $mtime = is_file($path) ? (int) filemtime($path) : 0;
    if ($cached !== null && $cachedMtime === $mtime) {
        return $cached;
    }
    $defaults = extractor_branding_defaults();
    if (!is_file($path)) {
        $cached = $defaults;
        $cachedMtime = $mtime;

        return $cached;
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        $cached = $defaults;
        $cachedMtime = $mtime;

        return $cached;
    }
    $cached = array_replace_recursive($defaults, $raw);
    $cachedMtime = $mtime;

    return $cached;
}

/**
 * @param array<string, mixed> $patch
 */
function extractor_branding_save(array $patch): void
{
    $current = extractor_branding();
    $merged = array_replace_recursive($current, $patch);
    if (!is_dir(EXTRACTOR_DATA)) {
        mkdir(EXTRACTOR_DATA, 0770, true);
    }
    file_put_contents(
        extractor_site_content_path(),
        json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
        LOCK_EX
    );
}

function extractor_branding_asset_path(string $kind): ?string
{
    $b = extractor_branding();
    $file = trim((string) ($kind === 'logo' ? ($b['logo_file'] ?? '') : ($b['favicon_file'] ?? '')));
    if ($file === '') {
        return null;
    }
    $path = extractor_branding_dir() . '/' . basename($file);
    if (!is_file($path)) {
        return null;
    }

    return $path;
}

function extractor_branding_asset_url(string $kind): ?string
{
    if (extractor_branding_asset_path($kind) === null) {
        return null;
    }

    return extractor_url('brand.php?k=' . rawurlencode($kind) . '&v=' . rawurlencode((string) filemtime(extractor_branding_asset_path($kind))));
}

function extractor_site_name(): string
{
    $b = extractor_branding();

    return trim((string) ($b['site_name'] ?? 'Extrator')) ?: 'Extrator';
}

function extractor_favicon_link_tags(): string
{
    $url = extractor_branding_asset_url('favicon');
    if ($url === null) {
        return '';
    }

    return '<link rel="icon" href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" />' . "\n  ";
}

/**
 * @param array{href?: string, class?: string, as_sidebar?: bool} $opts
 */
function extractor_brand_html(array $opts = []): string
{
    $href = (string) ($opts['href'] ?? extractor_url('index.php'));
    $class = (string) ($opts['class'] ?? 'brand');
    $asSidebar = !empty($opts['as_sidebar']);
    $name = extractor_site_name();
    $logoUrl = extractor_branding_asset_url('logo');
    $faviconUrl = extractor_branding_asset_url('favicon');
    $imgUrl = $logoUrl ?? $faviconUrl;

    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if ($imgUrl !== null) {
        $imgClass = $asSidebar ? 'brand-logo brand-logo-sidebar' : 'brand-logo';
        $inner = '<img src="' . $h($imgUrl) . '" alt="' . $h($name) . '" class="' . $h($imgClass) . '" />';
        if (!$asSidebar || $logoUrl !== null) {
            $inner .= '<span class="brand-text">' . $h($name) . '</span>';
        }
    } else {
        $inner = $h($name);
    }

    return '<a class="' . $h($class) . '" href="' . $h($href) . '">' . $inner . '</a>';
}

/**
 * @return array<string, string>
 */
function extractor_plan_blurb_map(): array
{
    $b = extractor_branding();
    $defaults = (array) ($b['plan_blurbs'] ?? []);

    return is_array($defaults) ? array_map('strval', $defaults) : [];
}

function extractor_plan_blurb(string $code): string
{
    $map = extractor_plan_blurb_map();

    return $map[$code] ?? 'Plano flexível para o seu dia a dia.';
}

/**
 * @param list<array<string, mixed>> $allPlans
 * @return list<array<string, mixed>>
 */
function extractor_visible_plans_for_landing(array $allPlans): array
{
    $b = extractor_branding();
    $codes = $b['visible_plan_codes'] ?? [];
    if (!is_array($codes) || $codes === []) {
        return $allPlans;
    }
    $allowed = array_flip(array_map('strval', $codes));
    $out = [];
    foreach ($allPlans as $p) {
        $c = (string) ($p['code'] ?? '');
        if (isset($allowed[$c])) {
            $out[] = $p;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed>|null $file $_FILES entry
 */
function extractor_branding_upload(?array $file, string $kind): void
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Erro no envio do ficheiro (' . $kind . ').');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Imagem demasiado grande (máx. 2 MB).');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Upload inválido.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $extMap = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];
    if (!isset($extMap[$mime])) {
        throw new RuntimeException('Formato não suportado. Use PNG, JPG, WEBP, SVG ou ICO.');
    }
    $ext = $extMap[$mime];
    if ($kind !== 'logo' && $kind !== 'favicon') {
        throw new RuntimeException('Tipo de imagem inválido.');
    }
    $dir = extractor_branding_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    foreach (glob($dir . '/' . $kind . '.*') ?: [] as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
    $destName = $kind . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $destName)) {
        throw new RuntimeException('Não foi possível guardar a imagem.');
    }
    $b = extractor_branding();
    $key = $kind === 'logo' ? 'logo_file' : 'favicon_file';
    $b[$key] = $destName;
    extractor_branding_save($b);
}
