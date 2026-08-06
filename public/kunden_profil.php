<?php
session_start();

// Prüfen ob Kunde eingeloggt ist
if (empty($_SESSION['user']['email'])) {
	// Nach dem Login zurück zu dieser Seite
	header('Location: kunden_login.php?redirect=kunden_profil.php');
	exit;
}

$usersPath = __DIR__ . '/../data/users.json';
$email = $_SESSION['user']['email'];
$users = json_decode(file_get_contents($usersPath), true) ?: [];
$user = $users[$email] ?? null;

// --- Speichern ---
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$fields = ['first_name','last_name','company','phone','street','zip','city','country'];
	foreach ($fields as $f) {
		$users[$email][$f] = trim($_POST[$f] ?? '');
	}
	if (!empty($_POST['password'])) {
		if ($_POST['password'] === $_POST['password2'] && strlen($_POST['password']) >= 6) {
			$users[$email]['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
			$msg = "Passwort geändert. ";
		} else {
			$msg = "Passwort nicht geändert (zu kurz oder stimmt nicht überein). ";
		}
	}
	file_put_contents($usersPath, json_encode($users, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
	$msg .= "Daten gespeichert!";
	$user = $users[$email];
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Mein Profil</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/styles.css">
  <style>
	body { font-family: 'Inter', Arial, sans-serif; background: var(--bg, #f7fafc);}
	.container { max-width: 520px; margin: 44px auto 0 auto; background:#fff; border-radius:18px; box-shadow:0 8px 24px rgba(0,0,0,.09); padding:38px 32px 24px 32px;}
	h1 { font-size:2em; margin-bottom:26px; }
	label { font-size:1.07em; font-weight:500; display:block; margin-bottom:4px;}
	input[type="text"], input[type="email"], input[type="tel"], input[type="password"] {
	  width:100%; padding:9px 12px; border-radius:12px; border:1.5px solid #d1d9e6;
	  margin-bottom:14px; background:#f5f6fa; font-size:1.03em; transition:border .16s;
	}
	input:focus { border-color:#95bf20; outline:none; }
	.btn {
	  padding:9px 22px; border-radius:999px; font-size:1.07em;
	  border:none; cursor:pointer; font-weight:600;
	  background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02));
	  color:#111; border:1.5px solid #e0e8ed;
	  box-shadow:0 2px 8px rgba(0,0,0,.03);
	  transition:background .15s, border-color .15s;
	  margin-top: 10px;
	}
	.btn:hover { background: #95bf20; color:#fff; border-color:#95bf20;}
	.msg-success { color:#398600; margin-bottom:14px; font-weight:600;}
	.msg-error { color:#b20808; margin-bottom:14px; font-weight:600;}
	@media (max-width:600px) {
	  .container { padding:18px 4vw; }
	}
  </style>
</head>
<body>
  <div class="container">
	<h1>Mein Profil</h1>
	<?php if ($msg): ?><div class="msg-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
	<form method="post" autocomplete="off">
	  <label>E-Mail (kann nicht geändert werden)</label>
	  <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" readonly style="background:#f1f2f5;color:#7a8594;">
	  <label>Vorname</label>
	  <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
	  <label>Nachname</label>
	  <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
	  <label>Firma (optional)</label>
	  <input type="text" name="company" value="<?= htmlspecialchars($user['company'] ?? '') ?>">
	  <label>Telefon (optional)</label>
	  <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
	  <label>Straße / Nr.</label>
	  <input type="text" name="street" value="<?= htmlspecialchars($user['street'] ?? '') ?>">
	  <label>PLZ</label>
	  <input type="text" name="zip" value="<?= htmlspecialchars($user['zip'] ?? '') ?>">
	  <label>Ort</label>
	  <input type="text" name="city" value="<?= htmlspecialchars($user['city'] ?? '') ?>">
	  <label>Land</label>
	  <input type="text" name="country" value="<?= htmlspecialchars($user['country'] ?? '') ?>">
	  <label>Neues Passwort (mind. 6 Zeichen, optional)</label>
	  <input type="password" name="password" autocomplete="new-password" placeholder="••••••••">
	  <label>Neues Passwort wiederholen</label>
	  <input type="password" name="password2" autocomplete="new-password" placeholder="••••••••">
	  <button class="btn" type="submit">Profil speichern</button>
	</form>
  </div>
</body>
</html>
