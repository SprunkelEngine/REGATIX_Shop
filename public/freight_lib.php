<?php
declare(strict_types=1);

/**
 * ✅ Pfad zur CSV-Datei anpassen!
 * Variante A (empfohlen): CSV ins Projekt kopieren, z.B. /data/
 *   __DIR__ . '/../data/REGATIX Versandzonen DE.csv'
 *
 * Variante B: exakt dein Serverpfad
 */
const PV_FREIGHT_CSV = __DIR__ . '/../data/REGATIX Versandzonen DE.csv';

/** PLZ normalisieren */
function pv_norm_zip(string $zip): string {
  $z = preg_replace('/\D+/', '', $zip) ?? '';
  if ($z !== '' && strlen($z) < 5) $z = str_pad($z, 5, '0', STR_PAD_LEFT);
  return substr($z, 0, 5);
}

/** CSV lesen: deine Datei ist ; getrennt, Fallback auf , */
function pv_fgetcsv_auto($fh): array|false {
  $pos = ftell($fh);
  $row = fgetcsv($fh, 0, ';');
  if ($row === false) return false;

  // Wenn Trennzeichen falsch: oft alles in Spalte 0
  if (count($row) === 1 && str_contains((string)$row[0], ',')) {
	fseek($fh, $pos);
	$row = fgetcsv($fh, 0, ',');
  }
  return $row;
}

/** Map PLZ -> Zone A/B/C/REQUEST */
function pv_load_zip_zones(): array {
  static $map = null;
  if (is_array($map)) return $map;

  $map = [];
  if (!is_file(PV_FREIGHT_CSV)) return $map;

  $fh = fopen(PV_FREIGHT_CSV, 'rb');
  if (!$fh) return $map;

  $header = pv_fgetcsv_auto($fh);
  if (!$header) { fclose($fh); return $map; }

  // Header trimmen + BOM entfernen
  $header = array_map(function($h){
	$h = (string)$h;
	$h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h; // BOM
	return trim($h);
  }, $header);

  $idxPLZ  = array_search('PLZ', $header, true);
  $idxZone = array_search('PLZ-Bereich', $header, true);

  if ($idxPLZ === false || $idxZone === false) {
	fclose($fh);
	return $map;
  }

  while (($row = pv_fgetcsv_auto($fh)) !== false) {
	$plzRaw  = (string)($row[$idxPLZ] ?? '');
	$zoneRaw = (string)($row[$idxZone] ?? '');

	$plz = pv_norm_zip($plzRaw);
	if ($plz === '') continue;

	$zoneRaw = trim($zoneRaw);

	if (stripos($zoneRaw, 'Versandzone A') !== false) $map[$plz] = 'A';
	elseif (stripos($zoneRaw, 'Versandzone B') !== false) $map[$plz] = 'B';
	elseif (stripos($zoneRaw, 'Versandzone C') !== false) $map[$plz] = 'C';
	elseif (stripos($zoneRaw, 'Versand nur auf Anfrage') !== false) $map[$plz] = 'REQUEST';
  }

  fclose($fh);
  return $map;
}

/** Netto-Fracht nach Zone */
function pv_freight_net_for_zone(string $zone): float {
  return match (strtoupper($zone)) {
	'A' => 41.60,
	'B' => 50.00,
	'C' => 58.40,
	default => 0.0,
  };
}

/**
 * Prüft, ob "Fachbodenregal" im Order JSON vorkommt.
 * (Falls du stattdessen SKU/ID matchen willst: sag mir kurz die Struktur)
 */
function pv_order_has_fachbodenregal($order): bool {
  if (is_array($order) || is_object($order)) {
	$json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($json && stripos($json, 'fachbodenregal') !== false) return true;
  }
  return false;
}

/**
 * Berechnet Fracht:
 * - nur wenn nicht Abholung
 * - nur wenn Fachbodenregal im Warenkorb
 * - PLZ -> Zone -> Betrag
 */
function pv_calc_freight(array $payload): array {
  $zip = pv_norm_zip((string)($payload['zip'] ?? ''));
  $pickup = !empty($payload['pickup']); // true => Abholung => keine Fracht
  $order  = $payload['order'] ?? null;

  $apply = (!$pickup) && pv_order_has_fachbodenregal($order);

  if (!$apply) {
	return [
	  'ok'=>true, 'apply'=>false, 'zone'=>null,
	  'freight_net'=>0.0, 'freight_gross'=>0.0, 'message'=>''
	];
  }

  $map  = pv_load_zip_zones();
  $zone = $zip !== '' ? ($map[$zip] ?? null) : null;

  if ($zone === 'REQUEST') {
	return [
	  'ok'=>true, 'apply'=>true, 'zone'=>'REQUEST',
	  'freight_net'=>0.0, 'freight_gross'=>0.0,
	  'message'=>'Versand nur auf Anfrage für diese PLZ. Bitte Angebot anfordern.'
	];
  }

  if (!$zone) {
	return [
	  'ok'=>true, 'apply'=>true, 'zone'=>null,
	  'freight_net'=>0.0, 'freight_gross'=>0.0,
	  'message'=>'PLZ nicht in Versandzonen gefunden. Bitte prüfen oder Angebot anfordern.'
	];
  }

  $net = pv_freight_net_for_zone($zone);

  // ✅ USt (Standard 19%)
  $vatRate = 0.19;
  $gross = round($net * (1.0 + $vatRate), 2);

  return [
	'ok'=>true, 'apply'=>true, 'zone'=>$zone,
	'freight_net'=>$net, 'freight_gross'=>$gross, 'message'=>''
  ];
}
