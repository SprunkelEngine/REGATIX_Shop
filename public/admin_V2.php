<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/Admin.php';

use PV\Repository;
use PV\Admin;

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

/**
 * ✅ FIX: Produkt-Keys mit Umlauten erlauben (Unicode)
 * Erlaubt: Buchstaben (Unicode) + Ziffern + _ + -
 */
function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}

function pv_safe_path(string $s): string {
  return preg_replace('~[^A-Za-z0-9_\-]~', '_', $s) ?: 'x';
}

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ===========================
   ✅ Atomic JSON write helper
   =========================== */
function pv_atomic_write_json(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('JSON konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
    if (!@copy($tmp, $path)) {
      @unlink($tmp);
      throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
    }
    @unlink($tmp);
  }
}

/* ===========================
   ✅ Rabattcodes (Admin)
   =========================== */
function pv_discounts_path(): string { return __DIR__ . '/../config/discounts.json'; }

function pv_norm_code(string $code): string {
  $code = trim($code);
  $code = preg_replace('/\s+/', '', $code);
  return strtoupper((string)$code);
}

function pv_load_discounts(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function pv_save_discounts(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('discounts.json konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
    if (!@copy($tmp, $path)) {
      @unlink($tmp);
      throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
    }
    @unlink($tmp);
  }
}

/* ===========================
   ✅ Frontend Produkt-Sichtbarkeit
   =========================== */
function pv_visibility_path(): string { return __DIR__ . '/../config/product_visibility.json'; }

function pv_load_visibility(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function pv_save_visibility(string $path, array $map): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }
  $tmp = $path . '.tmp';
  $json = json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('product_visibility.json konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
    if (!@copy($tmp, $path)) {
      @unlink($tmp);
      throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
    }
    @unlink($tmp);
  }
}

/* ===========================
   ✅ Frontend Warnhinweis (pro Produkt)
   Datei: config/product_warn.json
   Default: Warnhinweis AN
   =========================== */
function pv_warn_path(): string { return __DIR__ . '/../config/product_warn.json'; }

function pv_load_warn(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function pv_save_warn(string $path, array $map): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }
  $tmp = $path . '.tmp';
  $json = json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('product_warn.json konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
    if (!@copy($tmp, $path)) {
      @unlink($tmp);
      throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
    }
    @unlink($tmp);
  }
}

/* ===========================
   ✅ Produkt-spezifische Felder (fields.json)
   =========================== */
function pv_fields_path(string $productKey): string {
  $productKey = pv_key($productKey);
  if ($productKey === '') $productKey = 'fachbodenregal';
  return __DIR__ . '/../data/products/' . $productKey . '/fields.json';
}

function pv_default_fields_template(): array {
  return [
    'overrides' => [
      ['key'=>'Ebenen','label'=>'Ebenen','type'=>'text'],
      ['key'=>'Material','label'=>'Material','type'=>'text'],
      ['key'=>'Nennhöhe mm','label'=>'Nennhöhe mm','type'=>'text'],
      ['key'=>'Nenntiefe mm','label'=>'Nenntiefe mm','type'=>'text'],
      ['key'=>'Nennlänge mm','label'=>'Nennlänge mm','type'=>'text'],
      ['key'=>'Gewicht kg','label'=>'Gewicht kg','type'=>'text'],
      ['key'=>'lichte Breite mm','label'=>'lichte Breite mm','type'=>'text'],
      ['key'=>'Achsmaß mm','label'=>'Achsmaß mm','type'=>'text'],
      ['key'=>'ohne MwSt. €','label'=>'ohne MwSt. €','type'=>'text'],
      ['key'=>'mit MwSt. €','label'=>'mit MwSt. €','type'=>'text'],
      ['key'=>'Verfügbarkeit','label'=>'Verfügbarkeit','type'=>'text'],
    ],
    'free_text' => [],
  ];
}

function pv_sanitize_field_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[\x00-\x1F\x7F]~u', '', $s);
  if (mb_strlen($s, 'UTF-8') > 80) $s = mb_substr($s, 0, 80, 'UTF-8');
  return $s;
}

function pv_sanitize_field_label(string $s): string {
  $s = trim($s);
  $s = preg_replace('~[\x00-\x1F\x7F]~u', '', $s);
  if (mb_strlen($s, 'UTF-8') > 120) $s = mb_substr($s, 0, 120, 'UTF-8');
  return $s;
}

function pv_norm_field_type(string $t): string {
  $t = strtolower(trim($t));
  return in_array($t, ['text','textarea'], true) ? $t : 'text';
}

function pv_load_fields(string $productKey): array {
  $path = pv_fields_path($productKey);
  if (!is_file($path)) return pv_default_fields_template();

  $raw = json_decode((string)file_get_contents($path), true);
  if (!is_array($raw)) return pv_default_fields_template();

  $raw['overrides'] = (isset($raw['overrides']) && is_array($raw['overrides'])) ? $raw['overrides'] : [];
  $raw['free_text'] = (isset($raw['free_text']) && is_array($raw['free_text'])) ? $raw['free_text'] : [];

  foreach (['overrides','free_text'] as $grp) {
    $norm = [];
    foreach ($raw[$grp] as $f) {
      if (!is_array($f)) continue;
      $k = pv_sanitize_field_key((string)($f['key'] ?? ''));
      if ($k === '') continue;
      $label = pv_sanitize_field_label((string)($f['label'] ?? $k));
      $type = pv_norm_field_type((string)($f['type'] ?? 'text'));
      $norm[] = ['key'=>$k, 'label'=>($label !== '' ? $label : $k), 'type'=>$type];
    }
    $raw[$grp] = $norm;
  }

  return $raw;
}

function pv_save_fields(string $productKey, array $fields): void {
  $path = pv_fields_path($productKey);
  $out = [
    'overrides' => (isset($fields['overrides']) && is_array($fields['overrides'])) ? array_values($fields['overrides']) : [],
    'free_text' => (isset($fields['free_text']) && is_array($fields['free_text'])) ? array_values($fields['free_text']) : [],
  ];
  pv_atomic_write_json($path, $out);
}

/* ===========================
   ✅ Auth Token
   =========================== */
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');

if ($expected !== '' && !hash_equals($expected, $token)) {
  http_response_code(403);
  echo "Forbidden (token). Setze security.admin_token in config/config.json.";
  exit;
}

/* ===========================
   ✅ Router: ausgelagerte Tools
   =========================== */
$viewForward = preg_replace('~[^A-Za-z0-9_\-]~', '', (string)($_GET['view'] ?? ''));

// Order/Invoice/PDF ausgelagert
if (in_array($viewForward, ['order', 'invoice', 'invoice_pdf'], true)) {
  $id = (string)($_GET['id'] ?? '');
  header('Location: admin_order.php?token=' . urlencode($token) . '&view=' . urlencode($viewForward) . '&id=' . urlencode($id));
  exit;
}

// Kundenverwaltung ausgelagert
if ($viewForward === 'customers') {
  $user = (string)($_GET['user'] ?? '');
  header('Location: admin_customers.php?token=' . urlencode($token) . ($user !== '' ? '&user=' . urlencode($user) : ''));
  exit;
}

// Flash Message (PRG)
$flash = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$msg = $flash !== '' ? $flash : '';
$err = '';

/* ===========================
   ✅ Produktliste aus /data/products/<key>/… (automatisch)
   - Label aus variants.json (Produktgruppe + Produktart)
   - Eckregal wird NICHT angeboten
   =========================== */
function pv_products(): array {
  $out = [];
  $base = __DIR__ . '/../data/products';
  if (!is_dir($base)) return $out;

  foreach (scandir($base) ?: [] as $d) {
    if ($d === '.' || $d === '..') continue;
    $path = $base . '/' . $d;
    if (!is_dir($path)) continue;

    $key = pv_key($d);
    if ($key === '') continue;

    $label = $key;
    $isEckregal = false;

    $vfile = $path . '/variants.json';
    if (is_file($vfile)) {
      $variants = json_decode((string)file_get_contents($vfile), true);

      if (is_array($variants) && isset($variants['variants']) && is_array($variants['variants'])) {
        $variants = $variants['variants'];
      }

      if (is_array($variants) && !empty($variants[0]) && is_array($variants[0])) {
        $v0 = $variants[0];

        if (!empty($v0['Produktgruppe']) && !empty($v0['Produktart'])) {
          $label = trim((string)$v0['Produktgruppe'] . ' ' . (string)$v0['Produktart']);
        } elseif (!empty($v0['Produktart'])) {
          $label = trim((string)$v0['Produktart']);
        } elseif ($key === 'fachbodenregal') {
          $label = 'Fachbodenregal';
        }

        if (isset($v0['Produktart']) && stripos((string)$v0['Produktart'], 'Eckregal') !== false) {
          $isEckregal = true;
        }
      }
    } else {
      if ($key === 'fachbodenregal') $label = 'Fachbodenregal';
    }

    if ($isEckregal) continue;
    $out[$key] = $label;
  }

  ksort($out);
  return $out;
}

/* ===========================
   ✅ Produktwechsel (ohne p in URL)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_product') {
  $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
  header('Location: admin.php?token=' . urlencode($token));
  exit;
}

/* ===========================
   ✅ Default-Produkt: fachbodenregal
   =========================== */
if (pv_key((string)($_SESSION['pv_product'] ?? '')) === '') {
  $_SESSION['pv_product'] = 'fachbodenregal';
}

$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));

// Repo/Admin im aktiven Produkt-Kontext
$repo  = new Repository($configPath, $productKey);
$cfg   = $repo->getConfig();
$admin = new Admin($configPath, $productKey);

// Produktliste & Sichtbarkeit laden
$products = pv_products();
$visibilityPath = pv_visibility_path();
$visibility = pv_load_visibility($visibilityPath);

// ✅ Warn-Map laden
$warnPath = pv_warn_path();
$warnMap  = pv_load_warn($warnPath);

// Discounts laden
$discountsPath = pv_discounts_path();
$discounts = pv_load_discounts($discountsPath);

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    /* ===========================
       ✅ Frontend-Regalauswahl speichern
       =========================== */
    if ($action === 'save_product_visibility') {
      $posted = (array)($_POST['vis'] ?? []);
      $newMap = [];

      foreach ($products as $k => $_label) {
        $k = pv_key((string)$k);
        if ($k === '') continue;
        $val = isset($posted[$k]) ? (string)$posted[$k] : '0';
        $newMap[$k] = ($val === '1');
      }

      pv_save_visibility($visibilityPath, $newMap);

      $_SESSION['pv_flash'] = 'Frontend-Regalauswahl gespeichert.';
      header('Location: admin.php?token=' . urlencode($token) . '#visibility');
      exit;
    }

    /* ===========================
       ✅ Frontend-Warnhinweis speichern
       =========================== */
    if ($action === 'save_product_warn') {
      $posted = (array)($_POST['warn'] ?? []);
      $newMap = [];

      foreach ($products as $k => $_label) {
        $k = pv_key((string)$k);
        if ($k === '') continue;
        // 1 = Warnhinweis AN, 0 = AUS
        $val = isset($posted[$k]) ? (string)$posted[$k] : '1';
        $newMap[$k] = ($val === '1');
      }

      pv_save_warn($warnPath, $newMap);

      $_SESSION['pv_flash'] = 'Warnhinweis-Einstellungen gespeichert.';
      header('Location: admin.php?token=' . urlencode($token) . '#warn');
      exit;
    }

    /* ===========================
       ✅ Rabattcode: Speichern/Löschen
       =========================== */
    if ($action === 'save_discount') {
      $code = pv_norm_code((string)($_POST['d_code'] ?? ''));
      $type = strtolower(trim((string)($_POST['d_type'] ?? 'percent')));
      $valueRaw = trim((string)($_POST['d_value'] ?? '0'));
      $active = isset($_POST['d_active']) && (string)($_POST['d_active'] ?? '') === '1';
      $expires = trim((string)($_POST['d_expires'] ?? '')); // YYYY-MM-DD optional
      $minGrossRaw = trim((string)($_POST['d_min_gross_eur'] ?? '0'));
      $note = trim((string)($_POST['d_note'] ?? ''));

      if ($code === '') throw new RuntimeException('Rabattcode fehlt.');
      if (!in_array($type, ['percent','fixed'], true)) throw new RuntimeException('Rabatt-Typ ist ungültig.');
      if ($valueRaw === '' || !is_numeric($valueRaw) || (float)$valueRaw <= 0) throw new RuntimeException('Rabatt-Wert ist ungültig.');
      if ($minGrossRaw !== '' && (!is_numeric($minGrossRaw) || (float)$minGrossRaw < 0)) throw new RuntimeException('Mindestbestellwert ist ungültig.');
      if ($expires !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $expires)) throw new RuntimeException('Expires muss YYYY-MM-DD sein (oder leer).');

      $newEntry = [
        'code' => $code,
        'type' => $type,
        'value' => (float)$valueRaw,
        'active' => $active,
        'expires' => $expires,
        'min_gross_eur' => (float)$minGrossRaw,
        'note' => $note,
      ];

      $found = false;
      foreach ($discounts as $i => $d) {
        if (is_array($d) && pv_norm_code((string)($d['code'] ?? '')) === $code) {
          $discounts[$i] = $newEntry;
          $found = true;
          break;
        }
      }
      if (!$found) $discounts[] = $newEntry;

      pv_save_discounts($discountsPath, array_values($discounts));

      $_SESSION['pv_flash'] = 'Rabattcode gespeichert: ' . $code;
      header('Location: admin.php?token=' . urlencode($token) . '#discounts');
      exit;
    }

    if ($action === 'delete_discount') {
      $code = pv_norm_code((string)($_POST['d_code'] ?? ''));
      if ($code === '') throw new RuntimeException('Code fehlt.');

      $discounts = array_values(array_filter($discounts, function($d) use ($code){
        return !(is_array($d) && pv_norm_code((string)($d['code'] ?? '')) === $code);
      }));

      pv_save_discounts($discountsPath, $discounts);

      $_SESSION['pv_flash'] = 'Rabattcode gelöscht: ' . $code;
      header('Location: admin.php?token=' . urlencode($token) . '#discounts');
      exit;
    }

    /* ===========================
       ✅ Neues Produkt anlegen
       =========================== */
    if ($action === 'create_product') {
      $newKeyRaw = (string)($_POST['new_product_key'] ?? '');
      $newKey = pv_key($newKeyRaw);

      if ($newKey === '') {
        throw new RuntimeException('Bitte einen gültigen Produkt-Key eingeben (Buchstaben/Ziffern, _ und -; Umlaute erlaubt).');
      }

      $baseDataDir = __DIR__ . '/../data/products/' . $newKey;
      $baseImgDir  = __DIR__ . '/../images/' . $newKey;

      if (!is_dir($baseDataDir) && !@mkdir($baseDataDir, 0775, true)) {
        throw new RuntimeException('Konnte Produkt-Datenordner nicht anlegen: ' . $baseDataDir);
      }
      if (!is_dir($baseImgDir) && !@mkdir($baseImgDir, 0775, true)) {
        throw new RuntimeException('Konnte Produkt-Bilderordner nicht anlegen: ' . $baseImgDir);
      }

      $contentFile = $baseDataDir . '/content.json';
      if (!is_file($contentFile)) {
        $tpl = [
          'product' => ['title' => '', 'info_html' => ''],
          'articles' => (object)[],
        ];
        file_put_contents(
          $contentFile,
          json_encode($tpl, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)
        );
      }

      $variantsFile = $baseDataDir . '/variants.json';
      if (!is_file($variantsFile)) {
        $tpl = [
          'generated_at' => null,
          'vat_rate' => (float)($cfg['import']['vat_rate'] ?? 0.19),
          'variants' => [],
        ];
        file_put_contents(
          $variantsFile,
          json_encode($tpl, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)
        );
      }

      $fieldsFile = $baseDataDir . '/fields.json';
      if (!is_file($fieldsFile)) {
        file_put_contents(
          $fieldsFile,
          json_encode(pv_default_fields_template(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)
        );
      }

      $_SESSION['pv_product'] = $newKey;
      $_SESSION['pv_flash'] = 'Produkt "' . $newKey . '" wurde angelegt. Jetzt bitte Import ausführen.';
      header('Location: admin.php?token=' . urlencode($token));
      exit;
    }

    /* ===========================
       ✅ Neue Variante manuell anlegen (ohne CSV)
       =========================== */
    if ($action === 'create_variant') {
      $newPk = trim((string)($_POST['new_pk'] ?? ''));

      $pkField = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');
      if ($newPk === '') {
        throw new RuntimeException('Bitte eine ' . $pkField . ' angeben.');
      }

      $jsonPath = $productKey === ''
        ? (__DIR__ . '/../' . (string)($cfg['import']['json_path'] ?? 'data/variants.json'))
        : (__DIR__ . '/../data/products/' . $productKey . '/variants.json');

      $store = [];
      if (is_file($jsonPath)) {
        $store = json_decode((string)file_get_contents($jsonPath), true);
      }
      if (!is_array($store)) $store = [];
      if (!isset($store['variants']) || !is_array($store['variants'])) $store['variants'] = [];
      if (!isset($store['vat_rate'])) $store['vat_rate'] = (float)($cfg['import']['vat_rate'] ?? 0.19);

      foreach ($store['variants'] as $v) {
        if (is_array($v) && isset($v[$pkField]) && (string)$v[$pkField] === $newPk) {
          throw new RuntimeException('Variante existiert bereits: ' . $newPk);
        }
      }

      $keys = (array)($_POST['k'] ?? []);
      $vals = (array)($_POST['v'] ?? []);

      $variant = [];
      $variant[$pkField] = $newPk;

      foreach ($vals as $safe => $valueRaw) {
        $realKey = isset($keys[$safe]) ? (string)$keys[$safe] : '';
        if ($realKey === '') continue;
        $value = trim((string)$valueRaw);
        if ($value === '') continue;
        if ($realKey === $pkField) continue;
        $variant[$realKey] = $value;
      }

      $store['variants'][] = $variant;

      $dir = dirname($jsonPath);
      if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('Konnte Zielordner nicht anlegen: ' . $dir);
      }

      $tmp = $jsonPath . '.tmp';
      $json = json_encode($store, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
      if ($json === false) throw new RuntimeException('JSON konnte nicht erzeugt werden.');
      if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
      if (!@rename($tmp, $jsonPath)) {
        if (!@copy($tmp, $jsonPath)) {
          @unlink($tmp);
          throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $jsonPath);
        }
        @unlink($tmp);
      }

      $_SESSION['pv_flash'] = 'Variante "' . $newPk . '" angelegt.';
      header('Location: admin.php?token=' . urlencode($token) . '&article=' . urlencode($newPk));
      exit;
    }

    /* ===========================
       ✅ Variante duplizieren
       =========================== */
    if ($action === 'duplicate_variant') {
      $srcPk = trim((string)($_POST['source_pk'] ?? ''));
      $newPk = trim((string)($_POST['new_pk'] ?? ''));
      $copyMeta = isset($_POST['copy_meta']) && (string)($_POST['copy_meta'] ?? '') === '1';
      $copyImages = isset($_POST['copy_images']) && (string)($_POST['copy_images'] ?? '') === '1';

      $pkField = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');
      if ($srcPk === '') throw new RuntimeException('Quelle fehlt: Bitte eine Variante auswählen.');
      if ($newPk === '') throw new RuntimeException('Bitte eine neue ' . $pkField . ' angeben.');
      if ($newPk === $srcPk) throw new RuntimeException('Neue ' . $pkField . ' muss sich von der Quelle unterscheiden.');

      $jsonPath = $productKey === ''
        ? (__DIR__ . '/../' . (string)($cfg['import']['json_path'] ?? 'data/variants.json'))
        : (__DIR__ . '/../data/products/' . $productKey . '/variants.json');

      $store = [];
      if (is_file($jsonPath)) $store = json_decode((string)file_get_contents($jsonPath), true);
      if (!is_array($store)) $store = [];
      if (!isset($store['variants']) || !is_array($store['variants'])) $store['variants'] = [];

      foreach ($store['variants'] as $v) {
        if (is_array($v) && isset($v[$pkField]) && (string)$v[$pkField] === $newPk) {
          throw new RuntimeException('Variante existiert bereits: ' . $newPk);
        }
      }

      $srcVariant = null;
      foreach ($store['variants'] as $v) {
        if (is_array($v) && isset($v[$pkField]) && (string)$v[$pkField] === $srcPk) {
          $srcVariant = $v;
          break;
        }
      }
      if (!is_array($srcVariant)) throw new RuntimeException('Quell-Variante nicht gefunden: ' . $srcPk);

      $newVariant = $srcVariant;
      $newVariant[$pkField] = $newPk;
      $store['variants'][] = $newVariant;

      $dir = dirname($jsonPath);
      if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Konnte Zielordner nicht anlegen: ' . $dir);

      $tmp = $jsonPath . '.tmp';
      $json = json_encode($store, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
      if ($json === false) throw new RuntimeException('JSON konnte nicht erzeugt werden.');
      if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
      if (!@rename($tmp, $jsonPath)) {
        if (!@copy($tmp, $jsonPath)) {
          @unlink($tmp);
          throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $jsonPath);
        }
        @unlink($tmp);
      }

      $copied = [];
      if ($copyMeta || $copyImages) {
        $contentPath = $productKey === ''
          ? (__DIR__ . '/../data/content.json')
          : (__DIR__ . '/../data/products/' . $productKey . '/content.json');

        $c = ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]];
        if (is_file($contentPath)) {
          $tmpc = json_decode((string)file_get_contents($contentPath), true);
          if (is_array($tmpc)) $c = $tmpc;
        }
        $c['articles'] = $c['articles'] ?? [];

        $srcEntry = $c['articles'][$srcPk] ?? null;
        if (is_array($srcEntry)) {
          $dstEntry = $c['articles'][$newPk] ?? [];

          if ($copyMeta) {
            if (isset($srcEntry['info_html'])) $dstEntry['info_html'] = $srcEntry['info_html'];
            if (isset($srcEntry['overrides'])) $dstEntry['overrides'] = $srcEntry['overrides'];
            $copied[] = 'Infotext/Overrides';
          }

          if ($copyImages && isset($srcEntry['images']) && is_array($srcEntry['images'])) {
            $srcSafe = pv_safe_path($srcPk);
            $dstSafe = pv_safe_path($newPk);
            $newImages = [];
            foreach ($srcEntry['images'] as $img) {
              $img = (string)$img;
              $base = basename($img);
              if ($productKey === '') $newImages[] = $newPk . '/' . $base;
              else $newImages[] = $productKey . '/' . $dstSafe . '/' . $base;
            }
            $dstEntry['images'] = $newImages;
            $copied[] = 'Bilder';

            $imgBase = __DIR__ . '/../images';
            $srcDir = $productKey === '' ? ($imgBase . '/' . $srcSafe) : ($imgBase . '/' . $productKey . '/' . $srcSafe);
            $dstDir = $productKey === '' ? ($imgBase . '/' . $dstSafe) : ($imgBase . '/' . $productKey . '/' . $dstSafe);

            if (is_dir($srcDir)) {
              if (!is_dir($dstDir)) @mkdir($dstDir, 0775, true);
              foreach (scandir($srcDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                $from = $srcDir . '/' . $f;
                $to   = $dstDir . '/' . $f;
                if (is_file($from) && !is_file($to)) @copy($from, $to);
              }
            }
          }

          $c['articles'][$newPk] = $dstEntry;

          $cdir = dirname($contentPath);
          if (!is_dir($cdir)) @mkdir($cdir, 0775, true);
          $ctmp = $contentPath . '.tmp';
          file_put_contents($ctmp, json_encode($c, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
          @rename($ctmp, $contentPath);
        }
      }

      $_SESSION['pv_flash'] = 'Variante dupliziert: "' . $srcPk . '" → "' . $newPk . '"' . (empty($copied) ? '' : ' (mit ' . implode(', ', $copied) . ')');
      header('Location: admin.php?token=' . urlencode($token) . '&article=' . urlencode($newPk));
      exit;
    }

    /* ===========================
       ✅ Shop E-Mail speichern
       =========================== */
    if ($action === 'save_shop_email') {
      $email = trim((string)($_POST['shop_email'] ?? ''));

      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Bitte eine gültige Shop-E-Mail eingeben.');
      }

      $conf = json_decode((string)file_get_contents($configPath), true) ?: [];
      $conf['shop'] = $conf['shop'] ?? [];

      $conf['shop']['contact_email'] = $email;
      $conf['shop']['email'] = $email;
      $conf['shop']['dev_order_email'] = $email;
      $conf['shop']['dev_order_from'] = $email;

      $tmp = $configPath . '.tmp';
      file_put_contents($tmp, json_encode($conf, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
      @rename($tmp, $configPath);

      $_SESSION['pv_flash'] = 'Shop-E-Mail gespeichert: ' . $email;
      header('Location: admin.php?token=' . urlencode($token) . '#shop');
      exit;
    }

    /* ===========================
       ✅ Felder pro Produkt verwalten (fields.json)
       =========================== */
    if ($action === 'add_field_def') {
      $group = (string)($_POST['fd_group'] ?? 'overrides'); // overrides|free_text
      if (!in_array($group, ['overrides','free_text'], true)) $group = 'overrides';

      $k = pv_sanitize_field_key((string)($_POST['fd_key'] ?? ''));
      $label = pv_sanitize_field_label((string)($_POST['fd_label'] ?? $k));
      $type = pv_norm_field_type((string)($_POST['fd_type'] ?? 'text'));

      if ($k === '') throw new RuntimeException('Feld-Key fehlt.');

      $fields = pv_load_fields($productKey);
      foreach ($fields[$group] as $f) {
        if (is_array($f) && (string)($f['key'] ?? '') === $k) {
          throw new RuntimeException('Feld existiert bereits in ' . $group . ': ' . $k);
        }
      }

      $fields[$group][] = ['key'=>$k, 'label'=>($label !== '' ? $label : $k), 'type'=>$type];
      pv_save_fields($productKey, $fields);

      $_SESSION['pv_flash'] = 'Feld hinzugefügt (' . $group . '): ' . $k;
      header('Location: admin.php?token=' . urlencode($token) . '#fields');
      exit;
    }

    if ($action === 'delete_field_def') {
      $group = (string)($_POST['fd_group'] ?? 'overrides');
      if (!in_array($group, ['overrides','free_text'], true)) $group = 'overrides';

      $k = pv_sanitize_field_key((string)($_POST['fd_key'] ?? ''));
      if ($k === '') throw new RuntimeException('Feld-Key fehlt.');

      $fields = pv_load_fields($productKey);
      $fields[$group] = array_values(array_filter($fields[$group], function($f) use ($k){
        return !(is_array($f) && (string)($f['key'] ?? '') === $k);
      }));

      pv_save_fields($productKey, $fields);

      $_SESSION['pv_flash'] = 'Feld gelöscht (' . $group . '): ' . $k;
      header('Location: admin.php?token=' . urlencode($token) . '#fields');
      exit;
    }

    if ($action === 'reset_field_defs') {
      pv_save_fields($productKey, pv_default_fields_template());

      $_SESSION['pv_flash'] = 'Feld-Definitionen zurückgesetzt (Default).';
      header('Location: admin.php?token=' . urlencode($token) . '#fields');
      exit;
    }

    /* ===========================
       ✅ Bestehende Actions
       =========================== */
    if ($action === 'save_text') {
      $admin->saveText(
        (string)($_POST['scope'] ?? 'product'),
        (string)($_POST['article'] ?? ''),
        (string)($_POST['title'] ?? ''),
        (string)($_POST['info_html'] ?? '')
      );
      $msg = 'Gespeichert.';
    } elseif ($action === 'save_overrides') {
      $admin->saveOverrides(
        (string)($_POST['article'] ?? ''),
        (array)($_POST['ov'] ?? [])
      );
      $msg = 'Overrides gespeichert.';
    } elseif ($action === 'upload_images') {
      $admin->uploadImages((string)($_POST['article'] ?? ''), $_FILES['images'] ?? null);
      $msg = 'Bilder hochgeladen.';
    } elseif ($action === 'delete_image') {
      $admin->deleteImage((string)($_POST['article'] ?? ''), (string)($_POST['img'] ?? ''));
      $msg = 'Bild gelöscht.';
    } elseif ($action === 'delete_all_images') {
      $art = (string)($_POST['article'] ?? '');
      if ($art === '') throw new RuntimeException('Artikel fehlt.');

      $d = $repo->detail($art);
      $imgs = $d['content_article']['images'] ?? [];

      if (!is_array($imgs) || empty($imgs)) {
        $msg = 'Keine Bilder vorhanden.';
      } else {
        foreach ($imgs as $img) $admin->deleteImage($art, (string)$img);
        $msg = 'Alle Bilder gelöscht.';
      }
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

/* ===========================
   ✅ Re-bootstrap (nach PRG)
   =========================== */
$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));
$repo  = new Repository($configPath, $productKey);
$cfg   = $repo->getConfig();
$admin = new Admin($configPath, $productKey);

$boot = $repo->bootstrap();
$variants = $boot['variants'] ?? [];
$pk = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');

$article = (string)($_GET['article'] ?? ($_POST['article'] ?? ''));
if ($article === '' && isset($variants[0][$pk])) $article = (string)$variants[0][$pk];

$detail = $repo->detail($article);

// Produktliste & Sichtbarkeit neu laden
$products = pv_products();
$currentLabel = $products[$productKey] ?? $productKey;

$visibilityPath = pv_visibility_path();
$visibility = pv_load_visibility($visibilityPath);

// ✅ Warn-Map neu laden
$warnPath = pv_warn_path();
$warnMap  = pv_load_warn($warnPath);

// Discounts neu laden
$discountsPath = pv_discounts_path();
$discounts = pv_load_discounts($discountsPath);

// Shop Email für UI
$shopEmail = (string)($cfg['shop']['dev_order_email'] ?? $cfg['shop']['contact_email'] ?? $cfg['shop']['email'] ?? '');

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Produktkonfigurator</title>
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
    select, input[type="text"], input[type="file"], input[type="number"], textarea{
      background: rgba(0,0,0,.03) !important;
      color: var(--text) !important;
    }
    textarea{ border:1px solid var(--border) !important; }
    .card{ background: var(--card) !important; }
    .btn{ background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02)) !important; }
    .btn:hover{ border-color: rgba(149,191,32,.55) !important; }

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
    body.layout-pro select,
    body.layout-pro input[type="text"],
    body.layout-pro input[type="file"],
    body.layout-pro input[type="number"],
    body.layout-pro textarea{
      background: rgba(0,0,0,.25) !important;
      color: var(--text) !important;
    }
    body.layout-pro .card{ background: rgba(255,255,255,.03) !important; }
    body.layout-pro .btn{ background: linear-gradient(135deg, rgba(118,167,255,.18), rgba(0,0,0,.25)) !important; }
    body.layout-pro .btn:hover{ border-color: rgba(118,167,255,.35) !important; }
    body.layout-pro .pill-toggle,
    body.layout-pro .pill-select{
      background: rgba(0,0,0,.18);
      color: var(--text);
    }
    body.layout-pro .pill-toggle:hover,
    body.layout-pro .pill-select:hover{
      border-color: rgba(118,167,255,.35);
    }

    .h-sub, .small{ color: var(--muted) !important; }

    .pv-table{ width:100%; border-collapse:collapse; }
    .pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; }
    .pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
    .pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }
    .pv-row2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
    @media (max-width: 820px){ .pv-row2{ grid-template-columns:1fr; } }

    @media (max-width: 980px){
      .pv-bottom-grid{ grid-template-columns:1fr !important; }
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div>
        <div class="h-title">Admin</div>
        <div class="h-sub">Produkt: <strong><?= h($currentLabel) ?></strong></div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
        <!-- Produkt-Umschalter -->
        <form method="post" style="margin:0">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="set_product">
          <select class="pill-select" name="product" onchange="this.form.submit()">
            <?php foreach ($products as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= ($k === $productKey ? 'selected' : '') ?>>
                <?= h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>

        <!-- Layout Umschalter -->
        <button class="pill-toggle" id="pv_layout_toggle" type="button" title="Layout umschalten">Layout: Dunkel</button>

        <a class="btn" href="index.php">Frontend</a>
        <a class="btn" href="admin_customers.php?token=<?= urlencode($token) ?>">Kundenverwaltung</a>

        <a class="btn" href="https://regatix.shop/public/admin_offers.php?token=<?= urlencode($token) ?>">Angebotstool</a>
        <a class="btn" target="_blank" rel="noopener" href="admin_discounts.php?token=<?= urlencode($token) ?>">Rabattcodes</a>

        <a class="btn" target="_blank" href="admin_links.php?token=<?= urlencode($token) ?>">Produktgruppen-Links</a>
        <a class="btn" target="_blank" href="admin_new_variant.php?token=<?= urlencode($token) ?>">Neue Variante</a>
        <a class="btn" target="_blank" href="admin_fields.php?token=<?= urlencode($token) ?>">Produktfelder</a>
        <a class="btn" target="_blank" href="admin_import.php?token=<?= urlencode($token) ?>">Import</a>
        <a class="btn" target="_blank" href="admin_patch.php?token=<?= urlencode($token) ?>">Preis Änderung</a>
      </div>
    </div>

    <?php if ($msg): ?><div class="card" style="border-radius:14px"><div class="card-b"><?= h($msg) ?></div></div><div style="height:10px"></div><?php endif; ?>
    <?php if ($err): ?><div class="warn"><div class="warn-top"><div class="tri" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/></svg>
    </div><div><h4>Fehler</h4><div class="small"><?= h($err) ?></div></div></div></div><div style="height:10px"></div><?php endif; ?>

    <!-- ✅ Neues Produkt anlegen -->
    <div class="card" style="border-radius:14px; margin-bottom:12px;">
      <div class="card-h">
        <div class="card-title">Neues Produkt anlegen</div>
        <div class="small">Erstellt Ordner + leere JSONs und setzt das Produkt direkt aktiv.</div>
      </div>
      <div class="card-b">
        <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="create_product">
          <div style="min-width:260px">
            <label>Produkt-Key (z.B. fachbodenregal oder sonderposten)</label>
            <input name="new_product_key" placeholder="sonderposten" required value="">
          </div>
          <button class="btn" type="submit">Produkt anlegen</button>
        </form>
        <div class="small" style="margin-top:8px">Hinweis: Key darf <code>Buchstaben/Ziffern _ -</code> enthalten (Umlaute erlaubt).</div>
      </div>
    </div>

    <!-- ✅ Frontend-Regalauswahl -->
    <div class="card" id="visibility" style="border-radius:14px; margin-bottom:12px;">
      <div class="card-h">
        <div class="card-title">Frontend-Regalauswahl (sichtbar/unsichtbar)</div>
        <div class="small">Steuert, welche Produkte im Frontend-Dropdown angezeigt werden. Datei: <code>config/product_visibility.json</code></div>
      </div>
      <div class="card-b">
        <form method="post">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="save_product_visibility">

          <table class="pv-table">
            <thead>
              <tr>
                <th>Produkt-Key</th>
                <th>Label</th>
                <th>Sichtbar</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($products as $k => $label):
                $isVisible = !isset($visibility[$k]) ? true : (bool)$visibility[$k];
              ?>
                <tr>
                  <td><span class="pv-pill"><?= h($k) ?></span></td>
                  <td><?= h($label) ?></td>
                  <td>
                    <input type="hidden" name="vis[<?= h($k) ?>]" value="0">
                    <label style="display:inline-flex; gap:10px; align-items:center; margin:0">
                      <input type="checkbox" name="vis[<?= h($k) ?>]" value="1" <?= $isVisible ? 'checked' : '' ?> style="width:18px; height:18px;">
                      <span class="small" style="font-weight:800"><?= $isVisible ? 'Sichtbar' : 'Ausgeblendet' ?></span>
                    </label>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="height:10px"></div>
          <button class="btn" type="submit">Sichtbarkeit speichern</button>
        </form>
      </div>
    </div>

    <!-- ✅ Frontend-Warnhinweis -->
    <div class="card" id="warn" style="border-radius:14px; margin-bottom:12px;">
      <div class="card-h">
        <div class="card-title">Frontend-Warnhinweis (an/aus)</div>
        <div class="small">Steuert, ob der Warnhinweis im Frontend angezeigt wird. Datei: <code>config/product_warn.json</code></div>
      </div>
      <div class="card-b">
        <form method="post">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="save_product_warn">

          <table class="pv-table">
            <thead>
              <tr>
                <th>Produkt-Key</th>
                <th>Label</th>
                <th>Warnhinweis</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($products as $k => $label):
                // Default: Warnhinweis AN
                $isWarnOn = !isset($warnMap[$k]) ? true : (bool)$warnMap[$k];
              ?>
                <tr>
                  <td><span class="pv-pill"><?= h($k) ?></span></td>
                  <td><?= h($label) ?></td>
                  <td>
                    <input type="hidden" name="warn[<?= h($k) ?>]" value="0">
                    <label style="display:inline-flex; gap:10px; align-items:center; margin:0">
                      <input type="checkbox" name="warn[<?= h($k) ?>]" value="1" <?= $isWarnOn ? 'checked' : '' ?> style="width:18px; height:18px;">
                      <span class="small" style="font-weight:800"><?= $isWarnOn ? 'An' : 'Aus' ?></span>
                    </label>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="height:10px"></div>
          <button class="btn" type="submit">Warnhinweis speichern</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-h">
        <div class="card-title">Variante wählen</div>
        <div class="small"><?= h($pk) ?></div>
      </div>
      <div class="card-b">
        <?php if (!$variants): ?>
          <div class="small">Noch keine Varianten vorhanden. Bitte zuerst Import ausführen.</div>
        <?php else: ?>
       <form method="get" id="pvVariantForm" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
         <input type="hidden" name="token" value="<?= h($token) ?>">

         <div style="min-width:260px">
           <label>Suche</label>
           <input
             id="pvVariantSearch"
             type="text"
             inputmode="search"
             autocomplete="off"
             placeholder="z.B. 12345 oder _Kopie"
             style="height:38px"
           >
           <div class="small" style="margin-top:6px">Tippen zum Filtern · Enter lädt den ersten Treffer</div>
         </div>

         <div style="min-width:260px">
           <label>Artikelnummer</label>
           <select id="pvVariantSelect" name="article" onchange="this.form.submit()">
             <?php foreach ($variants as $v):
               $val = (string)($v[$pk] ?? '');
               if ($val === '') continue;
               $sel = ($val === $article) ? 'selected' : '';
             ?>
               <option value="<?= h($val) ?>" <?= $sel ?>><?= h($val) ?></option>
             <?php endforeach; ?>
           </select>
         </div>

         <noscript><button class="btn" type="submit">Laden</button></noscript>
       </form>

          <div style="height:12px"></div>
          <div class="small">Variante duplizieren:</div>
          <form method="post" autocomplete="off" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:6px">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="duplicate_variant">
            <input type="hidden" name="source_pk" value="<?= h($article) ?>">

            <div style="min-width:260px">
              <label>Neue <?= h($pk) ?></label>
              <input name="new_pk" required placeholder="z.B. <?= h($article) ?>_Kopie">
            </div>

            <label style="display:flex; gap:8px; align-items:center; margin:0">
              <input type="checkbox" name="copy_meta" value="1" checked>
              <span class="small">Infotext/Overrides mitnehmen</span>
            </label>

            <label style="display:flex; gap:8px; align-items:center; margin:0">
              <input type="checkbox" name="copy_images" value="1" checked>
              <span class="small">Bilder mitnehmen</span>
            </label>

            <button class="btn" type="submit">Duplizieren</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div style="height:14px"></div>

    <div class="grid">
      <div class="card">
        <div class="card-h"><div class="card-title">Infotext (global)</div></div>
        <div class="card-b">
          <form method="post">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_text">
            <input type="hidden" name="scope" value="product">
            <label>Titel (optional)</label>
            <input name="title" value="<?= h($boot['content']['product']['title'] ?? '') ?>">
            <div style="height:10px"></div>
            <label>Info HTML</label>
            <textarea name="info_html" style="width:100%; min-height:180px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($boot['content']['product']['info_html'] ?? '') ?></textarea>
            <div style="height:10px"></div>
            <button class="btn" type="submit">Speichern</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><div class="card-title">Infotext (Variante)</div></div>
        <div class="card-b">
          <form method="post">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_text">
            <input type="hidden" name="scope" value="article">
            <input type="hidden" name="article" value="<?= h($article) ?>">
            <label>Info HTML (überschreibt global)</label>
            <textarea name="info_html" style="width:100%; min-height:180px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($detail['content_article']['info_html'] ?? '') ?></textarea>
            <div style="height:10px"></div>
            <button class="btn" type="submit">Speichern</button>
          </form>
        </div>
      </div>
    </div>

    <div style="height:14px"></div>

    <div class="grid">
      <div class="card">
        <div class="card-h"><div class="card-title">Bilder</div></div>
        <div class="card-b">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="upload_images">
            <input type="hidden" name="article" value="<?= h($article) ?>">
            <label>Bilder auswählen (jpg/png/webp)</label>
            <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp" style="color:var(--muted)">
            <div style="height:10px"></div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
              <button class="btn" type="submit">Hochladen</button>
            </div>
          </form>

          <?php $hasImages = !empty($detail['content_article']['images'] ?? []); ?>
          <form method="post" style="margin-top:10px" onsubmit="return confirm('Wirklich ALLE Bilder dieser Variante löschen?')">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="delete_all_images">
            <input type="hidden" name="article" value="<?= h($article) ?>">
            <button class="btn" type="submit" style="height:32px" <?= $hasImages ? '' : 'disabled' ?>>Alle löschen</button>
          </form>

          <div style="height:12px"></div>

          <div class="small">Aktuelle Bilder:</div>
          <div class="thumbs">
            <?php foreach (($detail['content_article']['images'] ?? []) as $img): ?>
              <div>
                <div class="thumb" style="width:110px; height:80px"><img src="../images/<?= h($img) ?>" alt=""></div>
                <form method="post" style="margin-top:6px">
                  <input type="hidden" name="token" value="<?= h($token) ?>">
                  <input type="hidden" name="action" value="delete_image">
                  <input type="hidden" name="article" value="<?= h($article) ?>">
                  <input type="hidden" name="img" value="<?= h($img) ?>">
                  <button class="btn" type="submit" style="height:32px">Löschen</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><div class="card-title">Texte/Felder korrigieren (Overrides)</div></div>
        <div class="card-b">
          <div class="small">
            Overrides werden gespeichert in
            <code><?= $productKey === '' ? 'data/content.json' : ('data/products/' . h($productKey) . '/content.json') ?></code>
          </div>
          <div style="height:10px"></div>

          <form method="post">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="save_overrides">
            <input type="hidden" name="article" value="<?= h($article) ?>">

            <?php
              $fieldsDef2 = pv_load_fields($productKey);
              $ov = $detail['content_article']['overrides'] ?? [];
              $overrideFields = $fieldsDef2['overrides'] ?? [];
              $freeTextFields = $fieldsDef2['free_text'] ?? [];
              if (empty($overrideFields) && empty($freeTextFields)) {
                $fieldsDef2 = pv_default_fields_template();
                $overrideFields = $fieldsDef2['overrides'];
                $freeTextFields = $fieldsDef2['free_text'];
              }
            ?>

            <?php foreach ($overrideFields as $f):
              if (!is_array($f)) continue;
              $k = (string)($f['key'] ?? '');
              if ($k === '') continue;
              $label = (string)($f['label'] ?? $k);
              $type  = strtolower((string)($f['type'] ?? 'text'));
              $cur = $ov[$k] ?? ($detail['article'][$k] ?? '');
            ?>
              <div style="margin-bottom:10px">
                <label><?= h($label) ?></label>
                <?php if ($type === 'textarea'): ?>
                  <textarea name="ov[<?= h($k) ?>]" style="width:100%; min-height:110px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($cur) ?></textarea>
                <?php else: ?>
                  <input name="ov[<?= h($k) ?>]" value="<?= h($cur) ?>">
                <?php endif; ?>
              </div>
            <?php endforeach; ?>

            <?php if (!empty($freeTextFields)): ?>
              <div class="small" style="font-weight:800; margin:14px 0 8px;">Freitextfelder</div>
              <?php foreach ($freeTextFields as $f):
                if (!is_array($f)) continue;
                $k = (string)($f['key'] ?? '');
                if ($k === '') continue;
                $label = (string)($f['label'] ?? $k);
                $type  = strtolower((string)($f['type'] ?? 'textarea'));
                $cur = $ov[$k] ?? '';
              ?>
                <div style="margin-bottom:10px">
                  <label><?= h($label) ?></label>
                  <?php if ($type === 'text'): ?>
                    <input name="ov[<?= h($k) ?>]" value="<?= h($cur) ?>">
                  <?php else: ?>
                    <textarea name="ov[<?= h($k) ?>]" style="width:100%; min-height:140px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($cur) ?></textarea>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

            <button class="btn" type="submit">Overrides speichern</button>
          </form>
        </div>
      </div>
    </div>

    <div style="height:14px"></div>

    <!-- ✅ Shop E-Mail -->
    <div class="card" id="shop" style="border-radius:14px; margin-bottom:12px;">
      <div class="card-h">
        <div class="card-title">Shop-E-Mail</div>
        <div class="small">Empfänger/Absender für Bestellmails (setzt mehrere Fallback-Keys in <code>config.json</code>).</div>
      </div>
      <div class="card-b">
        <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="save_shop_email">
          <div style="min-width:320px">
            <label>E-Mail</label>
            <input name="shop_email" value="<?= h($shopEmail) ?>" placeholder="info@..." required>
          </div>
          <button class="btn" type="submit">Speichern</button>
        </form>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const KEY = 'pv_layout';
      const btn = document.getElementById('pv_layout_toggle');

      function setButtonLabel(){
        const isPro = document.body.classList.contains('layout-pro');
        if(btn) btn.textContent = 'Layout: ' + (isPro ? 'Hell' : 'Dunkel');
      }
      function apply(mode){
        document.body.classList.toggle('layout-pro', mode === 'pro');
        setButtonLabel();
      }
      const saved = localStorage.getItem(KEY);
      apply(saved === 'pro' ? 'pro' : 'light');

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
  (function(){
    const input = document.getElementById('pvVariantSearch');
    const select = document.getElementById('pvVariantSelect');
    const form = document.getElementById('pvVariantForm');
    if (!input || !select || !form) return;

    // Original-Optionen merken (damit wir sauber re-filtern können)
    const original = Array.from(select.options).map(o => ({
      value: o.value,
      text: o.text,
      selected: o.selected
    }));

    function rebuild(filter){
      const q = (filter || '').trim().toLowerCase();

      // aktuelle Auswahl merken
      const current = select.value;

      // Select leeren
      select.innerHTML = '';

      // Neu aufbauen
      let firstValue = '';
      for (const opt of original) {
        if (q !== '' && !opt.text.toLowerCase().includes(q)) continue;

        const o = document.createElement('option');
        o.value = opt.value;
        o.textContent = opt.text;

        if (opt.value === current) o.selected = true;
        select.appendChild(o);

        if (!firstValue) firstValue = opt.value;
      }

      // Wenn aktuelle Auswahl weggefiltert wurde: auf ersten Treffer stellen
      if (select.options.length > 0 && !Array.from(select.options).some(o => o.selected)) {
        select.value = firstValue;
      }
    }

    // Live filtern
    input.addEventListener('input', () => rebuild(input.value));

    // Enter: ersten Treffer laden
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (select.options.length > 0) form.submit();
      }
      // Escape: Suche leeren, alles wieder anzeigen
      if (e.key === 'Escape') {
        input.value = '';
        rebuild('');
      }
    });

    // Optional: beim Start schon mal “reset”
    rebuild('');
  })();
  </script>

</body>
</html>
