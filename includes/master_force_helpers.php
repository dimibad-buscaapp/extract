<?php

declare(strict_types=1);

require_once __DIR__ . '/master_extractor.php';

/**
 * Descoberta Forçada — extração ampla + formulários POST + snippets JS quando o URL aparece só em script.
 */

/**
 * @return list<string>
 */
function extractor_force_wide_tag_urls(string $html, string $pageUrl): array
{
    $out = extractor_master_extract_urls_from_html($html, $pageUrl);
    $more = [
        '~<script[^>]+src\s*=\s*["\']([^"\']+)["\']~i',
        '~<(?:img)[^>]+\bsrc\s*=\s*["\']([^"\']+)["\']~i',
        '~<embed[^>]+\bsrc\s*=\s*["\']([^"\']+)["\']~i',
        '~<object[^>]+\bdata\s*=\s*["\']([^"\']+)["\']~i',
        '~<(?:link|img)[^>]+\bhref\s*=\s*["\']([^"\']+)["\']~i',
        '~<video[^>]+\bposter\s*=\s*["\']([^"\']+)["\']~i',
    ];
    foreach ($more as $re) {
        if (!preg_match_all($re, $html, $m)) {
            continue;
        }
        foreach ($m[1] as $raw) {
            $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $abs = extractor_normalize_url($pageUrl, $raw);
            if ($abs !== null && str_starts_with($abs, 'http')) {
                $out[] = $abs;
            }
        }
    }
    if (preg_match_all('~<meta[^>]+http-equiv\s*=\s*["\']refresh["\'][^>]+content\s*=\s*["\']([^"\']+)["\']~i', $html, $mr)) {
        foreach ($mr[1] as $content) {
            if (preg_match('~URL\s*=\s*([^;]+)~i', $content, $u)) {
                $abs = extractor_normalize_url($pageUrl, trim($u[1]));
                if ($abs !== null && str_starts_with($abs, 'http')) {
                    $out[] = $abs;
                }
            }
        }
    }

    return array_values(array_unique($out));
}

/**
 * Scripts-only URLs relativos são normalizados com a página atual.
 *
 * @return array{script_only:list<array{url:string, snippet:string}>}
 */
function extractor_force_script_only_candidates(string $html, string $pageUrl, array $tagUrls): array
{
    $tagSet = [];
    foreach ($tagUrls as $t) {
        $tagSet[extractor_force_visit_key($t)] = true;
    }

    $out = [];
    if (!preg_match_all('~<script[^>]*>(.*?)</script>~is', $html, $blocks)) {
        return ['script_only' => []];
    }

    foreach ($blocks[1] as $block) {
        $snippet = trim(preg_replace('~\s+~', ' ', $block));
        $snippetShort = substr($snippet, 0, 3500);

        foreach (extractor_force_collect_script_literals_from_block($block) as $candidate) {
            $abs = $candidate;
            if (!preg_match('#^https?://#i', $abs)) {
                $absNorm = extractor_normalize_url($pageUrl, $candidate);
                $abs = $absNorm ?? '';
            }
            if ($abs === '' || !str_starts_with($abs, 'http')) {
                continue;
            }
            $vk = extractor_force_visit_key($abs);
            if (isset($tagSet[$vk])) {
                continue;
            }
            $out[] = ['url' => $abs, 'snippet' => $snippetShort !== '' ? $snippetShort : substr($candidate, 0, 3500)];
        }
    }

    $seenUrl = [];
    $unique = [];
    foreach ($out as $row) {
        $vk = extractor_force_visit_key($row['url']);
        $sk = substr($vk, 0, 500) . "\0" . substr($row['snippet'], 0, 200);
        if (isset($seenUrl[$sk])) {
            continue;
        }
        $seenUrl[$sk] = true;
        $unique[] = $row;
    }

    return ['script_only' => $unique];
}

/**
 * URLs/paths vindos apenas de dentro de uma tag script (um bloco).
 *
 * @return list<string>
 */
function extractor_force_collect_script_literals_from_block(string $block): array
{
    $urls = [];
    if (preg_match_all('~(?:(?:https?:)?//|https?://)[^\s"\'`\)\]\}<>]+~i', $block, $m)) {
        foreach ($m[0] as $raw) {
            $u = rtrim((string) $raw, "',.;)\]}>");
            if (str_starts_with($u, '//')) {
                $u = 'https:' . $u;
            }
            $urls[] = $u;
        }
    }
    if (preg_match_all('~["\'](/[^"\']+)["\']~', $block, $rel)) {
        foreach ($rel[1] as $r) {
            if (preg_match('#^/[a-z0-9_./?=&%-]+$#i', $r)) {
                $urls[] = $r;
            }
        }
    }
    if (preg_match_all('~\b(location\.href|window\.location|document\.location)\s*=\s*["\']([^"\']+)["\']~i', $block, $loc)) {
        foreach ($loc[2] as $t) {
            $urls[] = trim((string) $t);
        }
    }

    return array_values(array_unique($urls));
}

function extractor_force_visit_key(string $url): string
{
    $u = preg_replace('#\#.+$#', '', $url) ?? $url;

    return strtolower($u);
}

/**
 * @return list<array{action:string, fields:array<string,string>}>
 */
function extractor_force_parse_post_forms(string $html, string $pageUrl): array
{
    $out = [];
    if (!class_exists(DOMDocument::class)) {
        return $out;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $wrapped = '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>';
    if (@$dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR) !== true) {
        libxml_clear_errors();

        return $out;
    }
    libxml_clear_errors();

    $forms = $dom->getElementsByTagName('form');
    for ($i = 0; $i < $forms->length; $i++) {
        /** @var DOMElement|null $form */
        $form = $forms->item($i);
        if ($form === null) {
            continue;
        }
        $method = strtoupper(trim((string) $form->getAttribute('method')));
        if ($method !== '' && $method !== 'POST') {
            continue;
        }
        if ($method === '') {
            $method = 'GET';
        }
        if ($method !== 'POST') {
            continue;
        }

        $actionRaw = trim((string) $form->getAttribute('action'));
        $action = $pageUrl;
        if ($actionRaw !== '') {
            $n = extractor_normalize_url($pageUrl, $actionRaw);
            $action = $n ?? $pageUrl;
        }

        $fields = [];
        foreach ($form->getElementsByTagName('input') as $inp) {
            if (!$inp instanceof DOMElement) {
                continue;
            }
            $nm = trim((string) $inp->getAttribute('name'));
            if ($nm === '') {
                continue;
            }
            $typ = strtolower(trim((string) $inp->getAttribute('type'))) ?: 'text';
            if (in_array($typ, ['submit', 'image', 'button'], true)) {
                continue;
            }
            $fields[$nm] = $inp->getAttribute('value');
        }
        foreach ($form->getElementsByTagName('textarea') as $ta) {
            if (!$ta instanceof DOMElement) {
                continue;
            }
            $nm = trim((string) $ta->getAttribute('name'));
            if ($nm === '') {
                continue;
            }
            $fields[$nm] = $ta->textContent;
        }
        foreach ($form->getElementsByTagName('select') as $sel) {
            if (!$sel instanceof DOMElement) {
                continue;
            }
            $nm = trim((string) $sel->getAttribute('name'));
            if ($nm === '') {
                continue;
            }
            $val = '';
            foreach ($sel->getElementsByTagName('option') as $opt) {
                if (!$opt instanceof DOMElement) {
                    continue;
                }
                if (!$opt->hasAttribute('selected')) {
                    continue;
                }
                $val = $opt->getAttribute('value');
                if ($val === '') {
                    $val = $opt->textContent;
                }

                break;
            }
            if ($val === '' && $sel->getElementsByTagName('option')->length > 0) {
                $first = $sel->getElementsByTagName('option')->item(0);
                if ($first instanceof DOMElement) {
                    $val = $first->getAttribute('value') !== '' ? $first->getAttribute('value') : $first->textContent;
                }
            }
            $fields[$nm] = $val;
        }

        if ($fields !== []) {
            $out[] = ['action' => $action, 'fields' => $fields];
        }
    }

    return $out;
}

/**
 * Hint legível para partilhas (Drive já normalizado pelo master_extractor).
 */
function extractor_force_external_download_hint(string $url): string
{
    $uLower = strtolower($url);
    $hint = extractor_master_download_hint($url);

    if (str_contains($uLower, 'drive.google.com') && str_contains($uLower, 'open?id=')) {
        if (preg_match('~[?&]id=([a-zA-Z0-9_-]+)~', $url, $m)) {
            $derived = 'https://drive.google.com/uc?export=download&id=' . $m[1];
            if (!str_contains($hint, $m[1]) || strpos($hint, 'uc?') === false) {
                $hint = trim($hint . ($hint !== $url ? "\n" : '') . "\n" . $derived);
            }
        }
    }

    $svc = extractor_master_service($url);

    if (
        str_contains($uLower, 'sendspace.com')
        || $svc === 'sendspace'
    ) {
        return trim(
            $hint . "\n" . '(SendSpace: a página pode usar temporizadores ou anúncios; '
            . 'URL directa estável no servidor costuma exigir browser.)'
        );
    }
    if (
        str_contains($uLower, 'zippyshare.com')
        || $svc === 'zippyshare'
    ) {
        return trim(
            $hint . "\n" . '(Zippyshare: o link final é normalmente montado no browser (JS); '
            . 'cURL puro costuma não chegar ao ficheiro.)'
        );
    }
    if (
        str_contains($uLower, 'uploaded.net')
        || str_contains($uLower, 'uploaded.to')
        || str_contains($uLower, 'ul.to')
        || $svc === 'uploaded'
    ) {
        return trim(
            $hint . "\n" . '(Uploaded / ul.to / uploaded.to: captcha ou temporizadores; '
            . 'abra no browser ou use ferramenta compatível com o host.)'
        );
    }

    if (
        str_contains($uLower, 'rapidgator.net')
        || str_contains($uLower, 'rapidgator.asia')
        || $svc === 'rapidgator'
    ) {
        return trim(
            $hint . "\n" . '(Rapidgator: fila gratuita ou limite por IP; o ficheiro real aparece só na página '
            . 'depois da espera, e automatizar só com cURL não costuma funcionar.)'
        );
    }
    if (
        str_contains($uLower, 'nitroflare.com')
        || str_contains($uLower, 'nitro.download')
        || $svc === 'nitroflare'
    ) {
        return trim(
            $hint . "\n" . '(Nitroflare: temporizadores, limitações de IP e página intermédia; '
            . 'URL directa típico exige fluxo como no browser ou ferramentas compatíveis.)'
        );
    }
    if (
        str_contains($uLower, 'turbobit.net')
        || str_contains($uLower, 'turbobit.com')
        || str_contains($uLower, 'turbobit.cloud')
        || str_contains($uLower, 'turbo-bit.net')
        || $svc === 'turbobit'
    ) {
        return trim(
            $hint . "\n" . '(TurboBit: contagem regressiva ou bloqueios; o download real só após aceitar '
            . 'cookies e passos na página, difícil replicar com um único GET anónimo.)'
        );
    }

    if (str_contains($uLower, 'mega.nz')) {
        return trim($hint . "\n" . '(Mega: normalmente só abre na página autenticada ou com ferramenta dedicada; URL conservada)');
    }

    return $hint;
}

/**
 * @return array{kind:string, external_service:?string, type_label:string, download_hint:string}
 */
function extractor_force_classify_leaf(string $url, string $seedHost): array
{
    $typeMatch = extractor_master_match_type($url);
    if ($typeMatch !== null) {
        return [
            'kind' => 'direct_download',
            'external_service' => null,
            'type_label' => $typeMatch,
            'download_hint' => extractor_force_external_download_hint($url),
        ];
    }

    $svcTag = extractor_master_service($url);
    if ($svcTag !== 'direto') {
        return [
            'kind' => 'external_service',
            'external_service' => $svcTag,
            'type_label' => $svcTag,
            'download_hint' => extractor_force_external_download_hint($url),
        ];
    }

    $lk = strtolower($url);
    foreach (['baixar', 'download', 'transferir', 'obter', 'get-file'] as $kw) {
        if (str_contains($lk, $kw)) {
            return [
                'kind' => 'suspected_download',
                'external_service' => null,
                'type_label' => 'suspected',
                'download_hint' => $url,
            ];
        }
    }

    $h = extractor_url_host($url);
    if (($h !== null && strcasecmp((string) $h, $seedHost) === 0)) {
        return [
            'kind' => 'internal_page',
            'external_service' => null,
            'type_label' => 'internal',
            'download_hint' => '',
        ];
    }

    return [
        'kind' => 'external_link',
        'external_service' => null,
        'type_label' => 'external',
        'download_hint' => $url,
    ];
}

function extractor_force_dedupe_item(string $kind, string $url, string $sourcePage, string $postJson = ''): string
{
    return hash(
        'sha256',
        $kind . "\0" . extractor_force_visit_key($url) . "\0" . $sourcePage . "\0" . $postJson,
        false
    );
}

/**
 * @param array<string,string> $headers
 *
 * @return array{code:int, content_type:string, note:string, body_prefix:string}
 */
function extractor_force_http_post_attempt(string $url, array $fields, array $headers): array
{
    if (!function_exists('curl_init')) {
        return ['code' => 0, 'content_type' => '', 'note' => 'curl indisponível', 'body_prefix' => ''];
    }

    $h = array_merge(
        ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
        $headers
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => false,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctypeRaw = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $ctype = is_string($ctypeRaw) ? $ctypeRaw : '';
    $raw = is_string($body) ? $body : '';
    $snippet = substr($raw, 0, 480);
    $note = '';

    $binish = preg_match('#^application/octet-stream|video/|audio/|application/pdf|application/zip|^application/.*download#i', $ctype) === 1;
    $small = strlen($raw) <= 524288 && !str_contains($raw, '</html>');
    if ($binish || ($code === 200 && $snippet !== '' && !str_contains((string) $snippet, '<html'))) {
        $note = 'Possível payload binário / ficheiro (Content-Type: ' . $ctype . '). Snippet omitido.';
    } else {
        $note = $snippet !== '' ? $snippet : '(corpo vazio)';
    }

    return [
        'code' => $code,
        'content_type' => $ctype,
        'note' => substr($note, 0, 900),
        'body_prefix' => substr($snippet, 0, 200),
    ];
}
