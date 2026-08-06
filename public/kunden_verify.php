<?php
declare(strict_types=1);

session_start();

$path = __DIR__ . '/../data/users.json';
$users = [];
if (is_file($path)) {
  $tmp = json_decode((string)file_get_contents($path), true);
  if (is_array($tmp)) $users = $tmp;
}

$email = strtolower(trim((string)($_GET['email'] ?? '')));
$token = trim((string)($_GET['token'] ?? ''));

function html(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function saveUsers(string $path, array $users): void {
  file_put_contents($path, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$ok = false;
$msg = 'Ungültiger Link.';

if (filter_var($email, FILTER_VALIDATE_EMAIL) && $token !== '' && isset($users[$email])) {
  $u = $users[$email];

  $expires = (int)($u['verify_token_expires'] ?? 0);
  $hash    = (string)($u['verify_token_hash'] ?? '');

  if ($expires > 0 && time() > $expires) {
	$msg = 'Der Bestätigungslink ist abgelaufen. Bitte im Login erneut senden.';
  } else {
	$tokenHash = hash('sha256', $token);
	if ($hash !== '' && hash_equals($hash, $tokenHash)) {
	  $users[$email]['email_verified'] = true;
	  $users[$email]['verify_token_hash'] = '';
	  $users[$email]['verify_token_expires'] = 0;
	  $users[$email]['updated_at'] = date('c');
	  saveUsers($path, $users);

	  // Session setzen (ohne sensitive Felder)
	  $sessionUser = $users[$email];
	  unset($sessionUser['password_hash'], $sessionUser['verify_token_hash'], $sessionUser['verify_token_expires']);
	  $_SESSION['user'] = $sessionUser;

	  $ok = true;
	  $msg = 'E-Mail erfolgreich bestätigt. Du kannst dich jetzt anmelden.';
	} else {
	  $msg = 'Token ungültig. Bitte Link aus der neuesten E-Mail verwenden.';
	}
  }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>E-Mail bestätigen</title>
  <style>
	body{ font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; background:#f6f7fb; }
	.box{ max-width:680px; margin:6vh auto; background:#fff; border-radius:16px; box-shadow:0 10px 28px rgba(0,0,0,.08); padding:26px; }
	h1{ margin:0 0 10px 0; font-size:22px; }
	p{ margin:0 0 14px 0; color:rgba(0,0,0,.70); }
	.ok{ color:#14804a; font-weight:700; }
	.bad{ color:#b42318; font-weight:700; }
	a.btn{ display:inline-block; padding:10px 14px; border-radius:12px; background:#95bf20; color:#000; text-decoration:none; font-weight:800; }
	a.btn:hover{ opacity:.9; }
  </style>
</head>
<body>
  <div class="box">
	<h1>E-Mail bestätigen</h1>
	<p><strong><?= $ok ? '<span class="ok">OK</span>' : '<span class="bad">Hinweis</span>' ?></strong></p>
	<p><?= html($msg) ?></p>

	<p style="margin-top:18px;">
	  <a class="btn" href="checkout.php">Zurück zum Checkout</a>
	</p>

	<p style="margin-top:14px; font-size:13px; color:rgba(0,0,0,.55);">
	  Konto: <?= html($email) ?>
	</p>
  </div>
</body>
</html>
