<?php
declare(strict_types=1);

namespace PV;

/**
 * Very small mustache-like templater:
 * - {{path.to.value}} HTML-escaped
 * - {{{path.to.value}}} unescaped (raw HTML)
 * - {{#list}} ... {{/list}} repeats block for array items; inside block, context becomes the item.
 */
final class HtmlTemplate
{
  public static function render(string $tpl, array $data): string
  {
    // Sections
    $tpl = preg_replace_callback('/\{\{#([a-zA-Z0-9_.-]+)\}\}(.*?)\{\{\/\1\}\}/s', function($m) use ($data) {
      $key = $m[1];
      $block = $m[2];
      $list = self::get($data, $key);
      if (!is_array($list)) return '';
      $out = '';
      foreach ($list as $item) {
        $ctx = is_array($item) ? $item : ['value' => $item];
        // merge: item overrides parent; parent is fallback
        $merged = $data;
        foreach ($ctx as $k => $v) $merged[$k] = $v;
        $out .= self::render($block, $merged);
      }
      return $out;
    }, $tpl);

    // Unescaped {{{ }}}
    $tpl = preg_replace_callback('/\{\{\{([a-zA-Z0-9_.-]+)\}\}\}/', function($m) use ($data) {
      $v = self::get($data, $m[1]);
      return is_scalar($v) ? (string)$v : '';
    }, $tpl);

    // Escaped {{ }}
    $tpl = preg_replace_callback('/\{\{([a-zA-Z0-9_.-]+)\}\}/', function($m) use ($data) {
      $v = self::get($data, $m[1]);
      $s = is_scalar($v) ? (string)$v : '';
      return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }, $tpl);

    return $tpl;
  }

  private static function get(array $data, string $path)
  {
    if ($path === '') return null;
    $cur = $data;
    foreach (explode('.', $path) as $p) {
      if (is_array($cur) && array_key_exists($p, $cur)) $cur = $cur[$p];
      else return null;
    }
    return $cur;
  }
}
