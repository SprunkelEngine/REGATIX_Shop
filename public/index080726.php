<?php

declare(strict_types=1);

if (!headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../src/Repository.php';

function pv_key(string $s): string
{
    $s = trim($s);

    if ($s === '') {
        return '';
    }

    $s = preg_replace('~[^\pL\pN_\-]+~u', '', $s);

    return $s ?: '';
}

function pv_lower(string $s): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($s, 'UTF-8')
        : strtolower($s);
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function pv_norm_key(string $s): string
{
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $s);
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
    $s = trim($s);
    $s = preg_replace('~\s+~u', ' ', $s);

    return pv_lower($s);
}

function pv_row_get(array $row, array $candidates): ?string
{
    $want = [];

    foreach ($candidates as $c) {
        $want[pv_norm_key((string)$c)] = true;
    }

    foreach ($row as $k => $v) {
        if (!is_string($k) && !is_int($k)) {
            continue;
        }

        $nk = pv_norm_key((string)$k);

        if (isset($want[$nk])) {
            $s = trim((string)$v);
            return $s === '' ? null : $s;
        }
    }

    return null;
}

function pv_labels_path(): string
{
    return __DIR__ . '/../config/product_labels.json';
}

function pv_load_labels(): array
{
    $path = pv_labels_path();

    if (!is_file($path)) {
        return [];
    }

    $raw = (string)file_get_contents($path);
    $arr = json_decode($raw, true);

    return is_array($arr) ? $arr : [];
}

function pv_visibility_path(): string
{
    return __DIR__ . '/../config/product_visibility.json';
}

function pv_load_visibility(): array
{
    $path = pv_visibility_path();

    if (!is_file($path)) {
        return [];
    }

    $raw = (string)file_get_contents($path);
    $arr = json_decode($raw, true);

    if (!is_array($arr)) {
        return [];
    }

    if (isset($arr['visible']) && is_array($arr['visible'])) {
        return $arr['visible'];
    }

    if (!empty($arr) && !isset($arr['visible'])) {
        return $arr;
    }

    return [];
}

function pv_product_warn_path(): string
{
    return __DIR__ . '/../config/product_warn.json';
}

function pv_load_product_warn_map(): array
{
    $path = pv_product_warn_path();

    if (!is_file($path)) {
        return [];
    }

    $rawTxt = (string)file_get_contents($path);
    $rawTxt = preg_replace('/^\xEF\xBB\xBF/', '', $rawTxt);

    if (trim($rawTxt) === '') {
        return [];
    }

    $raw = json_decode($rawTxt, true);

    return is_array($raw) ? $raw : [];
}

function pv_boolish($v): ?bool
{
    if (is_bool($v)) {
        return $v;
    }

    if (is_int($v) || is_float($v)) {
        return ((float)$v) !== 0.0;
    }

    if (is_string($v)) {
        $s = pv_lower(trim($v));

        if ($s === '') {
            return null;
        }

        if (in_array($s, ['1', 'true', 'yes', 'ja', 'on', 'an', 'enable', 'enabled'], true)) {
            return true;
        }

        if (in_array($s, ['0', 'false', 'no', 'nein', 'off', 'aus', 'disable', 'disabled'], true)) {
            return false;
        }

        return null;
    }

    return null;
}

function pv_warn_enabled_for_product(string $productKey, array $map, bool $default = false): bool
{
    $k = pv_key($productKey);

    if ($k === '') {
        return $default;
    }

    foreach (['product_warn', 'warn', 'products', 'map', 'data', 'items'] as $container) {
        if (isset($map[$container]) && is_array($map[$container])) {
            $map = $map[$container];
            break;
        }
    }

    if (array_key_exists($k, $map)) {
        $b = pv_boolish($map[$k]);
        return $b === null ? $default : $b;
    }

    $lk = pv_lower($k);

    foreach ($map as $mk => $mv) {
        if (!is_string($mk)) {
            continue;
        }

        if (pv_lower(pv_key($mk)) === $lk) {
            $b = pv_boolish($mv);
            return $b === null ? $default : $b;
        }
    }

    return $default;
}

function pv_products(): array
{
    $out = [];
    $base = __DIR__ . '/../data/products';

    if (!is_dir($base)) {
        return $out;
    }

    $vis = pv_load_visibility();
    $labelOverrides = pv_load_labels();

    foreach (scandir($base) ?: [] as $d) {
        if ($d === '.' || $d === '..') {
            continue;
        }

        $path = $base . '/' . $d;

        if (!is_dir($path)) {
            continue;
        }

        $key = pv_key($d);

        if ($key === '') {
            continue;
        }

        if (isset($vis[$key]) && !$vis[$key]) {
            continue;
        }

        $label = $key;
        $vfile = $path . '/variants.json';
        $isEckregal = false;

        if (is_file($vfile)) {
            $variantsStore = json_decode((string)file_get_contents($vfile), true);
            $variants = $variantsStore;

            if (
                is_array($variantsStore)
                && isset($variantsStore['variants'])
                && is_array($variantsStore['variants'])
            ) {
                $variants = $variantsStore['variants'];
            }

            if (!empty($variants[0]['Produktgruppe']) && !empty($variants[0]['Produktart'])) {
                $label = trim((string)$variants[0]['Produktgruppe'] . ' ' . (string)$variants[0]['Produktart']);
            } elseif (!empty($variants[0]['Produktart'])) {
                $label = trim((string)$variants[0]['Produktart']);
            }

            $labelNorm = pv_lower(preg_replace('~\s+~u', ' ', trim((string)$label)));

            if ($labelNorm === 'fachbodenregal grundregal') {
                $label = 'Fachbodenregal';
            }

            if (
                isset($variants[0]['Produktart'])
                && stripos((string)$variants[0]['Produktart'], 'Eckregal') !== false
            ) {
                $isEckregal = true;
            }
        }

        if ($isEckregal) {
            continue;
        }

        if (
            isset($labelOverrides[$key])
            && is_string($labelOverrides[$key])
            && trim($labelOverrides[$key]) !== ''
        ) {
            $label = trim($labelOverrides[$key]);
        }

        $out[$key] = $label;
    }

    return $out;
}

function pv_offers_path(): string
{
    $cands = [
        __DIR__ . '/../data/offers/offers.json',
        __DIR__ . '/../data/offers.json',
        __DIR__ . '/../config/offers.json',
        __DIR__ . '/../data/offers_store.json',
        __DIR__ . '/../data/offers/offers_store.json',
    ];

    foreach ($cands as $p) {
        if (is_file($p)) {
            return $p;
        }
    }

    return __DIR__ . '/../data/offers/offers.json';
}

function pv_load_offers_any(string &$usedPath = ''): array
{
    $path = pv_offers_path();
    $usedPath = $path;

    if (!is_file($path)) {
        return [];
    }

    $raw = json_decode((string)file_get_contents($path), true);

    if (!is_array($raw)) {
        return [];
    }

    if (isset($raw['offers']) && is_array($raw['offers'])) {
        $raw = $raw['offers'];
    }

    if (array_is_list($raw)) {
        $out = [];

        foreach ($raw as $o) {
            if (!is_array($o)) {
                continue;
            }

            $id = (string)($o['id'] ?? $o['offer_id'] ?? '');

            if ($id === '') {
                continue;
            }

            $out[$id] = $o;
        }

        return $out;
    }

    $out = [];

    foreach ($raw as $k => $v) {
        if (!is_array($v)) {
            continue;
        }

        $id = (string)($v['id'] ?? $v['offer_id'] ?? $k);

        if ($id === '') {
            continue;
        }

        $out[$id] = $v;
    }

    return $out;
}

function pv_float_or_null($v): ?float
{
    if ($v === null) {
        return null;
    }

    if (is_float($v) || is_int($v)) {
        return (float)$v;
    }

    $s = trim((string)$v);

    if ($s === '') {
        return null;
    }

    $s = str_replace(['.', ' '], ['', ''], $s);
    $s = str_replace(',', '.', $s);

    return is_numeric($s) ? (float)$s : null;
}

function pv_money_fmt(float $v): string
{
    return number_format($v, 2, ',', '.') . ' €';
}

/* =========================== Bilder / Artikelcontent =========================== */

function pv_product_image_base_url(): string
{
    return 'https://regatix.shop/images/';
}

function pv_is_abs_url(string $s): bool
{
    return (bool)preg_match('~^https?://~i', $s);
}

function pv_normalize_image_path(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (pv_is_abs_url($path)) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    return rtrim(pv_product_image_base_url(), '/') . '/' . $path;
}

function pv_normalize_article_content_entry(array $entry): array
{
    $images = [];
    $seen = [];

    if (isset($entry['images']) && is_array($entry['images'])) {
        foreach ($entry['images'] as $img) {
            $url = pv_normalize_image_path((string)$img);

            if ($url === '') {
                continue;
            }

            $lk = pv_lower($url);

            if (isset($seen[$lk])) {
                continue;
            }

            $seen[$lk] = true;
            $images[] = $url;
        }
    }

    return [
        'info_html' => isset($entry['info_html']) && is_string($entry['info_html'])
            ? $entry['info_html']
            : '',
        'images' => $images,
    ];
}

function pv_normalize_product_content(array $boot): array
{
    if (!isset($boot['content']) || !is_array($boot['content'])) {
        $boot['content'] = [];
    }

    if (!isset($boot['content']['product']) || !is_array($boot['content']['product'])) {
        $boot['content']['product'] = [];
    }

    $product = $boot['content']['product'];
    $boot['content']['product'] = pv_normalize_article_content_entry(is_array($product) ? $product : []);

    $candidatePaths = [
        ['content', 'article_content'],
        ['content', 'article_contents'],
        ['content', 'by_article'],
        ['content', 'articles'],
        ['content', 'items'],
        ['article_content'],
        ['article_contents'],
        ['by_article'],
        ['articles'],
        ['items'],
    ];

    foreach ($candidatePaths as $path) {
        $ref =& $boot;
        $exists = true;

        foreach ($path as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $exists = false;
                break;
            }

            $ref =& $ref[$segment];
        }

        if (!$exists) {
            continue;
        }

        $normalizedMap = [];

        foreach ($ref as $articleKey => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalizedMap[(string)$articleKey] = pv_normalize_article_content_entry($entry);
        }

        $ref = $normalizedMap;
    }

    return $boot;
}

/* =========================== Widerruf: Helpers =========================== */

function pv_csrf_token(): string
{
    if (empty($_SESSION['pv_csrf']) || !is_string($_SESSION['pv_csrf'])) {
        $_SESSION['pv_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['pv_csrf'];
}

function pv_post_str(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function pv_withdrawal_dir(): string
{
    return __DIR__ . '/../data/withdrawals';
}

function pv_withdrawal_log_path(): string
{
    return pv_withdrawal_dir() . '/withdrawals.jsonl';
}

function pv_client_ip(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

    foreach ($keys as $k) {
        $v = trim((string)($_SERVER[$k] ?? ''));

        if ($v === '') {
            continue;
        }

        if ($k === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_map('trim', explode(',', $v));
            return (string)($parts[0] ?? '');
        }

        return $v;
    }

    return '';
}

function pv_store_withdrawal_request(array $payload): bool
{
    $dir = pv_withdrawal_dir();

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($line) || $line === '') {
        return false;
    }

    return file_put_contents(
        pv_withdrawal_log_path(),
        $line . PHP_EOL,
        FILE_APPEND | LOCK_EX
    ) !== false;
}

function pv_send_withdrawal_mail(array $payload): void
{
    $to = 'info@regatix.com';
    $subject = 'Widerruf über Shop-Button';

    $body =
        "Es wurde ein Widerruf über den Shop ausgelöst.\n\n" .
        "Zeitpunkt: " . ($payload['created_at'] ?? '') . "\n" .
        "Name: " . ($payload['name'] ?? '') . "\n" .
        "E-Mail: " . ($payload['email'] ?? '') . "\n" .
        "Bestell-/Angebotsnummer: " . ($payload['order_ref'] ?? '') . "\n" .
        "Produkt-Key: " . ($payload['product_key'] ?? '') . "\n" .
        "Produkt-Label: " . ($payload['product_label'] ?? '') . "\n" .
        "Artikelnummer: " . ($payload['article'] ?? '') . "\n" .
        "Offer-ID: " . ($payload['offer_id'] ?? '') . "\n" .
        "Seite: " . ($payload['page_url'] ?? '') . "\n" .
        "IP: " . ($payload['ip'] ?? '') . "\n\n" .
        "Nachricht:\n" . ($payload['message'] ?? '') . "\n";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: REGATIX Shop <no-reply@regatix.com>',
        'Reply-To: ' . ($payload['email'] ?? 'info@regatix.com'),
    ];

    @mail(
        $to,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $body,
        implode("\r\n", $headers)
    );
}

/* =========================== Produktwechsel / Widerruf POST =========================== */

$withdrawalSuccess = '';
$withdrawalError = '';
$csrfToken = pv_csrf_token();

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'submit_withdrawal'
) {
    $postedToken = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $withdrawalError = 'Die Anfrage konnte aus Sicherheitsgründen nicht verarbeitet werden. Bitte Seite neu laden und erneut versuchen.';
    } else {
        $name = pv_post_str('wd_name');
        $email = pv_post_str('wd_email');
        $orderRef = pv_post_str('wd_order_ref');
        $message = pv_post_str('wd_message');
        $productKeyIn = pv_key(pv_post_str('wd_product_key'));
        $productLabel = pv_post_str('wd_product_label');
        $article = pv_post_str('wd_article');
        $offerIdIn = pv_post_str('wd_offer_id');
        $pageUrl = pv_post_str('wd_page_url');
        $confirm = (string)($_POST['wd_confirm'] ?? '') === '1';

        if ($name === '') {
            $withdrawalError = 'Bitte einen Namen angeben.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $withdrawalError = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } elseif (!$confirm) {
            $withdrawalError = 'Bitte bestätigen, dass du den Widerruf absenden möchtest.';
        } else {
            $payload = [
                'id' => 'wd_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)),
                'created_at' => date('c'),
                'name' => $name,
                'email' => $email,
                'order_ref' => $orderRef,
                'message' => $message,
                'product_key' => $productKeyIn,
                'product_label' => $productLabel,
                'article' => $article,
                'offer_id' => $offerIdIn,
                'page_url' => $pageUrl,
                'ip' => pv_client_ip(),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ];

            if (!pv_store_withdrawal_request($payload)) {
                $withdrawalError = 'Der Widerruf konnte nicht gespeichert werden. Bitte prüfen, ob ../data/withdrawals beschreibbar ist.';
            } else {
                pv_send_withdrawal_mail($payload);
                $withdrawalSuccess = 'Dein Widerruf wurde erfasst. Wir melden uns an ' . h($email) . '.';
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'set_product'
) {
    $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
    header('Location: index.php');
    exit;
}

$products = pv_products();
$productsByLower = [];

foreach ($products as $k => $_label) {
    $productsByLower[pv_lower((string)$k)] = (string)$k;
}

function pv_canon_product(string $in, array $map): string
{
    $k = pv_key($in);

    if ($k === '') {
        return '';
    }

    $lk = pv_lower($k);

    return $map[$lk] ?? '';
}

$defaultKey = null;

foreach ($products as $k => $v) {
    if (stripos($v, 'Fachbodenregal') !== false) {
        $defaultKey = $k;
        break;
    }
}

if ($defaultKey === null) {
    reset($products);
    $defaultKey = key($products);
}

$getKey = isset($_GET['product'])
    ? pv_canon_product((string)$_GET['product'], $productsByLower)
    : '';

if ($getKey !== '') {
    $productKey = $getKey;
    $_SESSION['pv_product'] = $productKey;
} else {
    $sessKey = pv_canon_product((string)($_SESSION['pv_product'] ?? ''), $productsByLower);

    if ($sessKey !== '') {
        $productKey = $sessKey;
    } else {
        $productKey = (string)$defaultKey;
        $_SESSION['pv_product'] = $productKey;
    }
}

$offerId = trim((string)($_GET['offer'] ?? ''));
$offerArticle = trim((string)($_GET['article'] ?? ''));
$offerData = null;
$offerGross = null;
$offerNet = null;
$listGross = null;
$listNet = null;
$offersUsedPath = '';

$repo = new PV\Repository(__DIR__ . '/../config/config.json', $productKey);
$boot = $repo->bootstrap();
$boot = pv_normalize_product_content($boot);

if ($offerId !== '') {
    $offers = pv_load_offers_any($offersUsedPath);

    if (isset($offers[$offerId]) && is_array($offers[$offerId])) {
        $offerData = $offers[$offerId];

        $oProductRaw = (string)($offerData['product'] ?? $offerData['product_key'] ?? '');
        $oProduct = pv_canon_product($oProductRaw, $productsByLower);

        if ($oProduct !== '' && isset($products[$oProduct])) {
            $productKey = $oProduct;
            $_SESSION['pv_product'] = $productKey;

            $repo = new PV\Repository(__DIR__ . '/../config/config.json', $productKey);
            $boot = $repo->bootstrap();
            $boot = pv_normalize_product_content($boot);
        }

        $oArticle = trim((string)($offerData['article'] ?? $offerData['sku'] ?? $offerData['Artikelnummer'] ?? ''));

        if ($offerArticle === '' && $oArticle !== '') {
            $offerArticle = $oArticle;
        }

        $offerGross = pv_float_or_null(
            $offerData['price_gross']
                ?? $offerData['offer_price_gross']
                ?? $offerData['gross']
                ?? null
        );

        $offerNet = pv_float_or_null(
            $offerData['price_net']
                ?? $offerData['offer_price_net']
                ?? $offerData['net']
                ?? null
        );

        if (!empty($boot['variants']) && $offerArticle !== '') {
            $pk = (string)($boot['config']['variant']['primary_key'] ?? 'Artikelnummer');
            $priceGrossKey = (string)($boot['config']['variant']['price_gross'] ?? 'mit MwSt. €');
            $priceNetKey = (string)($boot['config']['variant']['price_net'] ?? 'ohne MwSt. €');

            foreach ($boot['variants'] as $v) {
                if (!is_array($v)) {
                    continue;
                }

                if ((string)($v[$pk] ?? '') !== $offerArticle) {
                    continue;
                }

                $listGross = pv_float_or_null($v[$priceGrossKey] ?? null);
                $listNet = pv_float_or_null($v[$priceNetKey] ?? null);
                break;
            }
        }
    }
}

$title = (string)($boot['content']['product']['title'] ?? '');

if ($title === '') {
    $v0t = $boot['variants'][0] ?? [];
    $pg = pv_row_get($v0t, ['Produktgruppe', 'produktgruppe', 'Produktgruppe ', 'Produkt-Gruppe', 'Gruppe']) ?? '';
    $pa = pv_row_get($v0t, ['Produktart', 'produktart', 'Produktart ', 'Art']) ?? '';
    $title = trim($pg . ' ' . $pa);

    if ($title === '') {
        $title = (string)($boot['config']['ui']['brand_title'] ?? 'Produktkonfigurator');
    }
}

$currentLabel = $products[$productKey] ?? $productKey;
$warnMapProducts = pv_load_product_warn_map();
$warnEnabled = pv_warn_enabled_for_product($productKey, $warnMapProducts, false);
$hideWarn = !$warnEnabled;
$hideInfo = false;

?>
<!doctype html>
<html lang="de">
<head>
    <script>
        (function(w,d,s,l,i){
            w[l]=w[l]||[];
            w[l].push({'gtm.start': new Date().getTime(),event:'gtm.js'});
            var f=d.getElementsByTagName(s)[0],
                j=d.createElement(s),
                dl=l!='dataLayer'?'&l='+l:'';
            j.async=true;
            j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;
            f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','GTM-W5WRQLFN');
    </script>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> kaufen | REGATIX Shop</title>
    <meta
        name="description"
        content="REGATIX Shop: Regale und Betriebseinrichtungen für Profis konfigurieren, Preise prüfen und direkt Kontakt oder Angebot anfordern."
    >
    <link rel="stylesheet" href="assets/styles.css">

    <style>
        :root{
            --bg:#ffffff;
            --card:#ffffff;
            --text:#000000;
            --muted:rgba(0,0,0,.65);
            --border:rgba(0,0,0,.14);
            --accent:#95bf20;
            --shadow:0 8px 22px rgba(0,0,0,.08);
            --field-bg: rgba(0,0,0,.03);
            --soft: rgba(0,0,0,.02);
            --btn-grad-a: rgba(149,191,32,.18);
            --btn-grad-b: rgba(0,0,0,.02);
            --danger:#b42318;
            --danger-bg:rgba(180,35,24,.08);
            --success:#027a48;
            --success-bg:rgba(2,122,72,.08);
        }

        body.layout-pro{
            --bg:#0f1115;
            --card:#151922;
            --text:#f2f4f8;
            --muted:rgba(255,255,255,.70);
            --border:rgba(255,255,255,.16);
            --shadow:0 10px 26px rgba(0,0,0,.55);
            --field-bg: rgba(255,255,255,.06);
            --soft: rgba(255,255,255,.05);
            --btn-grad-a: rgba(149,191,32,.22);
            --btn-grad-b: rgba(255,255,255,.04);
            --danger-bg:rgba(180,35,24,.16);
            --success-bg:rgba(2,122,72,.18);
        }

        body{
            background: var(--bg) !important;
            color: var(--text);
        }

        select,
        input[type="number"],
        input[type="text"],
        input[type="email"],
        textarea{
            background: var(--field-bg) !important;
            color: var(--text) !important;
            border-color: var(--border) !important;
        }

        .card{
            background: var(--card) !important;
            box-shadow: var(--shadow);
        }

        .btn{
            background: linear-gradient(135deg, var(--btn-grad-a), var(--btn-grad-b)) !important;
        }

        .btn:hover{
            border-color: rgba(149,191,32,.55) !important;
        }

        .hero,
        .thumb,
        .kv-row{
            background: var(--soft) !important;
        }

        #pv_hero{
            display:flex;
            align-items:center;
            justify-content:center;
            min-height:320px;
            padding:12px;
            border-radius:12px;
            overflow:hidden;
        }

        #pv_hero img{
            display:block;
            width:100%;
            max-width:100%;
            height:auto;
            max-height:520px;
            object-fit:contain;
        }

        #pv_thumbs{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-top:10px;
        }

        #pv_thumbs .thumb{
            width:88px;
            height:88px;
            border-radius:10px;
            overflow:hidden;
            border:1px solid var(--border);
            display:flex;
            align-items:center;
            justify-content:center;
        }

        #pv_thumbs .thumb img{
            width:100%;
            height:100%;
            object-fit:contain;
            display:block;
        }

        .pill-toggle{
            height:38px;
            padding:0 14px;
            border-radius:999px;
            border:1px solid var(--border);
            background:var(--field-bg);
            color:var(--text);
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:8px;
            text-decoration:none;
            white-space:nowrap;
        }

        .pill-select{
            height:38px;
            padding:0 14px;
            border-radius:999px;
            border:1px solid var(--border);
            background:var(--field-bg);
            color:var(--text);
            cursor:pointer;
            min-width:220px;
        }

        .price-net{
            font-size:13px;
            color: var(--muted) !important;
        }

        .notice{
            margin:0 0 12px 0;
            padding:12px 14px;
            border:1px solid var(--border);
            border-radius:14px;
            line-height:1.45;
        }

        .notice--error{
            border-color:rgba(180,35,24,.35);
            background:var(--danger-bg);
            color:var(--text);
        }

        .notice--success{
            border-color:rgba(2,122,72,.35);
            background:var(--success-bg);
            color:var(--text);
        }

        select.pv-native-select{
            position:absolute !important;
            left:-9999px !important;
            width:1px !important;
            height:1px !important;
            opacity:0 !important;
            pointer-events:none !important;
        }

        .pv-select{
            position:relative;
            display:inline-block;
            width:100%;
            max-width:100%;
        }

        .pv-select.pv-select--pill{
            min-width:220px;
        }

        .pv-select__btn{
            width:100%;
            height:38px;
            padding:0 14px;
            border-radius:999px;
            border:1px solid var(--border);
            background:var(--field-bg);
            color:var(--text);
            cursor:pointer;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            text-align:left;
            user-select:none;
        }

        .pv-select__btn:focus{
            outline:2px solid rgba(149,191,32,.35);
            outline-offset:2px;
        }

        .pv-select__chev{
            width:0;
            height:0;
            border-left:5px solid transparent;
            border-right:5px solid transparent;
            border-top:6px solid currentColor;
            opacity:.75;
            flex:0 0 auto;
        }

        .pv-select__menu{
            position:absolute;
            top:calc(100% + 6px);
            left:0;
            right:0;
            z-index:9999;
            background:var(--card);
            border:1px solid var(--border);
            border-radius:14px;
            box-shadow: var(--shadow);
            padding:6px;
            margin:0;
            list-style:none;
            max-height:320px;
            overflow:auto;
            display:none;
        }

        .pv-select.is-open .pv-select__menu{
            display:block;
        }

        .pv-select__menu[hidden],
        .wd-modal[hidden]{
            display:none !important;
        }

        .pv-select__opt{
            padding:10px 10px;
            border-radius:10px;
            cursor:pointer;
            line-height:1.2;
        }

        .pv-select__opt[aria-selected="true"]{
            background:rgba(149,191,32,.18);
            border:1px solid rgba(149,191,32,.35);
        }

        .pv-select__opt:hover{
            background:rgba(0,0,0,.06);
        }

        body.layout-pro .pv-select__opt:hover{
            background:rgba(255,255,255,.07);
        }

        .wd-modal{
            position:fixed;
            inset:0;
            z-index:10050;
            display:none;
            align-items:center;
            justify-content:center;
            padding:18px;
            background:rgba(0,0,0,.55);
            backdrop-filter: blur(2px);
        }

        .wd-modal.is-open{
            display:flex;
        }

        .wd-dialog{
            width:min(760px, 100%);
            max-height:calc(100vh - 36px);
            overflow:auto;
            background:var(--card);
            color:var(--text);
            border:1px solid var(--border);
            border-radius:20px;
            box-shadow:var(--shadow);
        }

        .wd-head{
            padding:18px 20px 10px;
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:12px;
        }

        .wd-title{
            font-size:20px;
            font-weight:700;
            line-height:1.2;
        }

        .wd-close{
            border:1px solid var(--border);
            background:var(--field-bg);
            color:var(--text);
            border-radius:999px;
            width:38px;
            height:38px;
            cursor:pointer;
            font-size:20px;
            line-height:1;
        }

        .wd-body{
            padding:0 20px 20px;
        }

        .wd-help{
            color:var(--muted);
            font-size:14px;
            line-height:1.5;
            margin:0 0 16px 0;
        }

        .wd-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:14px;
        }

        .wd-field{
            display:flex;
            flex-direction:column;
            gap:6px;
        }

        .wd-field--full{
            grid-column:1 / -1;
        }

        .wd-field label{
            font-size:14px;
            font-weight:600;
        }

        .wd-field input,
        .wd-field textarea{
            width:100%;
            border:1px solid var(--border);
            border-radius:12px;
            min-height:42px;
            padding:10px 12px;
            font:inherit;
            resize:vertical;
        }

        .wd-check{
            display:flex;
            gap:10px;
            align-items:flex-start;
            margin-top:8px;
            font-size:14px;
            line-height:1.5;
        }

        .wd-actions{
            display:flex;
            gap:10px;
            justify-content:flex-end;
            flex-wrap:wrap;
            margin-top:18px;
        }

        .wd-btn{
            min-height:42px;
            padding:0 16px;
            border-radius:999px;
            border:1px solid var(--border);
            background:var(--field-bg);
            color:var(--text);
            cursor:pointer;
            font:inherit;
        }

        .wd-btn--primary{
            border-color:rgba(149,191,32,.55);
            background:linear-gradient(135deg, var(--btn-grad-a), var(--btn-grad-b));
        }


        .sr-only{
            position:absolute;
            width:1px;
            height:1px;
            padding:0;
            margin:-1px;
            overflow:hidden;
            clip:rect(0,0,0,0);
            white-space:nowrap;
            border:0;
        }

        .seo-intro{
            margin:0 0 18px 0;
            padding:18px 20px;
            border:1px solid var(--border);
            border-radius:20px;
            background:var(--card);
            box-shadow:var(--shadow);
        }

        .seo-intro h1{
            margin:0 0 8px 0;
            font-size:clamp(26px, 4vw, 44px);
            line-height:1.05;
            letter-spacing:-.04em;
        }

        .seo-intro p{
            margin:0;
            max-width:920px;
            color:var(--muted);
            line-height:1.55;
        }

        .seo-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:14px;
        }

        .seo-steps{
            display:grid;
            grid-template-columns:repeat(3, minmax(0, 1fr));
            gap:12px;
            margin:18px 0;
        }

        .seo-step{
            padding:16px;
            border:1px solid var(--border);
            border-radius:18px;
            background:var(--card);
            box-shadow:var(--shadow);
        }

        .seo-step h2{
            margin:0 0 6px 0;
            font-size:18px;
        }

        .seo-step p{
            margin:0;
            color:var(--muted);
            line-height:1.45;
        }

        .contact-strip{
            margin:18px 0 0;
            padding:18px 20px;
            border:1px solid var(--border);
            border-radius:20px;
            background:var(--card);
            box-shadow:var(--shadow);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            flex-wrap:wrap;
        }

        .contact-strip h2{
            margin:0 0 4px 0;
            font-size:22px;
        }

        .contact-strip p{
            margin:0;
            color:var(--muted);
            line-height:1.45;
        }

        .footer-links{
            display:inline-flex;
            gap:10px;
            flex-wrap:wrap;
            align-items:center;
            justify-content:center;
        }

        @media (max-width: 860px){
            .seo-steps{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 720px){
            .wd-grid{
                grid-template-columns:1fr;
            }
        }



        /* ================= REGATIX Shop V2 Full-Width Layout ================= */
        html,
        body{
            width:100%;
            min-width:0;
            overflow-x:hidden;
        }

        #logo,
        #footer{
            width:100%;
        }

        #logo{
            background:linear-gradient(135deg, #95bf20 0%, #7aa115 100%);
            border-bottom:1px solid rgba(0,0,0,.12);
            box-shadow:0 10px 28px rgba(0,0,0,.12);
            margin-bottom:clamp(18px, 2vw, 34px);
        }

        #logo .logostage{
            width:100%;
            max-width:2200px;
            margin:0 auto;
            padding:clamp(32px, 3.2vw, 56px) clamp(16px, 3vw, 56px);
            min-height:clamp(118px, 9vw, 176px);
            display:flex;
            align-items:center;
            box-sizing:border-box;
        }

        #logo img{
            max-height:clamp(58px, 5.8vw, 112px);
            height:auto;
        }

        #logologo{
            width:100%;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:clamp(18px, 4vw, 72px);
        }

        #regatixoben{
            margin-left:auto;
            flex:0 0 auto;
            display:flex;
            align-items:center;
            justify-content:flex-end;
        }

        #regatixoben img{
            width:auto;
            height:auto;
            max-width:min(64vw, 1280px);
            max-height:clamp(372px, 37.2vw, 716px);
            object-fit:contain;
            transform:none;
        }

        @media (max-width: 760px){
            #logologo{
                gap:16px;
            }

            #regatixoben img{
                max-width:64vw;
                max-height:440px;
            }
        }

        #logo a:focus-visible,
        .pill-toggle:focus-visible,
        .wd-btn:focus-visible,
        .wd-close:focus-visible{
            outline:3px solid rgba(149,191,32,.45);
            outline-offset:3px;
        }

        #logo a:focus-visible{
            outline-color:rgba(255,255,255,.85);
        }

        .container{
            width:100% !important;
            max-width:2200px !important;
            margin:0 auto !important;
            padding-left:clamp(16px, 3vw, 56px) !important;
            padding-right:clamp(16px, 3vw, 56px) !important;
            box-sizing:border-box;
        }

        .header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            flex-wrap:wrap;
        }

        .h-title{
            font-size:clamp(24px, 2.4vw, 44px);
            line-height:1.05;
            letter-spacing:-.035em;
        }

        .product-stage{
            overflow:visible;
        }

        .product-stage > .card-b{
            padding:clamp(18px, 2vw, 34px) !important;
        }

        .product-workspace{
            display:grid;
            grid-template-columns:minmax(420px, 0.85fr) minmax(560px, 1.15fr);
            gap:clamp(22px, 3vw, 56px);
            align-items:start;
        }

        .product-media,
        .product-config{
            min-width:0;
        }

        .product-config{
            position:sticky;
            top:18px;
        }

        #pv_hero{
            min-height:clamp(360px, 34vw, 720px);
        }

        #pv_hero img{
            max-height:clamp(420px, 38vw, 760px);
        }

        #pv_thumbs .thumb{
            width:clamp(82px, 5.2vw, 118px);
            height:clamp(82px, 5.2vw, 118px);
        }

        .form{
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:14px 18px;
        }

        .form > div{
            min-width:0;
        }

        .form label,
        .product-config label{
            display:block;
            margin:0 0 6px;
            font-weight:700;
        }

        .form select,
        .form input,
        .product-config input{
            width:100%;
        }

        .prices{
            margin-top:18px;
            padding:18px 20px;
            border:1px solid var(--border);
            border-radius:20px;
            background:var(--soft);
        }

        .price-gross{
            font-size:clamp(30px, 3vw, 54px);
            line-height:1;
            letter-spacing:-.04em;
        }

        .product-info-wide{
            margin-top:clamp(22px, 3vw, 46px);
        }

        .product-info-wide .info{
            font-size:clamp(16px, 1vw, 19px);
            line-height:1.65;
            max-width:1500px;
        }

        .product-lower-grid{
            display:grid;
            grid-template-columns:minmax(420px, .85fr) minmax(560px, 1.15fr);
            gap:clamp(22px, 3vw, 56px);
            margin-top:clamp(22px, 3vw, 46px);
            align-items:start;
        }

        .kv{
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:10px;
        }

        .kv-row{
            min-width:0;
        }

        .contact-strip,
        .seo-intro,
        .seo-steps{
            max-width:none;
        }

        #footer{
            box-sizing:border-box;
            padding:clamp(24px, 2.6vw, 44px) clamp(16px, 3vw, 56px);
            background:linear-gradient(135deg, #95bf20 0%, #7aa115 100%);
            color:#102000;
            border-top:1px solid rgba(0,0,0,.12);
            box-shadow:0 -10px 28px rgba(0,0,0,.10);
            margin-top:clamp(24px, 3vw, 52px);
        }

        #footer p{
            max-width:2200px;
            margin:0 auto;
            line-height:1.6;
        }

        #footer a{
            color:#102000;
            font-weight:700;
            text-decoration:underline;
            text-underline-offset:3px;
        }

        #footer .pill-toggle{
            background:rgba(255,255,255,.24) !important;
            border-color:rgba(16,32,0,.24);
            color:#102000;
            text-decoration:none;
        }

        @media (min-width: 1800px){
            .product-workspace,
            .product-lower-grid{
                grid-template-columns:minmax(520px, .8fr) minmax(760px, 1.2fr);
            }

            .seo-steps{
                grid-template-columns:repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 1280px){
            .product-workspace,
            .product-lower-grid{
                grid-template-columns:1fr;
            }

            .product-config{
                position:static;
            }
        }

        @media (max-width: 760px){
            .container{
                padding-left:14px !important;
                padding-right:14px !important;
            }

            .product-stage > .card-b{
                padding:14px !important;
            }

            .form,
            .kv{
                grid-template-columns:1fr;
            }

            #pv_hero{
                min-height:260px;
            }
        }

        @media (hover:hover) and (pointer:fine){
            .pv-select.pv-open-on-hover:hover .pv-select__menu{
                display:block;
            }

            .pv-select.pv-open-on-hover:hover{
                z-index:9999;
            }

            .pv-select.pv-open-on-hover:hover .pv-select__btn{
                border-color: rgba(149,191,32,.55);
            }
        }
    </style>
</head>

<body
    data-hide-info="<?= $hideInfo ? '1' : '0' ?>"
    data-product-key="<?= h($productKey) ?>"
    data-product-label="<?= h($currentLabel) ?>"
>
    <noscript>
        <iframe
            src="https://www.googletagmanager.com/ns.html?id=GTM-W5WRQLFN"
            height="0"
            width="0"
            style="display:none;visibility:hidden"
            title="Google Tag Manager"
        ></iframe>
    </noscript>

    <script>
        (function(){
            function readableAlt(img){
                var product = document.body ? (document.body.getAttribute('data-product-label') || '') : '';
                var src = img.getAttribute('src') || '';

                if(img.closest && img.closest('#logo')){
                    return src.indexOf('SHOP_zeigt_nach_links') !== -1
                        ? 'REGATIX Shop Hinweisfigur'
                        : 'REGATIX Shop Logo';
                }

                if(img.closest && img.closest('#pv_thumbs')){
                    return product ? ('Vorschaubild ' + product) : 'Produkt Vorschaubild';
                }

                if(img.closest && img.closest('#pv_hero')){
                    return product ? ('Produktbild ' + product) : 'Produktbild REGATIX Shop';
                }

                return product ? ('Produktbild ' + product) : 'Bild REGATIX Shop';
            }

            function fixEmptyAlt(root){
                var scope = root && root.querySelectorAll ? root : document;
                scope.querySelectorAll('img').forEach(function(img){
                    if(!img.hasAttribute('alt') || String(img.getAttribute('alt') || '').trim() === ''){
                        img.setAttribute('alt', readableAlt(img));
                    }
                });
            }

            if(document.readyState === 'loading'){
                document.addEventListener('DOMContentLoaded', function(){ fixEmptyAlt(document); });
            } else {
                fixEmptyAlt(document);
            }

            function readableLinkLabel(a){
                var text = String(a.textContent || '').replace(/\s+/g, ' ').trim();
                if(text) return text;

                var img = a.querySelector ? a.querySelector('img') : null;
                if(img){
                    var alt = String(img.getAttribute('alt') || '').trim();
                    if(alt) return alt;
                }

                var href = String(a.getAttribute('href') || '').trim();
                if(href.indexOf('mailto:') === 0) return 'E-Mail an REGATIX senden';
                if(href.indexOf('tel:') === 0) return 'REGATIX telefonisch kontaktieren';
                if(href.indexOf('cart') !== -1) return 'Warenkorb öffnen';
                if(href.indexOf('withdraw') !== -1 || href.indexOf('widerruf') !== -1) return 'Vertrag widerrufen öffnen';
                if(href.indexOf('impressum') !== -1) return 'Impressum öffnen';
                if(href.indexOf('datenschutz') !== -1) return 'Datenschutz öffnen';
                if(href.indexOf('regatix.com') !== -1) return 'REGATIX Homepage öffnen';
                return 'Link öffnen';
            }

            function fixEmptyLinks(root){
                var scope = root && root.querySelectorAll ? root : document;
                scope.querySelectorAll('a').forEach(function(a){
                    var text = String(a.textContent || '').replace(/\s+/g, ' ').trim();
                    var label = String(a.getAttribute('aria-label') || '').trim();
                    var title = String(a.getAttribute('title') || '').trim();
                    var imgAlt = '';
                    var img = a.querySelector ? a.querySelector('img') : null;
                    if(img) imgAlt = String(img.getAttribute('alt') || '').trim();

                    var name = label || text || title || imgAlt || readableLinkLabel(a);

                    if(!label){
                        a.setAttribute('aria-label', name);
                    }
                    if(!title){
                        a.setAttribute('title', name);
                    }
                    if(!text && !a.querySelector('.sr-only')){
                        var sr = document.createElement('span');
                        sr.className = 'sr-only';
                        sr.textContent = name;
                        a.insertBefore(sr, a.firstChild);
                    }
                });
            }

            fixEmptyLinks(document);

            new MutationObserver(function(mutations){
                mutations.forEach(function(mutation){
                    mutation.addedNodes.forEach(function(node){
                        if(node && node.nodeType === 1){
                            if(node.tagName === 'IMG'){
                                if(!node.hasAttribute('alt') || String(node.getAttribute('alt') || '').trim() === ''){
                                    node.setAttribute('alt', readableAlt(node));
                                }
                            } else {
                                fixEmptyAlt(node);
                            }

                            if(node.tagName === 'A'){
                                fixEmptyLinks(node.parentNode || document);
                            } else {
                                fixEmptyLinks(node);
                            }
                        }
                    });
                });
            }).observe(document.documentElement, {childList:true, subtree:true});
        })();
    </script>

    <header id="logo" role="banner">
        <div class="logostage">
            <div id="logologo">
                <a href="https://regatix.com" title="REGATIX Homepage öffnen" aria-label="REGATIX Homepage öffnen">
                    <span class="sr-only">REGATIX Homepage öffnen</span>
                    <img alt="REGATIX Shop Logo" src="https://www.regatix.com/media/regatixshoplogo.png" />
                </a>

                <div id="regatixoben">
                    <a href="https://regatix.com" title="REGATIX Homepage öffnen" aria-label="REGATIX Homepage öffnen">
                        <span class="sr-only">REGATIX Homepage öffnen</span>
                        <img alt="REGATIX Shop Hinweisfigur" src="https://www.regatix.com/media/REGATIX/SHOP_zeigt_nach_links.png" />
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main id="main-content" class="container" role="main">
        <div class="header">
            <div>
                <div class="h-title"><?= h($title) ?></div>
                <div class="small">
                    Produkt: <strong><?= h($currentLabel) ?></strong>
                </div>
            </div>

            <nav aria-label="Shop-Navigation und Kontakt" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
                <form method="post" style="margin:0">
                    <input type="hidden" name="action" value="set_product">
                    <label class="sr-only" for="product_switcher">Produkt wählen</label>

                    <select class="pill-select" id="product_switcher" name="product" onchange="this.form.submit()" title="Produkt wählen" aria-label="Produkt wählen">
                        <?php foreach ($products as $k => $label): ?>
                            <option value="<?= h($k) ?>" <?= ($k === $productKey ? 'selected' : '') ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <a class="pill-toggle" href="#main-content" title="Direkt zum Konfigurator" aria-label="Direkt zum Produktkonfigurator springen">Konfigurator</a>

                <a class="pill-toggle" href="cart.php" title="Warenkorb öffnen" aria-label="Warenkorb öffnen">
                    Warenkorb
                    <span id="pv_cart_badge" class="pv-badge" hidden aria-hidden="true"></span>
                </a>

                <a class="pill-toggle" href="tel:+497062239020" title="REGATIX telefonisch kontaktieren" aria-label="REGATIX telefonisch kontaktieren unter 07062 239020">Kontakt: 07062 - 23 902 - 0</a>

                <a class="pill-toggle" href="mailto:info@regatix.com?subject=Angebot%20REGATIX%20Shop" title="Angebot per E-Mail anfordern" aria-label="Angebot per E-Mail anfordern">Angebot anfordern</a>

                <a
                    class="pill-toggle"
                    target="_blank"
                    rel="noopener"
                    aria-label="Vertrag widerrufen öffnen"
                    href="withdraw.php?product=<?= h($productKey) ?>&article=<?= h($offerArticle) ?>&offer=<?= h($offerId) ?>"
                >
                    Vertrag widerrufen
                </a>

                <button class="pill-toggle" id="pv_layout_toggle" type="button" title="Layout umschalten">
                    Layout: Hell
                </button>
            </nav>
        </div>

        <section class="seo-intro" aria-labelledby="page-title">
            <h1 id="page-title"><?= h($title) ?> im REGATIX Shop konfigurieren</h1>
            <p>
                Wählen Sie die passende Ausführung, prüfen Sie Produktdaten und Preise und starten Sie direkt
                Ihre Anfrage. REGATIX liefert professionelle Regale und Betriebseinrichtungen für Lager,
                Werkstatt, Industrie und Handel.
            </p>
            <div class="seo-actions" aria-label="Wichtige Aktionen">
                <a class="pill-toggle" href="#pv_dims" aria-label="Jetzt Produkt konfigurieren">Jetzt konfigurieren</a>
                <a class="pill-toggle" href="mailto:info@regatix.com?subject=Angebot%20REGATIX%20Shop" aria-label="Angebot per E-Mail anfordern">Angebot anfordern</a>
                <a class="pill-toggle" href="tel:+497062239020" aria-label="Telefonische Beratung anrufen">Telefonisch beraten lassen</a>
            </div>
        </section>

        <section class="seo-steps" aria-label="Vorteile und Ablauf">
            <article class="seo-step">
                <h2>Produkt auswählen</h2>
                <p>Konfigurieren Sie Maße, Ausführung und technische Daten passend zu Ihrem Lagerprojekt.</p>
            </article>
            <article class="seo-step">
                <h2>Preis und Details prüfen</h2>
                <p>Der Shop zeigt relevante Artikeldaten, Bilder, Infotexte und Preisangaben übersichtlich an.</p>
            </article>
            <article class="seo-step">
                <h2>Anfrage starten</h2>
                <p>Fordern Sie ein Angebot an oder sprechen Sie direkt mit REGATIX über Ihr Projekt.</p>
            </article>
        </section>

        <?php if ($withdrawalError !== ''): ?>
            <div class="notice notice--error"><?= h($withdrawalError) ?></div>
        <?php endif; ?>

        <?php if ($withdrawalSuccess !== ''): ?>
            <div class="notice notice--success"><?= $withdrawalSuccess ?></div>
        <?php endif; ?>

        <div id="pv_error" class="small"></div>

        <?php if (empty($boot['variants'])): ?>
            <div class="card">
                <div class="card-b">
                    <h2 class="card-title">Keine Varianten gefunden</h2>
                    <div class="small" style="margin-top:6px">
                        Für dieses Produkt sind noch keine Daten importiert. Bitte im Admin den Import ausführen
                        (oder anderes Produkt wählen).
                    </div>
                </div>
            </div>

            <div style="height:12px"></div>
        <?php endif; ?>

        <section class="card product-stage" aria-labelledby="config-title">
            <div class="card-h">
                <h2 class="card-title" id="config-title">Konfiguration</h2>
            </div>

            <div class="card-b">
                <div class="product-workspace">
                    <div class="product-media" aria-label="Produktbilder">
                        <div class="hero" id="pv_hero"></div>
                        <div class="thumbs" id="pv_thumbs"></div>
                    </div>

                    <div class="product-config" aria-label="Produkt konfigurieren">
                        <div class="form" id="pv_dims"></div>

                        <div class="prices" aria-live="polite">
                            <div class="price-gross" id="pv_price_gross">—</div>
                            <div class="price-net" id="pv_price_net"></div>
                        </div>

                        <div style="margin-top:16px">
                            <label for="pv_qty">Menge</label>
                            <input type="number" min="1" step="1" id="pv_qty" name="qty" value="1">
                        </div>
                    </div>
                </div>

                <div class="product-info-wide">
                    <div class="card" id="pv_info_card" style="border-radius:var(--radius2)">
                        <div class="card-h">
                            <h2 class="card-title">Produktbeschreibung</h2>
                        </div>

                        <div class="card-b">
                            <div class="info" id="pv_info_html"></div>
                        </div>
                    </div>
                </div>

                <div class="product-lower-grid">
                    <div class="card" style="border-radius:var(--radius2)">
                        <div class="card-h">
                            <h2 class="card-title">Technische Daten</h2>
                        </div>

                        <div class="card-b">
                            <div class="kv" id="pv_info_fields"></div>
                        </div>
                    </div>

                    <div>
                        <?php
                        $partialBase = __DIR__ . '/partials';

                        if ($hideWarn) {
                            require $partialBase . '/warn_off.php';
                        } else {
                            require $partialBase . '/warn_on.php';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </section>
        <section class="contact-strip" aria-labelledby="contact-title">
            <div>
                <h2 id="contact-title">Beratung oder Angebot gewünscht?</h2>
                <p>REGATIX unterstützt bei Auswahl, Planung und Angebot für professionelle Regale und Betriebseinrichtungen.</p>
            </div>
            <div class="seo-actions">
                <a class="pill-toggle" href="tel:+497062239020" aria-label="REGATIX jetzt telefonisch anrufen">Jetzt anrufen</a>
                <a class="pill-toggle" href="mailto:info@regatix.com?subject=Angebot%20REGATIX%20Shop" aria-label="Angebot per E-Mail anfordern">Angebot anfordern</a>
            </div>
        </section>
    </main>

    <div class="wd-modal<?= $withdrawalError !== '' ? ' is-open' : '' ?>" id="wd_modal" <?= $withdrawalError !== '' ? '' : 'hidden inert' ?>>
        <div class="wd-dialog" role="dialog" aria-modal="true" aria-labelledby="wd_title">
            <div class="wd-head">
                <div>
                    <div class="wd-title" id="wd_title">Vertrag widerrufen</div>
                    <p class="wd-help">
                        Hier kann eine Widerrufserklärung elektronisch übermittelt werden.
                        Produkt, Artikelnummer und aktuelle Seite werden automatisch mitgesendet.
                    </p>
                </div>

                <button type="button" class="wd-close" id="wd_close" aria-label="Fenster schließen">×</button>
            </div>

            <div class="wd-body">
                <form method="post" id="wd_form">
                    <input type="hidden" name="action" value="submit_withdrawal">
                    <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="wd_product_key" id="wd_product_key" value="<?= h($productKey) ?>">
                    <input type="hidden" name="wd_product_label" id="wd_product_label" value="<?= h($currentLabel) ?>">
                    <input type="hidden" name="wd_article" id="wd_article" value="<?= h($offerArticle) ?>">
                    <input type="hidden" name="wd_offer_id" id="wd_offer_id" value="<?= h($offerId) ?>">
                    <input type="hidden" name="wd_page_url" id="wd_page_url" value="">

                    <div class="wd-grid">
                        <div class="wd-field">
                            <label for="wd_name">Name *</label>
                            <input
                                type="text"
                                id="wd_name"
                                name="wd_name"
                                required
                                value="<?= h(pv_post_str('wd_name')) ?>"
                            >
                        </div>

                        <div class="wd-field">
                            <label for="wd_email">E-Mail *</label>
                            <input
                                type="email"
                                id="wd_email"
                                name="wd_email"
                                required
                                value="<?= h(pv_post_str('wd_email')) ?>"
                            >
                        </div>

                        <div class="wd-field wd-field--full">
                            <label for="wd_order_ref">Bestell- oder Angebotsnummer</label>
                            <input
                                type="text"
                                id="wd_order_ref"
                                name="wd_order_ref"
                                value="<?= h(pv_post_str('wd_order_ref')) ?>"
                            >
                        </div>

                        <div class="wd-field wd-field--full">
                            <label for="wd_message">Nachricht</label>
                            <textarea
                                id="wd_message"
                                name="wd_message"
                                rows="5"
                                placeholder="Optional: z. B. weitere Angaben zum widerrufenen Vertrag."
                            ><?= h(pv_post_str('wd_message')) ?></textarea>
                        </div>
                    </div>

                    <label class="wd-check">
                        <input
                            type="checkbox"
                            name="wd_confirm"
                            value="1"
                            <?= ((string)($_POST['wd_confirm'] ?? '') === '1') ? 'checked' : '' ?>
                        >
                        <span>Ich möchte meine Widerrufserklärung elektronisch absenden.</span>
                    </label>

                    <div class="wd-actions">
                        <button type="button" class="wd-btn" id="wd_cancel">Abbrechen</button>
                        <button type="submit" class="wd-btn wd-btn--primary">Widerruf absenden</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer id="footer" role="contentinfo">
        <p>
            REGATIX Betriebseinrichtungen GmbH &bull; Porschestra&szlig;e 9 &bull; 74360 Ilsfeld &bull;
            Telefon: 07062 - 23 902 - 0 &bull; E-Mail: info@regatix.com<br />
            Montag - Donnerstag: 08:00 - 12:00 Uhr | 13:00 - 17:00 Uhr &bull;
            Freitag:08:00 - 12:00 Uhr | 13:00 - 16:30 Uhr&nbsp; | <br>
            <span class="footer-links" aria-label="Rechtliche Links und Kontakt">
                <a href="https://www.regatix.com/pages/start/impressum.php" target="_blank" rel="noopener" aria-label="Impressum öffnen">Impressum</a>
                <a href="https://www.regatix.com/pages/start/datenschutz.php" target="_blank" rel="noopener" aria-label="Datenschutz öffnen">Datenschutz</a>
                <a href="https://www.regatix.com/pages/start/versandbedingungen.php" target="_blank" rel="noopener" aria-label="Versandbedingungen öffnen">Versandbedingungen</a>
                <a href="https://www.regatix.com/pages/start/widerrufsrecht.php" target="_blank" rel="noopener" aria-label="Widerrufsrecht öffnen">Widerrufsrecht</a>
                <a href="https://www.regatix.com/pages/start/shop-bedingungen.php" target="_blank" rel="noopener" aria-label="SHOP Bedingungen öffnen">SHOP Bedingungen</a>
                <a href="mailto:info@regatix.com?subject=Angebot%20REGATIX%20Shop" aria-label="Angebot per E-Mail anfordern">Angebot anfordern</a>
                <a href="tel:+497062239020" aria-label="Telefonkontakt REGATIX anrufen">Telefonkontakt</a>
                <a
                    class="pill-toggle"
                    target="_blank"
                    rel="noopener"
                    aria-label="Vertrag widerrufen öffnen"
                    href="withdraw.php?product=<?= h($productKey) ?>&article=<?= h($offerArticle) ?>&offer=<?= h($offerId) ?>"
                >
                    Vertrag widerrufen
                </a>
            </span>
        </p>
    </footer>

    <script>
        (function(){
            const KEY = 'pv_layout';
            const btn = document.getElementById('pv_layout_toggle');

            if(!btn) return;

            function apply(mode){
                const dark = (mode === 'dark');
                document.body.classList.toggle('layout-pro', dark);
                btn.textContent = 'Layout: ' + (dark ? 'Dunkel' : 'Hell');
                btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
            }

            let mode = localStorage.getItem(KEY);

            if(mode !== 'dark' && mode !== 'light'){
                const prefersDark = window.matchMedia
                    && window.matchMedia('(prefers-color-scheme: dark)').matches;
                mode = prefersDark ? 'dark' : 'light';
            }

            apply(mode);

            btn.addEventListener('click', function(){
                const nowDark = document.body.classList.contains('layout-pro');
                mode = nowDark ? 'light' : 'dark';
                localStorage.setItem(KEY, mode);
                apply(mode);
            });
        })();
    </script>

    <script>
        (function(){
            const IS_DESKTOP_HOVER = window.matchMedia
                && window.matchMedia('(hover:hover) and (pointer:fine)').matches;

            function closeAll(except){
                document.querySelectorAll('.pv-select.is-open').forEach(el => {
                    if(except && el === except) return;
                    el.classList.remove('is-open');

                    const btn = el.querySelector('.pv-select__btn');
                    const menu = el.querySelector('.pv-select__menu');
                    if(btn) btn.setAttribute('aria-expanded', 'false');
                    if(menu) menu.hidden = true;
                });
            }

            function fireChange(select){
                try{
                    const ev = new Event('change', {bubbles:true});
                    select.dispatchEvent(ev);
                }catch(e){
                    const ev = document.createEvent('Event');
                    ev.initEvent('change', true, true);
                    select.dispatchEvent(ev);
                }
            }

            function enhanceSelect(select){
                if(!(select instanceof HTMLSelectElement)) return;
                if(select.dataset.pvEnhanced === '1') return;
                if(select.closest('.pv-select')) return;
                if(select.multiple) return;
                if(select.size && select.size > 1) return;

                select.dataset.pvEnhanced = '1';

                const isPill = select.classList.contains('pill-select');
                const wrap = document.createElement('div');
                wrap.className =
                    'pv-select'
                    + (IS_DESKTOP_HOVER ? ' pv-open-on-hover' : '')
                    + (isPill ? ' pv-select--pill' : '');
                wrap.setAttribute('data-pv-select', '1');

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pv-select__btn';
                btn.setAttribute('aria-haspopup', 'listbox');
                btn.setAttribute('aria-expanded', 'false');

                const labelSpan = document.createElement('span');
                labelSpan.className = 'pv-select__label';

                const chev = document.createElement('span');
                chev.className = 'pv-select__chev';
                chev.setAttribute('aria-hidden', 'true');

                btn.appendChild(labelSpan);
                btn.appendChild(chev);

                const menu = document.createElement('ul');
                menu.className = 'pv-select__menu';
                menu.setAttribute('role', 'listbox');
                menu.hidden = true;

                select.classList.add('pv-native-select');

                const parent = select.parentNode;
                parent.insertBefore(wrap, select);
                wrap.appendChild(select);
                wrap.appendChild(btn);
                wrap.appendChild(menu);

                function currentOptionText(){
                    const opt = select.options[select.selectedIndex];
                    return opt ? (opt.textContent || opt.label || '') : '—';
                }

                function rebuildOptions(){
                    menu.innerHTML = '';

                    Array.from(select.options).forEach((opt, idx) => {
                        const li = document.createElement('li');
                        li.className = 'pv-select__opt';
                        li.setAttribute('role', 'option');
                        li.setAttribute('data-value', opt.value);
                        li.setAttribute('data-index', String(idx));
                        li.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
                        li.textContent = opt.textContent || opt.label || opt.value;

                        if(opt.disabled){
                            li.style.opacity = '0.55';
                            li.style.pointerEvents = 'none';
                        }

                        li.addEventListener('mousedown', function(e){
                            e.preventDefault();
                        });

                        li.addEventListener('click', function(){
                            select.selectedIndex = idx;
                            labelSpan.textContent = currentOptionText();
                            rebuildOptions();
                            closeAll();
                            fireChange(select);
                        });

                        menu.appendChild(li);
                    });

                    labelSpan.textContent = currentOptionText();
                }

                rebuildOptions();

                function open(){
                    closeAll(wrap);
                    wrap.classList.add('is-open');
                    btn.setAttribute('aria-expanded', 'true');
                    menu.hidden = false;
                }

                function close(){
                    wrap.classList.remove('is-open');
                    btn.setAttribute('aria-expanded', 'false');
                    menu.hidden = true;
                }

                function toggle(){
                    if(wrap.classList.contains('is-open')) close();
                    else open();
                }

                if(IS_DESKTOP_HOVER){
                    wrap.addEventListener('mouseenter', function(){ open(); });
                    wrap.addEventListener('mouseleave', function(){ close(); });
                }

                btn.addEventListener('click', function(){
                    toggle();
                });

                btn.addEventListener('keydown', function(e){
                    const key = e.key;

                    if(key === 'Enter' || key === ' '){
                        e.preventDefault();
                        toggle();
                    } else if(key === 'ArrowDown'){
                        e.preventDefault();
                        open();
                        select.selectedIndex = Math.min(select.selectedIndex + 1, select.options.length - 1);
                        labelSpan.textContent = currentOptionText();
                        rebuildOptions();
                        fireChange(select);
                    } else if(key === 'ArrowUp'){
                        e.preventDefault();
                        open();
                        select.selectedIndex = Math.max(select.selectedIndex - 1, 0);
                        labelSpan.textContent = currentOptionText();
                        rebuildOptions();
                        fireChange(select);
                    } else if(key === 'Escape'){
                        e.preventDefault();
                        close();
                    }
                });

                select.addEventListener('change', function(){
                    labelSpan.textContent = currentOptionText();
                    rebuildOptions();
                });

                document.addEventListener('mousedown', function(e){
                    if(!wrap.contains(e.target)) close();
                });

                document.addEventListener('keydown', function(e){
                    if(e.key === 'Escape') close();
                });
            }

            function enhanceAll(root){
                (root || document).querySelectorAll('select').forEach(enhanceSelect);
            }

            window.enhanceAll = enhanceAll;

            if(document.readyState === 'loading'){
                document.addEventListener('DOMContentLoaded', function(){
                    enhanceAll(document);
                });
            } else {
                enhanceAll(document);
            }

            const mo = new MutationObserver(function(muts){
                for(const m of muts){
                    if(m.type !== 'childList') continue;

                    m.addedNodes.forEach(node => {
                        if(!(node instanceof Element)) return;

                        if(node.tagName === 'SELECT'){
                            enhanceSelect(node);
                        } else {
                            const sels = node.querySelectorAll ? node.querySelectorAll('select') : [];
                            sels.forEach(enhanceSelect);
                        }
                    });
                }
            });

            mo.observe(document.documentElement, {subtree:true, childList:true});
        })();
    </script>

    <script>
        window.PV_BOOTSTRAP = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        window.PV_CURRENT_PRODUCT_KEY = <?= json_encode($productKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.PV_CURRENT_PRODUCT_LABEL = <?= json_encode($currentLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.PV_OFFER_ARTICLE = <?= json_encode($offerArticle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.PV_OFFER_ID = <?= json_encode($offerId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.PV_IMAGE_BASE_URL = <?= json_encode(rtrim(pv_product_image_base_url(), '/') . '/', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>

    <script>
        (function(){
            function norm(s){
                return String(s == null ? '' : s)
                    .replace(/\u00A0/g, ' ')
                    .replace(/[\u200B-\u200D\uFEFF]/g, '')
                    .trim()
                    .toLowerCase();
            }

            function normUrl(img){
                img = String(img == null ? '' : img).trim();
                if(!img) return '';
                if(/^https?:\/\//i.test(img)) return img;
                img = img.replace(/\\/g, '/').replace(/^\/+/, '');
                return String(window.PV_IMAGE_BASE_URL || '').replace(/\/+$/, '/') + img;
            }

            function getBoot(){
                return window.PV_BOOTSTRAP || {};
            }

            function getPrimaryKeyName(){
                const boot = getBoot();
                const cfg = (boot.config && boot.config.variant) ? boot.config.variant : {};
                return String(cfg.primary_key || 'Artikelnummer');
            }

            function rowGet(row, candidates){
                if(!row || typeof row !== 'object') return '';

                const wanted = {};
                candidates.forEach(function(c){
                    wanted[norm(c)] = true;
                });

                for(const k in row){
                    if(!Object.prototype.hasOwnProperty.call(row, k)) continue;

                    if(wanted[norm(k)]){
                        const v = String(row[k] == null ? '' : row[k]).trim();
                        if(v !== '') return v;
                    }
                }

                return '';
            }

            function getCurrentVariant(){
                if(window.PV_CURRENT_VARIANT && typeof window.PV_CURRENT_VARIANT === 'object'){
                    return window.PV_CURRENT_VARIANT;
                }

                const boot = getBoot();
                const variants = Array.isArray(boot.variants) ? boot.variants : [];
                if(!variants.length) return null;

                const pk = getPrimaryKeyName();
                const offerArticle = String(window.PV_OFFER_ARTICLE || '').trim();

                if(offerArticle){
                    const found = variants.find(function(v){
                        return String(v && v[pk] != null ? v[pk] : '').trim() === offerArticle;
                    });

                    if(found) return found;
                }

                return variants[0] || null;
            }

            function getCurrentArticleNumber(){
                const variant = getCurrentVariant();
                const pk = getPrimaryKeyName();

                return rowGet(variant, [
                    pk,
                    'Artikelnummer',
                    'artikelnummer',
                    'Artikel-Nr',
                    'Artikelnr.',
                    'ArtNr',
                    'SKU',
                    'sku'
                ]);
            }

            function getArticleContentMap(){
                const boot = getBoot();
                const content = (boot && boot.content && typeof boot.content === 'object')
                    ? boot.content
                    : {};

                const candidates = [
                    content.article_content,
                    content.article_contents,
                    content.by_article,
                    content.articles,
                    content.items,
                    boot.article_content,
                    boot.article_contents,
                    boot.by_article,
                    boot.articles,
                    boot.items
                ];

                for(const candidate of candidates){
                    if(candidate && typeof candidate === 'object' && !Array.isArray(candidate)){
                        return candidate;
                    }
                }

                const directMap = {};
                let foundAny = false;

                Object.keys(content).forEach(function(key){
                    const val = content[key];

                    if(
                        val
                        && typeof val === 'object'
                        && !Array.isArray(val)
                        && (Array.isArray(val.images) || typeof val.info_html === 'string')
                    ){
                        directMap[key] = val;
                        foundAny = true;
                    }
                });

                return foundAny ? directMap : {};
            }

            function getProductFallbackContent(){
                const boot = getBoot();
                const product = (boot.content && boot.content.product && typeof boot.content.product === 'object')
                    ? boot.content.product
                    : {};

                return {
                    info_html: typeof product.info_html === 'string' ? product.info_html : '',
                    images: Array.isArray(product.images) ? product.images : []
                };
            }

            function getCurrentArticleContent(){
                const article = getCurrentArticleNumber();
                const map = getArticleContentMap();

                if(article && map && typeof map === 'object'){
                    if(map[article] && typeof map[article] === 'object'){
                        return {
                            article: article,
                            info_html: typeof map[article].info_html === 'string' ? map[article].info_html : '',
                            images: Array.isArray(map[article].images) ? map[article].images : []
                        };
                    }

                    const wanted = norm(article);

                    for(const k in map){
                        if(!Object.prototype.hasOwnProperty.call(map, k)) continue;

                        if(norm(k) === wanted && map[k] && typeof map[k] === 'object'){
                            return {
                                article: article,
                                info_html: typeof map[k].info_html === 'string' ? map[k].info_html : '',
                                images: Array.isArray(map[k].images) ? map[k].images : []
                            };
                        }
                    }
                }

                const fallback = getProductFallbackContent();

                return {
                    article: article,
                    info_html: fallback.info_html,
                    images: fallback.images
                };
            }

            function normalizeImages(images){
                const out = [];
                const seen = {};

                (Array.isArray(images) ? images : []).forEach(function(img){
                    const url = normUrl(img);
                    if(!url) return;

                    const key = url.toLowerCase();
                    if(seen[key]) return;

                    seen[key] = true;
                    out.push(url);
                });

                return out;
            }

            function renderInfoHtml(content){
                const info = document.getElementById('pv_info_html');
                if(!info) return;

                const html = String(content && content.info_html != null ? content.info_html : '').trim();
                info.innerHTML = html;
            }

            function renderGallery(content){
                const hero = document.getElementById('pv_hero');
                const thumbs = document.getElementById('pv_thumbs');

                if(!hero || !thumbs) return;

                const images = normalizeImages(content && content.images ? content.images : []);

                hero.innerHTML = '';
                thumbs.innerHTML = '';

                if(!images.length) return;

                let activeIndex = 0;

                function drawHero(){
                    const src = images[activeIndex] || '';
                    hero.innerHTML = '';
                    if(!src) return;

                    const a = document.createElement('a');
                    a.href = src;
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.setAttribute('aria-label', 'Produktbild in voller Größe öffnen');
                    a.title = 'Produktbild in voller Größe öffnen';

                    const sr = document.createElement('span');
                    sr.className = 'sr-only';
                    sr.textContent = 'Produktbild in voller Größe öffnen';
                    a.appendChild(sr);

                    const img = document.createElement('img');
                    img.src = src;
                    img.alt = (document.body && document.body.getAttribute('data-product-label')) || 'Produktbild';
                    img.loading = 'eager';
                    img.decoding = 'async';
                    img.style.display = 'block';
                    img.style.width = '100%';
                    img.style.height = 'auto';
                    img.style.maxHeight = '520px';
                    img.style.objectFit = 'contain';
                    img.style.borderRadius = '12px';

                    a.appendChild(img);
                    hero.appendChild(a);
                }

                function drawThumbs(){
                    thumbs.innerHTML = '';
                    if(images.length <= 1) return;

                    images.forEach(function(src, index){
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'thumb';
                        btn.setAttribute('aria-label', 'Produktbild ' + (index + 1) + ' anzeigen');
                        btn.style.cursor = 'pointer';
                        btn.style.padding = '0';
                        btn.style.background = 'transparent';
                        btn.style.border = index === activeIndex ? '2px solid #95bf20' : '';

                        const img = document.createElement('img');
                        img.src = src;
                        img.alt = 'Vorschaubild ' + (index + 1);
                        img.loading = 'lazy';
                        img.decoding = 'async';
                        img.style.display = 'block';
                        img.style.width = '100%';
                        img.style.height = '100%';
                        img.style.objectFit = 'contain';

                        btn.addEventListener('click', function(){
                            activeIndex = index;
                            drawHero();
                            drawThumbs();
                        });

                        btn.appendChild(img);
                        thumbs.appendChild(btn);
                    });
                }

                drawHero();
                drawThumbs();
            }

            function renderArticleContent(){
                const content = getCurrentArticleContent();
                renderInfoHtml(content);
                renderGallery(content);
            }

            function run(){
                renderArticleContent();
            }

            if(document.readyState === 'loading'){
                document.addEventListener('DOMContentLoaded', run);
            } else {
                run();
            }

            window.addEventListener('load', run);
            document.addEventListener('pv:variant-changed', run);
            setTimeout(run, 100);
            setTimeout(run, 400);
            setTimeout(run, 1000);
        })();
    </script>

    <script>
        (function(){
            function norm(s){
                return String(s == null ? '' : s)
                    .replace(/\u00A0/g, ' ')
                    .replace(/[\u200B-\u200D\uFEFF]/g, '')
                    .trim()
                    .toLowerCase();
            }

            function compact(s){
                return norm(s).replace(/[\s\-_]+/g, '');
            }

            function isPalettenregalProduct(){
                const body = document.body;
                const key = body ? (body.getAttribute('data-product-key') || '') : '';
                const label = body ? (body.getAttribute('data-product-label') || '') : '';
                const title = document.querySelector('.h-title')
                    ? document.querySelector('.h-title').textContent
                    : '';
                const all = compact(key + ' ' + label + ' ' + title);

                return all.indexOf('palettenregal') !== -1
                    || all.indexOf('palettenregalgrundregal') !== -1;
            }

            if(!isPalettenregalProduct()) return;

            const boot = window.PV_BOOTSTRAP || {};
            const variants = Array.isArray(boot.variants) ? boot.variants : [];
            if(!variants.length) return;

            const dimsWrap = document.getElementById('pv_dims');
            const infoWrap = document.getElementById('pv_info_fields');
            const priceGrossEl = document.getElementById('pv_price_gross');
            const priceNetEl = document.getElementById('pv_price_net');
            if(!dimsWrap) return;

            const variantCfg = (boot.config && boot.config.variant) ? boot.config.variant : {};
            const primaryKey = String(variantCfg.primary_key || 'Artikelnummer');
            const priceGrossKey = String(variantCfg.price_gross || 'mit MwSt. €');
            const priceNetKey = String(variantCfg.price_net || 'ohne MwSt. €');

            const PAL_FIELDS = [
                { label: 'Produkt Art', keys: ['Produkt Art', 'Produktart', 'Produkt-Art', 'Art'] },
                { label: 'Feldanzahl', keys: ['Feldanzahl', 'Feld Anzahl', 'Feld-Anzahl', 'Felder', 'Feldzahl', 'Anzahl Felder', 'Felder anzahl'] },
                { label: 'Ebenen', keys: ['Ebenen', 'Ebene', 'Anzahl Ebenen'] },
                { label: 'Platz Kg', keys: ['Platz Kg', 'Platz KG', 'Platz kg', 'Platzlast Kg', 'Platzlast KG', 'Fachlast Kg', 'Fachlast KG'] },
                { label: 'Nennhöhe', keys: ['Nennhöhe', 'Nennhoehe', 'Nenn Höhe', 'Nenn-Höhe', 'Höhe', 'Hoehe', 'Nennhöhe mm'] },
                { label: 'Nenntiefe', keys: ['Nenntiefe', 'Nenn Tiefe', 'Nenn-Tiefe', 'Tiefe', 'Nenntiefe mm'] },
                { label: 'Nennlänge', keys: ['Nennlänge', 'Nennlaenge', 'Nenn Länge', 'Nenn-Länge', 'Nenn-Laenge', 'Länge', 'Laenge', 'Breite', 'Nennbreite', 'Nennlänge mm'] }
            ];

            function getActualKey(row, candidates){
                if(!row || typeof row !== 'object') return '';

                const wanted = {};
                candidates.forEach(function(c){
                    wanted[norm(c)] = true;
                });

                for(const k in row){
                    if(!Object.prototype.hasOwnProperty.call(row, k)) continue;
                    if(wanted[norm(k)]) return k;
                }

                return '';
            }

            const resolvedFields = PAL_FIELDS
                .map(function(field){
                    let actualKey = '';

                    for(const row of variants){
                        actualKey = getActualKey(row, field.keys);
                        if(actualKey) break;
                    }

                    return {
                        label: field.label,
                        key: actualKey,
                        keys: field.keys.slice()
                    };
                })
                .filter(function(field){
                    return !!field.key;
                });

            if(!resolvedFields.length) return;

            const state = {
                selected: {},
                currentVariant: null
            };

            function parseMoney(v){
                const s = String(v == null ? '' : v).trim();
                if(!s) return '';
                if(s.indexOf('€') !== -1) return s;

                const normalized = s.replace(/\./g, '').replace(',', '.');
                const n = parseFloat(normalized);
                if(!isFinite(n)) return s;

                return n.toLocaleString('de-DE', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }) + ' €';
            }

            function getOptionsForField(fieldIndex, selectionMap){
                const field = resolvedFields[fieldIndex];
                const values = [];
                const seen = {};

                variants.forEach(function(row){
                    for(let i = 0; i < fieldIndex; i++){
                        const prevField = resolvedFields[i];
                        const prevSelected = selectionMap[prevField.label];

                        if(prevSelected && String(row[prevField.key] ?? '').trim() !== prevSelected){
                            return;
                        }
                    }

                    const value = String(row[field.key] ?? '').trim();
                    if(!value) return;

                    const nk = norm(value);
                    if(seen[nk]) return;

                    seen[nk] = true;
                    values.push(value);
                });

                return values;
            }

            function findMatchingVariant(selectionMap){
                const exactMatches = variants.filter(function(row){
                    return resolvedFields.every(function(field){
                        const selected = selectionMap[field.label];
                        if(!selected) return true;
                        return String(row[field.key] ?? '').trim() === selected;
                    });
                });

                if(exactMatches.length) return exactMatches[0];

                let best = null;
                let bestScore = -1;

                variants.forEach(function(row){
                    let score = 0;

                    resolvedFields.forEach(function(field){
                        const selected = selectionMap[field.label];
                        if(selected && String(row[field.key] ?? '').trim() === selected){
                            score++;
                        }
                    });

                    if(score > bestScore){
                        best = row;
                        bestScore = score;
                    }
                });

                return best || variants[0] || null;
            }

            function renderInfo(row){
                if(!infoWrap || !row) return;

                infoWrap.innerHTML = '';

                function addRow(label, value){
                    const val = String(value == null ? '' : value).trim();

                    const r = document.createElement('div');
                    r.className = 'kv-row';

                    const k = document.createElement('div');
                    k.className = 'kv-k';
                    k.textContent = label;

                    const v = document.createElement('div');
                    v.className = 'kv-v';
                    v.textContent = val || '—';

                    r.appendChild(k);
                    r.appendChild(v);
                    infoWrap.appendChild(r);
                }

                function getRowValue(candidates){
                    if(!row || typeof row !== 'object') return '';

                    const wanted = {};
                    candidates.forEach(function(c){
                        wanted[norm(c)] = true;
                    });

                    for(const key in row){
                        if(!Object.prototype.hasOwnProperty.call(row, key)) continue;

                        if(wanted[norm(key)]){
                            const val = String(row[key] == null ? '' : row[key]).trim();
                            if(val !== '') return val;
                        }
                    }

                    return '';
                }

addRow('Artikelnummer', getRowValue([primaryKey, 'Artikelnummer', 'Artikel-Nr', 'Artikelnr.', 'ArtNr', 'SKU', 'sku']));
                addRow('Produkt Art', getRowValue(['Produkt Art', 'Produktart', 'Produkt-Art', 'Art']));
                addRow('Ebenen', getRowValue(['Ebenen', 'Ebene', 'Anzahl Ebenen']));
                addRow('Feldanzahl', getRowValue(['Feldanzahl', 'Feld Anzahl', 'Feld-Anzahl', 'Felder', 'Feldzahl', 'Anzahl Felder', 'Felder anzahl']));
                addRow('Platz Kg', getRowValue(['Platz Kg', 'Platz KG', 'Platz kg', 'Platzlast Kg', 'Platzlast KG', 'Fachlast Kg', 'Fachlast KG']));
const gewicht = getRowValue(['Gewicht KG', 'Gewicht Kg', 'Gewicht kg', 'Gewicht', 'Eigengewicht KG', 'Eigengewicht Kg', 'Eigengewicht kg']);
                addRow('Gewicht Kg', gewicht || '');               addRow('Nennhöhe', getRowValue(['Nennhöhe', 'Nennhoehe', 'Nenn Höhe', 'Nenn-Höhe', 'Höhe', 'Hoehe', 'Nennhöhe mm']));
                addRow('Nenntiefe', getRowValue(['Nenntiefe', 'Nenn Tiefe', 'Nenn-Tiefe', 'Tiefe', 'Nenntiefe mm']));
                addRow('Nennlänge', getRowValue(['Nennlänge', 'Nennlaenge', 'Nenn Länge', 'Nenn-Länge', 'Nenn-Laenge', 'Länge', 'Laenge', 'Breite', 'Nennbreite', 'Nennlänge mm']));
            }

            function renderPrices(row){
                if(!row) return;

                if(priceGrossEl){
                    const gross = String(row[priceGrossKey] ?? '').trim();
                    priceGrossEl.textContent = gross ? parseMoney(gross) : '—';
                }

                if(priceNetEl){
                    const net = String(row[priceNetKey] ?? '').trim();
                    priceNetEl.textContent = net ? ('zzgl. MwSt.: ' + parseMoney(net)) : '';
                }
            }

            function updateCurrentVariant(){
                const currentSelections = {};

                resolvedFields.forEach(function(field){
                    const sel = dimsWrap.querySelector('select[data-pal-field="' + field.label + '"]');
                    currentSelections[field.label] = sel ? String(sel.value || '').trim() : '';
                });

                state.selected = currentSelections;
                state.currentVariant = findMatchingVariant(currentSelections);

                if(state.currentVariant){
                    window.PV_CURRENT_VARIANT = state.currentVariant;
                    window.currentVariant = state.currentVariant;
                    renderInfo(state.currentVariant);
                    renderPrices(state.currentVariant);
                }

                document.dispatchEvent(new Event('pv:variant-changed'));
            }

            function buildField(field, fieldIndex){
                const fieldWrap = document.createElement('div');

                const label = document.createElement('label');
                label.setAttribute('for', 'pv_pal_field_' + fieldIndex);
                label.textContent = field.label;

                const select = document.createElement('select');
                select.id = 'pv_pal_field_' + fieldIndex;
                select.name = field.label;
                select.setAttribute('data-pal-field', field.label);

                const options = getOptionsForField(fieldIndex, state.selected);

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Bitte wählen';
                select.appendChild(placeholder);

                options.forEach(function(value){
                    const opt = document.createElement('option');
                    opt.value = value;
                    opt.textContent = value;
                    select.appendChild(opt);
                });

                const currentValue = state.selected[field.label] || '';

                if(currentValue && options.includes(currentValue)){
                    select.value = currentValue;
                } else if(!currentValue && options.length === 1){
                    state.selected[field.label] = options[0];
                    select.value = options[0];
                } else if(currentValue && !options.includes(currentValue)){
                    state.selected[field.label] = '';
                    select.value = '';
                }

                select.addEventListener('change', function(){
                    state.selected[field.label] = String(select.value || '').trim();

                    let resetFollowing = false;

                    resolvedFields.forEach(function(f){
                        if(resetFollowing){
                            state.selected[f.label] = '';
                        }

                        if(f.label === field.label){
                            resetFollowing = true;
                        }
                    });

                    renderPulldowns();
                    updateCurrentVariant();
                });

                fieldWrap.appendChild(label);
                fieldWrap.appendChild(select);

                return fieldWrap;
            }

            function renderPulldowns(){
                dimsWrap.innerHTML = '';

                const desiredOrder = [
                    'Produkt Art',
                    'Feldanzahl',
                    'Ebenen',
                    'Platz Kg',
                    'Nennhöhe',
                    'Nenntiefe',
                    'Nennlänge'
                ];

                desiredOrder.forEach(function(label){
                    const field = resolvedFields.find(function(f){
                        return f.label === label;
                    });

                    if(field){
                        const originalIndex = resolvedFields.indexOf(field);
                        dimsWrap.appendChild(buildField(field, originalIndex));
                    }
                });

                if(typeof window.enhanceAll === 'function'){
                    window.enhanceAll(dimsWrap);
                }
            }

            function initSelection(){
                let initialVariant = null;
                const offerArticle = String(window.PV_OFFER_ARTICLE || '').trim();

                if(offerArticle){
                    initialVariant = variants.find(function(row){
                        return String(row[primaryKey] ?? '').trim() === offerArticle;
                    }) || null;
                }

                if(!initialVariant){
                    initialVariant = variants[0] || null;
                }

                if(!initialVariant) return;

                resolvedFields.forEach(function(field){
                    state.selected[field.label] = String(initialVariant[field.key] ?? '').trim();
                });
            }

            function run(){
                initSelection();
                renderPulldowns();
                updateCurrentVariant();
            }

            if(document.readyState === 'loading'){
                document.addEventListener('DOMContentLoaded', run);
            } else {
                run();
            }

            window.addEventListener('load', run);
            setTimeout(run, 150);
            setTimeout(run, 700);
        })();
    </script>

    <script>
        (function(){
            const modal = document.getElementById('wd_modal');
            const openBtns = [
                document.getElementById('pv_withdrawal_open'),
                document.getElementById('pv_withdrawal_open_footer')
            ].filter(Boolean);

            const closeBtn = document.getElementById('wd_close');
            const cancelBtn = document.getElementById('wd_cancel');
            const body = document.body;
            const fProductKey = document.getElementById('wd_product_key');
            const fProductLabel = document.getElementById('wd_product_label');
            const fArticle = document.getElementById('wd_article');
            const fOfferId = document.getElementById('wd_offer_id');
            const fPageUrl = document.getElementById('wd_page_url');

            if(!modal) return;

            function norm(s){
                return String(s == null ? '' : s)
                    .replace(/\u00A0/g, ' ')
                    .replace(/[\u200B-\u200D\uFEFF]/g, '')
                    .trim()
                    .toLowerCase();
            }

            function rowGet(row, candidates){
                if(!row || typeof row !== 'object') return '';

                const wanted = {};
                candidates.forEach(c => wanted[norm(c)] = true);

                for(const k in row){
                    if(!Object.prototype.hasOwnProperty.call(row, k)) continue;

                    if(wanted[norm(k)]){
                        const v = String(row[k] == null ? '' : row[k]).trim();
                        if(v !== '') return v;
                    }
                }

                return '';
            }

            function getPrimaryKeyName(){
                const boot = window.PV_BOOTSTRAP || {};
                const cfg = (boot && boot.config && boot.config.variant) ? boot.config.variant : {};
                return String(cfg.primary_key || 'Artikelnummer');
            }

            function getVariants(){
                const boot = window.PV_BOOTSTRAP || {};
                return Array.isArray(boot.variants) ? boot.variants : [];
            }

            function getCurrentSelectedCriteria(){
                const criteria = {};
                const dims = document.getElementById('pv_dims');
                if(!dims) return criteria;

                dims.querySelectorAll('select').forEach(function(sel){
                    const value = (sel.value || '').trim();
                    if(value === '') return;

                    let key = (sel.getAttribute('name') || '').trim();

                    if(!key){
                        const id = sel.id || '';

                        if(id){
                            const labelByFor = dims.querySelector('label[for="' + CSS.escape(id) + '"]');
                            if(labelByFor) key = (labelByFor.textContent || '').trim();
                        }
                    }

                    if(!key){
                        const parent = sel.closest('label');
                        if(parent) key = (parent.textContent || '').trim();
                    }

                    if(!key){
                        const prev = sel.previousElementSibling;
                        if(prev && prev.tagName === 'LABEL'){
                            key = (prev.textContent || '').trim();
                        }
                    }

                    if(key) criteria[key] = value;
                });

                return criteria;
            }

            function findVariantByCurrentSelection(){
                const variants = getVariants();
                if(!variants.length) return null;

                if(window.PV_CURRENT_VARIANT && typeof window.PV_CURRENT_VARIANT === 'object'){
                    return window.PV_CURRENT_VARIANT;
                }

                const pk = getPrimaryKeyName();
                const criteria = getCurrentSelectedCriteria();
                const explicitArticle =
                    (window.PV_OFFER_ARTICLE || '').trim()
                    || (fArticle && fArticle.value ? fArticle.value.trim() : '');

                if(explicitArticle){
                    const foundByPk = variants.find(v =>
                        String(v && v[pk] != null ? v[pk] : '').trim() === explicitArticle
                    );

                    if(foundByPk) return foundByPk;
                }

                const criterionKeys = Object.keys(criteria);

                if(criterionKeys.length){
                    let best = null;
                    let bestScore = -1;

                    variants.forEach(function(v){
                        if(!v || typeof v !== 'object') return;

                        let score = 0;
                        let mismatch = false;

                        criterionKeys.forEach(function(selKey){
                            const selVal = String(criteria[selKey] == null ? '' : criteria[selKey]).trim();
                            if(selVal === '') return;

                            for(const vk in v){
                                if(!Object.prototype.hasOwnProperty.call(v, vk)) continue;
                                if(norm(vk) !== norm(selKey)) continue;

                                if(norm(String(v[vk] == null ? '' : v[vk])) === norm(selVal)){
                                    score++;
                                } else {
                                    mismatch = true;
                                }

                                break;
                            }
                        });

                        if(!mismatch && score > bestScore){
                            best = v;
                            bestScore = score;
                        }
                    });

                    if(best) return best;
                }

                return variants[0] || null;
            }

            function getCurrentArticleNumber(){
                const pk = getPrimaryKeyName();
                const variant = findVariantByCurrentSelection();
                if(!variant) return '';

                return rowGet(variant, [
                    pk,
                    'Artikelnummer',
                    'artikelnummer',
                    'Artikelnr.',
                    'ArtNr',
                    'SKU',
                    'sku'
                ]);
            }

            function syncWithdrawalFields(){
                if(fProductKey) fProductKey.value = body ? (body.getAttribute('data-product-key') || '') : '';
                if(fProductLabel) fProductLabel.value = body ? (body.getAttribute('data-product-label') || '') : '';
                if(fArticle) fArticle.value = getCurrentArticleNumber() || (window.PV_OFFER_ARTICLE || '');
                if(fOfferId) fOfferId.value = window.PV_OFFER_ID || '';
                if(fPageUrl) fPageUrl.value = window.location.href;
            }

            function openModal(){
                syncWithdrawalFields();
                modal.hidden = false;
                modal.removeAttribute('inert');
                modal.classList.add('is-open');
                document.body.style.overflow = 'hidden';

                const firstInput = document.getElementById('wd_name');
                if(firstInput) setTimeout(() => firstInput.focus(), 10);
            }

            function closeModal(){
                modal.classList.remove('is-open');
                modal.setAttribute('inert', '');
                modal.hidden = true;
                document.body.style.overflow = '';
            }

            openBtns.forEach(btn => btn.addEventListener('click', openModal));

            if(closeBtn) closeBtn.addEventListener('click', closeModal);
            if(cancelBtn) cancelBtn.addEventListener('click', closeModal);

            modal.addEventListener('click', function(e){
                if(e.target === modal) closeModal();
            });

            document.addEventListener('keydown', function(e){
                if(e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
            });

            const dimsWrap = document.getElementById('pv_dims');

            if(dimsWrap){
                dimsWrap.addEventListener('change', syncWithdrawalFields, true);
                new MutationObserver(syncWithdrawalFields).observe(dimsWrap, {
                    childList:true,
                    subtree:true
                });
            }

            const infoWrap = document.getElementById('pv_info_fields');

            if(infoWrap){
                new MutationObserver(syncWithdrawalFields).observe(infoWrap, {
                    childList:true,
                    subtree:true,
                    characterData:true
                });
            }

            document.addEventListener('pv:variant-changed', syncWithdrawalFields);
            window.addEventListener('load', syncWithdrawalFields);
            setTimeout(syncWithdrawalFields, 200);
            setTimeout(syncWithdrawalFields, 900);
        })();
    </script>
</body>
</html>