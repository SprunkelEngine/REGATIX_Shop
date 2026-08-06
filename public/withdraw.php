<?php
declare(strict_types=1);
session_start();

function h($s){
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$product = trim($_GET['product'] ?? '');
$article = trim($_GET['article'] ?? '');
$offer   = trim($_GET['offer'] ?? '');

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $_SESSION['withdrawal_form_time'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $spamCheck = isset($_POST['spam_check']) && $_POST['spam_check'] === '1';
  $website   = trim($_POST['website'] ?? ''); // Honeypot
  $formTime  = $_SESSION['withdrawal_form_time'] ?? time();
  $tooFast   = (time() - (int)$formTime) < 3;

  if (!$spamCheck) {
	$error = 'Bitte bestätigen Sie den Spam-Schutz.';
  } elseif ($website !== '') {
	$error = 'Spam-Verdacht erkannt.';
  } elseif ($tooFast) {
	$error = 'Das Formular wurde zu schnell abgesendet. Bitte versuchen Sie es erneut.';
  } else {

	$data = [
	  'time' => date('c'),
	  'name' => trim($_POST['name'] ?? ''),
	  'email' => trim($_POST['email'] ?? ''),
	  'order' => trim($_POST['order'] ?? ''),
	  'product' => trim($_POST['product'] ?? ''),
	  'article' => trim($_POST['article'] ?? ''),
	  'offer' => trim($_POST['offer'] ?? ''),
	  'message' => trim($_POST['message'] ?? ''),
	  'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
	];

	$dir = __DIR__ . '/../data/withdrawals';
	if (!is_dir($dir)) {
	  mkdir($dir, 0777, true);
	}

	file_put_contents(
	  $dir . '/withdrawals.log',
	  json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL,
	  FILE_APPEND
	);

	mail(
	  "info@regatix.com",
	  "Widerruf über Shop",
	  print_r($data, true)
	);

	$success = true;
	unset($_SESSION['withdrawal_form_time']);
  }
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Widerruf erklären</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
body{
  font-family:Arial;
  background:#f5f5f5;
  padding:40px;
}

.card{
  max-width:700px;
  margin:auto;
  background:white;
  padding:30px;
  border-radius:10px;
  box-shadow:0 10px 30px rgba(0,0,0,.1);
}

input,textarea{
  width:100%;
  padding:10px;
  margin-top:6px;
  margin-bottom:16px;
  border:1px solid #ccc;
  border-radius:6px;
  box-sizing:border-box;
}

button{
  background:#95bf20;
  border:0;
  padding:12px 18px;
  border-radius:6px;
  cursor:pointer;
}

.confirm{
  background:#c62828;
  color:white;
  font-weight:bold;
}

.checkbox-wrap{
  display:flex;
  align-items:flex-start;
  gap:10px;
  margin:18px 0;
}

.checkbox-wrap input{
  width:auto;
  margin:3px 0 0 0;
}

.error{
  background:#fdecea;
  color:#b71c1c;
  border:1px solid #f5c6cb;
  padding:12px;
  border-radius:6px;
  margin-bottom:20px;
}

.hp{
  position:absolute;
  left:-9999px;
  width:1px;
  height:1px;
  overflow:hidden;
}
</style>
</head>

<body>

<div class="card">

<h2>Vertrag widerrufen</h2>

<?php if($success): ?>

<p><b>Ihr Widerruf wurde erfolgreich übermittelt.</b></p>
<p>Wir bestätigen den Eingang per E-Mail.</p>
<a href="index.php">Zurück zum Shop</a>

<?php else: ?>

<p>
Sie können hier den mit uns geschlossenen Vertrag widerrufen.
</p>

<?php if($error): ?>
  <div class="error"><?= h($error) ?></div>
<?php endif; ?>

<form method="post">

<input type="hidden" name="product" value="<?=h($product)?>">
<input type="hidden" name="article" value="<?=h($article)?>">
<input type="hidden" name="offer" value="<?=h($offer)?>">

<div class="hp" aria-hidden="true">
  <label for="website">Website</label>
  <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
</div>

<label>Name</label>
<input type="text" name="name" required>

<label>E-Mail</label>
<input type="email" name="email" required>

<label>Bestell- oder Angebotsnummer</label>
<input type="text" name="order">

<label>Nachricht (optional)</label>
<textarea name="message"></textarea>

<div class="checkbox-wrap">
  <input type="checkbox" name="spam_check" id="spam_check" value="1" required>
  <label for="spam_check">
	Ich bestätige, dass ich kein Spam-Bot bin und dieses Formular manuell absende.
  </label>
</div>

<p>
Durch Klick auf den folgenden Button erklären Sie verbindlich
den Widerruf des Vertrages.
</p>

<button type="submit" class="confirm">
Widerruf jetzt verbindlich absenden
</button>

</form>

<?php endif; ?>

</div>

</body>
</html>