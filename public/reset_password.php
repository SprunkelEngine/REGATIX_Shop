<?php
declare(strict_types=1);

$email = trim((string)($_GET['email'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Passwort zurücksetzen</title>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
	:root{
	  --bg:#ffffff; --card:#ffffff; --text:#000; --muted:rgba(0,0,0,.65);
	  --border:rgba(0,0,0,.14); --accent:#95bf20;
	}
	body{ background:var(--bg); }
	.container{ max-width: 720px; margin: 0 auto; padding: 18px; }
	.card{ background:var(--card); border:1px solid var(--border); border-radius:16px; padding:16px; }
	input{ width:100%; height:44px; border-radius:12px; border:1px solid var(--border); padding:0 12px; }
	.btn{ display:inline-flex; align-items:center; justify-content:center; height:44px; padding:0 16px; border-radius:12px; border:1px solid var(--border); cursor:pointer; background: rgba(149,191,32,.18); font-weight:800; }
	.small{ color:var(--muted); font-size:13px; }
	.err{ color:#e53e3e; font-weight:700; }
	.ok{ color: var(--accent); font-weight:700; }
	
	body div.container div.card h2{
		 font-family: "Lucida Grande", Lucida, Verdana, sans-serif!important;
	 }
  </style>
</head>
<body>
  <div class="container">
	<div class="card">
	  <h2 style="margin-top:0;">Passwort zurücksetzen</h2>

	  <?php if ($email === '' || $token === ''): ?>
		<div class="err">Ungültiger Link (E-Mail oder Token fehlt).</div>
		<div style="height:10px"></div>
		<a class="btn" href="checkout.php">Zurück</a>
	  <?php else: ?>
		<div class="small">E-Mail: <strong><?= h($email) ?></strong></div>
		<div style="height:12px"></div>

		<form id="resetForm" autocomplete="off">
		  <label>Neues Passwort</label>
		  <input type="password" id="pw1" required minlength="6" autocomplete="new-password" placeholder="mindestens 6 Zeichen">
		  <div style="height:10px"></div>

		  <label>Neues Passwort wiederholen</label>
		  <input type="password" id="pw2" required minlength="6" autocomplete="new-password" placeholder="Passwort wiederholen">
		  <div style="height:14px"></div>

		  <button class="btn" type="submit" style="width:100%;">Passwort setzen</button>
		</form>

		<div style="height:10px"></div>
		<div id="msg" class="small"></div>

		<div style="height:16px"></div>
		<a class="btn" href="checkout.php" style="background:#eee; font-weight:700;">Zurück zum Checkout</a>

		<script>
		  const EMAIL = <?= json_encode($email, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
		  const TOKEN = <?= json_encode($token, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

		  document.getElementById('resetForm').addEventListener('submit', async (e) => {
			e.preventDefault();
			const msg = document.getElementById('msg');
			msg.textContent = '';
			msg.className = 'small';

			const pw1 = document.getElementById('pw1').value || '';
			const pw2 = document.getElementById('pw2').value || '';

			if (pw1.length < 6) { msg.textContent = 'Passwort zu kurz (min. 6 Zeichen).'; msg.classList.add('err'); return; }
			if (pw1 !== pw2) { msg.textContent = 'Passwörter stimmen nicht überein.'; msg.classList.add('err'); return; }

			try{
			  const res = await fetch('kunden_api.php', {
				method: 'POST',
				headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
				body: new URLSearchParams({
				  action: 'reset_password',
				  email: EMAIL,
				  token: TOKEN,
				  new_password: pw1
				})
			  });

			  const data = await res.json().catch(()=>null);
			  if (!res.ok || !data || !data.ok) {
				msg.textContent = (data && data.error) ? data.error : ('Fehler (HTTP ' + res.status + ')');
				msg.classList.add('err');
				return;
			  }

			  msg.textContent = 'OK. Passwort wurde gesetzt. Du kannst dich jetzt anmelden.';
			  msg.classList.add('ok');
			  setTimeout(()=>{ window.location.href = 'checkout.php'; }, 900);

			}catch(err){
			  msg.textContent = 'Serverfehler beim Zurücksetzen.';
			  msg.classList.add('err');
			}
		  });
		</script>
	  <?php endif; ?>
	</div>
  </div>
</body>
</html>
