<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_bootstrap.php';

/**
 * HINWEIS:
 * - Dieser Code setzt voraus, dass admin_bootstrap.php bereits liefert:
 *   - $token, $cfg, $configPath, $productKey, $msg
 *   - helper: pv_key(), h(), pv_fields_path(), pv_load_fields(), pv_default_fields_template()
 *
 * - NEU: Frachtkosten sind komplett JSON-basiert:
 *   /data/products/<productKey>/shipping.json
 *   Varianten können optional "shipping" haben:
 *     shipping.mode = inherit|preset|custom|free
 *     shipping.preset_key
 *     shipping.cost
 */

/* ===========================================================
   ✅ Fallback: atomic JSON writer (falls pv_atomic_write_json nicht existiert)
   =========================================================== */
if (!function_exists('pv_atomic_write_json')) {
  function pv_atomic_write_json(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
      throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
    }
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('JSON konnte nicht erzeugt werden: ' . $path);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
    if (!@rename($tmp, $path)) {
      if (!@copy($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path); }
      @unlink($tmp);
    }
  }
}

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

// ✅ Varianten-Tool ausgelagert
if ($viewForward === 'variant_tool') {
  $articleQ = (string)($_GET['article'] ?? '');
  header('Location: admin_variant_tool.php?token=' . urlencode($token) . ($articleQ !== '' ? '&article=' . urlencode($articleQ) : ''));
  exit;
}

/* ===========================
   ✅ Sichtbarkeit + Warnhinweis (Admin)
   =========================== */
function pv_visibility_path(): string { return __DIR__ . '/../config/product_visibility.json'; }
function pv_warn_path(): string { return __DIR__ . '/../config/product_warn.json'; }

function pv_load_visibility(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function pv_save_visibility(string $path, array $map): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  $tmp = $path . '.tmp';
  $json = json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('product_visibility.json konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
    if (!@copy($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path); }
    @unlink($tmp);
  }
}

function pv_load_warn(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function pv_save_warn(string $path, array $map): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  $tmp = $path . '.tmp';
  $json = json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('product_warn.json konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
    if (!@copy($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path); }
    @unlink($tmp);
  }
}

/* ===========================
   ✅ NEU: Produkt-Label Overrides (Umbenennen)
   =========================== */
function pv_labels_path(): string { return __DIR__ . '/../config/product_labels.json'; }

function pv_load_labels(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function pv_save_labels(string $path, array $map): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  $tmp = $path . '.tmp';
  $json = json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('product_labels.json konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
    if (!@copy($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path); }
    @unlink($tmp);
  }
}

/* ===========================
   ✅ NEU: Rekursiv Ordner löschen
   =========================== */
function pv_rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $items = scandir($dir);
  if (!$items) return;

  foreach ($items as $it) {
    if ($it === '.' || $it === '..') continue;
    $p = $dir . DIRECTORY_SEPARATOR . $it;
    if (is_link($p) || is_file($p)) {
      @unlink($p);
    } elseif (is_dir($p)) {
      pv_rrmdir($p);
    }
  }
  @rmdir($dir);
}

/* ===========================
   ✅ Felder pro Produkt (save helpers)
   =========================== */
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

function pv_save_fields(string $productKey, array $fields): void {
  $path = pv_fields_path($productKey);
  $out = [
    'overrides' => (isset($fields['overrides']) && is_array($fields['overrides'])) ? array_values($fields['overrides']) : [],
    'free_text' => (isset($fields['free_text']) && is_array($fields['free_text'])) ? array_values($fields['free_text']) : [],
  ];
  pv_atomic_write_json($path, $out);
}

/* ===========================
   ✅ NEU: Shipping (Fracht) pro Produkt
   Datei: /data/products/<key>/shipping.json
   =========================== */
function pv_shipping_path(string $productKey): string {
  $k = pv_key($productKey);
  return __DIR__ . '/../data/products/' . $k . '/shipping.json';
}

function pv_default_shipping_template(): array {
  return [
    'enabled' => true,
    'presets' => [
      ['key' => 'standard', 'label' => 'Standard', 'cost' => 0.0],
    ],
    'default' => ['mode' => 'preset', 'preset_key' => 'standard', 'cost' => 0.0],
  ];
}

function pv_load_shipping(string $productKey): array {
  $path = pv_shipping_path($productKey);
  if (!is_file($path)) return pv_default_shipping_template();

  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  if (!is_array($arr)) return pv_default_shipping_template();

  $tpl = pv_default_shipping_template();

  $arr['enabled'] = isset($arr['enabled']) ? (bool)$arr['enabled'] : $tpl['enabled'];
  $arr['presets'] = (isset($arr['presets']) && is_array($arr['presets'])) ? array_values($arr['presets']) : $tpl['presets'];
  $arr['default'] = (isset($arr['default']) && is_array($arr['default'])) ? $arr['default'] : $tpl['default'];

  $arr['default']['mode'] = in_array(($arr['default']['mode'] ?? ''), ['preset','custom'], true) ? (string)$arr['default']['mode'] : 'preset';
  $arr['default']['preset_key'] = (string)($arr['default']['preset_key'] ?? 'standard');
  $arr['default']['cost'] = (float)($arr['default']['cost'] ?? 0.0);

  // Minimal: Presets normalisieren
  $norm = [];
  foreach ($arr['presets'] as $p) {
    if (!is_array($p)) continue;
    $k = pv_sanitize_ship_key((string)($p['key'] ?? ''));
    if ($k === '') continue;
    $label = pv_sanitize_ship_label((string)($p['label'] ?? $k));
    if ($label === '') $label = $k;
    $cost = (float)($p['cost'] ?? 0.0);
    $norm[] = ['key'=>$k, 'label'=>$label, 'cost'=>round($cost, 2)];
  }
  if (!$norm) $norm = $tpl['presets'];
  $arr['presets'] = $norm;

  return $arr;
}

function pv_sanitize_ship_key(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('~[^a-z0-9_\-]~', '', $s);
  if (strlen($s) > 40) $s = substr($s, 0, 40);
  return $s;
}

function pv_sanitize_ship_label(string $s): string {
  $s = trim($s);
  $s = preg_replace('~[\x00-\x1F\x7F]~u', '', $s);
  if (mb_strlen($s, 'UTF-8') > 80) $s = mb_substr($s, 0, 80, 'UTF-8');
  return $s;
}

function pv_parse_money(string $s): float {
  $s = trim($s);
  $s = str_replace([' ', "\t"], '', $s);
  $s = str_replace(',', '.', $s);
  if ($s === '' || !preg_match('~^-?\d+(\.\d+)?$~', $s)) return 0.0;
  return round((float)$s, 2);
}

function pv_save_shipping(string $productKey, array $ship): void {
  $path = pv_shipping_path($productKey);
  pv_atomic_write_json($path, $ship);
}

/* ===========================
   ✅ Produktliste & Maps laden
   =========================== */
$visibilityPath = pv_visibility_path();
$warnPath       = pv_warn_path();
$labelsPath     = pv_labels_path();

$visibility = pv_load_visibility($visibilityPath);
$warnMap    = pv_load_warn($warnPath);
$labelOverrides = pv_load_labels($labelsPath);

$products = pv_products();

// Label-Overrides anwenden (nur Anzeige)
foreach ($products as $k => $lbl) {
  if (isset($labelOverrides[$k]) && is_string($labelOverrides[$k]) && trim($labelOverrides[$k]) !== '') {
    $products[$k] = trim($labelOverrides[$k]);
  }
}

$currentLabel = $products[$productKey] ?? $productKey;

// ✅ NEU: Shipping laden
$shippingCfg = pv_load_shipping($productKey);
$shippingPresets = [];
foreach (($shippingCfg['presets'] ?? []) as $p) {
  if (!is_array($p)) continue;
  $pk = (string)($p['key'] ?? '');
  if ($pk === '') continue;
  $shippingPresets[$pk] = [
    'label' => (string)($p['label'] ?? $pk),
    'cost'  => (float)($p['cost'] ?? 0.0),
  ];
}

/* ===========================
   ✅ POST Actions (nur "admin.php" Bereich)
   =========================== */
$err = $err ?? '';

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
       ✅ NEU: Produkt umbenennen (nur Anzeigename)
       =========================== */
    if ($action === 'save_product_label') {
      $k = pv_key((string)($_POST['product_key'] ?? ''));
      if ($k === '' || !isset($products[$k])) {
        throw new RuntimeException('Ungültiges Produkt.');
      }

      $newLabel = pv_sanitize_field_label((string)($_POST['product_label'] ?? ''));
      $labels = pv_load_labels($labelsPath);

      if ($newLabel === '') {
        unset($labels[$k]);
        $_SESSION['pv_flash'] = 'Anzeigename zurückgesetzt: ' . $k;
      } else {
        $labels[$k] = $newLabel;
        $_SESSION['pv_flash'] = 'Anzeigename gespeichert: ' . $newLabel;
      }

      pv_save_labels($labelsPath, $labels);
      header('Location: admin.php?token=' . urlencode($token) . '#manage_products');
      exit;
    }

    /* ===========================
       ✅ NEU: Produkt löschen (Daten + Bilder + Config-Aufräumen)
       =========================== */
    if ($action === 'delete_product') {
      $k = pv_key((string)($_POST['product_key'] ?? ''));
      if ($k === '' || !isset($products[$k])) {
        throw new RuntimeException('Ungültiges Produkt.');
      }

      $dataDir = __DIR__ . '/../data/products/' . $k;
      $imgDir  = __DIR__ . '/../images/' . $k;

      pv_rrmdir($dataDir);
      pv_rrmdir($imgDir);

      // Maps aufräumen
      $vis = pv_load_visibility($visibilityPath);
      unset($vis[$k]);
      pv_save_visibility($visibilityPath, $vis);

      $wm = pv_load_warn($warnPath);
      unset($wm[$k]);
      pv_save_warn($warnPath, $wm);

      $labels = pv_load_labels($labelsPath);
      unset($labels[$k]);
      pv_save_labels($labelsPath, $labels);

      // Falls gerade aktiv: Produkt-Session entfernen
      if (($productKey ?? '') === $k) {
        unset($_SESSION['pv_product']);
      }

      $_SESSION['pv_flash'] = 'Produkt gelöscht: ' . $k;
      header('Location: admin.php?token=' . urlencode($token) . '#manage_products');
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

      // ✅ NEU: shipping.json optional initial anlegen (erst beim Speichern nötig, aber nice)
      $shipFile = $baseDataDir . '/shipping.json';
      if (!is_file($shipFile)) {
        pv_atomic_write_json($shipFile, pv_default_shipping_template());
      }

      $_SESSION['pv_product'] = $newKey;
      $_SESSION['pv_flash'] = 'Produkt "' . $newKey . '" wurde angelegt. Jetzt bitte Import ausführen.';
      header('Location: admin.php?token=' . urlencode($token));
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

      // config.json direkt schreiben (atomic via temp)
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
       ✅ Felder pro Produkt verwalten
       =========================== */
    if ($action === 'add_field_def') {
      $group = (string)($_POST['fd_group'] ?? 'overrides'); // overrides|free_text
      if (!in_array($group, ['overrides','free_text'], true)) $group = 'overrides';

      $k = pv_sanitize_field_key((string)($_POST['fd_key'] ?? ''));
      $label = pv_sanitize_field_label((string)($_POST['fd_label'] ?? $k));
      $type = pv_norm_field_type((string)($_POST['fd_type'] ?? 'text'));

      if ($k === '') throw new RuntimeException('Feld-Key fehlt.');

      $fields = pv_load_fields($productKey);
      foreach (($fields[$group] ?? []) as $f) {
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
      $fields[$group] = array_values(array_filter(($fields[$group] ?? []), function($f) use ($k){
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
       ✅ NEU: Fracht - Grundeinstellung speichern
       =========================== */
    if ($action === 'save_shipping_settings') {
      $ship = pv_load_shipping($productKey);

      $enabled = ((string)($_POST['ship_enabled'] ?? '0') === '1');
      $defMode = (string)($_POST['ship_default_mode'] ?? 'preset');
      $defMode = in_array($defMode, ['preset','custom'], true) ? $defMode : 'preset';

      $defPreset = pv_sanitize_ship_key((string)($_POST['ship_default_preset'] ?? 'standard'));
      $defCost   = pv_parse_money((string)($_POST['ship_default_cost'] ?? '0'));

      $ship['enabled'] = $enabled;
      $ship['default'] = [
        'mode' => $defMode,
        'preset_key' => $defPreset,
        'cost' => $defCost,
      ];

      // Wenn preset gewählt, aber key existiert nicht -> fallback auf erstes preset
      if ($defMode === 'preset') {
        $ok = false;
        foreach (($ship['presets'] ?? []) as $p) {
          if (is_array($p) && (string)($p['key'] ?? '') === $defPreset) { $ok = true; break; }
        }
        if (!$ok) {
          $first = $ship['presets'][0]['key'] ?? 'standard';
          $ship['default']['preset_key'] = (string)$first;
        }
      }

      pv_save_shipping($productKey, $ship);

      $_SESSION['pv_flash'] = 'Fracht-Einstellungen gespeichert.';
      header('Location: admin.php?token=' . urlencode($token) . '#shipping');
      exit;
    }

    /* ===========================
       ✅ NEU: Fracht - Preset hinzufügen
       =========================== */
    if ($action === 'add_shipping_preset') {
      $ship = pv_load_shipping($productKey);

      $k = pv_sanitize_ship_key((string)($_POST['ship_preset_key'] ?? ''));
      $label = pv_sanitize_ship_label((string)($_POST['ship_preset_label'] ?? ''));
      $cost = pv_parse_money((string)($_POST['ship_preset_cost'] ?? '0'));

      if ($k === '') throw new RuntimeException('Preset-Key fehlt (nur a-z 0-9 _ -).');
      if ($label === '') $label = $k;

      foreach (($ship['presets'] ?? []) as $p) {
        if (is_array($p) && (string)($p['key'] ?? '') === $k) {
          throw new RuntimeException('Preset existiert bereits: ' . $k);
        }
      }

      $ship['presets'][] = ['key' => $k, 'label' => $label, 'cost' => $cost];

      pv_save_shipping($productKey, $ship);

      $_SESSION['pv_flash'] = 'Fracht-Preset hinzugefügt: ' . $k;
      header('Location: admin.php?token=' . urlencode($token) . '#shipping');
      exit;
    }

    /* ===========================
       ✅ NEU: Fracht - Preset löschen
       =========================== */
    if ($action === 'delete_shipping_preset') {
      $ship = pv_load_shipping($productKey);
      $k = pv_sanitize_ship_key((string)($_POST['ship_preset_key'] ?? ''));

      if ($k === '') throw new RuntimeException('Preset-Key fehlt.');

      $ship['presets'] = array_values(array_filter(($ship['presets'] ?? []), function($p) use ($k){
        return !(is_array($p) && (string)($p['key'] ?? '') === $k);
      }));

      // Falls Default auf gelöschtem Preset stand -> auf erstes Preset umbiegen
      if (($ship['default']['mode'] ?? '') === 'preset' && (string)($ship['default']['preset_key'] ?? '') === $k) {
        $first = $ship['presets'][0]['key'] ?? 'standard';
        $ship['default']['preset_key'] = (string)$first;
      }

      pv_save_shipping($productKey, $ship);

      $_SESSION['pv_flash'] = 'Fracht-Preset gelöscht: ' . $k;
      header('Location: admin.php?token=' . urlencode($token) . '#shipping');
      exit;
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

/* ===========================
   ✅ Daten für UI (Shop Email)
   =========================== */
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

    /* ===========================================================
       ✅ NEU: schöner Header + quadratische Tool-Kacheln
       =========================================================== */
    .pv-topbar{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:16px;
      flex-wrap:wrap;
      margin-bottom:12px;
    }
    .pv-top-left{ min-width:240px; }
    .pv-top-right{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      justify-content:flex-end;
    }
    .pv-tools{
      width:100%;
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:10px;
    }
    .tool-btn{
      width:132px;
      height:92px;
      display:flex;
      flex-direction:column;
      justify-content:center;
      align-items:flex-start;
      gap:6px;
      padding:12px;
      border-radius:14px;
      border:1px solid var(--border);
      box-shadow: var(--shadow);
      background: linear-gradient(135deg, rgba(149,191,32,.14), rgba(0,0,0,.02));
      color: var(--text);
      text-decoration:none;
      transition: transform .08s ease, border-color .12s ease, background .12s ease;
    }
    .tool-btn:hover{
      transform: translateY(-1px);
      border-color: rgba(149,191,32,.55);
    }
    body.layout-pro .tool-btn{
      background: linear-gradient(135deg, rgba(118,167,255,.16), rgba(0,0,0,.25));
    }
    body.layout-pro .tool-btn:hover{
      border-color: rgba(118,167,255,.35);
    }
    .tool-title{ font-weight:900; font-size:13px; line-height:1.1; }
    .tool-sub{ font-size:12px; color: var(--muted); line-height:1.15; }

    @media (max-width: 640px){
      .tool-btn{ width: calc(50% - 5px); height: 86px; }
    }
    @media (max-width: 420px){
      .tool-btn{ width: 100%; height: 82px; }
    }

    body div.container div.header.pv-topbar div.pv-tools a.tool-btn{
      width: 19%;
    }
    body div.container div.header.pv-topbar div.pv-tools a.tool-btn:hover{
      width: 20%;
      background-color: rgba(0, 128, 0, 0.18);
    }
  </style>
</head>
<body>
  <div class="container">

    <!-- ✅ Header/Tools -->
    <div class="header pv-topbar">
      <div class="pv-top-left">
        <div class="h-title">Admin</div>
        <div class="h-sub">Produkt: <strong><?= h($currentLabel) ?></strong></div>
      </div>

      <div class="pv-top-right">
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
      </div>

      <!-- Tool-Kacheln -->
      <div class="pv-tools">
        <a class="tool-btn" target="_blank" rel="noopener" href="index.php">
          <div class="tool-title">Frontend</div>
          <div class="tool-sub">Öffnen</div>
        </a>

        <a class="tool-btn" rel="noopener" href="admin_customers.php?token=<?= urlencode($token) ?>">
          <div class="tool-title">Kunden</div>
          <div class="tool-sub">Verwaltung</div>
        </a>

        <a class="tool-btn" rel="noopener" href="https://regatix.shop/public/admin_offers.php?token=<?= urlencode($token) ?>">
          <div class="tool-title">Angebote</div>
          <div class="tool-sub">Tool</div>
        </a>

        <a class="tool-btn" rel="noopener" href="admin_discounts.php?token=<?= urlencode($token) ?>">
          <div class="tool-title">Rabatte</div>
          <div class="tool-sub">Codes</div>
        </a>

        <a class="tool-btn" rel="noopener" href="admin_links.php?token=<?= urlencode($token) ?>">
          <div class="tool-title">Links</div>
          <div class="tool-sub">Produktgruppen</div>
        </a>

        <a class="tool-btn" rel="noopener" href="admin_new_variant.php?token=<?= urlencode($token) ?>">
          <div class="tool-title">Variante</div>
          <div class="tool-sub">Neu anlegen</div>
        </a>

        <a class="tool-btn" rel="noopener" href="admin.php?token=<?= urlencode($token) ?>&view=variant_tool">
          <div class="tool-title">Varianten</div>
          <div class="tool-sub">Bearbeiten</div>
        </a>

        <a class="tool-btn" rel="noopener" href="admin_fields.php?token=<?= urlencode($token) ?>">
          <div class="tool-title">Felder</div>
          <div class="tool-sub">Produktfelder</div>
        </a>

        <a class="tool-btn" rel="noopener" href="admin_import.php?token=<?= urlencode($token) ?>">
          <div class="tool-title">Import</div>
          <div class="tool-sub">CSV/JSON</div>
        </a>

        <a class="tool-btn" rel="noopener" href="admin_patch.php?token=<?= urlencode($token) ?>">
          <div class="tool-title">Preis</div>
          <div class="tool-sub">Änderung</div>
        </a>
      </div>
    </div>

    <?php if (!empty($msg)): ?>
      <div class="card" style="border-radius:14px"><div class="card-b"><?= h($msg) ?></div></div>
      <div style="height:10px"></div>
    <?php endif; ?>

    <?php if (!empty($err)): ?>
      <div class="warn">
        <div class="warn-top">
          <div class="tri" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
              <path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/>
            </svg>
          </div>
          <div>
            <h4>Fehler</h4>
            <div class="small"><?= h((string)$err) ?></div>
          </div>
        </div>
      </div>
      <div style="height:10px"></div>
    <?php endif; ?>

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

    <!-- ✅ Produkte verwalten (Umbenennen/Löschen) -->
    <div class="card" id="manage_products" style="border-radius:14px; margin-bottom:12px;">
      <div class="card-h">
        <div class="card-title">Produkte verwalten</div>
        <div class="small">Anzeigename ändern (Dropdown/Listen) oder Produkt komplett löschen (Daten + Bilder).</div>
      </div>
      <div class="card-b">
        <table class="pv-table">
          <thead>
            <tr>
              <th>Produkt-Key</th>
              <th>Aktuelles Label</th>
              <th>Anzeigename (Override)</th>
              <th>Aktion</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $k => $label): ?>
              <tr>
                <td><span class="pv-pill"><?= h($k) ?></span></td>
                <td><?= h($label) ?></td>
                <td>
                  <form method="post" style="display:flex; gap:8px; align-items:center; margin:0; flex-wrap:wrap">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="save_product_label">
                    <input type="hidden" name="product_key" value="<?= h($k) ?>">
                    <input
                      type="text"
                      name="product_label"
                      placeholder="leer = zurücksetzen"
                      value="<?= h((string)($labelOverrides[$k] ?? '')) ?>"
                      style="min-width:260px"
                    >
                    <button class="btn" type="submit">Speichern</button>
                  </form>
                </td>
                <td>
                  <form method="post" style="margin:0"
                        onsubmit="return confirm('Produkt wirklich löschen?\n\nKey: <?= h($k) ?>\n\nEs werden /data/products/<?= h($k) ?>/ und /images/<?= h($k) ?>/ gelöscht.');">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="product_key" value="<?= h($k) ?>">
                    <button class="btn" type="submit" style="border-color:rgba(255,77,77,.65)">Löschen</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div class="small" style="margin-top:10px">
          Tipp: Wenn du beim Umbenennen das Feld leer lässt, wird das Override entfernt und wieder das Label aus <code>variants.json</code> genutzt.
        </div>
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

    <!-- ✅ NEU: Frachtkosten -->
    <div class="card" id="shipping" style="border-radius:14px; margin-bottom:12px;">
      <div class="card-h">
        <div class="card-title">Frachtkosten</div>
        <div class="small">Pro Produkt aktivierbar + Frachtgruppen (Presets). Varianten können inherit/preset/custom/free nutzen.</div>
      </div>
      <div class="card-b">

        <!-- Grundeinstellung -->
        <form method="post" style="display:grid; gap:10px; max-width:840px;">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="save_shipping_settings">

          <label style="display:inline-flex; gap:10px; align-items:center; margin:0">
            <input type="hidden" name="ship_enabled" value="0">
            <input type="checkbox" name="ship_enabled" value="1" <?= !empty($shippingCfg['enabled']) ? 'checked' : '' ?> style="width:18px; height:18px;">
            <span style="font-weight:900">Frachtkosten für dieses Produkt aktiv</span>
          </label>

          <div class="pv-row2">
            <div>
              <label>Standard-Modus</label>
              <select name="ship_default_mode">
                <?php $dm = (string)($shippingCfg['default']['mode'] ?? 'preset'); ?>
                <option value="preset" <?= $dm === 'preset' ? 'selected' : '' ?>>Preset (Frachtgruppe)</option>
                <option value="custom" <?= $dm === 'custom' ? 'selected' : '' ?>>Fester Betrag</option>
              </select>
              <div class="small">Gilt, wenn eine Variante auf <code>inherit</code> steht.</div>
            </div>

            <div>
              <label>Standard-Preset (wenn Preset-Modus)</label>
              <?php $dp = (string)($shippingCfg['default']['preset_key'] ?? 'standard'); ?>
              <select name="ship_default_preset">
                <?php foreach ($shippingPresets as $pk => $pinfo): ?>
                  <option value="<?= h($pk) ?>" <?= $pk === $dp ? 'selected' : '' ?>>
                    <?= h($pinfo['label']) ?> (<?= number_format((float)$pinfo['cost'], 2, ',', '.') ?> €)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div style="max-width:320px">
            <label>Standard-Betrag (wenn fester Betrag)</label>
            <input type="text" name="ship_default_cost" value="<?= h((string)($shippingCfg['default']['cost'] ?? 0)) ?>" placeholder="z.B. 99,00">
          </div>

          <div>
            <button class="btn" type="submit">Fracht-Einstellungen speichern</button>
          </div>
        </form>

        <div style="height:14px"></div>

        <!-- Presets -->
        <div class="card" style="border-radius:12px;">
          <div class="card-h">
            <div class="card-title">Frachtgruppen (Presets)</div>
            <div class="small">Diese Presets können Varianten direkt zugewiesen bekommen.</div>
          </div>
          <div class="card-b">

            <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px;">
              <input type="hidden" name="token" value="<?= h($token) ?>">
              <input type="hidden" name="action" value="add_shipping_preset">
              <div style="min-width:180px">
                <label>Key</label>
                <input name="ship_preset_key" placeholder="spedition_klein" required>
              </div>
              <div style="min-width:240px">
                <label>Label</label>
                <input name="ship_preset_label" placeholder="Spedition (klein)">
              </div>
              <div style="min-width:140px">
                <label>Kosten (€)</label>
                <input name="ship_preset_cost" placeholder="69,00">
              </div>
              <button class="btn" type="submit">Preset hinzufügen</button>
            </form>

            <table class="pv-table">
              <thead>
                <tr>
                  <th>Key</th>
                  <th>Label</th>
                  <th>Kosten</th>
                  <th>Aktion</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (($shippingCfg['presets'] ?? []) as $p):
                  if (!is_array($p)) continue;
                  $pk = (string)($p['key'] ?? '');
                  if ($pk === '') continue;
                ?>
                  <tr>
                    <td><span class="pv-pill"><?= h($pk) ?></span></td>
                    <td><?= h((string)($p['label'] ?? $pk)) ?></td>
                    <td><?= number_format((float)($p['cost'] ?? 0.0), 2, ',', '.') ?> €</td>
                    <td>
                      <form method="post" style="margin:0" onsubmit="return confirm('Preset wirklich löschen?\n\n<?= h($pk) ?>');">
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <input type="hidden" name="action" value="delete_shipping_preset">
                        <input type="hidden" name="ship_preset_key" value="<?= h($pk) ?>">
                        <button class="btn" type="submit" style="border-color:rgba(255,77,77,.65)">Löschen</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <div class="small" style="margin-top:10px">
              Varianten: setze <code>shipping.mode</code> auf <code>inherit</code>, <code>preset</code>, <code>custom</code> oder <code>free</code>.
            </div>

          </div>
        </div>

      </div>
    </div>

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
</body>
</html>