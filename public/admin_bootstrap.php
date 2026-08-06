<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/Admin.php';

use PV\Repository;
use PV\Admin;

/* ===========================
   Config
   =========================== */
$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

/* ===========================
   Helpers
   =========================== */
function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}

function pv_safe_path(string $s): string {
  return preg_replace('~[^A-Za-z0-9_\-]~', '_', $s) ?: 'x';
}

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ===========================
   Atomic JSON write helper
   =========================== */
function pv_atomic_write_json(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
	throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('JSON konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
	if (!@copy($tmp, $path)) {
	  @unlink($tmp);
	  throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
	}
	@unlink($tmp);
  }
}

/* ===========================
   Paths + JSON loader + rm -rf
   =========================== */
function pv_variants_path_for_product(array $cfg, string $productKey): string {
  return $productKey === ''
	? (__DIR__ . '/../' . (string)($cfg['import']['json_path'] ?? 'data/variants.json'))
	: (__DIR__ . '/../data/products/' . $productKey . '/variants.json');
}

function pv_content_path_for_product(string $productKey): string {
  return $productKey === ''
	? (__DIR__ . '/../data/content.json')
	: (__DIR__ . '/../data/products/' . $productKey . '/content.json');
}

function pv_load_json_array(string $path, array $default = []): array {
  if (!is_file($path)) return $default;
  $raw = json_decode((string)file_get_contents($path), true);
  return is_array($raw) ? $raw : $default;
}

function pv_delete_dir_recursive(string $dir): void {
  if (!is_dir($dir)) return;
  foreach (scandir($dir) ?: [] as $f) {
	if ($f === '.' || $f === '..') continue;
	$p = $dir . '/' . $f;
	if (is_dir($p)) pv_delete_dir_recursive($p);
	else @unlink($p);
  }
  @rmdir($dir);
}

/* ===========================
   Felder pro Produkt (fields.json)
   =========================== */
function pv_fields_path(string $productKey): string {
  $productKey = pv_key($productKey);
  if ($productKey === '') $productKey = 'fachbodenregal';
  return __DIR__ . '/../data/products/' . $productKey . '/fields.json';
}

function pv_default_fields_template(): array {
  return [
	'overrides' => [
	  ['key'=>'Ebenen','label'=>'Ebenen','type'=>'text'],
	  ['key'=>'Material','label'=>'Material','type'=>'text'],
	  ['key'=>'Nennhöhe mm','label'=>'Nennhöhe mm','type'=>'text'],
	  ['key'=>'Nenntiefe mm','label'=>'Nenntiefe mm','type'=>'text'],
	  ['key'=>'Nennlänge mm','label'=>'Nennlänge mm','type'=>'text'],
	  ['key'=>'Gewicht kg','label'=>'Gewicht kg','type'=>'text'],
	  ['key'=>'lichte Breite mm','label'=>'lichte Breite mm','type'=>'text'],
	  ['key'=>'Achsmaß mm','label'=>'Achsmaß mm','type'=>'text'],
	  ['key'=>'ohne MwSt. €','label'=>'ohne MwSt. €','type'=>'text'],
	  ['key'=>'mit MwSt. €','label'=>'mit MwSt. €','type'=>'text'],
	  ['key'=>'Verfügbarkeit','label'=>'Verfügbarkeit','type'=>'text'],
	],
	'free_text' => [],
  ];
}

function pv_load_fields(string $productKey): array {
  $path = pv_fields_path($productKey);
  if (!is_file($path)) return pv_default_fields_template();

  $raw = json_decode((string)file_get_contents($path), true);
  if (!is_array($raw)) return pv_default_fields_template();

  $raw['overrides'] = (isset($raw['overrides']) && is_array($raw['overrides'])) ? $raw['overrides'] : [];
  $raw['free_text'] = (isset($raw['free_text']) && is_array($raw['free_text'])) ? $raw['free_text'] : [];

  return $raw;
}

/* ===========================
   Auth Token (shared)
   =========================== */
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');

if ($expected !== '' && !hash_equals($expected, $token)) {
  http_response_code(403);
  echo "Forbidden (token). Setze security.admin_token in config/config.json.";
  exit;
}

/* ===========================
   Flash Message (shared)
   =========================== */
$flash = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$msg = $flash !== '' ? $flash : '';
$err = '';

/* ===========================
   Produkt-Kontext (shared)
   =========================== */
if (pv_key((string)($_SESSION['pv_product'] ?? '')) === '') {
  $_SESSION['pv_product'] = 'fachbodenregal';
}
$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));

/* ===========================
   Repo/Admin (shared)
   =========================== */
$repo  = new Repository($configPath, $productKey);
$cfg   = $repo->getConfig();
$admin = new Admin($configPath, $productKey);

/* ===========================
   Bootstrap (shared)
   =========================== */
$boot = $repo->bootstrap();
$variants = $boot['variants'] ?? [];
$pk = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');

/* Artikel bestimmen (shared default) */
$article = (string)($_GET['article'] ?? ($_POST['article'] ?? ''));
if ($article === '' && isset($variants[0][$pk])) $article = (string)$variants[0][$pk];

/* Detail laden (shared) */
$detail = $repo->detail($article);
