<?php
declare(strict_types=1);

namespace PV;

require_once __DIR__ . '/compat.php';

final class Repository
{
  private array $configRaw;
  private array $schema;
  private array $config; // effective (raw + schema)
  private string $root;
  private string $productKey;

  private string $jsonPath;
  private string $contentPath;
  private string $schemaPath;

  public function __construct(string $configPath, string $productKey = '')
  {
    $this->root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

    $this->configRaw = json_decode((string)file_get_contents($configPath), true) ?: [];
    $this->productKey = $this->safeKey($productKey);

    // Paths
    if ($this->productKey === '') {
      $this->jsonPath    = $this->root . '/' . ltrim((string)($this->configRaw['import']['json_path'] ?? 'data/variants.json'), '/');
      $this->contentPath = $this->root . '/data/content.json';
      $this->schemaPath  = $this->root . '/data/schema.json'; // optional legacy schema
    } else {
      $base = $this->root . '/data/products/' . $this->productKey;
      $this->jsonPath    = $base . '/variants.json';
      $this->contentPath = $base . '/content.json';
      $this->schemaPath  = $base . '/schema.json';
    }

    $this->schema = $this->readSchema();
    $this->config = $this->deepMerge($this->configRaw, $this->schema); // schema overrides
  }

  public function getConfig(): array
  {
    return $this->config;
  }

  public function getSchema(): array
  {
    return $this->schema;
  }

  public function bootstrap(): array
  {
    $variantsPayload = $this->readVariants();
    $variants = $variantsPayload['variants'] ?? [];

    $content = $this->readContent();

    // apply overrides
    $pk = (string)($this->config['variant']['primary_key'] ?? 'Artiklnummer');
    foreach ($variants as &$v) {
      $art = (string)($v[$pk] ?? '');
      $ov = $content['articles'][$art]['overrides'] ?? null;
      if (is_array($ov)) {
        foreach ($ov as $k => $val) {
          if ($val === '' || $val === null) continue;
          $v[$k] = $val;
        }
      }
    }
    unset($v);

    return [
      'generated_at' => $variantsPayload['generated_at'] ?? null,
      'vat_rate' => $variantsPayload['vat_rate'] ?? ($this->config['import']['vat_rate'] ?? 0.19),
      'product_key' => $this->productKey,
      'schema' => $this->schema,
      'config' => $this->config,
      'variants' => $variants,
      'content' => $content,
    ];
  }

  public function detail(string $articleNo): array
  {
    $boot = $this->bootstrap();
    $cfg = $boot['config'] ?? [];
    $pk = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');

    $found = null;
    foreach (($boot['variants'] ?? []) as $v) {
      if ((string)($v[$pk] ?? '') === (string)$articleNo) { $found = $v; break; }
    }

    $content = $boot['content'] ?? ['product'=>[], 'articles'=>[]];

    return [
      'article' => $found,
      'content_article' => $content['articles'][(string)$articleNo] ?? ['images'=>[]],
    ];
  }

  private function readVariants(): array
  {
    if (!is_file($this->jsonPath)) {
      return ['generated_at'=>null, 'vat_rate'=>$this->config['import']['vat_rate'] ?? 0.19, 'variants'=>[]];
    }
    $json = json_decode((string)file_get_contents($this->jsonPath), true);
    return is_array($json) ? $json : [];
  }

  private function readContent(): array
  {
    if (!is_file($this->contentPath)) return ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]];
    $json = json_decode((string)file_get_contents($this->contentPath), true);
    if (!is_array($json)) return ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]];

    $json['product']  = $json['product'] ?? ['title'=>'','info_html'=>''];
    $json['articles'] = $json['articles'] ?? [];
    return $json;
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
        // numerische Arrays (z.B. dimensions) sollen override komplett ersetzen
        $isList = array_keys($v) === range(0, count($v) - 1);
        if ($isList) {
          $base[$k] = $v;
        } else {
          $base[$k] = $this->deepMerge($base[$k], $v);
        }
      } else {
        $base[$k] = $v;
      }
    }
    return $base;
  }
}
