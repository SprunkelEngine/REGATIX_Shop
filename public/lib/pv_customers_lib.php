<?php
declare(strict_types=1);

/** Minimal helpers */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Produkt-/View-Keys mit Umlauten erlauben (Unicode)
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

/* =========================================================
   Auth + Flash
   ========================================================= */
function pv_require_admin_token(array $config, string $token): void {
  $expected = (string)($config['security']['admin_token'] ?? '');
  if ($expected !== '' && $token !== $expected) {
	http_response_code(403);
	echo "Forbidden (token). Setze security.admin_token in config/config.json.";
	exit;
  }
}

function pv_flash_get(): string {
  $msg = (string)($_SESSION['pv_flash'] ?? '');
  unset($_SESSION['pv_flash']);
  return $msg;
}

function pv_flash_set(string $msg): void {
  $_SESSION['pv_flash'] = $msg;
}

function pv_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* =========================================================
   Local file helper (same host URL => local read/write)
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
   Remote JSON Helper (robust)
   - lokal (same host) -> file_get_contents -> cURL
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

  // 0) Same host => lokal lesen
  $local = pv_local_path_from_url($url);
  if ($local && is_file($local) && is_readable($local)) {
	$raw = (string)file_get_contents($local);
	$arr = json_decode($raw, true);
	if (is_array($arr)) return $arr;
	return ['_error' => 'JSON konnte nicht geparst werden (lokale Datei).', '_hint' => 'Prüfe: ' . ($parts['path'] ?? '')];
  }

  // 1) file_get_contents
  $allow = (string)ini_get('allow_url_fopen');
  $allowBool = ($allow === '1' || strtolower($allow) === 'on' || strtolower($allow) === 'true');
  if ($allowBool) {
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

  // 2) cURL fallback
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
   Users: URL/Load/Save
   ========================================================= */
function pv_users_url(): string {
  return 'https://regatix.shop/data/users.json';
}
function pv_users_local_path(): ?string {
  return pv_local_path_from_url(pv_users_url());
}

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
	foreach ($raw as $v) { if (!is_array($v)) { $allValuesAreArrays = false; break; } }
	if ($allValuesAreArrays) {
	  $out = [];
	  foreach ($raw as $k => $v) {
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
   Orders collector (lokal /data/orders*)
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
	foreach ($raw as $v) { if (!is_array($v)) { $allValuesAreArrays = false; break; } }
	if ($allValuesAreArrays) {
	  foreach ($raw as $k => $v) {
		if (empty($v['id']) && empty($v['order_id'])) $v['id'] = (string)$k;
		$orders[] = $v;
	  }
	  return $orders;
	}
  }
  return [];
}

function pv_order_buyer_email(array $o): string {
  $cands = [$o['email'] ?? null, $o['customer_email'] ?? null, $o['user_email'] ?? null, $o['billing_email'] ?? null];
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

  foreach ([__DIR__ . '/../../data/orders.json', __DIR__ . '/../../data/order.json'] as $p) {
	$raw = pv_try_decode_json_file($p);
	if (is_array($raw)) $orders = array_merge($orders, pv_extract_orders_from_any($raw));
  }

  $dir = __DIR__ . '/../../data/orders';
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

/* =========================================================
   Format + totals
   ========================================================= */
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

/* =========================================================
   Reset link helpers
   ========================================================= */
function pv_make_reset_token(): string {
  return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}
function pv_hash_token(string $token): string {
  return hash('sha256', $token);
}
function pv_reset_link(string $email, string $token): string {
  return pv_base_url() . '/reset_password.php?email=' . rawurlencode($email) . '&token=' . rawurlencode($token);
}
