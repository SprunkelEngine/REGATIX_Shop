<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/lib/pv_customers_lib.php';

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

// Token
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
pv_require_admin_token($config, $token);

// Flash
$msg = pv_flash_get();
$err = '';

// URLs
$usersUrl  = pv_users_url();
$ordersUrl = 'https://regatix.shop/data/orders.json';

// POST: Passwort-Reset-Link erzeugen
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

	pv_flash_set('Passwort-Reset-Link erzeugt (24h gültig): ' . $link);
	header('Location: admin_customers.php?token=' . urlencode($token) . '&user=' . urlencode($email));
	exit;
  } catch (Throwable $e) {
	pv_flash_set('Fehler: ' . $e->getMessage());
	header('Location: admin_customers.php?token=' . urlencode($token));
	exit;
  }
}

// Load users.json (robust)
$usersRaw = pv_fetch_json_url($usersUrl);
if (isset($usersRaw['_error'])) {
  $err = 'users.json: ' . (string)$usersRaw['_error']
	  . (isset($usersRaw['_http_code']) ? (' (HTTP ' . (int)$usersRaw['_http_code'] . ')') : '');
  if (isset($usersRaw['_hint'])) $err .= ' — ' . (string)$usersRaw['_hint'];
}

// normalize users => list
$users = [];

if (isset($usersRaw['users']) && is_array($usersRaw['users'])) {
  $users = $usersRaw['users'];
} elseif (isset($usersRaw['data']) && is_array($usersRaw['data'])) {
  $users = $usersRaw['data'];
} elseif (is_array($usersRaw) && array_is_list($usersRaw)) {
  $users = $usersRaw;
} elseif (is_array($usersRaw) && !array_is_list($usersRaw)) {
  $allValuesAreArrays = true;
  foreach ($usersRaw as $v) { if (!is_array($v)) { $allValuesAreArrays = false; break; } }
  if ($allValuesAreArrays) {
	$tmp = [];
	foreach ($usersRaw as $k => $v) {
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

// orders local
$allOrders = pv_collect_orders_local();
$ordersByEmail = [];
foreach ($allOrders as $o) {
  if (!is_array($o)) continue;
  $em = pv_order_buyer_email($o);
  if ($em === '') continue;
  $ordersByEmail[$em][] = $o;
}

// selection
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
	  --bg:#ffffff; --card:#ffffff; --text:#000000; --muted:rgba(0,0,0,.65);
	  --border:rgba(0,0,0,.14); --accent:#95bf20; --shadow:0 8px 22px rgba(0,0,0,.08);
	}
	body{ background: var(--bg) !important; }
	.card{ background: var(--card) !important; }
	.h-sub, .small{ color: var(--muted) !important; }
	.btn{ background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02)) !important; }
	.btn:hover{ border-color: rgba(149,191,32,.55) !important; }
	input[type="text"]{ background: rgba(0,0,0,.03) !important; color: var(--text) !important; }

	body.layout-pro{
	  --bg:#0b0e14; --card:#121827; --text:#e8f0ff; --muted:#9ab0c7;
	  --border:rgba(255,255,255,.10); --accent:#76a7ff; --shadow:0 10px 30px rgba(0,0,0,.35);
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
	body.layout-pro .pill-toggle{ background: rgba(0,0,0,.18); color: var(--text); }
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
			<?php if ($err): ?><div style="height:6px"></div><div class="muted"><?= h($err) ?></div><?php endif; ?>
		  </div>
		<?php else: ?>
		  <table class="pv-table" id="pv_users_table">
			<thead>
			  <tr>
				<th>ID</th><th>Name</th><th>E-Mail</th><th>Telefon</th><th>Ort</th><th>Bestellungen</th><th>Aktion</th>
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
						 href="admin_customers.php?token=<?= urlencode($token) ?>&user=<?= urlencode($keyForLink) ?>">Details</a>
					<?php else: ?><span class="small">—</span><?php endif; ?>
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
			  <a class="btn" href="admin_customers.php?token=<?= urlencode($token) ?>" style="height:32px; line-height:30px; display:inline-block;">Zur Liste</a>

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
				  <th>Bestell-Nr.</th><th>Datum</th><th>Status</th><th>Summe</th><th>Positionen</th><th>Aktion</th>
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
				  $totalLabel = ($gross !== null) ? pv_money_fmt($gross) : ($rawTotal !== '' ? $rawTotal : '-');
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
					  <?php else: ?><span class="small">—</span><?php endif; ?>
					</td>
				  </tr>

				  <?php if (is_array($items) && !empty($items)): ?>
					<tr>
					  <td colspan="6" style="padding-top:6px; padding-bottom:12px;">
						<div class="small" style="opacity:.9; margin-bottom:6px;">Positionen:</div>
						<table class="pv-table" style="border:1px solid var(--border); border-radius:12px; overflow:hidden;">
						  <thead>
							<tr><th>Artikel</th><th>Menge</th><th>Preis</th><th>Summe</th></tr>
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
