<?php
// kunden_loginmodul.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user'])) $_SESSION['user'] = null;
?>
<div id="kundenmodul_box" style="margin-bottom:22px;display:flex;gap:18px;">
  <?php if (!$_SESSION['user']): ?>
	<button class="btn" type="button" onclick="kundenmodul_show('register')">Registrieren</button>
	<button class="btn" type="button" onclick="kundenmodul_show('login')">Bin bereits Kunde</button>
	<span style="color:#aaa;font-size:0.98em;align-self:center;">oder als Gast bestellen</span>
  <?php else: ?>
	<span style="color:#32b029; font-weight:bold;align-self:center;">
	  Angemeldet als <?=htmlspecialchars($_SESSION['user']['email']??'Kunde')?>
	</span>
	<a class="btn" href="profil.php" style="margin-left:10px;">Profil</a>
	<a class="btn" href="?logout=1" style="margin-left:10px;background:#eee;color:#666;">Abmelden</a>
  <?php endif; ?>
</div>

<!-- Overlay für Login/Register -->
<div id="kundenmodul_overlay" style="display:none;position:fixed;z-index:99999;top:0;left:0;width:100vw;height:100vh;background:rgba(30,40,50,0.12);align-items:center;justify-content:center;">
  <div id="kundenmodul_content" style="background:#fff;padding:28px 28px 22px 28px;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.14);min-width:340px;position:relative;">
	<button onclick="kundenmodul_hide()" style="position:absolute;right:12px;top:12px;font-size:1.4em;background:transparent;border:none;cursor:pointer;">×</button>
	<div id="kundenmodul_view"></div>
  </div>
</div>

<script>
function kundenmodul_show(mode){
  let html = '';
  if(mode==='register'){
	html = `<h2 style="margin-top:0;">Registrieren</h2>
	  <form method="post" onsubmit="return kundenmodul_do_register(this)">
		<input name="name" placeholder="Name" required style="width:100%;margin-bottom:10px;">
		<input name="email" placeholder="E-Mail" type="email" required style="width:100%;margin-bottom:10px;">
		<input name="pw" placeholder="Passwort" type="password" required style="width:100%;margin-bottom:10px;">
		<button class="btn" type="submit" style="width:100%;">Jetzt registrieren</button>
	  </form>
	  <div style="font-size:0.97em;margin-top:10px;">
		Schon Kunde? <a href="#" onclick="kundenmodul_show('login');return false;">Zum Login</a>
	  </div>`;
  }else{
	html = `<h2 style="margin-top:0;">Login</h2>
	  <form method="post" onsubmit="return kundenmodul_do_login(this)">
		<input name="email" placeholder="E-Mail" type="email" required style="width:100%;margin-bottom:10px;">
		<input name="pw" placeholder="Passwort" type="password" required style="width:100%;margin-bottom:10px;">
		<button class="btn" type="submit" style="width:100%;">Anmelden</button>
	  </form>
	  <div style="font-size:0.97em;margin-top:10px;">
		Noch kein Konto? <a href="#" onclick="kundenmodul_show('register');return false;">Jetzt registrieren</a>
	  </div>`;
  }
  document.getElementById('kundenmodul_view').innerHTML = html;
  document.getElementById('kundenmodul_overlay').style.display = 'flex';
}
function kundenmodul_hide(){
  document.getElementById('kundenmodul_overlay').style.display = 'none';
}
function kundenmodul_do_register(form){
  // Demo: Simple Register
  alert("Registrierung abgesendet.\n\n→ Hier muss PHP/DB für Useranlage angebunden werden!");
  kundenmodul_hide();
  return false;
}
function kundenmodul_do_login(form){
  // Demo: Simple Login
  alert("Login abgesendet.\n\n→ Hier muss PHP/DB für Loginabfrage angebunden werden!");
  kundenmodul_hide();
  return false;
}
</script>
