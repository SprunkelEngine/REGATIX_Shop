<?php
declare(strict_types=1);

session_start();

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
  // auch möglich: direktes dict
  if (!empty($arr) && !isset($arr['visible'])) return $arr;
  return [];
}

// Nur Produkte aus data/products laden, Eckregal filtern + Sichtbarkeit beachten
function pv_products(): array {
  $out = [];
  $base = __DIR__ . '/../data/products';
  if (!is_dir($base)) return $out;

  $vis = pv_load_visibility(); // key => bool (true/false)

  foreach (scandir($base) ?: [] as $d) {
    if ($d === '.' || $d === '..') continue;
    $path = $base . '/' . $d;
    if (!is_dir($path)) continue;

    $key = pv_key($d);
    if ($key === '') continue;

    // ✅ Sichtbarkeit: falls im JSON explizit false -> ausblenden
    if (isset($vis[$key]) && !$vis[$key]) {
      continue;
    }

    $label = $key;
    $vfile = $path . '/variants.json';
    $isEckregal = false;

    if (is_file($vfile)) {
      $variantsStore = json_decode(file_get_contents($vfile), true);
      // Varianten-Store kann entweder direkt Array sein oder {"variants":[...]}
      $variants = $variantsStore;
      if (is_array($variantsStore) && isset($variantsStore['variants']) && is_array($variantsStore['variants'])) {
        $variants = $variantsStore['variants'];
      }

      if (!empty($variants[0]['Produktgruppe']) && !empty($variants[0]['Produktart'])) {
        $label = trim($variants[0]['Produktgruppe'] . ' ' . $variants[0]['Produktart']);
      } elseif (!empty($variants[0]['Produktart'])) {
        $label = trim($variants[0]['Produktart']);
      }

      // ✅ Label-Override: "Fachbodenregal Grundregal" -> "Fachbodenregal"
      $labelNorm = pv_lower(preg_replace('~\s+~u', ' ', trim((string)$label)));
      if ($labelNorm === 'fachbodenregal grundregal') {
        $label = 'Fachbodenregal';
      }

      // Eckregal rausfiltern (Produktart)
      if (
        isset($variants[0]['Produktart']) &&
        stripos((string)$variants[0]['Produktart'], 'Eckregal') !== false
      ) {
        $isEckregal = true;
      }
    }

    if (!$isEckregal) {
      $out[$key] = $label;
    }
  }

  return $out;
}

// Produktwechsel (ohne p= in URL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_product') {
  $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
  header('Location: index.php');
  exit;
}

$products = pv_products();

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
if (isset($_GET['product']) && pv_key((string)$_GET['product']) && isset($products[pv_key((string)$_GET['product'])])) {
  $productKey = pv_key((string)$_GET['product']);
  $_SESSION['pv_product'] = $productKey;
} else {
  $productKey = pv_key((string)($_SESSION['pv_product'] ?? $defaultKey));
  if ($productKey === '' || !isset($products[$productKey])) {
    $productKey = (string)$defaultKey;
    $_SESSION['pv_product'] = $productKey;
  }
}

$repo = new PV\Repository(__DIR__ . '/../config/config.json', $productKey);
$boot = $repo->bootstrap();

$title = (string)($boot['content']['product']['title'] ?? '');
if ($title === '') {
  // fallback: derive from first variant
  $v0 = $boot['variants'][0] ?? [];
  $title = trim((string)($v0['Produktgruppe'] ?? '') . ' ' . (string)($v0['Produktart'] ?? ''));
  if ($title === '') $title = (string)($boot['config']['ui']['brand_title'] ?? 'Produktkonfigurator');
}

$currentLabel = $products[$productKey] ?? $productKey;

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>REGATIX SHOP | <?= h($title) ?></title>
  <link rel="stylesheet" href="assets/styles.css">

  <style>
    .pill-toggle{
      height:38px;
      padding:0 14px;
      border-radius:999px;
      border:1px solid var(--border);
      background:rgba(0,0,0,.04);
      color:var(--text);
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap:8px;
      text-decoration:none;
      white-space:nowrap;
    }
    .pill-toggle:hover{ border-color: rgba(149,191,32,.55); }
    .pill-select{
      height:38px;
      padding:0 14px;
      border-radius:999px;
      border:1px solid var(--border);
      background:rgba(0,0,0,.04);
      color:var(--text);
      cursor:pointer;
      min-width:220px;
    }
    .pill-select:hover{ border-color: rgba(149,191,32,.55); }
    .price-net{
      font-size:13px;
      color: rgba(0,0,0,.45) !important;
    }
    body.layout-pro .price-net{
      color: rgba(232,240,255,.55) !important;
    }
    .warn-top h4,
    .warn-top .small{
      color:#000 !important;
    }
    .warn-actions{
      margin-top:16px;
      padding-top:10px;
    }
    :root{
      --bg:#ffffff;
      --card:#ffffff;
      --text:#000000;
      --muted:rgba(0,0,0,.65);
      --border:rgba(0,0,0,.14);
      --accent:#95bf20;
      --shadow:0 8px 22px rgba(0,0,0,.08);
    }
    body{ background: var(--bg) !important; }
    select, input[type="number"]{
      background: rgba(0,0,0,.03) !important;
      color: var(--text) !important;
    }
    .card{ background: var(--card) !important; }
    .btn{
      background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02)) !important;
    }
    .btn:hover{
      border-color: rgba(149,191,32,.55) !important;
    }
    .hero, .thumb, .kv-row{ background: rgba(0,0,0,.02) !important; }
    body.layout-pro{
      --bg:#0b0e14;
      --card:#121827;
      --text:#e8f0ff;
      --muted:#9ab0c7;
      --border:rgba(255,255,255,.10);
      --accent:#76a7ff;
      --shadow:0 10px 30px rgba(0,0,0,.35);
      background:
        radial-gradient(1200px 600px at 30% 0%, rgba(118,167,255,.18), transparent 55%),
        radial-gradient(1200px 600px at 70% 0%, rgba(118,255,214,.08), transparent 55%),
        var(--bg) !important;
    }
    body.layout-pro .warn h4,
    body.layout-pro .warn .small{
      color:#fff !important;
    }
    body.layout-pro select,
    body.layout-pro input[type="number"]{
      background: rgba(0,0,0,.25) !important;
      color: var(--text) !important;
    }
    body.layout-pro .card{
      background: rgba(255,255,255,.03) !important;
    }
    body.layout-pro .btn{
      background: linear-gradient(135deg, rgba(118,167,255,.18), rgba(0,0,0,.25)) !important;
    }
    body.layout-pro .btn:hover{
      border-color: rgba(118,167,255,.35) !important;
    }
    body.layout-pro .hero,
    body.layout-pro .thumb,
    body.layout-pro .kv-row{
      background: rgba(0,0,0,.18) !important;
    }
    body.layout-pro .pill-toggle,
    body.layout-pro .pill-select{
      background: rgba(0,0,0,.18);
      color: var(--text);
    }
    body.layout-pro .pill-toggle:hover,
    body.layout-pro .pill-select:hover{
      border-color: rgba(118,167,255,.35);
    }
  </style>
</head>

<body>
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
        <button class="pill-toggle" id="pv_layout_toggle" type="button" title="Layout umschalten">Layout: Dunkel</button>
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

            <div class="card" style="border-radius:var(--radius2)">
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
            <div class="warn">
              <div class="warn-top">
                <div class="tri" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
                    <path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/>
                    <path d="M12 9v5" stroke="rgba(255,77,77,.95)" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M12 17.8h.01" stroke="rgba(255,77,77,.95)" stroke-width="3.2" stroke-linecap="round"/>
                  </svg>
                </div>
                <div>
                  <h4>Wichtiger Hinweis</h4>
                  <div class="small">Bitte Werte prüfen und den Hinweis bestätigen, bevor du in den Warenkorb legst.</div>
                </div>
              </div>
              <div style="height:10px"></div>
              <div class="kv" id="pv_warn_fields"></div>
              <div class="warn-actions" style="display:flex; gap:10px; flex-wrap:wrap">
                <button class="btn" id="pv_warn_ack" type="button">Warnhinweis bestätigen</button>
                <button class="btn" id="pv_add_to_cart" type="button" disabled>In den Warenkorb</button>
              </div>
              <p><a href="https://www.regatix.com/media/legenderegatix.webp" target="_blank"><img alt="" src="https://www.regatix.com/media/legenderegatix.webp" style="width: 100%;" /></a></p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Anbauregal-Finder-Logik: Zeige passenden Link bei Grundregal -->
    <?php
    function clean($v) { return trim((string)$v); }
    if (
        !empty($boot['variants']) &&
        isset($boot['variants'][0]['Produktart']) &&
        stripos((string)$boot['variants'][0]['Produktart'], 'Grundregal') !== false
    ) {
        $v0 = $boot['variants'][0];
        $merkmale = [
            'Nennhöhe mm'  => $v0['Nennhöhe mm']  ?? null,
            'Nenntiefe mm' => $v0['Nenntiefe mm'] ?? null,
            'Nennlänge mm' => $v0['Nennlänge mm'] ?? null,
            'Ebenen'       => $v0['Ebenen']       ?? null,
        ];
        $base = __DIR__ . '/../data/products';
        $currentKey = $productKey;
        $anbauKey = null;
        foreach (scandir($base) ?: [] as $d) {
            if ($d === '.' || $d === '..' || pv_key($d) === $currentKey) continue;
            $path = $base . '/' . $d . '/variants.json';
            if (!is_file($path)) continue;
            $variantsStore = json_decode(file_get_contents($path), true);
            $variants = $variantsStore;
            if (is_array($variantsStore) && isset($variantsStore['variants']) && is_array($variantsStore['variants'])) {
              $variants = $variantsStore['variants'];
            }
            if (!$variants || !isset($variants[0]['Produktart'])) continue;
            $v = $variants[0];
            if (
                stripos((string)$v['Produktart'], 'Anbauregal') !== false &&
                stripos((string)$v['Produktart'], 'Eckregal') === false &&
                clean($v['Nennhöhe mm'] ?? null)  == clean($merkmale['Nennhöhe mm']) &&
                clean($v['Nenntiefe mm'] ?? null) == clean($merkmale['Nenntiefe mm']) &&
                clean($v['Nennlänge mm'] ?? null) == clean($merkmale['Nennlänge mm']) &&
                clean($v['Ebenen'] ?? null)       == clean($merkmale['Ebenen'])
            ) {
                $anbauKey = pv_key($d);
                break;
            }
        }
        if ($anbauKey) {
            echo '<div style="margin-top:2em; padding:1.2em; background:#ecfeff; border-radius:18px; max-width:600px;">
                <b>Tipp:</b>
                <a href="?product='.h($anbauKey).'"
                   style="font-weight:bold; color:#256d4c; margin-left:0.8em; text-decoration:underline;">
                   Das passende Anbauregal anzeigen
                </a>
            </div>';
        }
    }
    ?>
  </div>

  <script>
    (function(){
      const KEY = 'pv_layout';
      const btn = document.getElementById('pv_layout_toggle');
      function setButtonLabel(){
        const isPro = document.body.classList.contains('layout-pro');
        if(btn){
          btn.textContent = 'Layout: ' + (isPro ? 'Hell' : 'Dunkel');
        }
      }
      function apply(mode){
        document.body.classList.toggle('layout-pro', mode === 'pro');
        setButtonLabel();
      }
      const saved = localStorage.getItem(KEY);
      const mode = (saved === 'pro') ? 'pro' : 'light';
      apply(mode);
      if(btn){
        btn.addEventListener('click', function(){
          const next = document.body.classList.contains('layout-pro') ? 'light' : 'pro';
          localStorage.setItem(KEY, next);
          apply(next);
        });
      }
    })();
  </script>

<script>
    // Wenn Seite aus BFCache kommt: App nochmal initialisieren / neu rendern
    window.addEventListener('pageshow', function (e) {
      if (e.persisted) {
        // Falls app.js eine init-Funktion hat: hier aufrufen
        if (window.PV && typeof window.PV.init === 'function') {
          window.PV.init({ force: true });
        } else {
          // Fallback: harter Reload, wenn du keine Init-Funktion hast
          window.location.reload();
        }
      }
    });
  </script>



<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
  
 <script>
 (function () {
   const qs = new URLSearchParams(location.search);
   if (qs.get('autocart') !== '1') return;
 
   const offer = qs.get('offer') || '';
   const article = qs.get('article') || '';
   const doneKey = 'pv_autocart_done_' + offer + '_' + article;
 
   // Doppeltes Hinzufügen bei Refresh verhindern
   if (sessionStorage.getItem(doneKey) === '1') {
     qs.delete('autocart');
     const clean = location.pathname + (qs.toString() ? '?' + qs.toString() : '') + location.hash;
     history.replaceState({}, '', clean);
     return;
   }
 
   const ackBtn = document.getElementById('pv_warn_ack');
   const addBtn = document.getElementById('pv_add_to_cart');
   if (!addBtn) return;
 
   function cleanUrl() {
     qs.delete('autocart');
     const clean = location.pathname + (qs.toString() ? '?' + qs.toString() : '') + location.hash;
     history.replaceState({}, '', clean);
   }
 
   function gotoCart() {
     const base = location.pathname.replace(/\/[^\/]*$/, '');
     location.href = base + '/cart.php';
   }
 
   let ackClicked = false;
   let addClicked = false;
 
   function tryAck() {
     if (!ackBtn) return;
     if (ackClicked) return;
 
     // Falls der Ack-Button irgendwann disabled wäre, warten
     if (ackBtn.disabled) return;
 
     // Warnhinweis bestätigen (damit Add-Button freigeschaltet wird)
     ackBtn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
     ackBtn.click();
     ackClicked = true;
   }
 
   function tryAdd() {
     if (addClicked) return false;
 
     // erst ack versuchen
     tryAck();
 
     // wenn Add noch disabled ist -> warten
     if (addBtn.disabled) return false;
 
     // Add-to-cart auslösen
     addBtn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
     addBtn.click();
     addClicked = true;
 
     sessionStorage.setItem(doneKey, '1');
     cleanUrl();
 
     // etwas länger warten, damit app.js den Cart speichert
     setTimeout(gotoCart, 800);
     return true;
   }
 
   // Polling: wartet bis App fertig ist + Button enabled
   let tries = 0;
   const iv = setInterval(() => {
     tries++;
     if (tryAdd()) { clearInterval(iv); return; }
     if (tries > 200) clearInterval(iv); // ~20s
   }, 100);
 
   // Beobachten: sobald disabled weggeht, sofort adden
   const mo = new MutationObserver(() => { tryAdd(); });
   mo.observe(addBtn, { attributes: true, attributeFilter: ['disabled', 'class', 'aria-disabled'] });
 
   // Wenn der Ack-Button erst später gerendert wird, nochmal nachfassen
   const mo2 = new MutationObserver(() => {
     const a = document.getElementById('pv_warn_ack');
     if (a && !ackClicked) {
       a.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
       a.click();
       ackClicked = true;
     }
   });
   mo2.observe(document.documentElement, { childList: true, subtree: true });
 
   setTimeout(() => { clearInterval(iv); mo.disconnect(); mo2.disconnect(); }, 20000);
 })();
 </script>


  
  
  <!-- Google tag (gtag.js) --> <script async src="https://www.googletagmanager.com/gtag/js?id=G-9MFEXYKPXF"></script> <script> window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', 'G-9MFEXYKPXF'); </script>

  <div id="footer"><p>REGATIX Betriebseinrichtungen GmbH &bull; Porschestra&szlig;e 9 &bull; 74360 Ilsfeld &bull; Telefon: 07062 - 23 902 - 0 &bull; E-Mail: info@regatix.com<br />
    Montag - Donnerstag: 08:00 - 12:00 Uhr | 13:00 - 17:00 Uhr &bull; Freitag:08:00 - 12:00 Uhr | 13:00 - 16:30 Uhr <br><a href="https://www.regatix.com/pages/start/impressum.php" target="_blank">Impressum</a> | <a href="https://www.regatix.com/pages/start/datenschutz.php" target="_blank">Datenschutz</a> | <a href="https://www.regatix.com/pages/start/versandbedingungen.php" target="_blank">Versandbedingungen</a> | <a href="https://www.regatix.com/pages/start/widerrufsrecht.php" target="_blank">Wideruf</a> | <a href="https://www.regatix.com/pages/start/shop-bedingungen.php" target="_blank">SHOP Bedingungen</a></p>
</div>
</body>
</html>
