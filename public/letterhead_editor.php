<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/src/compat.php';

$configFile = dirname(__DIR__) . '/config/config.json';
$config = is_file($configFile) ? json_decode((string)file_get_contents($configFile), true) : [];
if (!is_array($config)) $config = [];

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');
if ($expected === '') {
  http_response_code(500);
  echo "Admin-Token fehlt in config/config.json (security.admin_token).";
  exit;
}
if (!hash_equals($expected, $token)) {
  http_response_code(403);
  echo "Ungültiger Token.";
  exit;
}

$defaultLetterhead = dirname(__DIR__) . '/data/letterhead/briefbogen.png';
$letterheadPath = (string)($config['shop']['letterhead_image'] ?? $defaultLetterhead);
$layoutFile = dirname(__DIR__) . '/data/letterhead/layout.json';


if (isset($_GET['asset'])) {
  if (!is_file($letterheadPath)) {
    http_response_code(404);
    exit;
  }
  $ext = strtolower(pathinfo($letterheadPath, PATHINFO_EXTENSION));
  $ct = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : ($ext === 'webp' ? 'image/webp' : 'image/png');
  header('Content-Type: ' . $ct);
  header('Cache-Control: no-store');
  readfile($letterheadPath);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = (string)file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    http_response_code(400);
    echo "Ungültiges JSON.";
    exit;
  }
  // minimal sanity
  if (!isset($data['anchors']) || !is_array($data['anchors'])) {
    http_response_code(400);
    echo "anchors fehlen.";
    exit;
  }
  $data['saved_at'] = date('c');
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($json === false) {
    http_response_code(500);
    echo "Konnte JSON nicht encoden.";
    exit;
  }
  $tmp = $layoutFile . '.tmp';
  if (file_put_contents($tmp, $json) === false) {
    http_response_code(500);
    echo "Konnte layout nicht schreiben.";
    exit;
  }
  if (!@rename($tmp, $layoutFile)) {
    @copy($tmp, $layoutFile);
    @unlink($tmp);
  }
  header('Content-Type: application/json; charset=utf-8');
  echo $json;
  exit;
}

$layout = is_file($layoutFile) ? json_decode((string)file_get_contents($layoutFile), true) : null;
if (!is_array($layout)) $layout = ['version'=>1,'units'=>'relative','anchors'=>[]];

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Briefbogen Layout Editor</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f4f5f6;color:#111}
  header{padding:14px 18px;background:#111;color:#fff;display:flex;gap:14px;align-items:center}
  header a{color:#fff}
  main{display:grid;grid-template-columns:360px 1fr;gap:16px;padding:16px}
  .panel{background:#fff;border-radius:14px;padding:14px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
  .panel h2{margin:0 0 10px 0;font-size:16px}
  .hint{font-size:12px;color:#444;line-height:1.4}
  .btn{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:12px;padding:10px 12px;background:#111;color:#fff;cursor:pointer}
  .btn.secondary{background:#e8eaee;color:#111}
  .row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
  .kv{font-size:12px;color:#333;margin:6px 0}
  .stageWrap{overflow:auto;background:#fff;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.08)}
  .stage{position:relative;display:inline-block}
  .stage img{display:block;max-width:none}
  .box{position:absolute;border:2px dashed #95bf20;background:rgba(149,191,32,.10);border-radius:10px;cursor:move;min-width:90px;min-height:40px}
  .box .lbl{position:absolute;top:-22px;left:0;background:#111;color:#fff;font-size:11px;padding:3px 6px;border-radius:8px}
  .box .handle{position:absolute;right:-8px;bottom:-8px;width:16px;height:16px;border-radius:6px;background:#111;cursor:nwse-resize}
  .grid{display:grid;grid-template-columns:1fr;gap:10px}
  .field{display:grid;grid-template-columns:1fr 1fr;gap:8px;align-items:center}
  .field label{font-size:12px;color:#333}
  input[type="text"]{width:100%;padding:8px 10px;border:1px solid #d5d9e1;border-radius:10px}
  code{font-size:12px;background:#f2f3f7;padding:2px 6px;border-radius:8px}
  .ok{color:#0a7b20;font-weight:600}
  .err{color:#b00020;font-weight:600}
</style>
</head>
<body>
<header>
  <strong>Briefbogen Layout Editor</strong>
  <span style="opacity:.7">→ Änderungen speichern in <code>data/letterhead/layout.json</code></span>
  <span style="margin-left:auto"></span>
  <a href="admin.php?token=<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">Zurück zum Admin</a>
</header>

<main>
  <section class="panel">
    <h2>1) Elemente ziehen & skalieren</h2>
    <p class="hint">
      Ziehe die Boxen auf dem Briefbogen an die gewünschte Position. Unten rechts an der Box kannst du sie skalieren.
      Beim Speichern werden relative Koordinaten (unabhängig von der Bildauflösung) geschrieben.
    </p>

    <div class="grid" id="kv"></div>

    <div class="row">
      <button class="btn" id="saveBtn">Speichern</button>
      <button class="btn secondary" id="resetBtn">Auf Default zurück</button>
    </div>

    <p class="hint" style="margin-top:10px">
      Hinweis: Der PDF-Generator nutzt diese Anker in <code>src/Proforma.php</code>:
      <br>• Kunde (Titel + Textblock)
      <br>• Hinweis rechts (Titel + Textblock)
      <br>• Summenkasten
      <br>• Tabellenkopf / Tabellenstart
    </p>

    <div id="status" class="kv"></div>
  </section>

  <section class="stageWrap">
    <div class="stage" id="stage">
      <img id="lh" src="letterhead_editor.php?token=<?php echo htmlspecialchars($token, ENT_QUOTES); ?>&asset=1" alt="Briefbogen">
      <!-- boxes injected by JS -->
    </div>
  </section>
</main>

<script>
const token = <?php echo json_encode($token); ?>;
const initialLayout = <?php echo json_encode($layout, JSON_UNESCAPED_UNICODE); ?>;

const defs = {
  customer_title:  {w:180,h:42,label:"Kunde: Titel"},
  customer_lines:  {w:260,h:140,label:"Kunde: Textblock"},
  note_title:      {w:220,h:42,label:"Hinweis: Titel"},
  note_lines:      {w:260,h:120,label:"Hinweis: Textblock"},
  table_header:    {w:515,h:40,label:"Tabelle: Kopf"},
  table_rows_start:{w:515,h:300,label:"Tabelle: Zeilenbereich"},
  sum_box:         {w:210,h:85,label:"Summen-Kasten"}
};

function clamp(v,min,max){ return Math.max(min, Math.min(max, v)); }

function ensureAnchors(layout){
  const a = layout.anchors || {};
  for (const k of Object.keys(defs)){
    if (!a[k]) {
      a[k] = {x: 0.05, y: 0.1}; // relative, from top-left
    }
  }
  layout.anchors = a;
  layout.units = "relative";
  layout.version = layout.version || 1;
  return layout;
}

let layout = ensureAnchors(JSON.parse(JSON.stringify(initialLayout)));

const stage = document.getElementById('stage');
const img = document.getElementById('lh');
const kv = document.getElementById('kv');
const statusEl = document.getElementById('status');

function makeBox(key){
  const el = document.createElement('div');
  el.className = 'box';
  el.dataset.key = key;

  const lbl = document.createElement('div');
  lbl.className = 'lbl';
  lbl.textContent = defs[key].label;
  el.appendChild(lbl);

  const handle = document.createElement('div');
  handle.className = 'handle';
  el.appendChild(handle);

  stage.appendChild(el);

  // drag
  let dragging=false, resizing=false;
  let sx=0, sy=0, startLeft=0, startTop=0, startW=0, startH=0;

  function posToPx(){
    const W = img.naturalWidth || img.width;
    const H = img.naturalHeight || img.height;
    const ax = layout.anchors[key].x;
    const ay = layout.anchors[key].y;
    const wRel = layout.anchors[key].w || (defs[key].w / W);
    const hRel = layout.anchors[key].h || (defs[key].h / H);
    return {
      left: ax * W,
      top:  ay * H,
      width:  clamp(wRel * W, 60, W),
      height: clamp(hRel * H, 30, H),
      W,H
    };
  }

  function pxToPos(left, top, width, height){
    const W = img.naturalWidth || img.width;
    const H = img.naturalHeight || img.height;
    layout.anchors[key].x = clamp(left / W, 0, 1);
    layout.anchors[key].y = clamp(top / H, 0, 1);
    layout.anchors[key].w = clamp(width / W, 0.01, 1);
    layout.anchors[key].h = clamp(height / H, 0.01, 1);
  }

  function render(){
    const p = posToPx();
    el.style.left = p.left + 'px';
    el.style.top = p.top + 'px';
    el.style.width = p.width + 'px';
    el.style.height = p.height + 'px';
  }

  function onDown(e){
    if (e.target === handle){
      resizing = true;
    } else {
      dragging = true;
    }
    sx = e.clientX; sy = e.clientY;
    const rect = el.getBoundingClientRect();
    const stageRect = stage.getBoundingClientRect();
    startLeft = rect.left - stageRect.left;
    startTop  = rect.top - stageRect.top;
    startW = rect.width;
    startH = rect.height;
    e.preventDefault();
  }
  function onMove(e){
    if (!dragging && !resizing) return;
    const dx = e.clientX - sx;
    const dy = e.clientY - sy;
    let left = startLeft, top = startTop, w = startW, h = startH;

    if (dragging){
      left = startLeft + dx;
      top  = startTop + dy;
    } else if (resizing){
      w = startW + dx;
      h = startH + dy;
    }
    const W = img.naturalWidth || img.width;
    const H = img.naturalHeight || img.height;

    left = clamp(left, 0, W-20);
    top  = clamp(top,  0, H-20);
    w    = clamp(w, 60, W-left);
    h    = clamp(h, 30, H-top);

    pxToPos(left, top, w, h);
    render();
    refreshKV();
  }
  function onUp(){
    dragging=false; resizing=false;
  }

  el.addEventListener('mousedown', onDown);
  window.addEventListener('mousemove', onMove);
  window.addEventListener('mouseup', onUp);

  // touch
  el.addEventListener('touchstart', (e)=>onDown(e.touches[0]));
  window.addEventListener('touchmove', (e)=>{ if(e.touches[0]) onMove(e.touches[0]); }, {passive:false});
  window.addEventListener('touchend', onUp);

  // init size if absent
  if (!layout.anchors[key].w) layout.anchors[key].w = defs[key].w / (img.naturalWidth || img.width || 1000);
  if (!layout.anchors[key].h) layout.anchors[key].h = defs[key].h / (img.naturalHeight || img.height || 1400);

  el._render = render;
  return el;
}

const boxes = {};

function refreshKV(){
  kv.innerHTML = '';
  const W = img.naturalWidth || img.width;
  const H = img.naturalHeight || img.height;

  for (const k of Object.keys(defs)){
    const a = layout.anchors[k];
    const left = Math.round(a.x * W);
    const top  = Math.round(a.y * H);
    const w    = Math.round((a.w||0) * W);
    const h    = Math.round((a.h||0) * H);

    const div = document.createElement('div');
    div.className = 'kv';
    div.innerHTML = `<strong>${defs[k].label}</strong><br>
      px: <code>${left}, ${top}</code> · size: <code>${w}×${h}</code><br>
      rel: <code>${a.x.toFixed(4)}, ${a.y.toFixed(4)}</code>`;
    kv.appendChild(div);
  }
}

function renderAll(){
  for (const k of Object.keys(defs)){
    if (!boxes[k]) boxes[k] = makeBox(k);
    boxes[k]._render();
  }
  refreshKV();
}

img.addEventListener('load', ()=>{
  // Ensure the stage has the same size as image (natural)
  stage.style.width = img.naturalWidth + 'px';
  stage.style.height = img.naturalHeight + 'px';
  renderAll();
});

document.getElementById('saveBtn').addEventListener('click', async ()=>{
  statusEl.textContent = '';
  try{
    const res = await fetch(location.pathname + '?token=' + encodeURIComponent(token), {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(layout)
    });
    const txt = await res.text();
    if(!res.ok) throw new Error(txt || ('HTTP ' + res.status));
    statusEl.innerHTML = `<span class="ok">Gespeichert.</span>`;
  }catch(e){
    statusEl.innerHTML = `<span class="err">Fehler:</span> ` + (e && e.message ? e.message : e);
  }
});

document.getElementById('resetBtn').addEventListener('click', ()=>{
  layout = ensureAnchors({version:1,units:'relative',anchors:{}});
  renderAll();
  statusEl.textContent = 'Default gesetzt (nicht gespeichert).';
});
</script>
</body>
</html>
