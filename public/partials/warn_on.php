<?php
// Warnhinweis AN: Original-UI inkl. Ack
?>
<div class="warn" id="pv_warn_box">
  <div class="warn-top">
	<div class="tri" aria-hidden="true">
	  <svg viewBox="0 0 24 24" width="22" height="22" fill="none">
		<path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/>
		<path d="M12 9v5" stroke="rgba(255,77,77,.95)" stroke-width="1.8" stroke-linecap="round"/>
		<path d="M12 17.8h.01" stroke="rgba(255,77,77,.95)" stroke-width="3.2" stroke-linecap="round"/>
	  </svg>
	</div>
	<div>
	  <h4>Wichtiger Hinweis</h4>
	  <div class="small">Bitte Werte prüfen und den Hinweis bestätigen, bevor das Produkt in der Warenkorb gelegt wird. Reklamationen sind ausgeschlossen.</div>
	</div>
  </div>

  <div style="height:10px"></div>

  <div class="kv" id="pv_warn_fields"></div>

  <div class="warn-actions" style="display:flex; gap:10px; flex-wrap:wrap">
	<button class="btn" id="pv_warn_ack" type="button">Warnhinweis bestätigen</button>
	<button class="btn" id="pv_add_to_cart" type="button" disabled>In den Warenkorb</button>
  </div>

  <p>
	<a href="https://www.regatix.com/media/legenderegatix.webp" target="_blank">
	  <img alt="" src="https://www.regatix.com/media/legenderegatix.webp" style="width: 100%;" />
	</a>
  </p>
</div>
