<?php

/**
 * Defect check `confirm-repeats-irreversible` (docs/defects/confirm-repeats-irreversible.md).
 *
 * Fails (exit 1) when a PHP file lets a bulk-sale confirmation run again:
 *   - a `bulkSell_go_…` callback is built without the plan token from the preview;
 *   - `bulkSellResources(…)` is called with fewer than four arguments (no confirm token).
 *
 * usage: php scripts/defects-confirm-once-check.php [<file-or-dir>]   (default: app/)
 */

declare(strict_types=1);

$target = $argv[1] ?? 'app';
if (! file_exists($target)) {
    fwrite(STDERR, "confirm-once-check: no such path: {$target}\n");
    exit(2);
}

$files = [];
if (is_dir($target)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->isFile() && str_ends_with($f->getFilename(), '.php')) {
            $files[] = $f->getPathname();
        }
    }
} else {
    $files[] = $target;
}

$hits = [];
foreach ($files as $file) {
    $src = (string) file_get_contents($file);

    // 1. Confirm callback without the token on the same statement line.
    // explode, not preg_split('/\R/'): without /u, \R splits on the 0x85 byte inside Cyrillic letters.
    foreach (explode("\n", $src) as $i => $line) {
        if (str_contains($line, 'bulkSell_go_') && ! str_contains($line, 'token') && ! preg_match('/^\s*(\/\/|\*|#)/', $line)) {
            $hits[] = "{$file}:" . ($i + 1) . ': confirm callback without the plan token';
        }
    }

    // 2. Core call without the confirm token (fewer than 4 top-level arguments).
    $tokens = token_get_all($src);
    $n      = count($tokens);
    for ($k = 0; $k < $n; $k++) {
        $t = $tokens[$k];
        if (! is_array($t) || $t[0] !== T_STRING || $t[1] !== 'bulkSellResources') {
            continue;
        }
        // Skip the declaration `function bulkSellResources(`.
        $p = $k - 1;
        while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
            $p--;
        }
        if ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_FUNCTION) {
            continue;
        }
        $q = $k + 1;
        while ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) {
            $q++;
        }
        if ($q >= $n || $tokens[$q] !== '(') {
            continue;
        }
        $depth = 0;
        $args  = 0;
        $empty = true;
        for ($m = $q; $m < $n; $m++) {
            $tok = $tokens[$m];
            if ($tok === '(' || $tok === '[' || $tok === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($tok === ',' && $depth === 1) {
                $args++;
                continue;
            }
            if (! (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                $empty = false;
            }
        }
        $count = $empty ? 0 : $args + 1;
        if ($count < 4) {
            $hits[] = "{$file}:{$t[2]}: bulkSellResources() called with {$count} argument(s) — no confirm token";
        }
    }
}

if ($hits !== []) {
    fwrite(STDOUT, implode("\n", $hits) . "\n");
    exit(1);
}

fwrite(STDOUT, 'confirm-once-check: ok (' . count($files) . " file(s))\n");
exit(0);
