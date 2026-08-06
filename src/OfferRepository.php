<?php
declare(strict_types=1);

namespace PV;

final class OfferRepository
{
  private string $path;

  public function __construct(string $offersPath)
  {
	$this->path = $offersPath;
  }

  public function load(): array
  {
	if (!is_file($this->path)) return [];
	$raw = (string)file_get_contents($this->path);
	$arr = json_decode($raw, true);
	if (!is_array($arr)) return [];
	// Wir speichern als Objekt keyed by id
	return $arr;
  }

  public function get(string $id): ?array
  {
	$all = $this->load();
	return (isset($all[$id]) && is_array($all[$id])) ? $all[$id] : null;
  }

  public function save(array $offersById): void
  {
	$dir = dirname($this->path);
	if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
	  throw new \RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
	}

	// Normalisieren / sortieren
	$out = [];
	foreach ($offersById as $id => $o) {
	  if (!is_array($o)) continue;
	  $id = trim((string)$id);
	  if ($id === '') continue;
	  $o['id'] = $o['id'] ?? $id;
	  $out[$id] = $o;
	}
	ksort($out);

	$tmp = $this->path . '.tmp';
	$json = json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
	if ($json === false) throw new \RuntimeException('offers.json konnte nicht erzeugt werden.');
	if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new \RuntimeException('Konnte nicht schreiben: ' . $tmp);

	if (!@rename($tmp, $this->path)) {
	  if (!@copy($tmp, $this->path)) {
		@unlink($tmp);
		throw new \RuntimeException('Konnte Datei nicht ersetzen: ' . $this->path);
	  }
	  @unlink($tmp);
	}
  }

  public static function newId(): string
  {
	// kurze, URL-taugliche ID
	$b = random_bytes(9);
	return 'of_' . rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
  }
}
