<?php

declare(strict_types=1);

$script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

if ($script !== 'index.php') {
    return;
}

$cssVersion = @filemtime(__DIR__ . '/assets/shop2.css') ?: time();
$jsVersion = @filemtime(__DIR__ . '/assets/shop2.js') ?: time();
?>
<link rel="stylesheet" href="assets/shop2.css?v=<?= rawurlencode((string)$cssVersion) ?>">
<script src="assets/shop2.js?v=<?= rawurlencode((string)$jsVersion) ?>" defer></script>
