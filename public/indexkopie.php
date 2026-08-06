<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repository.php';

function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  return preg_replace('~[^A-Za-z0-9_\-]~', '', $s) ?: '';
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Produkte aus /data/products/<key>/ ... automatisch finden.
 * Default/Legacy (ohne Key) wird als "Eckregal" angezeigt.
 */
function pv_products(): array {
  $out = ['' => 'Eckregal']; // Legacy / Standard
  $base = __DIR__ . '/../data/products';
  if (!is_dir($base)) return $out;

  foreach (scandir($base) ?: [] as $d) {
    if ($d === '.' || $d === '..') continue;
    $path = $base . '/' . $d;
    if (!is_dir($path)) continue;

    $key = pv_key($d);
    if ($key === '') continue;

    // Label = Ordnername (kannst du später noch schöner mappen)
    $out[$key] = $key;
  }
  return $out;
}

// Produktwechsel (ohne p= in URL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_product') {
  $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
  header('Location: index.php');
  exit;
}

$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));

$repo = new PV\Repository(__DIR__ . '/../config/config.json', $productKey);
$boot = $repo->bootstrap();

$title = (string)($boot['content']['product']['title'] ?? '');
if ($title === '') {
  // fallback: derive from first variant
  $v0 = $boot['variants'][0] ?? [];
  $title = trim((string)($v0['Produktgruppe'] ?? '') . ' ' . (string)($v0['Produktart'] ?? ''));
  if ($title === '') $title = (string)($boot['config']['ui']['brand_title'] ?? 'Produktkonfigurator');
}

$products = pv_products();
$currentLabel = ($productKey === '') ? 'Eckregal' : $productKey;

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <link rel="stylesheet" href="assets/styles.css">

  <!-- Theme Override + Umschalter (Default: hell) -->
  <style>
    /* Toggle-Button */
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

    /* Produkt-Pulldown (sexy pill) */
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

    /* ✅ Netto sichtbar, hellgrau */
    .price-net{
      font-size:13px;
      color: rgba(0,0,0,.45) !important; /* helles Layout */
    }
    body.layout-pro .price-net{
      color: rgba(232,240,255,.55) !important; /* dunkles Layout */
    }

    /* Helles Layout: Warntext schwarz */
    .warn-top h4,
    .warn-top .small{
      color:#000 !important;
    }

    /* Warn-Buttons tiefer, damit nichts in den Rahmen ragt */
    .warn-actions{
      margin-top:16px;
      padding-top:10px;
    }

    /* ===== Default = HELL (weiß / schwarz / #95bf20) ===== */
    :root{
      --bg:#ffffff;
      --card:#ffffff;
      --text:#000000;
      --muted:rgba(0,0,0,.65);
      --border:rgba(0,0,0,.14);
      --accent:#95bf20;
      --shadow:0 8px 22px rgba(0,0,0,.08);
    }

    /* Hintergrund ohne Gradient */
    body{ background: var(--bg) !important; }

    /* Eingabefelder hell */
    select, input[type="number"]{
      background: rgba(0,0,0,.03) !important;
      color: var(--text) !important;
    }

    /* Cards wirklich weiß */
    .card{ background: var(--card) !important; }

    /* Buttons neutral + Akzent */
    .btn{
      background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02)) !important;
    }
    .btn:hover{
      border-color: rgba(149,191,32,.55) !important;
    }

    /* Bildbereich heller */
    .hero, .thumb, .kv-row{ background: rgba(0,0,0,.02) !important; }

    /* ===== Pro = DUNKEL (wenn body.layout-pro) ===== */
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

    /* Dunkles Layout: Warntext weiß */
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
  <div class="container">
    <div class="header">
      <div>
        <div class="h-title"><?= h($title) ?></div>
        <div class="small">Produkt: <strong><?= h($currentLabel) ?></strong></div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
        <!-- ✅ Produkt-Pulldown (Session) -->
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="set_product">
          <select class="pill-select" name="product" onchange="this.form.submit()" title="Produkt wählen">
            <?php foreach ($products as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= ($k === $productKey ? 'selected' : '') ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </form>

        <!-- ✅ Warenkorb Button wieder drin -->
        <a class="pill-toggle" href="cart.php" title="Warenkorb öffnen">Warenkorb <span id="pv_cart_badge" class="pv-badge" hidden></span></a>

        <!-- Layout Umschalter -->
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
              <div class="card-h"><div class="card-title">Infotexte</div></div>
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
            </div>

          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Umschalter-Logik (Default: hell) -->
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

  <script src="assets/app.js"></script>
</body>
</html>
