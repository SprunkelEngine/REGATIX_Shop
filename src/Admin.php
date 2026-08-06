<?php
declare(strict_types=1);

namespace PV;

require_once __DIR__ . '/compat.php';

final class Admin
{
  private array $config;
  private string $root;
  private string $contentPath;
  private string $imagesRoot;
  private string $productKey;

  /**
   * Backward compatible:
   * - $productKey === ''  => Legacy: data/content.json + images/<article>/
   * - $productKey gesetzt => data/products/<key>/content.json + images/<key>/<article>/
   */
  public function __construct(string $configPath, string $productKey = '')
  {
    $this->root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $this->config = json_decode((string)file_get_contents($configPath), true) ?: [];
    $this->productKey = $this->sanitizeKey($productKey);

    $this->contentPath = $this->resolveContentPath();
    $this->imagesRoot = $this->root . '/images';
  }

  public function getProductKey(): string
  {
    return $this->productKey;
  }

  public function saveText(string $scope, string $article, string $title, string $infoHtml): void
  {
    $c = $this->readContent();
    if ($scope === 'product') {
      $c['product']['title'] = trim($title);
      $c['product']['info_html'] = $infoHtml;
    } else {
      $article = trim($article);
      if ($article === '') throw new \RuntimeException('Artikelnummer fehlt.');
      $c['articles'][$article] = $c['articles'][$article] ?? [];
      $c['articles'][$article]['info_html'] = $infoHtml;
    }
    $this->writeContent($c);
  }

  public function saveOverrides(string $article, array $overrides): void
  {
    $article = trim($article);
    if ($article === '') throw new \RuntimeException('Artikelnummer fehlt.');
    $c = $this->readContent();
    $c['articles'][$article] = $c['articles'][$article] ?? [];
    $c['articles'][$article]['overrides'] = $c['articles'][$article]['overrides'] ?? [];

    foreach ($overrides as $k => $v) {
      $k = trim((string)$k);
      if ($k === '') continue;
      $c['articles'][$article]['overrides'][$k] = trim((string)$v);
    }
    $this->writeContent($c);
  }

  public function uploadImages(string $article, ?array $files): void
  {
    $article = trim($article);
    if ($article === '') throw new \RuntimeException('Artikelnummer fehlt.');
    if (!$files || !isset($files['name'])) throw new \RuntimeException('Keine Dateien.');

    $dir = $this->imagesDirForArticle($article);
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $allowed = ['jpg','jpeg','png','webp'];
    $max = 6 * 1024 * 1024;

    $c = $this->readContent();
    $c['articles'][$article] = $c['articles'][$article] ?? [];
    $c['articles'][$article]['images'] = $c['articles'][$article]['images'] ?? [];

    $names = (array)$files['name'];
    $tmps  = (array)$files['tmp_name'];
    $sizes = (array)$files['size'];
    $errs  = (array)$files['error'];

    for ($i=0; $i<count($names); $i++) {
      if ((int)$errs[$i] !== UPLOAD_ERR_OK) continue;
      if ((int)$sizes[$i] > $max) continue;

      $orig = (string)$names[$i];
      $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowed, true)) continue;

      $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($orig, PATHINFO_FILENAME)) ?: 'img';
      $target = $base . '.' . $ext;

      $n = 1;
      while (is_file($dir . '/' . $target)) {
        $target = $base . '_' . $n . '.' . $ext;
        $n++;
      }

      if (!move_uploaded_file((string)$tmps[$i], $dir . '/' . $target)) continue;

      $rel = $this->relativeImagePath($article, $target);
      if (!in_array($rel, $c['articles'][$article]['images'], true)) {
        $c['articles'][$article]['images'][] = $rel;
      }
    }

    $this->writeContent($c);
  }

  public function deleteImage(string $article, string $img): void
  {
    $article = trim($article);
    $img = trim($img);
    if ($article === '' || $img === '') return;

    $c = $this->readContent();
    $list = $c['articles'][$article]['images'] ?? [];
    if (is_array($list)) {
      $c['articles'][$article]['images'] = array_values(array_filter($list, fn($x)=> (string)$x !== $img));
    }

    $path = $this->imagesRoot . '/' . ltrim($img, '/');
    if (is_file($path)) @unlink($path);

    $this->writeContent($c);
  }

  private function readContent(): array
  {
    if (!is_file($this->contentPath)) return ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]];
    $json = json_decode((string)file_get_contents($this->contentPath), true);
    if (!is_array($json)) return ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]];
    $json['product'] = $json['product'] ?? ['title'=>'','info_html'=>''];
    $json['articles'] = $json['articles'] ?? [];
    return $json;
  }

  private function writeContent(array $c): void
  {
    $dir = dirname($this->contentPath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $tmp = $this->contentPath . '.tmp';
    file_put_contents($tmp, json_encode($c, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    rename($tmp, $this->contentPath);
  }

  private function resolveContentPath(): string
  {
    if ($this->productKey === '') return $this->root . '/data/content.json';
    return $this->root . '/data/products/' . $this->productKey . '/content.json';
  }

  private function imagesDirForArticle(string $article): string
  {
    $a = $this->safePath($article);
    if ($this->productKey === '') return $this->imagesRoot . '/' . $a;
    return $this->imagesRoot . '/' . $this->productKey . '/' . $a;
  }

  private function relativeImagePath(string $article, string $filename): string
  {
    // Wichtig: Frontend rendert <img src="../images/${src}">
    // Legacy bleibt: "<Artikelnr>/<file>"
    if ($this->productKey === '') return $article . '/' . $filename;

    // Multi: "<productKey>/<safeArticle>/<file>"
    return $this->productKey . '/' . $this->safePath($article) . '/' . $filename;
  }

  private function safePath(string $s): string
  {
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $s) ?: 'x';
  }

  private function sanitizeKey(string $key): string
  {
    $key = trim($key);
    if ($key === '') return '';
    return preg_replace('~[^A-Za-z0-9_\-]~', '', $key) ?: '';
  }
}
