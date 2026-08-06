<?php
declare(strict_types=1);

session_start();

// ✅ FIX: Seite nicht cachen (sonst bleibt HTML alt)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../src/Repository.php';

/**
 * ✅ Produkt-Key: Unicode-fähig (Umlaute etc. erlaubt)
 * Erlaubt: Buchstaben/Zahlen (Unicode), "_" und "-"
 */
function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\pL\pN_\-]+~u', '', $s);
  return $s ?: '';
}
function pv_lower(string $s): string {
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** ✅ Robust: Keys aus CSV/JSON können BOM/Leerzeichen/ZeroWidth enthalten */
function pv_norm_key(string $s): string {
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);                 // BOM
  $s = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $s);          // NBSP
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);  // Zero width
  $s = trim($s);
  $s = preg_replace('~\s+~u', ' ', $s);
  return pv_lower($s);
}

/** ✅ Robust: Wert aus Row holen, auch wenn Key z.B. "Produktgruppe " heißt */
function pv_row_get(array $row, array $candidates): ?string {
  $want = [];
  foreach ($candidates as $c) {
    $want[pv_norm_key((string)$c)] = true;
  }

  foreach ($row as $k => $v) {
    if (!is_string($k) && !is_int($k)) continue;
    $nk = pv_norm_key((string)$k);
    if (isset($want[$nk])) {
      $s = trim((string)$v);
      return $s === '' ? null : $s;
    }
  }
  return null;
}

/* ===========================
   ✅ Produkt-Label Overrides (aus Admin)
   Datei: config/product_labels.json
   =========================== */
function pv_labels_path(): string {
  return __DIR__ . '/../config/product_labels.json';
}
function pv_load_labels(): array {
  $path = pv_labels_path();
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

/** ✅ Sichtbarkeitspfad */
function pv_visibility_path(): string {
  return __DIR__ . '/../config/product_visibility.json';
}

/** ✅ Sichtbarkeit laden: default = sichtbar */
function pv_load_visibility(): array {
  $path = pv_visibility_path();
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  if (!is_array($arr)) return [];
  if (isset($arr['visible']) && is_array($arr['visible'])) return $arr['visible'];
  if (!empty($arr) && !isset($arr['visible'])) return $arr;
  return [];
}

/* ===========================
   ✅ NEU: Warnhinweis pro Produkt (product_warn.json)
   - Default: AUS (false)
   - Keys sind Produkt-Keys (wie im Admin)
   =========================== */
function pv_product_warn_path(): string {
  return __DIR__ . '/../config/product_warn.json';
}
function pv_load_product_warn_map(): array {
  $path = pv_product_warn_path();
  if (!is_file($path)) return [];

  $rawTxt = (string)file_get_contents($path);
  $rawTxt = preg_replace('/^\xEF\xBB\xBF/', '', $rawTxt); // BOM entfernen
  if (trim($rawTxt) === '') return [];

  $raw = json_decode($rawTxt, true);
  return is_array($raw) ? $raw : [];
}
function pv_boolish($v): ?bool {
  if (is_bool($v)) return $v;
  if (is_int($v) || is_float($v)) return ((float)$v) !== 0.0;
  if (is_string($v)) {
    $s = pv_lower(trim($v));
    if ($s === '') return null;
    if (in_array($s, ['1','true','yes','ja','on','an','enable','enabled'], true)) return true;
    if (in_array($s, ['0','false','no','nein','off','aus','disable','disabled'], true)) return false;
    return null;
  }
  return null;
}
function pv_warn_enabled_for_product(string $productKey, array $map, bool $default = false): bool {
  $k = pv_key($productKey);
  if ($k === '') return $default;

  // map kann auch verschachtelt sein – wir unterstützen beides:
  // 1) {"fachbodenregal": true, ...}
  // 2) {"warn": {"fachbodenregal": true, ...}} oder {"product_warn": {...}} etc.
  foreach (['product_warn','warn','products','map','data','items'] as $container) {
    if (isset($map[$container]) && is_array($map[$container])) {
      $map = $map[$container];
      break;
    }
  }

  // direktes Mapping
  if (array_key_exists($k, $map)) {
    $b = pv_boolish($map[$k]);
    return $b === null ? $default : $b;
  }

  // case-insensitive fallback (sicher, falls Keys mal anders geschrieben)
  $lk = pv_lower($k);
  foreach ($map as $mk => $mv) {
    if (!is_string($mk)) continue;
    if (pv_lower(pv_key($mk)) === $lk) {
      $b = pv_boolish($mv);
      return $b === null ? $default : $b;
    }
  }

  return $default;
}

// Nur Produkte aus data/products laden, Eckregal filtern + Sichtbarkeit beachten
function pv_products(): array {
  $out = [];
  $base = __DIR__ . '/../data/products';
  if (!is_dir($base)) return $out;

  $vis = pv_load_visibility();     // key => bool
  $labelOverrides = pv_load_labels(); // key => "Neues Label"

  foreach (scandir($base) ?: [] as $d) {
    if ($d === '.' || $d === '..') continue;
    $path = $base . '/' . $d;
    if (!is_dir($path)) continue;

    $key = pv_key($d);
    if ($key === '') continue;

    // ✅ Sichtbarkeit: falls im JSON explizit false -> ausblenden
    if (isset($vis[$key]) && !$vis[$key]) continue;

    $label = $key;
    $vfile = $path . '/variants.json';
    $isEckregal = false;

    if (is_file($vfile)) {
      $variantsStore = json_decode((string)file_get_contents($vfile), true);

      $variants = $variantsStore;
      if (is_array($variantsStore) && isset($variantsStore['variants']) && is_array($variantsStore['variants'])) {
        $variants = $variantsStore['variants'];
      }

      if (!empty($variants[0]['Produktgruppe']) && !empty($variants[0]['Produktart'])) {
        $label = trim((string)$variants[0]['Produktgruppe'] . ' ' . (string)$variants[0]['Produktart']);
      } elseif (!empty($variants[0]['Produktart'])) {
        $label = trim((string)$variants[0]['Produktart']);
      }

      // ✅ Label-Override: "Fachbodenregal Grundregal" -> "Fachbodenregal"
      $labelNorm = pv_lower(preg_replace('~\s+~u', ' ', trim((string)$label)));
      if ($labelNorm === 'fachbodenregal grundregal') {
        $label = 'Fachbodenregal';
      }

      // Eckregal rausfiltern (Produktart)
      if (isset($variants[0]['Produktart']) && stripos((string)$variants[0]['Produktart'], 'Eckregal') !== false) {
        $isEckregal = true;
      }
    }

    if ($isEckregal) continue;

    // ✅ Admin-Umbenennung greift im Frontend (Override gewinnt)
    if (isset($labelOverrides[$key]) && is_string($labelOverrides[$key]) && trim($labelOverrides[$key]) !== '') {
      $label = trim($labelOverrides[$key]);
    }

    $out[$key] = $label;
  }

  return $out;
}

/* ===========================
   ✅ OFFER HELPERS
   =========================== */

function pv_offers_path(): string {
  $cands = [
    __DIR__ . '/../data/offers/offers.json',
    __DIR__ . '/../data/offers.json',
    __DIR__ . '/../config/offers.json',
    __DIR__ . '/../data/offers_store.json',
    __DIR__ . '/../data/offers/offers_store.json',
  ];
  foreach ($cands as $p) {
    if (is_file($p)) return $p;
  }
  return __DIR__ . '/../data/offers/offers.json';
}

/**
 * offers.json akzeptierte Formen:
 * 1) {"offers":{ "of_...": {...}, ...}}
 * 2) { "of_...": {...}, ...}
 * 3) [{"id":"of_..."}, ...]
 */
function pv_load_offers_any(string &$usedPath = ''): array {
  $path = pv_offers_path();
  $usedPath = $path;
  if (!is_file($path)) return [];
  $raw = json_decode((string)file_get_contents($path), true);
  if (!is_array($raw)) return [];

  if (isset($raw['offers']) && is_array($raw['offers'])) $raw = $raw['offers'];

  if (array_is_list($raw)) {
    $out = [];
    foreach ($raw as $o) {
      if (!is_array($o)) continue;
      $id = (string)($o['id'] ?? $o['offer_id'] ?? '');
      if ($id === '') continue;
      $out[$id] = $o;
    }
    return $out;
  }

  $out = [];
  foreach ($raw as $k => $v) {
    if (!is_array($v)) continue;
    $id = (string)($v['id'] ?? $v['offer_id'] ?? $k);
    if ($id === '') continue;
    $out[$id] = $v;
  }
  return $out;
}

function pv_float_or_null($v): ?float {
  if ($v === null) return null;
  if (is_float($v) || is_int($v)) return (float)$v;
  $s = trim((string)$v);
  if ($s === '') return null;
  $s = str_replace(['.', ' '], ['', ''], $s);
  $s = str_replace(',', '.', $s);
  return is_numeric($s) ? (float)$s : null;
}

function pv_money_fmt(float $v): string {
  return number_format($v, 2, ',', '.') . ' €';
}

/* ===========================
   ✅ Produktwechsel
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_product') {
  $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
  header('Location: index.php');
  exit;
}

$products = pv_products();

// ✅ Produkt-Keys case-insensitive auflösen
$productsByLower = [];
foreach ($products as $k => $_label) {
  $productsByLower[pv_lower((string)$k)] = (string)$k;
}
function pv_canon_product(string $in, array $map): string {
  $k = pv_key($in);
  if ($k === '') return '';
  $lk = pv_lower($k);
  return $map[$lk] ?? '';
}

// "Fachbodenregal" als Default, sonst erstes Produkt
$defaultKey = null;
foreach ($products as $k => $v) {
  if (stripos($v, 'Fachbodenregal') !== false) { $defaultKey = $k; break; }
}
if ($defaultKey === null) {
  reset($products);
  $defaultKey = key($products);
}

// Priorität: GET > Session > Default
$getKey = isset($_GET['product']) ? pv_canon_product((string)$_GET['product'], $productsByLower) : '';
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

/* ===========================
   ✅ Offer Parameter
   =========================== */
$offerId = trim((string)($_GET['offer'] ?? ''));
$offerArticle = trim((string)($_GET['article'] ?? ''));
$offerData = null;
$offerGross = null;
$offerNet = null;
$listGross = null;
$listNet = null;
$offersUsedPath = '';

/* ===========================
   ✅ Bootstrapping
   =========================== */
$repo = new PV\Repository(__DIR__ . '/../config/config.json', $productKey);
$boot = $repo->bootstrap();

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
    }

    $oArticle = trim((string)($offerData['article'] ?? $offerData['sku'] ?? $offerData['Artikelnummer'] ?? ''));
    if ($offerArticle === '' && $oArticle !== '') $offerArticle = $oArticle;

    $offerGross = pv_float_or_null($offerData['price_gross'] ?? $offerData['offer_price_gross'] ?? $offerData['gross'] ?? null);
    $offerNet   = pv_float_or_null($offerData['price_net'] ?? $offerData['offer_price_net'] ?? $offerData['net'] ?? null);

    if (!empty($boot['variants']) && $offerArticle !== '') {
      $pk = (string)($boot['config']['variant']['primary_key'] ?? 'Artikelnummer');
      $priceGrossKey = (string)($boot['config']['variant']['price_gross'] ?? 'mit MwSt. €');
      $priceNetKey   = (string)($boot['config']['variant']['price_net'] ?? 'ohne MwSt. €');

      foreach ($boot['variants'] as $v) {
        if (!is_array($v)) continue;
        if ((string)($v[$pk] ?? '') !== $offerArticle) continue;
        $listGross = pv_float_or_null($v[$priceGrossKey] ?? null);
        $listNet   = pv_float_or_null($v[$priceNetKey] ?? null);
        break;
      }
    }
  }
}

$title = (string)($boot['content']['product']['title'] ?? '');
if ($title === '') {
  $v0t = $boot['variants'][0] ?? [];
  $pg = pv_row_get($v0t, ['Produktgruppe','produktgruppe','Produktgruppe ','Produkt-Gruppe','Gruppe']) ?? '';
  $pa = pv_row_get($v0t, ['Produktart','produktart','Produktart ','Art']) ?? '';
  $title = trim($pg . ' ' . $pa);
  if ($title === '') $title = (string)($boot['config']['ui']['brand_title'] ?? 'Produktkonfigurator');
}

$currentLabel = $products[$productKey] ?? $productKey;

/* =========================================================
   ✅ Warnhinweis: ZWINGEND pro Produkt-Key aus product_warn.json
   Default: AUS
   ========================================================= */
$warnMapProducts = pv_load_product_warn_map();
$warnEnabled = pv_warn_enabled_for_product($productKey, $warnMapProducts, false);
$hideWarn = !$warnEnabled;

// Infotext nicht per JSON gesteuert (aktuell immer sichtbar)
$hideInfo = false;

?><!doctype html>
<html lang="de">
<head>
  <!-- Google Tag Manager -->
  <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
  new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
  j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
  'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
  })(window,document,'script','dataLayer','GTM-W5WRQLFN');</script>
  <!-- End Google Tag Manager -->

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>REGATIX SHOP | <?= h($title) ?></title>
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
    }

    body{ background: var(--bg) !important; color: var(--text); }

    select, input[type="number"]{
      background: var(--field-bg) !important;
      color: var(--text) !important;
      border-color: var(--border) !important;
    }

    .card{ background: var(--card) !important; box-shadow: var(--shadow); }

    .btn{
      background: linear-gradient(135deg, var(--btn-grad-a), var(--btn-grad-b)) !important;
    }
    .btn:hover{ border-color: rgba(149,191,32,.55) !important; }

    .hero, .thumb, .kv-row{ background: var(--soft) !important; }

    .pill-toggle{
      height:38px; padding:0 14px; border-radius:999px;
      border:1px solid var(--border);
      background:var(--field-bg);
      color:var(--text);
      cursor:pointer;
      display:inline-flex; align-items:center; gap:8px;
      text-decoration:none; white-space:nowrap;
    }
    .pill-select{
      height:38px; padding:0 14px; border-radius:999px;
      border:1px solid var(--border);
      background:var(--field-bg);
      color:var(--text);
      cursor:pointer;
      min-width:220px;
    }
    .price-net{ font-size:13px; color: var(--muted) !important; }
  </style>
</head>

<body data-hide-info="<?= $hideInfo ? '1' : '0' ?>">
  <!-- Google Tag Manager (noscript) -->
  <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-W5WRQLFN"
  height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
  <!-- End Google Tag Manager (noscript) -->

<div id="logo">
  <div class="logostage">
    <div id="logologo">
      <a href="https://regatix.com" title="Zurück zur Homepage">
        <img alt="" src="https://www.regatix.com/media/regatixshoplogo.png" />
      </a>

      <div id="regatixoben">
        <a href="https://regatix.com" title="Zurück zur Homepage">
          <img alt="" src="https://www.regatix.com/media/REGATIX/SHOP_zeigt_nach_links.png" />
        </a>
      </div>
    </div>
  </div>
</div>

  <div class="container">
    <div class="header">
      <div>
        <div class="h-title"><?= h($title) ?></div>
        <div class="small">Produkt: <strong><?= h($currentLabel) ?></strong></div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="set_product">
          <select class="pill-select" name="product" onchange="this.form.submit()" title="Produkt wählen">
            <?php foreach ($products as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= ($k === $productKey ? 'selected' : '') ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <a class="pill-toggle" href="cart.php" title="Warenkorb öffnen">Warenkorb <span id="pv_cart_badge" class="pv-badge" hidden></span></a>
        <button class="pill-toggle" id="pv_layout_toggle" type="button" title="Layout umschalten">Layout: Hell</button>
      </div>
    </div>

    <div id="pv_error" class="small"></div>

    <?php if (empty($boot['variants'])): ?>
      <div class="card">
        <div class="card-b">
          <div class="card-title">Keine Varianten gefunden</div>
          <div class="small" style="margin-top:6px">
            Für dieses Produkt sind noch keine Daten importiert.
            Bitte im Admin den Import ausführen (oder anderes Produkt wählen).
          </div>
        </div>
      </div>
      <div style="height:12px"></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-h">
        <div class="card-title">Konfiguration</div>
      </div>
      <div class="card-b">
        <div class="grid">
          <div>
            <div class="hero" id="pv_hero"></div>
            <div class="thumbs" id="pv_thumbs"></div>

            <div class="hr"></div>

            <div class="card" id="pv_info_card" style="border-radius:var(--radius2)">
              <div class="card-h"><div class="card-title">Infotext</div></div>
              <div class="card-b">
                <div class="info" id="pv_info_html"></div>
              </div>
            </div>
          </div>
          <div>
            <div class="form" id="pv_dims"></div>
            <div class="prices">
              <div class="price-gross" id="pv_price_gross">—</div>
              <div class="price-net" id="pv_price_net"></div>
            </div>
            <div style="margin-top:12px">
              <label>Menge</label>
              <input type="number" min="1" step="1" id="pv_qty" value="1">
            </div>
            <div class="hr"></div>
            <div class="card" style="border-radius:var(--radius2)">
              <div class="card-h"><div class="card-title">Infodaten</div></div>
              <div class="card-b">
                <div class="kv" id="pv_info_fields"></div>
              </div>
            </div>
            <div style="height:12px"></div>

            <?php
              // ✅ PARTIAL INCLUDE: Warnbox strikt nach Produkt-Key-Map
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
    </div>
  </div>

  <!-- ✅ Layout Toggle -->
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
      const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
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

  <script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>

</body>
</html>
