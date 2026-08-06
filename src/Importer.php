<?php
declare(strict_types=1);

namespace PV;

final class Importer
{
  private string $root;
  private string $productKey;

  private array $configRaw;
  private array $schema;
  private array $config;

  private string $jsonPath;
  private string $schemaPath;

  public function __construct(string $configPath, string $productKey = '')
  {
    $this->root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $this->productKey = $this->safeKey($productKey);

    $this->configRaw = json_decode((string)file_get_contents($configPath), true) ?: [];

    if ($this->productKey === '') {
      $this->jsonPath   = $this->root . '/' . ltrim((string)($this->configRaw['import']['json_path'] ?? 'data/variants.json'), '/');
      $this->schemaPath = $this->root . '/data/schema.json';
    } else {
      $base = $this->root . '/data/products/' . $this->productKey;
      $this->jsonPath   = $base . '/variants.json';
      $this->schemaPath = $base . '/schema.json';
    }

    $this->schema = $this->readSchema();
    $this->config = $this->deepMerge($this->configRaw, $this->schema);
  }

  public function run(): array
  {
    $csvPath = $this->resolveCsvPath();
    if (!is_file($csvPath)) {
      throw new \RuntimeException('CSV nicht gefunden: ' . $csvPath);
    }

    // Backward compatible: older configs used import.delimiter / import.encoding
    $delimiter = (string)($this->config['import']['csv_delimiter'] ?? ($this->config['import']['delimiter'] ?? ';'));
    if ($delimiter === '') $delimiter = ';';

    $encoding = strtoupper((string)($this->config['import']['csv_encoding'] ?? ($this->config['import']['encoding'] ?? 'UTF-8')));
    $headerMap = $this->config['import']['header_map'] ?? [];
    if (!is_array($headerMap)) $headerMap = [];

    $pk = (string)($this->config['variant']['primary_key'] ?? 'Artiklnummer');

    $priceGrossKey = (string)($this->config['variant']['price_gross'] ?? 'mit MwSt. €');
    $priceNetKey   = (string)($this->config['variant']['price_net'] ?? 'ohne MwSt. €');

    $rows = $this->readCsvAssoc($csvPath, $delimiter, $encoding, $headerMap);

        // Optional: Filter variants for selected product (if CSV contains multiple product groups/types)
        if ($this->productKey !== '' && is_array($rows) && count($rows) > 0) {
          $col = (string)($this->config['import']['product_filter_column'] ?? '');
          $val = $this->config['import']['product_filter_value'] ?? null;
          $val = is_string($val) ? trim($val) : '';
          if ($val === '') $val = $this->productKey;

          if ($col === '') {
            $candidates = $this->config['import']['product_columns'] ?? ['Produktgruppe','Produkt','Produktart'];
            if (!is_array($candidates)) $candidates = ['Produktgruppe','Produkt','Produktart'];
            foreach ($candidates as $cand) {
              $cand = (string)$cand;
              if ($cand === '') continue;
              // column exists?
              if (!array_key_exists($cand, $rows[0])) continue;
              // does it contain current productKey?
              foreach ($rows as $r) {
                if (trim((string)($r[$cand] ?? '')) === $this->productKey) { $col = $cand; break 2; }
              }
            }
            // fallback: first existing candidate
            if ($col === '') {
              foreach ($candidates as $cand) {
                $cand = (string)$cand;
                if ($cand !== '' && array_key_exists($cand, $rows[0])) { $col = $cand; break; }
              }
            }
          }

          if ($col !== '' && array_key_exists($col, $rows[0])) {
            $rows = array_values(array_filter($rows, function($r) use ($col, $val){
              return trim((string)($r[$col] ?? '')) === $val;
            }));
          }
        }


    // Filter: nur Varianten (ohne PK -> raus)
    $variants = [];
    foreach ($rows as $r) {
      $art = trim((string)($r[$pk] ?? ''));
      if ($art === '') continue;

      // Preise robust parsen (float in JSON)
      if (isset($r[$priceGrossKey])) $r[$priceGrossKey] = $this->parseMoney($r[$priceGrossKey]);
      if (isset($r[$priceNetKey]))   $r[$priceNetKey]   = $this->parseMoney($r[$priceNetKey]);

      $variants[] = $r;
    }

    // write json
    $dir = dirname($this->jsonPath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $payload = [
      'generated_at' => date('c'),
      'vat_rate' => (float)($this->config['import']['vat_rate'] ?? 0.19),
      'variants' => $variants,
    ];

    $tmp = $this->jsonPath . '.tmp';
    file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    rename($tmp, $this->jsonPath);

    return [
      'rows' => count($variants),
      'json_path' => $this->jsonPath,
      'vat_rate' => $payload['vat_rate'],
    ];
  }

  private function resolveCsvPath(): string
  {
    // 1) Upload (falls du später ein Upload-Formular machst)
    if (!empty($_FILES['csv']['tmp_name']) && is_file((string)$_FILES['csv']['tmp_name'])) {
      return (string)$_FILES['csv']['tmp_name'];
    }

    // 2) Konfigurierter Pfad
    $cfg = (string)($this->config['import']['csv_path'] ?? '');
    if ($cfg !== '') {
      $p = $cfg;
      if (!str_starts_with($p, '/')) $p = $this->root . '/' . ltrim($p, '/');
      return $p;
    }

    // 3) Fallback
    return $this->root . '/data/import.csv';
  }

    private function readCsvAssoc(string $path, string $delimiter, string $encoding, array $headerMap): array
    {
      $fh = fopen($path, 'rb');
      if (!$fh) throw new \RuntimeException('Kann CSV nicht öffnen.');

      // Peek first few rows to detect real header row (some exports have a group row above headers)
      $peek = [];
      $maxPeek = 5;
      for ($i = 0; $i < $maxPeek; $i++) {
        $row = fgetcsv($fh, 0, $delimiter);
        if ($row === false) break;
        $peek[] = $row;
      }
      if (count($peek) === 0) { fclose($fh); return []; }

      $forced = $this->config['import']['header_row'] ?? null;
      $headerRow = null;
      if (is_int($forced) || (is_string($forced) && ctype_digit($forced))) {
        $headerRow = max(0, (int)$forced);
        if ($headerRow >= count($peek)) $headerRow = null;
      }

      if ($headerRow === null) {
        $bestIdx = 0;
        $bestScore = -1;
        foreach ($peek as $idx => $r) {
          $norm = array_map(fn($h) => $this->normalizeHeader($h), $r);
          $score = 0;
          foreach ($norm as $h) {
            if ($h === '') continue;
            if (preg_match('~^(X\\d*|Spalte\\d+)$~u', $h)) continue;
            $score++;
          }
          // Prefer later row on tie (headers usually below group row)
          if ($score > $bestScore || ($score === $bestScore && $idx > $bestIdx)) {
            $bestScore = $score;
            $bestIdx = $idx;
          }
        }
        $headerRow = $bestIdx;
      }

      // rewind and skip rows until header row
      rewind($fh);
      for ($i = 0; $i < $headerRow; $i++) {
        if (fgetcsv($fh, 0, $delimiter) === false) { fclose($fh); return []; }
      }

      $cols = fgetcsv($fh, 0, $delimiter);
      if ($cols === false || !is_array($cols)) { fclose($fh); return []; }

      $headers = array_map(fn($h) => $this->normalizeHeader($h), $cols);

      // Mapping: CSV-Header -> internes Feld
      foreach ($headers as &$h) {
        if (isset($headerMap[$h]) && is_string($headerMap[$h]) && trim($headerMap[$h]) !== '') {
          $h = trim($headerMap[$h]);
        }
      }
      unset($h);

      $rows = [];
      while (($cols = fgetcsv($fh, 0, $delimiter)) !== false) {
        // Zeile normalisieren auf gleiche Länge
        $cols = array_pad($cols, count($headers), '');

        // encoding conversion
        if ($encoding !== 'UTF-8' && function_exists('mb_convert_encoding')) {
          foreach ($cols as &$c) {
            $c = mb_convert_encoding((string)$c, 'UTF-8', $encoding);
          }
          unset($c);
        }

        $row = [];
        for ($i = 0; $i < count($headers); $i++) {
          if ($headers[$i] === '') continue;
          $row[$headers[$i]] = $this->trimCell($cols[$i] ?? '');
        }
        $rows[] = $row;
      }

      fclose($fh);
      return $rows;
    }

  private function normalizeHeader($h): string
  {
    $s = (string)$h;
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s; // BOM kill
    return trim($s);
  }

  private function trimCell($v): string
  {
    $s = trim((string)$v);
    // Excel NBSP
    $s = str_replace("\xC2\xA0", ' ', $s);
    return trim($s);
  }

  private function parseMoney($v): float
  {
    $s = trim((string)$v);
    if ($s === '') return 0.0;

    // remove currency and spaces
    $s = str_replace(['€','EUR'], '', $s);
    $s = preg_replace('/\s+/', '', $s) ?? $s;

    // if "1.234,56" -> 1234.56
    if (str_contains($s, ',') && str_contains($s, '.')) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, ',')) {
      // "1234,56" -> 1234.56
      $s = str_replace(',', '.', $s);
    }

    // keep only number chars
    $s = preg_replace('/[^0-9\.\-]/', '', $s) ?? $s;

    $n = (float)$s;
    return $n;
  }

  private function readSchema(): array
  {
    if (!is_file($this->schemaPath)) return [];
    $json = json_decode((string)file_get_contents($this->schemaPath), true);
    return is_array($json) ? $json : [];
  }

  private function safeKey(string $s): string
  {
    $s = trim($s);
    if ($s === '') return '';
    return preg_replace('~[^A-Za-z0-9_\-]~', '', $s) ?: '';
  }

  private function deepMerge(array $base, array $override): array
  {
    foreach ($override as $k => $v) {
      if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
        $isList = array_keys($v) === range(0, count($v) - 1);
        if ($isList) $base[$k] = $v;
        else $base[$k] = $this->deepMerge($base[$k], $v);
      } else {
        $base[$k] = $v;
      }
    }
    return $base;
  }
}
