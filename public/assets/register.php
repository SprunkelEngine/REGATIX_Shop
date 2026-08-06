<?php
// Optional pro Seite setzen:
// $reg_tab_label = 'Infos';
// $reg_title     = 'Infos';
// $reg_html      = '<p>Dein Inhalt…</p>';

$reg_tab_label = $reg_tab_label ?? 'Infos';
$reg_title     = $reg_title     ?? 'Infos';
$reg_html      = $reg_html      ?? '<p>Hier dein Inhalt (z.B. Kontakt, Versand, Öffnungszeiten ...).</p>';
?>

<div data-register>
  <button class="reg-tab" type="button" data-reg="open">
	<?= htmlspecialchars($reg_tab_label, ENT_QUOTES, 'UTF-8') ?>
  </button>

  <div class="reg-panel" data-reg="panel" aria-hidden="true">
	<div class="reg-head">
	  <div><?= htmlspecialchars($reg_title, ENT_QUOTES, 'UTF-8') ?></div>
	  <button class="reg-close" type="button" aria-label="Schließen" data-reg="close">×</button>
	</div>

	<div class="reg-body">
	  <?= $reg_html /* bewusst HTML erlaubt */ ?>
	</div>
  </div>
</div>
