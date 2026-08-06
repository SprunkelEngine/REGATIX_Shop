<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=UTF-8');

function out(array $a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function htrim($s): string {
  return trim((string)$s);
}

function lower($s): string {
  return mb_strtolower((string)$s, 'UTF-8');
}

$configPath = __DIR__ . '/../config/config.json';
$cfg = [];
if (is_file($configPath)) {
  $cfg = json_decode((string)file_get_contents($configPath), true) ?: [];
}
$shop = (array)($cfg['shop'] ?? []);

/**
 * ✅ Pfad zu users.json
 * In deinem Fall liegt sie unter /data/users.json
 */
$usersPath = __DIR__ . '/../data/users.json';

function load_users(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function save_users(string $path, array $users): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
    throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }

  $tmp = $path . '.tmp';
  $json = json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) {
    throw new RuntimeException('JSON konnte nicht erzeugt werden.');
  }

  if (file_put_contents($tmp, $json, LOCK_EX) === false) {
    throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  }

  if (!@rename($tmp, $path)) {
    if (!@copy($tmp, $path)) {
      @unlink($tmp);
      throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
    }
    @unlink($tmp);
  }
}

/**
 * ✅ Base URL für Reset-Link
 */
function base_url(array $shop): string {
  $b = trim((string)($shop['base_url'] ?? ''));
  if ($b !== '') return rtrim($b, '/');

  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
  return $scheme . '://' . $host;
}

/**
 * ✅ Mail senden
 */
function send_mail_better(string $to, string $subjectUtf8, string $bodyTextUtf8, array $shop, ?string &$debug = null): bool {
  $fromEmail = trim((string)($shop['mail_from'] ?? 'no-reply@regatix.shop'));
  $fromName  = trim((string)($shop['mail_from_name'] ?? 'REGATIX Shop'));

  $subject = '=?UTF-8?B?' . base64_encode($subjectUtf8) . '?=';

  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
  $headers[] = 'Content-Transfer-Encoding: 8bit';
  $headers[] = 'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $fromEmail);
  $headers[] = 'Reply-To: ' . $fromEmail;

  $host = (string)($_SERVER['HTTP_HOST'] ?? 'regatix.shop');
  $headers[] = 'Message-ID: <' . bin2hex(random_bytes(8)) . '.' . time() . '@' . $host . '>';
  $headers[] = 'Date: ' . date('r');

  $headerStr = implode("\r\n", $headers);

  $params = '';
  if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    $params = '-f ' . $fromEmail;
  }

  $ok = false;
  if ($params !== '') {
    $ok = @mail($to, $subject, $bodyTextUtf8, $headerStr, $params);
  } else {
    $ok = @mail($to, $subject, $bodyTextUtf8, $headerStr);
  }

  if (!$ok) {
    $debug = 'mail() returned false (Server Mail nicht konfiguriert oder blockiert).';
  }

  return $ok;
}

$action = htrim($_POST['action'] ?? '');

try {
  // ---------- WHOAMI ----------
  if ($action === 'whoami') {
    $u = $_SESSION['user'] ?? null;
    if (!is_array($u) || empty($u['email'])) {
      out(['ok' => false], 200);
    }
    out(['ok' => true, 'user' => $u], 200);
  }

  // ---------- LOGOUT ----------
  if ($action === 'logout') {
    $_SESSION['user'] = null;
    out(['ok' => true], 200);
  }

  // ---------- REGISTER ----------
  if ($action === 'register') {
    $email = lower(htrim($_POST['email'] ?? ''));
    $pw    = (string)($_POST['password'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      out(['ok' => false, 'error' => 'Ungültige E-Mail.'], 400);
    }

    if (strlen($pw) < 6) {
      out(['ok' => false, 'error' => 'Passwort zu kurz (min. 6 Zeichen).'], 400);
    }

    $users = load_users($GLOBALS['usersPath']);

    if (isset($users[$email])) {
      out(['ok' => false, 'error' => 'E-Mail existiert bereits.'], 409);
    }

    $first = htrim($_POST['first_name'] ?? '');
    $last  = htrim($_POST['last_name'] ?? '');
    $phone = htrim($_POST['phone'] ?? '');

    if ($first === '' || $last === '') {
      out(['ok' => false, 'error' => 'Vorname/Nachname fehlt.'], 400);
    }

    if ($phone === '') {
      out(['ok' => false, 'error' => 'Telefon für die Zustellung fehlt.'], 400);
    }

    $customerType = htrim($_POST['customer_type'] ?? '');
    $vatId        = htrim($_POST['vat_id'] ?? '');

    if (!in_array($customerType, ['private', 'company'], true)) {
      out(['ok' => false, 'error' => 'Bitte Privatkunde oder Firmenkunde wählen.'], 400);
    }

    if ($customerType === 'company' && $vatId === '') {
      out(['ok' => false, 'error' => 'Bitte USt-IdNr. eintragen.'], 400);
    }

    $user = [
      'first_name'       => $first,
      'last_name'        => $last,
      'company'          => htrim($_POST['company'] ?? ''),
      'phone'            => $phone,
      'email'            => $email,
      'street'           => htrim($_POST['street'] ?? ''),
      'zip'              => htrim($_POST['zip'] ?? ''),
      'city'             => htrim($_POST['city'] ?? ''),
      'country'          => htrim($_POST['country'] ?? ''),
      'customer_type'    => $customerType,
      'vat_id'           => $vatId,
      'password_hash'    => password_hash($pw, PASSWORD_DEFAULT),
      'created_at'       => date('c'),
      'updated_at'       => date('c'),
      'email_verified'   => true,
    ];

    $users[$email] = $user;
    save_users($GLOBALS['usersPath'], $users);

    $sess = $user;
    unset($sess['password_hash']);
    $_SESSION['user'] = $sess;

    out(['ok' => true, 'user' => $sess], 200);
  }

  // ---------- LOGIN ----------
  if ($action === 'login') {
    $email = lower(htrim($_POST['email'] ?? ''));
    $pw    = (string)($_POST['password'] ?? '');

    if ($email === '' || $pw === '') {
      out(['ok' => false, 'error' => 'E-Mail oder Passwort fehlt.'], 400);
    }

    $users = load_users($GLOBALS['usersPath']);
    $u = $users[$email] ?? null;

    if (!is_array($u) || empty($u['password_hash']) || !password_verify($pw, (string)$u['password_hash'])) {
      out(['ok' => false, 'error' => 'Login fehlgeschlagen.'], 401);
    }

    $sess = $u;
    unset($sess['password_hash']);
    $_SESSION['user'] = $sess;

    out(['ok' => true, 'user' => $sess], 200);
  }

  // ---------- UPDATE PROFILE ----------
  if ($action === 'update_profile') {
    $sess = $_SESSION['user'] ?? null;
    if (!is_array($sess) || empty($sess['email'])) {
      out(['ok' => false, 'error' => 'Nicht angemeldet.'], 401);
    }

    $email = lower((string)$sess['email']);
    $users = load_users($GLOBALS['usersPath']);

    if (!isset($users[$email]) || !is_array($users[$email])) {
      out(['ok' => false, 'error' => 'User nicht gefunden.'], 404);
    }

    $u = $users[$email];

    $u['first_name'] = htrim($_POST['first_name'] ?? $u['first_name'] ?? '');
    $u['last_name']  = htrim($_POST['last_name'] ?? $u['last_name'] ?? '');
    $u['company']    = htrim($_POST['company'] ?? $u['company'] ?? '');
    $u['phone']      = htrim($_POST['phone'] ?? $u['phone'] ?? '');
    $u['street']     = htrim($_POST['street'] ?? $u['street'] ?? '');
    $u['zip']        = htrim($_POST['zip'] ?? $u['zip'] ?? '');
    $u['city']       = htrim($_POST['city'] ?? $u['city'] ?? '');
    $u['country']    = htrim($_POST['country'] ?? $u['country'] ?? '');

    $customerType = htrim($_POST['customer_type'] ?? ($u['customer_type'] ?? ''));
    $vatId        = htrim($_POST['vat_id'] ?? ($u['vat_id'] ?? ''));

    if (!in_array($customerType, ['private', 'company'], true)) {
      out(['ok' => false, 'error' => 'Bitte Privatkunde oder Firmenkunde wählen.'], 400);
    }

    if ($customerType === 'company' && $vatId === '') {
      out(['ok' => false, 'error' => 'Bitte USt-IdNr. eintragen.'], 400);
    }

    if ($customerType === 'private') {
      $vatId = '';
    }

    $u['customer_type'] = $customerType;
    $u['vat_id']        = $vatId;
    $u['updated_at']    = date('c');

    if (($u['first_name'] ?? '') === '' || ($u['last_name'] ?? '') === '') {
      out(['ok' => false, 'error' => 'Vorname/Nachname fehlen.'], 400);
    }

    if (($u['street'] ?? '') === '' || ($u['zip'] ?? '') === '' || ($u['city'] ?? '') === '' || ($u['country'] ?? '') === '') {
      out(['ok' => false, 'error' => 'Adresse unvollständig.'], 400);
    }

    $users[$email] = $u;
    save_users($GLOBALS['usersPath'], $users);

    $sess = $u;
    unset($sess['password_hash']);
    $_SESSION['user'] = $sess;

    out(['ok' => true, 'user' => $sess], 200);
  }

  // ---------- RESET REQUEST ----------
  if ($action === 'reset_request') {
    $email = lower(htrim($_POST['email'] ?? ''));
    $generic = ['ok' => true, 'message' => 'Wenn die E-Mail existiert, wurde ein Reset-Link versendet.'];

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      out($generic, 200);
    }

    $users = load_users($GLOBALS['usersPath']);
    if (!isset($users[$email]) || !is_array($users[$email])) {
      out($generic, 200);
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = time() + 3600;

    $users[$email]['password_reset_token_hash'] = hash('sha256', $token);
    $users[$email]['password_reset_expires']    = $expires;
    $users[$email]['updated_at']                = date('c');

    save_users($GLOBALS['usersPath'], $users);

    $base = base_url($GLOBALS['shop']);
    $link = $base . '/reset_password.php?email=' . rawurlencode($email) . '&token=' . rawurlencode($token);

    $subject = 'Passwort zurücksetzen (REGATIX Shop)';
    $body = "Hallo,\n\n"
          . "Sie haben einen Link zum Zurücksetzen Ihres Passworts angefordert.\n\n"
          . "Reset-Link (gültig 60 Minuten):\n$link\n\n"
          . "Wenn Sie das nicht waren, ignorieren Sie diese E-Mail.\n\n"
          . "Viele Grüße\nREGATIX Shop\n";

    $debug = null;
    $sent = send_mail_better($email, $subject, $body, $GLOBALS['shop'], $debug);

    $showDev = !empty($GLOBALS['shop']['dev_show_reset_link']) || !$sent;

    $resp = $generic;
    if ($showDev) $resp['dev_reset_link'] = $link;
    if (!$sent && $debug) $resp['mail_debug'] = $debug;

    out($resp, 200);
  }

  // ---------- RESET PASSWORD ----------
  if ($action === 'reset_password') {
    $email = lower(htrim($_POST['email'] ?? ''));
    $token = htrim($_POST['token'] ?? '');
    $newPw = (string)($_POST['new_password'] ?? '');

    if ($email === '' || $token === '' || $newPw === '') {
      out(['ok' => false, 'error' => 'Ungültige Anfrage.'], 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      out(['ok' => false, 'error' => 'Ungültige E-Mail.'], 400);
    }

    if (strlen($newPw) < 6) {
      out(['ok' => false, 'error' => 'Passwort zu kurz (min. 6 Zeichen).'], 400);
    }

    $users = load_users($GLOBALS['usersPath']);
    if (!isset($users[$email]) || !is_array($users[$email])) {
      out(['ok' => false, 'error' => 'Ungültiger Link.'], 400);
    }

    $u = $users[$email];
    $hash = (string)($u['password_reset_token_hash'] ?? '');
    $exp  = (int)($u['password_reset_expires'] ?? 0);

    if ($hash === '' || $exp <= 0 || time() > $exp) {
      out(['ok' => false, 'error' => 'Token abgelaufen. Bitte neu anfordern.'], 400);
    }

    $calc = hash('sha256', $token);
    if (!hash_equals($hash, $calc)) {
      out(['ok' => false, 'error' => 'Ungültiger Token.'], 400);
    }

    $u['password_hash'] = password_hash($newPw, PASSWORD_DEFAULT);
    $u['password_reset_token_hash'] = '';
    $u['password_reset_expires'] = 0;
    $u['updated_at'] = date('c');

    $users[$email] = $u;
    save_users($GLOBALS['usersPath'], $users);

    out(['ok' => true], 200);
  }

  out(['ok' => false, 'error' => 'Ungültige Anfrage'], 400);

} catch (Throwable $e) {
  out(['ok' => false, 'error' => $e->getMessage()], 500);
}