<?php
declare(strict_types=1);

/**
 * Freight Admin Drop-in
 * - CSV Import PLZ->Region
 * - Freight Profiles verwalten
 * - Produkt -> Freight Profil zuweisen
 */

function pv_freight_profiles_path_admin(): string { return __DIR__ . '/../config/freight_profiles.json'; }
function pv_plz_regions_path_admin(): string { return __DIR__ . '/../config/plz_regions.json'; }
function pv_product_freight_map_path_admin(): string { return __DIR__ . '/../config/product_freight_profile.json'; }

function pv_freight_h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function pv_freight_load_json(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function pv_freight_save_json(string $path, array $data, string $errLabel = 'json'): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
	throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException($errLabel . ' konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
	if (!@copy($tmp, $path)) {
	  @unlink($tmp);
	  throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
	}
	@unlink($tmp);
  }
}

function pv_freight_norm_plz(string $plz): string {
  $plz = trim($plz);
  $plz = preg_replace('~\D+~', '', $plz);
  return (string)$plz;
}

function pv_freight_norm_region(string $s): string {
  $s = trim((string)$s);
  $s = preg_replace('~\s+~u', ' ', $s);
  return $s;
}

/**
 * CSV Import akzeptiert:
 * - 2 Spalten: plz;region  (oder plz,region)
 * - 3 Spalten: plz_from;plz_to;region
 * Ergebnis:
 * [
 *   "exact" => { "10115":"Berlin", ... },
 *   "ranges" => [ ["from"=>"01000","to"=>"01999","region"=>"Sachsen"], ... ]
 * ]
 */
function pv_freight_import_plz_csv_to_map(string $csvPath): array {
  if (!is_file($csvPath) || !is_readable($csvPath)) throw new RuntimeException('CSV nicht lesbar.');

  $fh = fopen($csvPath, 'rb');
  if (!$fh) throw new RuntimeException('CSV konnte nicht geöffnet werden.');

  $pos = ftell($fh);
  $firstLine = (string)fgets($fh);
  fseek($fh, $pos);

  $delim = (substr_count($firstLine, ';') >= substr_count($firstLine, ',')) ? ';' : ',';

  $exact = [];
  $ranges = [];

  $rowNum = 0;
  while (($row = fgetcsv($fh, 0, $delim)) !== false) {
	$rowNum++;
	if (!is_array($row)) continue;
	$row = array_map(fn($x) => trim((string)$x), $row);
	$nonEmpty = array_values(array_filter($row, fn($x)=>$x!==''));
	if (count($nonEmpty) === 0) continue;

	if ($rowNum === 1) {
	  $lower = strtolower(implode(' ', $row));
	  if (str_contains($lower, 'plz') || str_contains($lower, 'zip') || str_contains($lower, 'region')) {
		continue; // header skip
	  }
	}

	if (count($row) >= 3 && $row[0] !== '' && $row[1] !== '' && $row[2] !== '') {
	  $from = pv_freight_norm_plz($row[0]);
	  $to   = pv_freight_norm_plz($row[1]);
	  $reg  = pv_freight_norm_region($row[2]);
	  if ($from === '' || $to === '' || $reg === '') continue;
	  if ((int)$from > (int)$to) { $tmp2 = $from; $from = $to; $to = $tmp2; }
	  $ranges[] = ['from'=>$from, 'to'=>$to, 'region'=>$reg];
	  continue;
	}

	if (count($row) >= 2 && $row[0] !== '' && $row[1] !== '') {
	  $plz = pv_freight_norm_plz($row[0]);
	  $reg = pv_freight_norm_region($row[1]);
	  if ($plz === '' || $reg === '') continue;
	  $exact[$plz] = $reg;
	  continue;
	}
  }
  fclose($fh);

  usort($ranges, function($a,$b){
	return ((int)$a['from'] <=> (int)$b['from']) ?: ((int)$a['to'] <=> (int)$b['to']);
  });

  return ['exact'=>$exact, 'ranges'=>$ranges];
}

function pv_freight_norm_profile_id(string $id): string {
  $id = trim($id);
  $id = preg_replace('~\s+~', '', $id);
  $id = strtolower($id);
  $id = preg_replace('~[^a-z0-9_\-]~', '', $id);
  return $id ?: '';
}

function pv_freight_parse_region_cost_lines(string $text): array {
  // Zeilenformat: Region=49.00
  $out = [];
  $lines = preg_split('~\R~u', (string)$text) ?: [];
  foreach ($lines as $ln) {
	$ln = trim($ln);
	if ($ln === '' || str_starts_with($ln, '#')) continue;
	if (!str_contains($ln, '=')) continue;
	[$reg, $val] = array_map('trim', explode('=', $ln, 2));
	$reg = pv_freight_norm_region($reg);
	$val = str_replace(',', '.', (string)$val);
	if ($reg === '' || $val === '' || !is_numeric($val)) continue;
	$out[$reg] = (float)$val;
  }
  ksort($out);
  return $out;
}

function pv_freight_region_costs_to_lines(array $map): string {
  $lines = [];
  foreach ($map as $reg => $v) {
	if ($reg === '' || !is_numeric($v)) continue;
	$lines[] = $reg . '=' . number_format((float)$v, 2, '.', '');
  }
  return implode("\n", $lines);
}

/**
 * Wird in admin.php im POST-Block aufgerufen.
 * Wenn Action erkannt wird, macht die Funktion Redirect + exit.
 */
function pv_freight_admin_maybe_handle_action(string $action, string $token): bool {
  // CSV Import
  if ($action === 'import_plz_regions_csv') {
	if (empty($_FILES['plz_csv']) || !is_array($_FILES['plz_csv'])) {
	  throw new RuntimeException('Bitte CSV auswählen.');
	}
	$f = $_FILES['plz_csv'];
	if (!empty($f['error'])) throw new RuntimeException('Upload Fehler: ' . (int)$f['error']);
	$tmp = (string)($f['tmp_name'] ?? '');
	if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Upload Datei fehlt.');

	$map = pv_freight_import_plz_csv_to_map($tmp);
	pv_freight_save_json(pv_plz_regions_path_admin(), $map, 'plz_regions.json');

	$ex = is_array($map['exact'] ?? null) ? count($map['exact']) : 0;
	$rg = is_array($map['ranges'] ?? null) ? count($map['ranges']) : 0;

	$_SESSION['pv_flash'] = 'PLZ-Regionen importiert. Exact: ' . $ex . ' / Ranges: ' . $rg;
	header('Location: admin.php?token=' . urlencode($token) . '#freight');
	exit;
  }

  // Profile speichern
  if ($action === 'save_freight_profile') {
	$profilesPath = pv_freight_profiles_path_admin();
	$profiles = pv_freight_load_json($profilesPath);
	if (!is_array($profiles)) $profiles = [];

	$id = pv_freight_norm_profile_id((string)($_POST['f_id'] ?? ''));
	$title = trim((string)($_POST['f_title'] ?? ''));
	$active = isset($_POST['f_active']) && (string)($_POST['f_active'] ?? '') === '1';
	$mode = strtolower(trim((string)($_POST['f_mode'] ?? 'max')));
	$defaultRaw = str_replace(',', '.', trim((string)($_POST['f_default_cost'] ?? '0')));
	$lines = (string)($_POST['f_region_costs'] ?? '');

	if ($id === '') throw new RuntimeException('Profil-ID fehlt (nur a-z0-9_-).');
	if ($title === '') throw new RuntimeException('Titel fehlt.');
	if (!in_array($mode, ['max','sum'], true)) throw new RuntimeException('Mode ungültig (max oder sum).');
	if ($defaultRaw === '' || !is_numeric($defaultRaw) || (float)$defaultRaw < 0) throw new RuntimeException('Default-Kosten ungültig.');

	$regionCosts = pv_freight_parse_region_cost_lines($lines);

	$entry = [
	  'id' => $id,
	  'title' => $title,
	  'active' => $active,
	  'mode' => $mode,
	  'default_cost_eur' => (float)$defaultRaw,
	  'region_costs' => $regionCosts,
	];

	$found = false;
	foreach ($profiles as $i => $p) {
	  if (is_array($p) && (string)($p['id'] ?? '') === $id) {
		$profiles[$i] = $entry;
		$found = true;
		break;
	  }
	}
	if (!$found) $profiles[] = $entry;

	usort($profiles, fn($a,$b)=>strcmp((string)($a['id']??''),(string)($b['id']??'')));

	pv_freight_save_json($profilesPath, array_values($profiles), 'freight_profiles.json');

	$_SESSION['pv_flash'] = 'Frachtprofil gespeichert: ' . $id;
	header('Location: admin.php?token=' . urlencode($token) . '#freight');
	exit;
  }

  // Profil löschen
  if ($action === 'delete_freight_profile') {
	$id = pv_freight_norm_profile_id((string)($_POST['f_id'] ?? ''));
	if ($id === '') throw new RuntimeException('Profil-ID fehlt.');

	$profilesPath = pv_freight_profiles_path_admin();
	$profiles = pv_freight_load_json($profilesPath);
	if (!is_array($profiles)) $profiles = [];

	$profiles = array_values(array_filter($profiles, function($p) use ($id){
	  return !(is_array($p) && (string)($p['id'] ?? '') === $id);
	}));
	pv_freight_save_json($profilesPath, $profiles, 'freight_profiles.json');

	// Zuordnungen aufräumen
	$mapPath = pv_product_freight_map_path_admin();
	$map = pv_freight_load_json($mapPath);
	if (!is_array($map)) $map = [];
	foreach ($map as $pk => $pid) {
	  if ((string)$pid === $id) unset($map[$pk]);
	}
	pv_freight_save_json($mapPath, $map, 'product_freight_profile.json');

	$_SESSION['pv_flash'] = 'Frachtprofil gelöscht: ' . $id;
	header('Location: admin.php?token=' . urlencode($token) . '#freight');
	exit;
  }

  // Produkt -> Profil zuweisen
  if ($action === 'set_product_freight_profile') {
	$pid = pv_freight_norm_profile_id((string)($_POST['pf_profile_id'] ?? ''));
	$pk  = trim((string)($_POST['pf_product_key'] ?? ''));
	if ($pk === '') throw new RuntimeException('Produkt-Key fehlt.');

	$mapPath = pv_product_freight_map_path_admin();
	$map = pv_freight_load_json($mapPath);
	if (!is_array($map)) $map = [];

	if ($pid === '') {
	  unset($map[$pk]);
	  $_SESSION['pv_flash'] = 'Frachtprofil-Zuordnung entfernt für Produkt: ' . $pk;
	} else {
	  $map[$pk] = $pid;
	  $_SESSION['pv_flash'] = 'Frachtprofil "' . $pid . '" zugewiesen an Produkt: ' . $pk;
	}

	ksort($map);
	pv_freight_save_json($mapPath, $map, 'product_freight_profile.json');

	header('Location: admin.php?token=' . urlencode($token) . '#freight');
	exit;
  }

  return false;
}

/**
 * Rendert den Admin-Block (unterhalb Rabattcodes einfügen).
 */
function pv_freight_admin_render_section(string $token, string $productKey, array $products): void {
  $plzMap = pv_freight_load_json(pv_plz_regions_path_admin());
  $freightProfiles = pv_freight_load_json(pv_freight_profiles_path_admin());
  if (!is_array($freightProfiles)) $freightProfiles = [];
  $productFreightMap = pv_freight_load_json(pv_product_freight_map_path_admin());
  if (!is_array($productFreightMap)) $productFreightMap = [];

  $currentAssignedProfile = (string)($productFreightMap[$productKey] ?? '');

  $editFreight = null;
  if (isset($_GET['edit_freight'])) {
	$fid = pv_freight_norm_profile_id((string)$_GET['edit_freight']);
	foreach ($freightProfiles as $p) {
	  if (is_array($p) && (string)($p['id'] ?? '') === $fid) { $editFreight = $p; break; }
	}
  }

  $plzExactCount = is_array($plzMap['exact'] ?? null) ? count($plzMap['exact']) : 0;
  $plzRangeCount = is_array($plzMap['ranges'] ?? null) ? count($plzMap['ranges']) : 0;

  $ef = $editFreight ?: [];
  $efId = (string)($ef['id'] ?? '');
  $efTitle = (string)($ef['title'] ?? '');
  $efActive = !empty($ef['active']) || $efId==='';
  $efMode = (string)($ef['mode'] ?? 'max');
  $efDefault = (string)($ef['default_cost_eur'] ?? '0');
  $efLines = pv_freight_region_costs_to_lines((array)($ef['region_costs'] ?? []));

  ?>
  <div class="card" id="freight" style="border-radius:14px; margin-bottom:12px;">
	<div class="card-h">
	  <div class="card-title">Frachtkosten (PLZ → Region → Kosten)</div>
	  <div class="small">
		Dateien:
		<code>config/plz_regions.json</code>,
		<code>config/freight_profiles.json</code>,
		<code>config/product_freight_profile.json</code>
	  </div>
	</div>
	<div class="card-b">

	  <div class="small" style="font-weight:800; margin-bottom:8px;">1) PLZ-Regionen CSV importieren</div>
	  <div class="small" style="margin-bottom:8px;">
		CSV Formate:
		<code>plz;region</code> oder <code>plz_from;plz_to;region</code> (Trenner: ; oder ,).
		Aktuell: Exact <?= pv_freight_h((string)$plzExactCount) ?> / Ranges <?= pv_freight_h((string)$plzRangeCount) ?>.
	  </div>

	  <form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px;">
		<input type="hidden" name="token" value="<?= pv_freight_h($token) ?>">
		<input type="hidden" name="action" value="import_plz_regions_csv">
		<div style="min-width:320px">
		  <label>CSV Datei</label>
		  <input type="file" name="plz_csv" accept=".csv,text/csv" required>
		</div>
		<button class="btn" type="submit">CSV importieren</button>
	  </form>

	  <hr style="border:none; border-top:1px solid var(--border); margin:14px 0;">

	  <div class="small" style="font-weight:800; margin-bottom:8px;">2) Dieses Produkt: Frachtprofil zuweisen</div>
	  <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px;">
		<input type="hidden" name="token" value="<?= pv_freight_h($token) ?>">
		<input type="hidden" name="action" value="set_product_freight_profile">
		<input type="hidden" name="pf_product_key" value="<?= pv_freight_h($productKey) ?>">

		<div style="min-width:320px">
		  <label>Frachtprofil</label>
		  <select name="pf_profile_id">
			<option value="" <?= $currentAssignedProfile===''?'selected':'' ?>>(keins / 0€)</option>
			<?php foreach ($freightProfiles as $p): if (!is_array($p)) continue;
			  $pid = (string)($p['id'] ?? '');
			  $pt  = (string)($p['title'] ?? $pid);
			?>
			  <option value="<?= pv_freight_h($pid) ?>" <?= ($pid===$currentAssignedProfile?'selected':'') ?>>
				<?= pv_freight_h($pid . ' – ' . $pt) ?><?= !empty($p['active']) ? '' : ' (inaktiv)' ?>
			  </option>
			<?php endforeach; ?>
		  </select>
		  <div class="small" style="margin-top:6px">
			Aktuell: <strong><?= pv_freight_h($currentAssignedProfile !== '' ? $currentAssignedProfile : '(keins)') ?></strong>
		  </div>
		</div>

		<button class="btn" type="submit">Zuordnung speichern</button>
	  </form>

	  <hr style="border:none; border-top:1px solid var(--border); margin:14px 0;">

	  <div class="small" style="font-weight:800; margin-bottom:8px;">3) Frachtprofile verwalten</div>

	  <?php if (empty($freightProfiles)): ?>
		<div class="small">Noch keine Frachtprofile vorhanden.</div>
		<div style="height:10px"></div>
	  <?php else: ?>
		<table class="pv-table">
		  <thead>
			<tr>
			  <th>ID</th>
			  <th>Titel</th>
			  <th>Aktiv</th>
			  <th>Mode</th>
			  <th>Default €</th>
			  <th>Regionen</th>
			  <th>Aktionen</th>
			</tr>
		  </thead>
		  <tbody>
			<?php foreach ($freightProfiles as $p): if (!is_array($p)) continue;
			  $pid = (string)($p['id'] ?? '');
			  $cnt = is_array($p['region_costs'] ?? null) ? count($p['region_costs']) : 0;
			?>
			  <tr>
				<td><span class="pv-pill"><?= pv_freight_h($pid) ?></span></td>
				<td><?= pv_freight_h((string)($p['title'] ?? '')) ?></td>
				<td><?= !empty($p['active']) ? 'Ja' : 'Nein' ?></td>
				<td><?= pv_freight_h((string)($p['mode'] ?? 'max')) ?></td>
				<td><?= pv_freight_h((string)($p['default_cost_eur'] ?? 0)) ?></td>
				<td><?= pv_freight_h((string)$cnt) ?></td>
				<td style="white-space:nowrap">
				  <a class="btn" style="display:inline-block; height:32px; line-height:30px"
					href="admin.php?token=<?= urlencode($token) ?>&edit_freight=<?= urlencode($pid) ?>#freight">Bearbeiten</a>

				  <form method="post" style="display:inline-block; margin:0" onsubmit="return confirm('Frachtprofil wirklich löschen?')">
					<input type="hidden" name="token" value="<?= pv_freight_h($token) ?>">
					<input type="hidden" name="action" value="delete_freight_profile">
					<input type="hidden" name="f_id" value="<?= pv_freight_h($pid) ?>">
					<button class="btn" type="submit" style="height:32px">Löschen</button>
				  </form>
				</td>
			  </tr>
			<?php endforeach; ?>
		  </tbody>
		</table>
		<div style="height:12px"></div>
	  <?php endif; ?>

	  <div class="small" style="font-weight:800; margin:12px 0 8px;">
		<?= $efId !== '' ? ('Profil bearbeiten: ' . pv_freight_h($efId)) : 'Neues Frachtprofil anlegen' ?>
	  </div>

	  <form method="post" autocomplete="off">
		<input type="hidden" name="token" value="<?= pv_freight_h($token) ?>">
		<input type="hidden" name="action" value="save_freight_profile">

		<div class="pv-row2">
		  <div>
			<label>ID (a-z0-9_-)</label>
			<input name="f_id" required placeholder="z.B. palette" value="<?= pv_freight_h($efId) ?>">
		  </div>
		  <div>
			<label>Titel</label>
			<input name="f_title" required placeholder="z.B. Palette / Spedition" value="<?= pv_freight_h($efTitle) ?>">
		  </div>
		</div>

		<div class="pv-row2" style="margin-top:10px">
		  <div>
			<label>Mode</label>
			<select name="f_mode">
			  <option value="max" <?= $efMode==='max'?'selected':'' ?>>MAX im Warenkorb (empfohlen)</option>
			  <option value="sum" <?= $efMode==='sum'?'selected':'' ?>>SUMME über alle Artikel</option>
			</select>
			<div class="small" style="margin-top:6px">MAX = einmalige Fracht je Bestellung (nicht doppelt).</div>
		  </div>
		  <div>
			<label>Default-Kosten (EUR)</label>
			<input name="f_default_cost" value="<?= pv_freight_h($efDefault) ?>" placeholder="0">
			<div class="small" style="margin-top:6px">Wird genutzt, wenn Region keine eigene Zeile hat.</div>
		  </div>
		</div>

		<div style="margin-top:10px">
		  <label>Region-Kosten (je Zeile: Region=49.00)</label>
		  <textarea name="f_region_costs" style="width:100%; min-height:160px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= pv_freight_h($efLines) ?></textarea>
		  <div class="small" style="margin-top:6px">
			Region-Name muss exakt dem entsprechen, was aus der PLZ-CSV kommt (z.B. "Berlin", "Bayern", "Zone 1"...).
		  </div>
		</div>

		<div style="margin-top:10px; display:flex; align-items:center; gap:12px;">
		  <label style="display:flex; gap:10px; align-items:center; margin:0">
			<input type="checkbox" name="f_active" value="1" <?= $efActive ? 'checked' : '' ?> style="width:18px; height:18px;">
			<span class="small" style="font-weight:800">Aktiv</span>
		  </label>

		  <button class="btn" type="submit">Frachtprofil speichern</button>
		  <a class="btn" href="admin.php?token=<?= urlencode($token) ?>#freight" style="margin-left:8px">Neu anlegen</a>
		</div>
	  </form>

	</div>
  </div>
  <?php
}
