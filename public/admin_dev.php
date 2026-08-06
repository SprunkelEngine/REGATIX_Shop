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
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =========================================================
   ✅ Local file helper (same host URL => local read/write)
   ========================================================= */
function pv_normalize_host(string $h): string {
  $h = strtolower(trim($h));
  if (str_starts_with($h, 'www.')) $h = substr($h, 4);
  return $h;
}

function pv_is_same_host(string $urlHost): bool {
  $currentHost = (string)($_SERVER['HTTP_HOST'] ?? '');
  $ch = pv_normalize_host($currentHost);
  $th = pv_normalize_host($urlHost);
  if ($ch === '' || $th === '') return false;
  if ($ch === $th) return true;
  if (str_ends_with($ch, '.' . $th) || str_ends_with($th, '.' . $ch)) return true;
  return false;
}

/**
 * Gibt lokale Datei innerhalb DOCUMENT_ROOT zurück, wenn URL auf denselben Host zeigt.
 * Sicherheit: realpath muss innerhalb realpath(DOCUMENT_ROOT) liegen.
 */
function pv_local_path_from_url(string $url): ?string {
  $parts = parse_url($url);
  if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) return null;

  if (!pv_is_same_host((string)$parts['host'])) return null;

  $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
  if ($docRoot === '') return null;

  $docRootReal = realpath($docRoot);
  if (!$docRootReal) return null;

  $candidate = realpath(rtrim($docRoot, '/\\') . '/' . ltrim((string)$parts['path'], '/\\'));
  if (!$candidate) return null;

  if (!str_starts_with($candidate, $docRootReal)) return null;
  return $candidate;
}

/* ===========================
   ✅ Remote JSON Helper (Kundenverwaltung)
   - robust: lokal (same host) -> file_get_contents -> cURL
   - Fehlerdetails + HTTP Codes
   =========================== */
function pv_fetch_json_url(string $url, int $timeoutSeconds = 8): array {
  $url = trim($url);
  if ($url === '') return ['_error' => 'Leere URL.'];

  $parts = parse_url($url);
  if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
    return ['_error' => 'Ungültige URL.'];
  }
  $scheme = strtolower((string)$parts['scheme']);
  if (!in_array($scheme, ['https','http'], true)) {
    return ['_error' => 'Nur http/https erlaubt.'];
  }

  // ✅ 0) Wenn gleiche Domain: lokal lesen (um 403 via WAF/ACL zu umgehen)
  $local = pv_local_path_from_url($url);
  if ($local && is_file($local) && is_readable($local)) {
    $raw = (string)file_get_contents($local);
    $arr = json_decode($raw, true);
    if (is_array($arr)) return $arr;
    return ['_error' => 'JSON konnte nicht geparst werden (lokale Datei).', '_hint' => 'Prüfe: ' . ($parts['path'] ?? '')];
  }

  // 1) Versuch: file_get_contents (wenn allow_url_fopen aktiv)
  $raw = null;
  $allowUrlFopen = (string)ini_get('allow_url_fopen');
  $allowUrlFopenBool = ($allowUrlFopen === '1' || strtolower($allowUrlFopen) === 'on' || strtolower($allowUrlFopen) === 'true');

  if ($allowUrlFopenBool) {
    $ctx = stream_context_create([
      'http' => [
        'method'  => 'GET',
        'timeout' => $timeoutSeconds,
        'header'  => "Accept: application/json\r\nUser-Agent: PV-Admin/1.0\r\n",
      ],
      'ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
      ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw !== false && $raw !== null && $raw !== '') {
      $arr = json_decode($raw, true);
      if (is_array($arr)) return $arr;
      return ['_error' => 'JSON konnte nicht geparst werden (file_get_contents).'];
    }
  }

  // 2) Fallback: cURL
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
      CURLOPT_TIMEOUT        => $timeoutSeconds,
      CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'User-Agent: PV-Admin/1.0',
      ],
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body !== false && $body !== null && $body !== '') {
      $arr = json_decode($body, true);
      if (is_array($arr)) return $arr;
      return ['_error' => 'JSON konnte nicht geparst werden (cURL).', '_http_code' => $httpCode];
    }

    return [
      '_error' => 'HTTP Abruf fehlgeschlagen (cURL): ' . ($curlErr !== '' ? $curlErr : 'keine Details'),
      '_http_code' => $httpCode,
      '_hint' => ($httpCode === 403)
        ? 'HTTP 403 (Forbidden): Zugriff blockiert (WAF/ACL). Bei gleicher Domain wird lokal gelesen – prüfe Host-Alias / DOCUMENT_ROOT.'
        : 'Wenn SSL/CA Problem: Server CA-Bundle prüfen oder https testen.',
    ];
  }

  return [
    '_error' => 'Abruf fehlgeschlagen. allow_url_fopen ist aus und cURL ist nicht verfügbar.',
    '_hint'  => 'PHP: allow_url_fopen aktivieren oder cURL Extension installieren.',
  ];
}

/* =========================================================
   ✅ USERS: Laden/Speichern (lokal, same host)
   ========================================================= */
function pv_users_url(): string {
  return 'https://regatix.shop/data/users.json';
}
function pv_users_local_path(): ?string {
  return pv_local_path_from_url(pv_users_url());
}

/** users.json: Objekt keyed by email ODER Liste ODER wrapper. Wir speichern wieder als Objekt keyed by email. */
function pv_load_users_any(): array {
  $raw = pv_fetch_json_url(pv_users_url());
  if (!is_array($raw) || isset($raw['_error'])) return [];

  if (isset($raw['users']) && is_array($raw['users'])) $raw = $raw['users'];
  elseif (isset($raw['data']) && is_array($raw['data'])) $raw = $raw['data'];

  if (is_array($raw) && array_is_list($raw)) {
    $out = [];
    foreach ($raw as $u) {
      if (!is_array($u)) continue;
      $email = strtolower(trim((string)($u['email'] ?? '')));
      if ($email === '') continue;
      $out[$email] = $u;
    }
    return $out;
  }

  if (is_array($raw) && !array_is_list($raw)) {
    $allValuesAreArrays = true;
    foreach ($raw as $k => $v) {
      if (!is_array($v)) { $allValuesAreArrays = false; break; }
    }
    if ($allValuesAreArrays) {
      $out = [];
      foreach ($raw as $k => $v) {
        if (!is_array($v)) continue;
        $kStr = strtolower(trim((string)$k));
        $email = strtolower(trim((string)($v['email'] ?? $kStr)));
        if ($email === '') continue;
        if (empty($v['email'])) $v['email'] = $email;
        $out[$email] = $v;
      }
      return $out;
    }
  }

  return [];
}

function pv_atomic_write_json(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
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

function pv_save_users_object(array $usersByEmail): void {
  $path = pv_users_local_path();
  if (!$path) {
    throw new RuntimeException('users.json ist nicht lokal beschreibbar (Host/DOCUMENT_ROOT passt nicht).');
  }
  $out = [];
  foreach ($usersByEmail as $email => $u) {
    if (!is_array($u)) continue;
    $em = strtolower(trim((string)$email));
    if ($em === '') $em = strtolower(trim((string)($u['email'] ?? '')));
    if ($em === '') continue;
    $u['email'] = $u['email'] ?? $em;
    $out[$em] = $u;
  }
  ksort($out);
  pv_atomic_write_json($path, $out);
}

/* =========================================================
   ✅ Orders-Collector (orders.json kann leer bleiben)
   Sammelt Bestellungen lokal aus /data/orders*
   ========================================================= */
function pv_try_decode_json_file(string $path): ?array {
  if (!is_file($path) || !is_readable($path)) return null;
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : null;
}

function pv_extract_orders_from_any(array $raw): array {
  if (isset($raw['orders']) && is_array($raw['orders'])) $raw = $raw['orders'];
  if (isset($raw['data']) && is_array($raw['data'])) $raw = $raw['data'];

  $orders = [];

  if (is_array($raw) && array_is_list($raw)) {
    foreach ($raw as $o) if (is_array($o)) $orders[] = $o;
    return $orders;
  }

  if (is_array($raw) && !array_is_list($raw)) {
    $allValuesAreArrays = true;
    foreach ($raw as $k => $v) { if (!is_array($v)) { $allValuesAreArrays = false; break; } }
    if ($allValuesAreArrays) {
      foreach ($raw as $k => $v) {
        if (!is_array($v)) continue;
        if (empty($v['id']) && empty($v['order_id'])) $v['id'] = (string)$k;
        $orders[] = $v;
      }
      return $orders;
    }
  }

  return [];
}

function pv_order_buyer_email(array $o): string {
  $cands = [
    $o['email'] ?? null,
    $o['customer_email'] ?? null,
    $o['user_email'] ?? null,
    $o['billing_email'] ?? null,
  ];
  foreach ($cands as $c) {
    $e = strtolower(trim((string)$c));
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
  }
  $nested = [
    $o['customer']['email'] ?? null,
    $o['user']['email'] ?? null,
    $o['billing']['email'] ?? null,
    $o['address']['email'] ?? null,
  ];
  foreach ($nested as $c) {
    $e = strtolower(trim((string)$c));
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
  }
  return '';
}

function pv_collect_orders_local(): array {
  $orders = [];

  // 1) /data/orders.json
  $candidates = [
    __DIR__ . '/../data/orders.json',
    __DIR__ . '/../data/order.json',
  ];
  foreach ($candidates as $p) {
    $raw = pv_try_decode_json_file($p);
    if (is_array($raw)) $orders = array_merge($orders, pv_extract_orders_from_any($raw));
  }

  // 2) /data/orders/*.json und /data/orders/*/*.json
  $dir = __DIR__ . '/../data/orders';
  if (is_dir($dir)) {
    foreach (glob($dir . '/*.json') ?: [] as $f) {
      $raw = pv_try_decode_json_file($f);
      if (is_array($raw)) $orders = array_merge($orders, pv_extract_orders_from_any($raw));
    }
    foreach (glob($dir . '/*/*.json') ?: [] as $f) {
      $raw = pv_try_decode_json_file($f);
      if (is_array($raw)) $orders = array_merge($orders, pv_extract_orders_from_any($raw));
    }
  }

  $idx = [];
  foreach ($orders as $o) {
    if (!is_array($o)) continue;
    $id = (string)($o['id'] ?? $o['order_id'] ?? '');
    if ($id === '') $id = sha1(json_encode($o, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '');
    $idx[$id] = $o;
  }
  return array_values($idx);
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
   ✅ Produkt-spezifische Felder (fields.json)
   - pro Produkt definierbar: Overrides + Freitext (textarea)
   =========================== */
function pv_fields_path(string $productKey): string {
  $productKey = pv_key($productKey);
  if ($productKey === '') $productKey = 'fachbodenregal';
  return __DIR__ . '/../data/products/' . $productKey . '/fields.json';
}

function pv_default_fields_template(): array {
  // Default = bisherige feste Keys
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
    'free_text' => [
      // Für Sonderposten & Co. später im Admin anlegen (textarea etc.)
    ],
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
if ($expected !== '' && $token !== $expected) {
  http_response_code(403);
  echo "Forbidden (token). Setze security.admin_token in config/config.json.";
  exit;
}

/* ===========================
   ✅ Simple Router: Kundenverwaltung (view=customers)
   =========================== */
$view = pv_key((string)($_GET['view'] ?? ''));

/* ✅ NEU: Order/Invoice/PDF in admin_order.php auslagern */
$viewForward = preg_replace('~[^A-Za-z0-9_\-]~', '', (string)($_GET['view'] ?? ''));
if (in_array($viewForward, ['order', 'invoice', 'invoice_pdf'], true)) {
  $id = (string)($_GET['id'] ?? '');
  header('Location: admin_order.php?token=' . urlencode($token) . '&view=' . urlencode($viewForward) . '&id=' . urlencode($id));
  exit;
}

// Flash Message (PRG)
$flash = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$msg = $flash !== '' ? $flash : '';
$err = '';

/* =========================================================
   ✅ ACTION: Passwort-Reset-Link erzeugen (Admin -> Kunde)
   - schreibt verify_token_hash + verify_token_expires in users.json
   - Link: /reset_password.php?email=...&token=...
   ========================================================= */
function pv_make_reset_token(): string {
  return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}
function pv_hash_token(string $token): string {
  return hash('sha256', $token);
}
function pv_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function pv_reset_link(string $email, string $token): string {
  return pv_base_url() . '/reset_password.php?email=' . rawurlencode($email) . '&token=' . rawurlencode($token);
}

/* ✅ NEU: Helpers für Datum/Summe in Kunden-Bestellübersicht */
function pv_money_fmt(float $v): string {
  return number_format($v, 2, ',', '.') . ' €';
}
function pv_order_date_label(array $o): string {
  $d = (string)($o['created_at_local'] ?? '');
  if ($d !== '') return $d;

  $d2 = (string)($o['date'] ?? $o['created_at'] ?? $o['ordered_at'] ?? '');
  if ($d2 === '') return '-';

  $ts = strtotime($d2);
  return $ts ? date('d.m.Y H:i', $ts) : $d2;
}
function pv_order_total_gross(array $o): ?float {
  if (isset($o['totals']) && is_array($o['totals'])) {
    if (isset($o['totals']['gross_after']) && is_numeric($o['totals']['gross_after'])) return (float)$o['totals']['gross_after'];
    if (isset($o['totals']['gross_before']) && is_numeric($o['totals']['gross_before'])) return (float)$o['totals']['gross_before'];
  }
  foreach (['total_gross','total','sum','amount'] as $k) {
    if (isset($o[$k]) && is_numeric($o[$k])) return (float)$o[$k];
  }
  return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_password_reset') {
  try {
    $email = strtolower(trim((string)($_POST['reset_email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Bitte eine gültige E-Mail wählen.');
    }

    $users = pv_load_users_any();
    if (!isset($users[$email]) || !is_array($users[$email])) {
      throw new RuntimeException('User nicht gefunden: ' . $email);
    }

    $tokenPlain = pv_make_reset_token();
    $tokenHash  = pv_hash_token($tokenPlain);
    $expires    = time() + 3600 * 24; // 24h

    $users[$email]['verify_token_hash'] = $tokenHash;
    $users[$email]['verify_token_expires'] = $expires;
    $users[$email]['updated_at'] = date('c');

    pv_save_users_object($users);

    $link = pv_reset_link($email, $tokenPlain);

    $_SESSION['pv_flash'] = 'Passwort-Reset-Link erzeugt (24h gültig): ' . $link;
    header('Location: admin.php?token=' . urlencode($token) . '&view=customers&user=' . urlencode($email));
    exit;
  } catch (Throwable $e) {
    $_SESSION['pv_flash'] = 'Fehler: ' . $e->getMessage();
    header('Location: admin.php?token=' . urlencode($token) . '&view=customers');
    exit;
  }
}

if ($view === 'customers') {
  $usersUrl  = pv_users_url();
  $ordersUrl = 'https://regatix.shop/data/orders.json';

  $usersRaw = pv_fetch_json_url($usersUrl);

  if (isset($usersRaw['_error'])) {
    $err = 'users.json: ' . (string)$usersRaw['_error']
         . (isset($usersRaw['_http_code']) ? (' (HTTP ' . (int)$usersRaw['_http_code'] . ')') : '');
    if (isset($usersRaw['_hint'])) $err .= ' — ' . (string)$usersRaw['_hint'];
  }

  // ✅ Nutzer-Liste robust extrahieren (inkl. Objekt keyed by email)
  $users = [];

  if (isset($usersRaw['users']) && is_array($usersRaw['users'])) {
    $users = $usersRaw['users'];
  } elseif (isset($usersRaw['data']) && is_array($usersRaw['data'])) {
    $users = $usersRaw['data'];
  } elseif (is_array($usersRaw) && array_is_list($usersRaw)) {
    $users = $usersRaw;
  } elseif (is_array($usersRaw) && !array_is_list($usersRaw)) {
    $allValuesAreArrays = true;
    foreach ($usersRaw as $k => $v) {
      if (!is_array($v)) { $allValuesAreArrays = false; break; }
    }
    if ($allValuesAreArrays) {
      $tmp = [];
      foreach ($usersRaw as $k => $v) {
        if (!is_array($v)) continue;
        $kStr = (string)$k;

        if (empty($v['email']) && filter_var($kStr, FILTER_VALIDATE_EMAIL)) $v['email'] = $kStr;
        if (empty($v['id'])) $v['id'] = (string)($v['email'] ?? $kStr);

        if (!isset($v['address']) || !is_array($v['address'])) {
          $v['address'] = [
            'street'  => (string)($v['street'] ?? ''),
            'zip'     => (string)($v['zip'] ?? ''),
            'city'    => (string)($v['city'] ?? ''),
            'country' => (string)($v['country'] ?? ''),
          ];
        }

        $tmp[] = $v;
      }
      $users = $tmp;
    }
  }

  // ✅ Orders lokal sammeln, da remote orders.json leer ist
  $allOrders = pv_collect_orders_local();

  $ordersByEmail = [];
  foreach ($allOrders as $o) {
    if (!is_array($o)) continue;
    $em = pv_order_buyer_email($o);
    if ($em === '') continue;
    $ordersByEmail[$em][] = $o;
  }

  // User Auswahl
  $userParam = trim((string)($_GET['user'] ?? ''));
  $selectedUser = null;

  if ($userParam !== '') {
    foreach ($users as $u) {
      if (!is_array($u)) continue;
      $uid   = (string)($u['id'] ?? $u['uid'] ?? '');
      $email = (string)($u['email'] ?? $u['mail'] ?? '');
      if ($uid !== '' && $uid === $userParam) { $selectedUser = $u; break; }
      if ($email !== '' && $email === $userParam) { $selectedUser = $u; break; }
    }
  }

  $userOrders = [];
  $selEmail = '';
  if (is_array($selectedUser)) {
    $selEmail = strtolower(trim((string)($selectedUser['email'] ?? '')));
    if ($selEmail !== '' && isset($ordersByEmail[$selEmail])) $userOrders = $ordersByEmail[$selEmail];
  }

  ?><!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin · Kundenverwaltung</title>
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
      }
      body{ background: var(--bg) !important; }
      .card{ background: var(--card) !important; }
      .h-sub, .small{ color: var(--muted) !important; }
      .btn{ background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02)) !important; }
      .btn:hover{ border-color: rgba(149,191,32,.55) !important; }
      input[type="text"]{ background: rgba(0,0,0,.03) !important; color: var(--text) !important; }

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
      body.layout-pro .card{ background: rgba(255,255,255,.03) !important; }
      body.layout-pro input[type="text"]{ background: rgba(0,0,0,.25) !important; color: var(--text) !important; }
      body.layout-pro .btn{ background: linear-gradient(135deg, rgba(118,167,255,.18), rgba(0,0,0,.25)) !important; }
      body.layout-pro .btn:hover{ border-color: rgba(118,167,255,.35) !important; }

      .pill-toggle{
        height:38px; padding:0 14px; border-radius:999px;
        border:1px solid var(--border);
        background:rgba(0,0,0,.04);
        color:var(--text);
        cursor:pointer;
        display:inline-flex; align-items:center; gap:8px;
      }
      .pill-toggle:hover{ border-color: rgba(149,191,32,.55); }
      body.layout-pro .pill-toggle{
        background: rgba(0,0,0,.18);
        color: var(--text);
      }
      body.layout-pro .pill-toggle:hover{ border-color: rgba(118,167,255,.35); }

      .pv-table{ width:100%; border-collapse:collapse; }
      .pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; vertical-align:top; }
      .pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
      .pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }
      .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
      .muted{ color: var(--muted); font-size:12px; }
    </style>
  </head>
  <body>
    <div class="container">
      <div class="header">
        <div>
          <div class="h-title">Kundenverwaltung</div>
          <div class="h-sub">
            Datenquelle: <strong><?= h($usersUrl) ?></strong>
            <span class="small">· Orders: lokale Suche in <code>/data/orders*</code> (remote <code>orders.json</code> ist leer)</span>
          </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
          <button class="pill-toggle" id="pv_layout_toggle" type="button" title="Layout umschalten">Layout: Dunkel</button>
          <a class="btn" href="admin.php?token=<?= h(urlencode($token)) ?>">Zurück zum Admin</a>
          <a class="btn" href="index.php">Frontend</a>
        </div>
      </div>

      <?php if ($msg): ?><div class="card" style="border-radius:14px"><div class="card-b"><?= h($msg) ?></div></div><div style="height:10px"></div><?php endif; ?>

      <?php if ($err): ?>
        <div class="warn">
          <div class="warn-top">
            <div class="tri" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
                <path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/>
              </svg>
            </div>
            <div>
              <h4>Fehler</h4>
              <div class="small"><?= h($err) ?></div>
            </div>
          </div>
        </div>
        <div style="height:10px"></div>
      <?php endif; ?>

      <div class="card" style="border-radius:14px; margin-bottom:12px;">
        <div class="card-h">
          <div class="card-title">Kunden</div>
          <div class="small">Suche (Client-seitig) + Detailansicht + Bestellungen aus lokaler Order-Sammlung.</div>
        </div>
        <div class="card-b">
          <div class="row" style="margin-bottom:10px;">
            <div style="min-width:260px; flex:1">
              <label>Suche</label>
              <input id="pv_user_search" type="text" placeholder="Name, E-Mail, ID, Ort …" value="">
              <div class="muted" style="margin-top:6px;">
                Hinweis: Remote <code>orders.json</code> (<?= h($ordersUrl) ?>) ist leer – daher wird lokal gescannt: <code>/data/orders*</code>
              </div>
            </div>
          </div>

          <?php if (empty($users)): ?>
            <div class="small">
              Keine Nutzer gefunden oder <code>users.json</code> konnte nicht geladen werden.
              <?php if ($err): ?>
                <div style="height:6px"></div>
                <div class="muted"><?= h($err) ?></div>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <table class="pv-table" id="pv_users_table">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>E-Mail</th>
                  <th>Telefon</th>
                  <th>Ort</th>
                  <th>Bestellungen</th>
                  <th>Aktion</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): if (!is_array($u)) continue;
                  $uid   = (string)($u['id'] ?? $u['uid'] ?? '');
                  $name  = trim((string)($u['name'] ?? $u['full_name'] ?? (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))));
                  $email = strtolower(trim((string)($u['email'] ?? $u['mail'] ?? '')));
                  $phone = (string)($u['phone'] ?? $u['tel'] ?? '');
                  $city  = (string)($u['city'] ?? ($u['address']['city'] ?? '') ?? '');
                  $ordersCount = ($email !== '' && isset($ordersByEmail[$email])) ? count($ordersByEmail[$email]) : 0;
                  $keyForLink = $uid !== '' ? $uid : $email;
                ?>
                  <tr class="pv-user-row">
                    <td><span class="pv-pill"><?= h($uid !== '' ? $uid : '-') ?></span></td>
                    <td><?= h($name !== '' ? $name : '-') ?></td>
                    <td><?= h($email !== '' ? $email : '-') ?></td>
                    <td><?= h($phone !== '' ? $phone : '-') ?></td>
                    <td><?= h($city !== '' ? $city : '-') ?></td>
                    <td><?= h((string)$ordersCount) ?></td>
                    <td style="white-space:nowrap">
                      <?php if ($keyForLink !== ''): ?>
                        <a class="btn" style="display:inline-block; height:32px; line-height:30px"
                           href="admin.php?token=<?= urlencode($token) ?>&view=customers&user=<?= urlencode($keyForLink) ?>">Details</a>
                      <?php else: ?>
                        <span class="small">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <?php if (is_array($selectedUser)): ?>
        <?php
          $uid   = (string)($selectedUser['id'] ?? $selectedUser['uid'] ?? '');
          $name  = trim((string)($selectedUser['name'] ?? $selectedUser['full_name'] ?? (($selectedUser['first_name'] ?? '') . ' ' . ($selectedUser['last_name'] ?? ''))));
          $email = strtolower(trim((string)($selectedUser['email'] ?? $selectedUser['mail'] ?? '')));
          $phone = (string)($selectedUser['phone'] ?? $selectedUser['tel'] ?? '');

          $addr = $selectedUser['address'] ?? null;
          $addrLine = '';
          if (is_array($addr)) {
            $street = trim((string)($addr['street'] ?? $selectedUser['street'] ?? ''));
            $zip    = trim((string)($addr['zip'] ?? $addr['postal_code'] ?? $selectedUser['zip'] ?? ''));
            $city   = trim((string)($addr['city'] ?? $selectedUser['city'] ?? ''));
            $country= trim((string)($addr['country'] ?? $selectedUser['country'] ?? ''));
            $addrLine = trim(implode(' ', array_filter([$street, $zip, $city, $country])));
          } else {
            $addrLine = trim((string)($selectedUser['address_line'] ?? ''));
          }

          $created = (string)($selectedUser['created_at'] ?? $selectedUser['registered_at'] ?? '');
          $verified = !empty($selectedUser['email_verified']);
          $exp = (int)($selectedUser['verify_token_expires'] ?? 0);
          $hasReset = !empty($selectedUser['verify_token_hash']) && $exp > time();
        ?>

        <div class="card" style="border-radius:14px; margin-bottom:12px;">
          <div class="card-h">
            <div class="card-title">Kundendetails</div>
            <div class="small"><?= h($uid !== '' ? ('ID: ' . $uid) : 'ID: —') ?></div>
          </div>
          <div class="card-b">
            <div class="row" style="justify-content:space-between; align-items:flex-start">
              <div style="min-width:280px">
                <div class="small"><strong>Name:</strong> <?= h($name !== '' ? $name : '-') ?></div>
                <div class="small"><strong>E-Mail:</strong> <?= h($email !== '' ? $email : '-') ?></div>
                <div class="small"><strong>E-Mail verifiziert:</strong> <?= $verified ? 'Ja' : 'Nein' ?></div>
                <div class="small"><strong>Telefon:</strong> <?= h($phone !== '' ? $phone : '-') ?></div>
                <div class="small"><strong>Adresse:</strong> <?= h($addrLine !== '' ? $addrLine : '-') ?></div>
                <div class="small"><strong>Registriert:</strong> <?= h($created !== '' ? $created : '-') ?></div>
              </div>
              <div style="display:flex; flex-direction:column; gap:10px; align-items:flex-end">
                <a class="btn" href="admin.php?token=<?= urlencode($token) ?>&view=customers" style="height:32px; line-height:30px; display:inline-block;">Zur Liste</a>

                <?php if ($email !== ''): ?>
                  <form method="post" style="margin:0">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="create_password_reset">
                    <input type="hidden" name="reset_email" value="<?= h($email) ?>">
                    <button class="btn" type="submit" style="height:32px"
                      onclick="return confirm('Passwort-Reset-Link für <?= h($email) ?> erzeugen? (24h gültig)');">
                      Passwort-Reset-Link erzeugen
                    </button>
                    <?php if ($hasReset): ?>
                      <div class="muted" style="margin-top:6px; text-align:right">
                        Reset aktiv bis: <?= h(date('Y-m-d H:i', $exp)) ?>
                      </div>
                    <?php endif; ?>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <div style="height:12px"></div>
            <div class="small" style="font-weight:800; margin-bottom:8px;">Bestellübersicht</div>

            <?php if (empty($userOrders)): ?>
              <div class="small">Keine Bestellungen gefunden (Suche in <code>/data/orders*</code> hat für diese E-Mail nichts ergeben).</div>
            <?php else: ?>
              <table class="pv-table">
                <thead>
                  <tr>
                    <th>Bestell-Nr.</th>
                    <th>Datum</th>
                    <th>Status</th>
                    <th>Summe</th>
                    <th>Positionen</th>
                    <th>Aktion</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($userOrders as $o): if (!is_array($o)) continue;
                    $oid   = (string)($o['id'] ?? $o['order_id'] ?? '');
                    $stat  = (string)($o['status'] ?? '');
                    $items = $o['items'] ?? $o['positions'] ?? $o['line_items'] ?? null;
                    $itemsCount = is_array($items) ? count($items) : 0;

                    $dateLabel = pv_order_date_label($o);
                    $gross = pv_order_total_gross($o);
                    $rawTotal = trim((string)($o['total'] ?? $o['total_gross'] ?? $o['sum'] ?? $o['amount'] ?? ''));
                    $totalLabel = ($gross !== null)
                      ? pv_money_fmt($gross)
                      : ($rawTotal !== '' ? $rawTotal : '-');
                  ?>
                    <tr>
                      <td><span class="pv-pill"><?= h($oid !== '' ? $oid : '-') ?></span></td>
                      <td><?= h($dateLabel) ?></td>
                      <td><?= h($stat !== '' ? $stat : '-') ?></td>
                      <td><?= h($totalLabel) ?></td>
                      <td><?= h((string)$itemsCount) ?></td>
                      <td style="white-space:nowrap">
                        <?php if ($oid !== ''): ?>
                          <a class="btn" target="_blank" style="display:inline-block; height:32px; line-height:30px; margin-left:6px"
                             href="admin_order.php?token=<?= urlencode($token) ?>&view=invoice_pdf&id=<?= urlencode($oid) ?>">PDF</a>
                        <?php else: ?>
                          <span class="small">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php if (is_array($items) && !empty($items)): ?>
                      <tr>
                        <td colspan="6" style="padding-top:6px; padding-bottom:12px;">
                          <div class="small" style="opacity:.9; margin-bottom:6px;">Positionen:</div>
                          <table class="pv-table" style="border:1px solid var(--border); border-radius:12px; overflow:hidden;">
                            <thead>
                              <tr>
                                <th>Artikel</th>
                                <th>Menge</th>
                                <th>Preis</th>
                                <th>Summe</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($items as $it): if (!is_array($it)) continue;
                                $sku  = (string)($it['sku'] ?? $it['article'] ?? $it['id'] ?? $it['name'] ?? '');
                                $qty  = (string)($it['qty'] ?? $it['quantity'] ?? '1');
                                $price= (string)($it['price'] ?? $it['unit_price'] ?? '');
                                $sum  = (string)($it['sum'] ?? $it['total'] ?? '');
                              ?>
                                <tr>
                                  <td><?= h($sku !== '' ? $sku : '-') ?></td>
                                  <td><?= h($qty !== '' ? $qty : '-') ?></td>
                                  <td><?= h($price !== '' ? $price : '-') ?></td>
                                  <td><?= h($sum !== '' ? $sum : '-') ?></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
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

      (function(){
        const input = document.getElementById('pv_user_search');
        const table = document.getElementById('pv_users_table');
        if(!input || !table) return;

        const rows = Array.from(table.querySelectorAll('tbody tr.pv-user-row'));
        input.addEventListener('input', function(){
          const q = (input.value || '').toLowerCase().trim();
          rows.forEach(r => {
            const t = (r.textContent || '').toLowerCase();
            r.style.display = (q === '' || t.includes(q)) ? '' : 'none';
          });
        });
      })();
    </script>
  </body>
  </html>
  <?php
  exit;
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

// Discounts laden
$discountsPath = pv_discounts_path();
$discounts = pv_load_discounts($discountsPath);

// Edit Discount (Form prefill)
$editDiscount = null;
if (isset($_GET['edit_discount'])) {
  $c = pv_norm_code((string)$_GET['edit_discount']);
  foreach ($discounts as $d) {
    if (is_array($d) && pv_norm_code((string)($d['code'] ?? '')) === $c) {
      $editDiscount = $d;
      break;
    }
  }
}

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

    // ✅ Neues Produkt anlegen
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
          'product' => [
            'title' => '',
            'info_html' => '',
          ],
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

      // ✅ NEU: fields.json (pro Produkt) anlegen, mit Default-Feldern
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

    // ✅ Neue Variante manuell anlegen (ohne CSV)
    if ($action === 'create_variant') {
      $newPkRaw = (string)($_POST['new_pk'] ?? '');
      $newPk = trim($newPkRaw);

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
      if (!isset($store['variants']) || !is_array($store['variants'])) {
        $store['variants'] = [];
      }
      if (!isset($store['vat_rate'])) {
        $store['vat_rate'] = (float)($cfg['import']['vat_rate'] ?? 0.19);
      }

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
      if ($json === false) {
        throw new RuntimeException('JSON konnte nicht erzeugt werden.');
      }
      if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
      }
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

    // ✅ Variante duplizieren
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
      if (is_file($jsonPath)) {
        $store = json_decode((string)file_get_contents($jsonPath), true);
      }
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
      if (!is_array($srcVariant)) {
        throw new RuntimeException('Quell-Variante nicht gefunden: ' . $srcPk);
      }

      $newVariant = $srcVariant;
      $newVariant[$pkField] = $newPk;
      $store['variants'][] = $newVariant;

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
              if ($productKey === '') {
                $newImages[] = $newPk . '/' . $base;
              } else {
                $newImages[] = $productKey . '/' . $dstSafe . '/' . $base;
              }
            }
            $dstEntry['images'] = $newImages;
            $copied[] = 'Bilder';

            $imgBase = __DIR__ . '/../images';
            $srcDir = $productKey === ''
              ? ($imgBase . '/' . $srcSafe)
              : ($imgBase . '/' . $productKey . '/' . $srcSafe);
            $dstDir = $productKey === ''
              ? ($imgBase . '/' . $dstSafe)
              : ($imgBase . '/' . $productKey . '/' . $dstSafe);

            if (is_dir($srcDir)) {
              if (!is_dir($dstDir)) @mkdir($dstDir, 0775, true);
              foreach (scandir($srcDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                $from = $srcDir . '/' . $f;
                $to   = $dstDir . '/' . $f;
                if (is_file($from) && !is_file($to)) {
                  @copy($from, $to);
                }
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

    // ✅ Shop E-Mail speichern
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
      rename($tmp, $configPath);

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

    // Bestehende Actions
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

// Re-bootstrap (nach PRG)
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

$visibility = pv_load_visibility($visibilityPath);

// Discounts neu laden
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
        <!-- Kundenverwaltung -->
        <a class="btn" href="admin.php?token=<?= urlencode($token) ?>&view=customers">Kundenverwaltung</a>
        <a class="btn" href="https://regatix.shop/public/admin_offers.php?token=<?= urlencode($token) ?>">Angebotstool</a>
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

    <!-- ✅ Felder pro Produkt -->
    <?php $fieldsDef = pv_load_fields($productKey); ?>
    <div class="card" id="fields" style="border-radius:14px; margin-bottom:12px;">
      <div class="card-h">
        <div class="card-title">Felder pro Produkt (Overrides &amp; Freitext)</div>
        <div class="small">Hier legst du die Eingabefelder für dieses Produkt fest (ideal für <strong>Sonderposten</strong>).</div>
      </div>
      <div class="card-b">

        <div class="pv-row2">
          <div>
            <div class="small" style="font-weight:800; margin-bottom:6px;">Overrides</div>
            <?php if (empty($fieldsDef['overrides'])): ?>
              <div class="small">Keine Override-Felder definiert.</div>
            <?php else: ?>
              <table class="pv-table">
                <thead><tr><th>Key</th><th>Label</th><th>Typ</th><th>Aktion</th></tr></thead>
                <tbody>
                  <?php foreach ($fieldsDef['overrides'] as $f): if(!is_array($f)) continue; ?>
                    <tr>
                      <td><span class="pv-pill"><?= h((string)$f['key']) ?></span></td>
                      <td><?= h((string)($f['label'] ?? $f['key'])) ?></td>
                      <td><?= h((string)($f['type'] ?? 'text')) ?></td>
                      <td style="white-space:nowrap">
                        <form method="post" style="display:inline-block; margin:0" onsubmit="return confirm('Feld wirklich löschen?');">
                          <input type="hidden" name="token" value="<?= h($token) ?>">
                          <input type="hidden" name="action" value="delete_field_def">
                          <input type="hidden" name="fd_group" value="overrides">
                          <input type="hidden" name="fd_key" value="<?= h((string)$f['key']) ?>">
                          <button class="btn" type="submit" style="height:32px">Löschen</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <div>
            <div class="small" style="font-weight:800; margin-bottom:6px;">Freitextfelder (z.B. Sonderposten)</div>
            <?php if (empty($fieldsDef['free_text'])): ?>
              <div class="small">Keine Freitextfelder definiert.</div>
            <?php else: ?>
              <table class="pv-table">
                <thead><tr><th>Key</th><th>Label</th><th>Typ</th><th>Aktion</th></tr></thead>
                <tbody>
                  <?php foreach ($fieldsDef['free_text'] as $f): if(!is_array($f)) continue; ?>
                    <tr>
                      <td><span class="pv-pill"><?= h((string)$f['key']) ?></span></td>
                      <td><?= h((string)($f['label'] ?? $f['key'])) ?></td>
                      <td><?= h((string)($f['type'] ?? 'textarea')) ?></td>
                      <td style="white-space:nowrap">
                        <form method="post" style="display:inline-block; margin:0" onsubmit="return confirm('Feld wirklich löschen?');">
                          <input type="hidden" name="token" value="<?= h($token) ?>">
                          <input type="hidden" name="action" value="delete_field_def">
                          <input type="hidden" name="fd_group" value="free_text">
                          <input type="hidden" name="fd_key" value="<?= h((string)$f['key']) ?>">
                          <button class="btn" type="submit" style="height:32px">Löschen</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>

        <div style="height:12px"></div>

        <div class="small" style="font-weight:800; margin-bottom:8px;">Neues Feld hinzufügen</div>
        <form method="post" autocomplete="off" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="add_field_def">

          <div style="min-width:220px">
            <label>Gruppe</label>
            <select name="fd_group">
              <option value="overrides">Overrides</option>
              <option value="free_text">Freitext</option>
            </select>
          </div>

          <div style="min-width:240px">
            <label>Key (eindeutig)</label>
            <input name="fd_key" required placeholder="z.B. Zustand oder maengel">
          </div>

          <div style="min-width:260px">
            <label>Label (Anzeige)</label>
            <input name="fd_label" placeholder="z.B. Mängel / Hinweise">
          </div>

          <div style="min-width:200px">
            <label>Typ</label>
            <select name="fd_type">
              <option value="text">Text (1 Zeile)</option>
              <option value="textarea">Textarea (mehrzeilig)</option>
            </select>
          </div>

          <button class="btn" type="submit">Hinzufügen</button>
        </form>

        <div style="height:10px"></div>
        <form method="post" style="margin:0" onsubmit="return confirm('Wirklich auf Default-Felder zurücksetzen?');">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="reset_field_defs">
          <button class="btn" type="submit" style="height:32px">Auf Default zurücksetzen</button>
        </form>

        <div class="small" style="margin-top:8px">
          Tipp für <strong>Sonderposten</strong>: Produkt <code>sonderposten</code> anlegen, dann hier Freitextfelder wie
          <code>beschreibung_frei</code>, <code>maengel</code>, <code>intern</code> (Typ: <code>textarea</code>) hinzufügen.
        </div>

      </div>
    </div>

    <!-- ✅ Rabattcodes verwalten -->
    <div class="card" id="discounts" style="border-radius:14px; margin-bottom:12px;">
      <div class="card-h">
        <div class="card-title">Rabattcodes verwalten</div>
        <div class="small">Speichert in <code>config/discounts.json</code> (für Checkout-Rabatte).</div>
      </div>
      <div class="card-b">

        <?php if (empty($discounts)): ?>
          <div class="small">Noch keine Rabattcodes vorhanden.</div>
          <div style="height:10px"></div>
        <?php else: ?>
          <table class="pv-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Typ</th>
                <th>Wert</th>
                <th>Aktiv</th>
                <th>Expires</th>
                <th>Min. Brutto</th>
                <th>Aktionen</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($discounts as $d): if (!is_array($d)) continue; ?>
                <tr>
                  <td><span class="pv-pill"><?= h((string)($d['code'] ?? '')) ?></span></td>
                  <td><?= h((string)($d['type'] ?? '')) ?></td>
                  <td><?= h((string)($d['value'] ?? '')) ?></td>
                  <td><?= !empty($d['active']) ? 'Ja' : 'Nein' ?></td>
                  <td><?= h((string)($d['expires'] ?? '')) ?></td>
                  <td><?= h((string)($d['min_gross_eur'] ?? 0)) ?> €</td>
                  <td style="white-space:nowrap">
                    <a class="btn" style="display:inline-block; height:32px; line-height:30px"
                       href="admin.php?token=<?= urlencode($token) ?>&edit_discount=<?= urlencode((string)($d['code'] ?? '')) ?>#discounts">Bearbeiten</a>

                    <form method="post" style="display:inline-block; margin:0" onsubmit="return confirm('Rabattcode wirklich löschen?')">
                      <input type="hidden" name="token" value="<?= h($token) ?>">
                      <input type="hidden" name="action" value="delete_discount">
                      <input type="hidden" name="d_code" value="<?= h((string)($d['code'] ?? '')) ?>">
                      <button class="btn" type="submit" style="height:32px">Löschen</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="height:12px"></div>
        <?php endif; ?>

        <?php
          $ed = $editDiscount ?: [];
          $edCode = (string)($ed['code'] ?? '');
          $edType = (string)($ed['type'] ?? 'percent');
          $edValue = (string)($ed['value'] ?? '10');
          $edActive = !empty($ed['active']);
          $edExpires = (string)($ed['expires'] ?? '');
          $edMinGross = (string)($ed['min_gross_eur'] ?? '0');
          $edNote = (string)($ed['note'] ?? '');
        ?>

        <div class="small" style="font-weight:800; margin-bottom:8px;">
          <?= $edCode !== '' ? ('Code bearbeiten: ' . h($edCode)) : 'Neuen Rabattcode anlegen' ?>
        </div>

        <form method="post" autocomplete="off">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <input type="hidden" name="action" value="save_discount">

          <div class="pv-row2">
            <div>
              <label>Code</label>
              <input name="d_code" required placeholder="z.B. WELCOME10" value="<?= h($edCode) ?>">
            </div>
            <div>
              <label>Typ</label>
              <select name="d_type">
                <option value="percent" <?= $edType==='percent'?'selected':'' ?>>Prozent</option>
                <option value="fixed" <?= $edType==='fixed'?'selected':'' ?>>Fixbetrag (EUR)</option>
              </select>
            </div>
          </div>

          <div class="pv-row2" style="margin-top:10px">
            <div>
              <label>Wert</label>
              <input name="d_value" required value="<?= h($edValue) ?>" placeholder="10 oder 5.00">
              <div class="small" style="margin-top:6px">Bei <strong>Fixbetrag</strong> ist der Wert in EUR (z.B. 5.00).</div>
            </div>
            <div>
              <label>Min. Brutto (EUR, optional)</label>
              <input name="d_min_gross_eur" value="<?= h($edMinGross) ?>" placeholder="0">
            </div>
          </div>

          <div class="pv-row2" style="margin-top:10px">
            <div>
              <label>Expires (optional, YYYY-MM-DD)</label>
              <input name="d_expires" value="<?= h($edExpires) ?>" placeholder="2026-12-31">
            </div>
            <div style="display:flex; align-items:flex-end">
              <label style="display:flex; gap:10px; align-items:center; margin:0">
                <input type="checkbox" name="d_active" value="1" <?= $edActive || $edCode==='' ? 'checked' : '' ?> style="width:18px; height:18px;">
                <span class="small" style="font-weight:800">Aktiv</span>
              </label>
            </div>
          </div>

          <div style="margin-top:10px">
            <label>Notiz (optional)</label>
            <textarea name="d_note" style="width:100%; min-height:110px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($edNote) ?></textarea>
          </div>

          <div style="height:10px"></div>
          <button class="btn" type="submit">Rabattcode speichern</button>
          <a class="btn" href="admin.php?token=<?= urlencode($token) ?>#discounts" style="margin-left:8px">Neu anlegen</a>
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
          <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <div style="min-width:260px">
              <label>Artikelnummer</label>
              <select name="article" onchange="this.form.submit()">
                <?php foreach ($variants as $v):
                  $val = (string)($v[$pk] ?? '');
                  if ($val==='') continue;
                  $sel = $val===$article ? 'selected' : '';
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

    <!-- ✅ Neue Variante anlegen (ganz unten) -->
    <div class="pv-bottom-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items:start">
      <div class="card">
        <div class="card-h"><div class="card-title">Neue Variante anlegen</div></div>
        <div class="card-b">
          <form method="post" autocomplete="off">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <input type="hidden" name="action" value="create_variant">

            <label><?= h($pk) ?> <span class="small">(Pflicht)</span></label>
            <input name="new_pk" required placeholder="<?= h($pk) ?>">

            <div style="height:10px"></div>

            <div class="small">Felder (manuell):</div>
            <div style="height:8px"></div>

            <?php
              $fields = [];
              foreach (($cfg['variant']['dimensions'] ?? []) as $d) {
                if (!is_array($d) || !isset($d['key'])) continue;
                $fields[] = ['key'=>(string)$d['key'], 'label'=>(string)($d['label'] ?? $d['key'])];
              }
              $priceNet  = (string)($cfg['variant']['price_net'] ?? '');
              $priceGross= (string)($cfg['variant']['price_gross'] ?? '');
              if ($priceNet !== '')  $fields[] = ['key'=>$priceNet, 'label'=>$priceNet];
              if ($priceGross !== '') $fields[] = ['key'=>$priceGross, 'label'=>$priceGross];

              $optionsByKey = [];
              foreach ($variants as $vv) {
                if (!is_array($vv)) continue;
                foreach ($fields as $f) {
                  $k = $f['key'];
                  if (!isset($vv[$k])) continue;
                  $val = trim((string)$vv[$k]);
                  if ($val === '') continue;
                  $optionsByKey[$k][$val] = true;
                }
              }
            ?>

            <?php foreach ($fields as $f): $realKey = $f['key']; $safe = 'f_' . substr(md5($realKey), 0, 10); ?>
              <input type="hidden" name="k[<?= h($safe) ?>]" value="<?= h($realKey) ?>">
              <label><?= h($f['label']) ?></label>
              <input
                name="v[<?= h($safe) ?>]"
                list="dl_<?= h($safe) ?>"
                placeholder="<?= h($realKey) ?>"
              >
              <datalist id="dl_<?= h($safe) ?>">
                <?php if (isset($optionsByKey[$realKey])): ?>
                  <?php foreach (array_keys($optionsByKey[$realKey]) as $opt): ?>
                    <option value="<?= h($opt) ?>"></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </datalist>
              <div style="height:8px"></div>
            <?php endforeach; ?>

            <button class="btn" type="submit">Variante anlegen</button>
            <div class="small" style="margin-top:8px">
              Hinweis: Die Variante wird in <code>variants.json</code> gespeichert. Bilder/Infotexte kannst du danach oben bei „Variante“ pflegen.
            </div>
          </form>
        </div>
      </div>

      <div class="footer-note">
        <div class="card" style="margin-bottom:10px;">
          <div class="card-h"><div class="card-title">Produktgruppen-Links kopieren</div></div>
          <div class="card-b">
            <?php foreach ($products as $k => $label):
              $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
              $baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
              $link = $scheme . '://' . $_SERVER['HTTP_HOST'] . $baseDir . '/index.php?product=' . rawurlencode($k);
            ?>
              <div style="display:flex; gap:10px; align-items:center; margin-bottom:6px; flex-wrap:wrap;">
                <span><?= h($label) ?></span>
                <button type="button" class="btn"
                  onclick="navigator.clipboard.writeText('<?= h($link) ?>');this.textContent='Kopiert!';setTimeout(()=>this.textContent='Link kopieren',1500);"
                >Link kopieren</button>
                <span style="font-size:12px; color:#888"><?= h($link) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
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
