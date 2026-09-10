<?php
/**
 * Pictorial dashboard icons (ClassChakra-style) — filled, colorful, illustration-like.
 * Usage: <?= cc_ico('balance') ?>
 */
if (!function_exists('cc_ico')) {
function cc_ico(string $name, string $size = ''): string {
    static $seq = 0;
    $seq++;
    $uid = 'cci' . $seq;
    $cls = 'cc-ico' . ($size ? ' cc-ico-' . $size : '');
    $svgs = [

'balance' => <<<SVG
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <defs>
    <linearGradient id="{$uid}Bal" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#60a5fa"/><stop offset="100%" stop-color="#2563eb"/></linearGradient>
  </defs>
  <circle cx="32" cy="10" r="5" fill="url(#{$uid}Bal)"/>
  <rect x="30" y="14" width="4" height="28" rx="2" fill="#2563eb"/>
  <path d="M12 28h40" stroke="#2563eb" stroke-width="3.5" stroke-linecap="round"/>
  <path d="M14 28c0 8 4 14 10 14s10-6 10-14" fill="#93c5fd" opacity=".9"/>
  <path d="M30 28c0 8 4 14 10 14s10-6 10-14" fill="#60a5fa"/>
  <ellipse cx="24" cy="42" rx="9" ry="3.5" fill="#3b82f6"/>
  <ellipse cx="40" cy="42" rx="9" ry="3.5" fill="#1d4ed8"/>
  <rect x="22" y="48" width="20" height="5" rx="2.5" fill="#2563eb"/>
</svg>
SVG,

'pie' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="32" cy="32" r="22" fill="#dbeafe"/>
  <path d="M32 10 A22 22 0 0 1 52.5 40 L32 32 Z" fill="#f59e0b"/>
  <path d="M52.5 40 A22 22 0 0 1 18 48 L32 32 Z" fill="#22c55e"/>
  <path d="M18 48 A22 22 0 0 1 32 10 L32 32 Z" fill="#2563eb"/>
  <circle cx="32" cy="32" r="7" fill="#fff"/>
</svg>
SVG,

'bars' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <rect x="8" y="48" width="48" height="4" rx="2" fill="#93c5fd"/>
  <rect x="12" y="30" width="10" height="18" rx="3" fill="#60a5fa"/>
  <rect x="27" y="18" width="10" height="30" rx="3" fill="#2563eb"/>
  <rect x="42" y="24" width="10" height="24" rx="3" fill="#3b82f6"/>
  <circle cx="17" cy="26" r="3" fill="#fbbf24"/>
  <circle cx="32" cy="14" r="3" fill="#22c55e"/>
  <circle cx="47" cy="20" r="3" fill="#f97316"/>
</svg>
SVG,

'wallet' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <rect x="8" y="18" width="48" height="34" rx="8" fill="#2563eb"/>
  <rect x="8" y="14" width="48" height="12" rx="6" fill="#3b82f6"/>
  <rect x="36" y="30" width="20" height="14" rx="4" fill="#93c5fd"/>
  <circle cx="46" cy="37" r="3.5" fill="#fbbf24"/>
  <rect x="14" y="36" width="16" height="4" rx="2" fill="#bfdbfe"/>
</svg>
SVG,

'gradcap' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M8 28 L32 16 L56 28 L32 40 Z" fill="#2563eb"/>
  <path d="M18 32v12c0 0 8 8 14 8s14-8 14-8V32" fill="#60a5fa"/>
  <path d="M56 28v14" stroke="#f59e0b" stroke-width="3" stroke-linecap="round"/>
  <circle cx="56" cy="44" r="4" fill="#fbbf24"/>
  <path d="M32 40v6" stroke="#1e40af" stroke-width="2"/>
</svg>
SVG,

'enquiry' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="32" cy="32" r="22" fill="#2563eb"/>
  <circle cx="32" cy="32" r="16" fill="#eff6ff"/>
  <path d="M26 26c0-4 3-7 6.5-7S39 22 39 26c0 3-2 4.5-4 5.5-1.2.6-2 1.5-2 3" stroke="#2563eb" stroke-width="3.2" fill="none" stroke-linecap="round"/>
  <circle cx="33" cy="42" r="2.4" fill="#2563eb"/>
</svg>
SVG,

'exam' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <rect x="14" y="8" width="30" height="42" rx="4" fill="#dbeafe"/>
  <rect x="18" y="12" width="22" height="34" rx="2" fill="#fff"/>
  <path d="M22 20h14M22 26h14M22 32h10" stroke="#93c5fd" stroke-width="2.5" stroke-linecap="round"/>
  <path d="M36 34l8-18 6 3-8 18-5 1z" fill="#2563eb"/>
  <path d="M44 16l6 3" stroke="#fbbf24" stroke-width="2.5" stroke-linecap="round"/>
  <circle cx="48" cy="14" r="2.5" fill="#f59e0b"/>
</svg>
SVG,

'book' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M10 14c0-2 1.5-4 4-4h16v40H14c-2.5 0-4-2-4-4V14z" fill="#3b82f6"/>
  <path d="M54 14c0-2-1.5-4-4-4H34v40h16c2.5 0 4-2 4-4V14z" fill="#2563eb"/>
  <path d="M30 10h4v40h-4z" fill="#1d4ed8"/>
  <path d="M16 20h8M16 26h8M38 20h8M38 26h8" stroke="#bfdbfe" stroke-width="2" stroke-linecap="round"/>
</svg>
SVG,

'students' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="22" cy="20" r="8" fill="#60a5fa"/>
  <path d="M8 48c0-8 6-14 14-14s14 6 14 14" fill="#2563eb"/>
  <circle cx="44" cy="22" r="7" fill="#93c5fd"/>
  <path d="M34 48c1-7 6-12 12-12 7 0 12 5 13 12" fill="#3b82f6"/>
  <circle cx="52" cy="18" r="2" fill="#fbbf24"/>
</svg>
SVG,

'certificate' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="32" cy="28" r="18" fill="#2563eb"/>
  <circle cx="32" cy="28" r="12" fill="#dbeafe"/>
  <circle cx="32" cy="28" r="7" fill="#fff"/>
  <path d="M32 21l2 4.5 5 .7-3.6 3.5.9 5.1L32 32.5 27.7 34.8l.9-5.1L25 26.2l5-.7z" fill="#f59e0b"/>
  <path d="M24 44l-4 12 12-6 12 6-4-12" fill="#3b82f6"/>
  <path d="M28 48l4 3 4-3" fill="#60a5fa"/>
</svg>
SVG,

'check' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="32" cy="32" r="22" fill="#22c55e"/>
  <path d="M20 33l8 8 16-18" stroke="#fff" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
SVG,

'clock' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="32" cy="32" r="22" fill="#2563eb"/>
  <circle cx="32" cy="32" r="16" fill="#eff6ff"/>
  <path d="M32 20v13l9 5" stroke="#2563eb" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <circle cx="32" cy="32" r="3" fill="#f59e0b"/>
</svg>
SVG,

'bell' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M32 10c-9 0-16 7-16 16v8c0 4-3 7-3 7h38s-3-3-3-7v-8c0-9-7-16-16-16z" fill="#2563eb"/>
  <path d="M26 52c2 3 4 4 6 4s4-1 6-4" fill="#3b82f6"/>
  <circle cx="44" cy="16" r="7" fill="#ef4444"/>
  <text x="44" y="19" text-anchor="middle" font-size="9" font-weight="700" fill="#fff">!</text>
</svg>
SVG,

'cake' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <rect x="12" y="34" width="40" height="18" rx="4" fill="#2563eb"/>
  <path d="M12 34c4-6 8-6 12 0s8 6 12 0 8-6 12 0 8 6 12 0v4H12z" fill="#60a5fa"/>
  <rect x="28" y="16" width="8" height="14" rx="2" fill="#fbbf24"/>
  <ellipse cx="32" cy="14" rx="4" ry="5" fill="#f97316"/>
  <circle cx="20" cy="44" r="3" fill="#fbbf24"/>
  <circle cx="32" cy="44" r="3" fill="#fff"/>
  <circle cx="44" cy="44" r="3" fill="#fbbf24"/>
</svg>
SVG,

'chat' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M12 14h40a6 6 0 0 1 6 6v20a6 6 0 0 1-6 6H28l-10 10v-10H12a6 6 0 0 1-6-6V20a6 6 0 0 1 6-6z" fill="#2563eb"/>
  <circle cx="24" cy="30" r="3.5" fill="#fff"/>
  <circle cx="32" cy="30" r="3.5" fill="#bfdbfe"/>
  <circle cx="40" cy="30" r="3.5" fill="#fff"/>
</svg>
SVG,

'rupee' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="32" cy="32" r="22" fill="#2563eb"/>
  <path d="M22 22h20M22 28h20M28 22c6 0 10 3 10 8s-4 8-10 8h-2l12 12" stroke="#fff" stroke-width="3.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
SVG,

'useradd' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="26" cy="22" r="10" fill="#60a5fa"/>
  <path d="M8 52c0-10 8-16 18-16s18 6 18 16" fill="#2563eb"/>
  <circle cx="48" cy="28" r="10" fill="#22c55e"/>
  <path d="M48 22v12M42 28h12" stroke="#fff" stroke-width="3.2" stroke-linecap="round"/>
</svg>
SVG,

'edit' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <rect x="10" y="10" width="36" height="44" rx="5" fill="#dbeafe"/>
  <rect x="16" y="16" width="24" height="32" rx="2" fill="#fff"/>
  <path d="M20 24h16M20 30h16M20 36h10" stroke="#93c5fd" stroke-width="2.5" stroke-linecap="round"/>
  <path d="M34 40l12-20 7 4-12 20-6 1.5z" fill="#2563eb"/>
  <path d="M46 20l7 4" stroke="#f59e0b" stroke-width="2.5"/>
</svg>
SVG,

'bolt' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <circle cx="32" cy="32" r="22" fill="#2563eb"/>
  <path d="M36 12L22 34h10l-4 18 18-26H34l2-14z" fill="#fbbf24"/>
</svg>
SVG,

'card' => <<<'SVG'
<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <rect x="6" y="16" width="52" height="34" rx="7" fill="#2563eb"/>
  <rect x="6" y="24" width="52" height="8" fill="#1d4ed8"/>
  <rect x="14" y="38" width="16" height="5" rx="2" fill="#93c5fd"/>
  <rect x="40" y="36" width="10" height="8" rx="2" fill="#fbbf24"/>
</svg>
SVG,

    ];

    $svg = $svgs[$name] ?? $svgs['bolt'];
    return '<span class="' . htmlspecialchars($cls) . '">' . $svg . '</span>';
}
}

if (!function_exists('cc_png')) {
/** PNG dashboard icon from assets/icons/dash/ (128px) with cache-bust; falls back to full icons/. */
function cc_png(string $file, string $size = 'xl', string $alt = ''): string {
    static $preloaded = [];
    $cls = 'cc-ico' . ($size !== '' ? ' cc-ico-' . $size : '');
    $px = $size === 'sm' ? 44 : ($size === 'xl' ? 72 : 64);
    $file = basename(ltrim($file, '/'));
    $baseFs = dirname(__DIR__) . '/assets/icons/';
    $dashFs = $baseFs . 'dash/' . $file;
    $fullFs = $baseFs . $file;
    $useDash = is_file($dashFs);
    $fs = $useDash ? $dashFs : $fullFs;
    $rel = ($useDash ? '../assets/icons/dash/' : '../assets/icons/') . $file;
    $v = is_file($fs) ? (int)filemtime($fs) : 0;
    $src = $rel . ($v ? ('?v=' . $v) : '');
    $altEsc = htmlspecialchars($alt !== '' ? $alt : pathinfo($file, PATHINFO_FILENAME));
    // Eager + high priority once per unique icon (above-the-fold cards); avoid lazy delay
    $prio = empty($preloaded[$file]) && count($preloaded) < 4 ? ' fetchpriority="high"' : '';
    $preloaded[$file] = true;
    return '<span class="' . htmlspecialchars($cls) . '">'
        . '<img src="' . htmlspecialchars($src) . '" alt="' . $altEsc . '" width="' . $px . '" height="' . $px . '" loading="eager" decoding="async"' . $prio . '>'
        . '</span>';
}
}
