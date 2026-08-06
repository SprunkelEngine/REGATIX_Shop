<?php
declare(strict_types=1);

namespace PV;

final class Proforma
{

  /** Load letterhead layout anchors (relative coords from top-left). */
  private static function loadLetterheadLayout(): ?array
  {
    $file = dirname(__DIR__) . '/data/letterhead/layout.json';
    if (!is_file($file)) return null;
    $raw = (string)@file_get_contents($file);
    if ($raw === '') return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) return null;
    if (!isset($j['anchors']) || !is_array($j['anchors'])) return null;
    return $j;
  }

  private static function relX(array $a): float { return (float)($a['x'] ?? 0); }
  private static function relY(array $a): float { return (float)($a['y'] ?? 0); } // from top
  private static function relW(array $a): ?float { return isset($a['w']) ? (float)$a['w'] : null; }
  private static function relH(array $a): ?float { return isset($a['h']) ? (float)$a['h'] : null; }

  /** Convert relative top-left coords to PDF points (origin bottom-left). */
  private static function toPt(float $relX, float $relY): array
  {
    $x = $relX * 595.0;
    $yFromTop = $relY * 842.0;
    $y = 842.0 - $yFromTop;
    return [$x, $y];
  }

  /**
   * Create ProForma PDF.
   * - $isPreview true: shows "Vorschau" and omits missing customer details gracefully.
   */
  public static function createPdf(array $order, array $customer, array $shop, string $outFile, string $invoiceNo, bool $isPreview = false): void
  {
	$pdf = new SimplePDF();

	// Optional letterhead (PNG/JPG). Default path:
	// project_root/data/letterhead/briefbogen.png
	$defaultLetterhead = dirname(__DIR__) . '/data/letterhead/briefbogen.png';
	$letterheadPath = (string)($shop['letterhead_image'] ?? $defaultLetterhead);
	$letterheadName = null;

	if ($letterheadPath !== '' && is_file($letterheadPath)) {
	  $letterheadName = $pdf->loadImageAsJpegXObject($letterheadPath); // may return null if GD missing
	}

	

	// Optional layout anchors for letterhead editing (data/letterhead/layout.json)
	$layout = self::loadLetterheadLayout();
	$anchors = is_array($layout['anchors'] ?? null) ? $layout['anchors'] : [];
	$hasLetterhead = (bool)$letterheadName;
	$showHeader = (!$hasLetterhead) || !((bool)($layout['flags']['hide_header_band_if_letterhead'] ?? true));
	$showFooter = (!$hasLetterhead) || !((bool)($layout['flags']['hide_footer_if_letterhead'] ?? true));


	// Convenience: resolve an anchor with fallback (x,y in pt).
	$anchorPt = function(string $key, float $fallbackX, float $fallbackY) use ($anchors): array {
	  $a = $anchors[$key] ?? null;
	  if (is_array($a) && isset($a['x']) && isset($a['y'])) {
	    return self::toPt((float)$a['x'], (float)$a['y']);
	  }
	  return [$fallbackX, $fallbackY];
	};
// First page
	$pdf->addPage();
	if ($letterheadName) {
	  // Full A4 background (595 x 842 pt)
	  $pdf->drawImage($letterheadName, 0, 0, 595, 842);
	}

	// Colors
	// Accent: #95bf20
	$accent = [149/255, 191/255, 32/255];
	$dark   = [0.10, 0.10, 0.10];
	$muted  = [0.35, 0.35, 0.35];
	$line   = [0.85, 0.85, 0.85];
	$zebra  = [0.97, 0.97, 0.97];

	$company = (string)($shop['company_name'] ?? 'Firma (Demo)');
	$contactEmail = (string)($shop['contact_email'] ?? '');
	$createdAt = (string)($order['created_at'] ?? date('c'));
	$dateStr = date('d.m.Y H:i', strtotime($createdAt)) ?: date('d.m.Y H:i');

	// Header band (skip if letterhead template is used)
	if ($showHeader) {
	  $pdf->setFillColor($accent[0], $accent[1], $accent[2]);
	  $pdf->rect(0, 802, 595, 40, true, false);

	  $pdf->setTextColor(1,1,1);
	  $pdf->text(40, 818, 16, 'ProForma Rechnung');
	}
	if ($showHeader && $isPreview) {
	  $pdf->text(220, 818, 11, '(Vorschau aus Warenkorb)');
	}

	if ($showHeader) {
	// Right header info
	$pdf->setTextColor(1,1,1);
	$pdf->text(360, 822, 10, $company);

	// Reset text color
	$pdf->setTextColor($dark[0], $dark[1], $dark[2]);

	// Meta
	$pdf->text(40, 790, 10, 'Rechnungsnr.: ' . $invoiceNo);
	$pdf->text(40, 776, 10, 'Datum: ' . $dateStr);

	if ($contactEmail !== '') {
	  $pdf->setTextColor($muted[0], $muted[1], $muted[2]);
	  $pdf->text(360, 790, 9, 'E-Mail: ' . $contactEmail);
	  $pdf->setTextColor($dark[0], $dark[1], $dark[2]);
	}

	// Blocks
	$pdf->setStrokeColor($line[0], $line[1], $line[2]);
	$pdf->setLineWidth(1);
	$pdf->line(40, 758, 555, 758);
	}


	// Customer block title
	$pdf->setTextColor($muted[0], $muted[1], $muted[2]);
	list($cxT, $cyT) = $anchorPt('customer_title', 40, 742);
	$pdf->text($cxT, $cyT, 9, 'Kunde');
	$pdf->setTextColor($dark[0], $dark[1], $dark[2]);

	$name = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));
	$email = trim((string)($customer['email'] ?? ''));
	$companyC = trim((string)($customer['company'] ?? ''));

	list($cx, $cy) = $anchorPt('customer_lines', 40, 726);
	$y = $cy;
	if ($name !== '') { $pdf->text($cx, $y, 10, $name); $y -= 14; }
	if ($companyC !== '') { $pdf->text($cx, $y, 10, $companyC); $y -= 14; }
	if ($email !== '' && !$isPreview) { $pdf->text($cx, $y, 10, $email); $y -= 14; }

	$street = trim((string)($customer['street'] ?? ''));
	$zip = trim((string)($customer['zip'] ?? ''));
	$city = trim((string)($customer['city'] ?? ''));
	$country = trim((string)($customer['country'] ?? ''));

	if ($street !== '' && !$isPreview) { $pdf->text($cx, $y, 10, $street); $y -= 14; }
	$line2 = trim($zip . ' ' . $city);
	if ($line2 !== '' && !$isPreview) { $pdf->text($cx, $y, 10, $line2); $y -= 14; }
	if ($country !== '' && !$isPreview) { $pdf->text($cx, $y, 10, $country); $y -= 14; }

	// Product/Order note block on right
	$pdf->setTextColor($muted[0], $muted[1], $muted[2]);
	list($nxT, $nyT) = $anchorPt('note_title', 360, 742);
	$pdf->text($nxT, $nyT, 9, 'Hinweis');
	$pdf->setTextColor($dark[0], $dark[1], $dark[2]);

	$title = trim((string)($order['product_title'] ?? ''));
	if ($title !== '') $pdf->text(360, 726, 10, $title);

	$pdf->setTextColor($muted[0], $muted[1], $muted[2]);
	$pdf->text(360, 710, 9, 'ProForma – keine steuerrechtliche Rechnung');
	$pdf->setTextColor($dark[0], $dark[1], $dark[2]);

	$pdf->line(40, 690, 555, 690);

	// Table header
	$pdf->setFillColor(0.94,0.94,0.94);
	list($tx, $ty) = $anchorPt('table_header', 40, 668);
	$pdf->rect($tx, $ty, 515, 18, true, false);

	$pdf->setTextColor($muted[0], $muted[1], $muted[2]);
	$pdf->text($tx + 6, $ty + 6, 9, 'Artikel');
	$pdf->text($tx + 130, $ty + 6, 9, 'Auswahl');
	$pdf->text($tx + 375, $ty + 6, 9, 'Menge');
	$pdf->text($tx + 425, $ty + 6, 9, 'Preis');
	$pdf->text($tx + 475, $ty + 6, 9, 'Summe');
	$pdf->setTextColor($dark[0], $dark[1], $dark[2]);

	list($rx, $ry) = $anchorPt('table_rows_start', 40, 650);
	$y = $ry;
	$dx = $rx - 40;
	$row = 0;

	$items = $order['items'] ?? [];
	if (!is_array($items)) $items = [];

	foreach ($items as $it) {
	  if (!is_array($it)) continue;

	  if ($y < 160) {
		$pdf->addPage();
		if ($letterheadName) {
		  $pdf->drawImage($letterheadName, 0, 0, 595, 842);
		}

		// header band on new page (skip if letterhead template is used)
		if ($showHeader) {
		  $pdf->setFillColor($accent[0], $accent[1], $accent[2]);
		  $pdf->rect(0, 802, 595, 40, true, false);
		  $pdf->setTextColor(1,1,1);
		  $pdf->text(40, 818, 16, 'ProForma Rechnung');
		}
		$pdf->setTextColor(1,1,1);
		$pdf->text(40, 818, 14, 'ProForma Rechnung');
		$pdf->setTextColor($dark[0], $dark[1], $dark[2]);

		$pdf->text(40, 790, 10, 'Rechnungsnr.: ' . $invoiceNo);
		$pdf->text(40, 776, 10, 'Datum: ' . $dateStr);

		$pdf->setFillColor(0.94,0.94,0.94);
		$pdf->rect(40, 742, 515, 18, true, false);
		$pdf->setTextColor($muted[0], $muted[1], $muted[2]);
		$pdf->text(46,  748, 9, 'Artikel');
		$pdf->text(170, 748, 9, 'Auswahl');
		$pdf->text(415, 748, 9, 'Menge');
		$pdf->text(465, 748, 9, 'Preis');
		$pdf->text(515, 748, 9, 'Summe');
		$pdf->setTextColor($dark[0], $dark[1], $dark[2]);

		$y = 724;
		$row = 0;
	  }

	  $article = (string)($it['article'] ?? '');
	  $sel = (string)($it['selection_text'] ?? '');
	  $qty = (int)max(1, (int)($it['quantity'] ?? 1));

	  $unitGross = self::toFloat($it['price_gross_unit'] ?? null);
	  $lineGross = $unitGross !== null ? $unitGross * $qty : null;

	  // zebra background
	  if (($row % 2) === 0) {
		$pdf->setFillColor($zebra[0], $zebra[1], $zebra[2]);
		$pdf->rect(40, $y - 4, 515, 16, true, false);
	  }

	  $pdf->setTextColor($dark[0], $dark[1], $dark[2]);
	  $pdf->text(46 + $dx,  $y, 9, $article);

	  $wrapped = self::wrap($sel, 44);
	  $pdf->setTextColor($muted[0], $muted[1], $muted[2]);
	  $pdf->text(170 + $dx, $y, 8, $wrapped[0] ?? '');
	  $pdf->setTextColor($dark[0], $dark[1], $dark[2]);

	  $pdf->text(418, $y, 9, (string)$qty);
	  $pdf->text(465 + $dx, $y, 9, $unitGross !== null ? self::money($unitGross) : '—');
	  $pdf->text(515 + $dx, $y, 9, $lineGross !== null ? self::money($lineGross) : '—');

	  $y -= 16;
	  $row++;
	}

	// Totals
	$totals = is_array($order['totals'] ?? null) ? $order['totals'] : [];
	$sumGross = $totals['gross'] ?? null;
	$sumNet = $totals['net'] ?? null;

	list($sx, $sy) = $anchorPt('sum_box', 360, 120);
	$fixedSum = (bool)($anchors['sum_box']['fixed'] ?? false);
	$boxY = $fixedSum ? $sy : max($sy, $y - 10);
	$pdf->setFillColor(0.94,0.94,0.94);
	$pdf->rect($sx, $boxY, 195, 70, true, false);

	$pdf->setTextColor($muted[0], $muted[1], $muted[2]);
	$pdf->text($sx + 10, $boxY + 52, 9, 'Summe brutto');
	$pdf->text($sx + 10, $boxY + 34, 9, 'Summe netto');
	$pdf->setTextColor($dark[0], $dark[1], $dark[2]);
	$pdf->text($sx + 145, $boxY + 52, 10, self::moneyOrDash($sumGross));
	$pdf->text($sx + 145, $boxY + 34, 10, self::moneyOrDash($sumNet));

	if (self::isNumber($sumGross) && self::isNumber($sumNet)) {
	  $vat = (float)$sumGross - (float)$sumNet;
	  if ($vat >= 0) {
		$pdf->setTextColor($muted[0], $muted[1], $muted[2]);
		$pdf->text($sx + 10, $boxY + 16, 9, 'MwSt.');
		$pdf->setTextColor($dark[0], $dark[1], $dark[2]);
		$pdf->text($sx + 145, $boxY + 16, 10, self::money($vat));
	  }
	}

	// Footer (skip if letterhead template is used)
	if ($showFooter) {
	  $pdf->setTextColor($muted[0], $muted[1], $muted[2]);
	$pdf->text(40, 60, 8, 'ProForma Rechnung – keine steuerrechtliche Rechnung. Lieferung/Abholung nach Abstimmung.');
	if ($contactEmail !== '') {
	  $pdf->text(40, 46, 8, $company . ' · ' . $contactEmail);
	} else {
	  $pdf->text(40, 46, 8, $company);
	}

	}

	$pdf->saveToFile($outFile);
  }

  private static function toFloat($v): ?float
  {
	if ($v === null || $v === '' ) return null;
	if (is_float($v) || is_int($v)) return (float)$v;

	$s = trim((string)$v);
	$s = preg_replace('/[^\d,.\-]/', '', $s ?? '');
	if ($s === '') return null;

	if (str_contains($s, '.') && str_contains($s, ',')) {
	  $s = str_replace('.', '', $s);
	  $s = str_replace(',', '.', $s);
	} else {
	  $s = str_replace(',', '.', $s);
	}
	$n = (float)$s;
	return is_finite($n) ? $n : null;
  }

  private static function isNumber($v): bool
  {
	return $v !== null && $v !== '' && is_numeric($v);
  }

  private static function money(float $n): string
  {
	return number_format($n, 2, ',', '.') . ' €';
  }

  private static function moneyOrDash($v): string
  {
	if (!self::isNumber($v)) return '—';
	return self::money((float)$v);
  }

  private static function wrap(string $s, int $max): array
  {
	$s = trim($s);
	if ($s === '') return [''];

	if (mb_strlen($s) <= $max) return [$s];

	$out = [];
	$cur = '';
	foreach (preg_split('/\s+/', $s) as $w) {
	  $test = ($cur === '') ? $w : ($cur . ' ' . $w);
	  if (mb_strlen($test) <= $max) $cur = $test;
	  else {
		$out[] = $cur;
		$cur = $w;
		if (count($out) >= 2) break;
	  }
	}
	if ($cur !== '' && count($out) < 2) $out[] = $cur;
	return $out;
  }
}

/**
 * Ultra-light PDF writer:
 * - Helvetica with WinAnsiEncoding (German umlauts, €)
 * - supports filled rects + drawing embedded JPEG XObjects
 * - can load PNG/JPG via GD and convert to JPEG internally
 */
final class SimplePDF
{
  private array $pages = [];
  private int $cur = -1;

  /** @var array<string,array{data:string,width:int,height:int}> */
  private array $images = []; // name => data/size
  /** @var array<string,string> */
  private array $imageCache = []; // pathHash => name
  private int $imageCounter = 0;

  public function addPage(): void
  {
	$this->pages[] = '';
	$this->cur = count($this->pages) - 1;

	// defaults
	$this->setLineWidth(1);
	$this->setStrokeColor(0,0,0);
	$this->setFillColor(0,0,0);
	$this->setTextColor(0,0,0);
  }

  // ---------- Image handling ----------
  public function loadImageAsJpegXObject(string $path): ?string
  {
	$real = realpath($path);
	if (!$real || !is_file($real)) return null;

	$key = sha1($real . '|' . (string)@filemtime($real) . '|' . (string)@filesize($real));
	if (isset($this->imageCache[$key])) {
	  return $this->imageCache[$key];
	}

	// Needs GD
	if (!function_exists('imagecreatefrompng') || !function_exists('imagejpeg')) {
	  return null;
	}

	$info = @getimagesize($real);
	if (!$info || empty($info['mime'])) return null;

	$mime = (string)$info['mime'];
	$im = null;

	try {
	  if ($mime === 'image/png') {
		$im = @imagecreatefrompng($real);
	  } elseif ($mime === 'image/jpeg') {
		$im = @imagecreatefromjpeg($real);
	  } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
		$im = @imagecreatefromwebp($real);
	  } else {
		return null;
	  }

	  if (!$im) return null;

	  // Convert to JPEG bytes (no alpha; white background if needed)
	  $w = imagesx($im);
	  $h = imagesy($im);

	  $rgb = imagecreatetruecolor($w, $h);
	  $white = imagecolorallocate($rgb, 255, 255, 255);
	  imagefilledrectangle($rgb, 0, 0, $w, $h, $white);
	  imagecopy($rgb, $im, 0, 0, 0, 0, $w, $h);

	  ob_start();
	  imagejpeg($rgb, null, 92);
	  $jpeg = (string)ob_get_clean();

	  imagedestroy($rgb);
	  imagedestroy($im);

	  if ($jpeg === '') return null;

	  $name = 'Im' . (++$this->imageCounter);
	  $this->images[$name] = ['data' => $jpeg, 'width' => $w, 'height' => $h];
	  $this->imageCache[$key] = $name;

	  return $name;
	} catch (\Throwable $e) {
	  if (is_resource($im) || $im instanceof \GdImage) {
		@imagedestroy($im);
	  }
	  return null;
	}
  }

  /**
   * Draw image XObject on current page.
   * Coordinates in PDF points; origin bottom-left.
   */
  public function drawImage(string $name, float $x, float $y, float $w, float $h): void
  {
	if ($this->cur < 0) $this->addPage();
	if (!isset($this->images[$name])) return;

	// q ... cm /ImX Do Q
	$this->pages[$this->cur] .= "q\n{$w} 0 0 {$h} {$x} {$y} cm\n/{$name} Do\nQ\n";
  }

  // ---------- Graphics primitives ----------
  public function setLineWidth(float $w): void
  {
	if ($this->cur < 0) $this->addPage();
	$this->pages[$this->cur] .= $w . " w\n";
  }

  public function setStrokeColor(float $r, float $g, float $b): void
  {
	if ($this->cur < 0) $this->addPage();
	$this->pages[$this->cur] .= "{$r} {$g} {$b} RG\n";
  }

  public function setFillColor(float $r, float $g, float $b): void
  {
	if ($this->cur < 0) $this->addPage();
	$this->pages[$this->cur] .= "{$r} {$g} {$b} rg\n";
  }

  public function setTextColor(float $r, float $g, float $b): void
  {
	// Text uses fill color
	$this->setFillColor($r,$g,$b);
  }

  public function rect(float $x, float $y, float $w, float $h, bool $fill, bool $stroke): void
  {
	if ($this->cur < 0) $this->addPage();
	$op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
	$this->pages[$this->cur] .= "{$x} {$y} {$w} {$h} re {$op}\n";
  }

  public function text(float $x, float $y, int $size, string $text): void
  {
	if ($this->cur < 0) $this->addPage();
	$t = $this->esc($text);
	$this->pages[$this->cur] .= "BT\n/F1 {$size} Tf\n{$x} {$y} Td\n({$t}) Tj\nET\n";
  }

  public function line(float $x1, float $y1, float $x2, float $y2): void
  {
	if ($this->cur < 0) $this->addPage();
	$this->pages[$this->cur] .= "{$x1} {$y1} m {$x2} {$y2} l S\n";
  }

  public function saveToFile(string $file): void
  {
	$objects = [];
	$offsets = [];

	$addObj = function(string $content) use (&$objects): int {
	  $objects[] = $content;
	  return count($objects);
	};

	// ✅ WinAnsiEncoding => äöüÄÖÜß / € funktionieren (als CP1252 geschrieben)
	$fontObj = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");

	// Images -> XObjects
	$imgObjNo = []; // name => objNo
	foreach ($this->images as $name => $img) {
	  $bin = $img['data'];
	  $len = strlen($bin);
	  $w = (int)$img['width'];
	  $h = (int)$img['height'];

	  $imgObjNo[$name] = $addObj(
		"<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} " .
		"/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$len} >>\n" .
		"stream\n{$bin}\nendstream"
	  );
	}

	// Build XObject resource map
	$xobj = '';
	if (!empty($imgObjNo)) {
	  $pairs = [];
	  foreach ($imgObjNo as $name => $no) {
		$pairs[] = "/{$name} {$no} 0 R";
	  }
	  $xobj = " /XObject << " . implode(' ', $pairs) . " >>";
	}

	$pageObjs = [];
	foreach ($this->pages as $p) {
	  $stream = $p;
	  $len = strlen($stream);
	  $contentObj = $addObj("<< /Length {$len} >>\nstream\n{$stream}endstream");

	  $pageObj = $addObj(
		"<< /Type /Page /Parent 0 0 R /MediaBox [0 0 595 842] " .
		"/Resources << /Font << /F1 {$fontObj} 0 R >>{$xobj} >> " .
		"/Contents {$contentObj} 0 R >>"
	  );
	  $pageObjs[] = $pageObj;
	}

	$kids = implode(' ', array_map(fn($n)=> "{$n} 0 R", $pageObjs));
	$pagesObj = $addObj("<< /Type /Pages /Kids [ {$kids} ] /Count " . count($pageObjs) . " >>");

	foreach ($pageObjs as $pno) {
	  $objects[$pno-1] = str_replace('/Parent 0 0 R', "/Parent {$pagesObj} 0 R", $objects[$pno-1]);
	}

	$catalogObj = $addObj("<< /Type /Catalog /Pages {$pagesObj} 0 R >>");

	$pdf = "%PDF-1.4\n";
	$offsets[0] = 0;

	for ($i=0; $i<count($objects); $i++) {
	  $offsets[$i+1] = strlen($pdf);
	  $pdf .= ($i+1) . " 0 obj\n" . $objects[$i] . "\nendobj\n";
	}

	$xref = strlen($pdf);
	$pdf .= "xref\n0 " . (count($objects)+1) . "\n";
	$pdf .= "0000000000 65535 f \n";
	for ($i=1; $i<=count($objects); $i++) {
	  $pdf .= str_pad((string)$offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
	}

	$pdf .= "trailer\n<< /Size " . (count($objects)+1) . " /Root {$catalogObj} 0 R >>\n";
	$pdf .= "startxref\n{$xref}\n%%EOF";

	file_put_contents($file, $pdf);
  }

  private function esc(string $s): string
  {
	// normalize line breaks
	$s = str_replace(["\r\n","\r"], "\n", $s);
	$s = str_replace("\n", " ", $s);

	// ✅ convert UTF-8 -> Windows-1252 (WinAnsi)
	$enc = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
	if ($enc !== false) {
	  $s = $enc;
	}

	// remove non-printables (keep CP1252 range)
	$s = preg_replace('/[^\x20-\x7E\xA0-\xFF]/', ' ', $s) ?? $s;

	// PDF literal escaping
	$s = str_replace("\\", "\\\\", $s);
	$s = str_replace("(", "\\(", $s);
	$s = str_replace(")", "\\)", $s);

	return $s;
  }
}
