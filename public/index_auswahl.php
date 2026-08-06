<?php

var_dump(glob(__DIR__ . '/data/*.json'));
// Alle Produktdateien im data/-Verzeichnis finden (nur .json)
$productFiles = glob(__DIR__ . '/../data/*.json');

$produkte = [];
foreach ($productFiles as $file) {
    $produkt = json_decode(file_get_contents($file), true);
    if ($produkt) {
        $produkte[] = $produkt;
    }
}

// OPTIONAL: Eckregal ausblenden (lösche diese Zeile, wenn es angezeigt werden soll)
$produkte = array_filter($produkte, function($p){
    return strtolower($p['title']) !== 'eckregal';
});

// Produktwahl per URL-Parameter ?produkt=Fachbodenregal
$activeProdukt = null;
if (isset($_GET['produkt'])) {
    foreach ($produkte as $p) {
        if (
            (isset($p['slug']) && $p['slug'] === $_GET['produkt']) ||
            (isset($p['title']) && $p['title'] === $_GET['produkt'])
        ) {
            $activeProdukt = $p;
            break;
        }
    }
}
if (!$activeProdukt && count($produkte)) {
    $produkte = array_values($produkte); // Array-Keys reparieren!
    $activeProdukt = $produkte[0]; // Erstes Produkt als Default
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>Produktkatalog</title>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
    .prod-menu a {
      margin: 0 12px 0 0;
      padding: 7px 22px;
      border-radius: 22px;
      text-decoration: none;
      background: #f3f7f1;
      color: #222;
      font-weight: bold;
      border: 1.5px solid #e2e8e8;
      transition: background .2s;
    }
    .prod-menu a.active {
      background: #d1fae5;
      color: #256d4c;
      border-color: #a7f3d0;
    }
    .prod-detail {
      margin: 2.2em 0 0 0;
      padding: 2em;
      background: #fff;
      border-radius: 24px;
      box-shadow: 0 4px 18px #0001;
      max-width: 540px;
    }
    img.prod-img { max-width: 300px; border-radius:16px; box-shadow:0 2px 12px #0001; }
  </style>
</head>
<body>
  <div style="max-width:800px; margin: 1.5em auto;">
    <h1>Produktauswahl</h1>
    <div class="prod-menu" style="margin-bottom:2em;">
      <?php foreach ($produkte as $p): ?>
        <a href="?produkt=<?= urlencode($p['slug'] ?? $p['title']) ?>"
           class="<?= ($activeProdukt && ($p['slug'] ?? $p['title']) === ($activeProdukt['slug'] ?? $activeProdukt['title'])) ? 'active' : '' ?>">
           <?= htmlspecialchars($p['title']) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($activeProdukt): ?>
      <div class="prod-detail">
        <h2><?= htmlspecialchars($activeProdukt['title']) ?></h2>
        <?php if (!empty($activeProdukt['bild'])): ?>
          <img class="prod-img" src="media/<?= htmlspecialchars($activeProdukt['bild']) ?>" alt="<?= htmlspecialchars($activeProdukt['title']) ?>">
        <?php endif; ?>
        <p style="margin-top:1em"><?= htmlspecialchars($activeProdukt['beschreibung'] ?? '') ?></p>
        <div style="font-size:1.2em;margin-top:1.2em">
          <strong>Preis:</strong>
          <?= number_format($activeProdukt['preis'] ?? 0, 2, ',', '.') ?> € inkl. MwSt.
        </div>
      </div>
    <?php else: ?>
      <p>Kein Produkt gefunden.</p>
    <?php endif; ?>
  </div>
</body>
</html>
