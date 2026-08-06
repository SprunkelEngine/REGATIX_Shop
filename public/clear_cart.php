<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$target = 'danke.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bestellung abgeschlossen</title>
</head>
<body>
<script>
(function(){
  try {
	// ✅ Alle typischen Keys löschen (passt auch, wenn ihr euch beim Key vertan habt)
	const keys = [
	  'pv_cart_v1',
	  'pv_cart',
	  'cart',
	  'shopping_cart',
	  'pv_customer_v1',
	  'pv_discount_v1',
	  'pv_last_order_v1',
	  'pv_order_v1'
	];

	keys.forEach(k => {
	  try { localStorage.removeItem(k); } catch(e) {}
	  try { sessionStorage.removeItem(k); } catch(e) {}
	});

	// Optional: wenn eure checkout.js / cart.js mal alles unter pv_ speichert
	try {
	  Object.keys(localStorage).forEach(k => { if (k.startsWith('pv_')) localStorage.removeItem(k); });
	} catch(e) {}

  } catch(e) {}

  // ✅ Ohne History-Eintrag weiter (verhindert "Zurück" -> alter Cart)
  window.location.replace(<?php echo json_encode($target); ?>);
})();
</script>

<noscript>
  Bestellung abgeschlossen. Bitte klicken:
  <a href="<?php echo htmlspecialchars($target, ENT_QUOTES, 'UTF-8'); ?>">Weiter</a>
</noscript>
</body>
</html>
