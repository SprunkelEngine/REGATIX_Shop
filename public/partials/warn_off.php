<?php
// Warnhinweis AUS: nur ein Button
?>
<div id="pv_warn_box" style="margin-top:12px">
  <button class="btn btn-green" id="pv_add_to_cart" type="button">
	In den Warenkorb
  </button>
</div>

<style>
  /* nur für diesen Button */
  .btn.btn-green{
	width:100%;
	border-color: rgba(149,191,32,.65) !important;
	background: linear-gradient(135deg, rgba(149,191,32,.38), rgba(0,0,0,.02)) !important;
  }
  .btn.btn-green:hover{
	border-color: rgba(149,191,32,.95) !important;
  }
</style>

<script>
  // Falls app.js den Button sonst disabled lässt: hier sicher aktivieren
  (function(){
	const btn = document.getElementById('pv_add_to_cart');
	if (btn) btn.disabled = false;
  })();
</script>
