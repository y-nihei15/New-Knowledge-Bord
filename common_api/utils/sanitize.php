<?php
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('sanitize_string')) {
  function sanitize_string(?string $s): string {
    $s = trim((string)$s);
    // 制御文字を除去（必要なら更に厳格化）
    $s = preg_replace('/[^\P{C}\t\n\r]+/u', '', $s);
    return $s;
  }
}
